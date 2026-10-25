<?php
/**
 * TDX 流量限制紀錄。
 *
 * 每次轉發 TDX 後由 proxyToTdx() 呼叫 rateLimitRecord()，把回應裡的限流 header
 * （ratelimit-*、x-ratelimit-*）與本服務自己的呼叫計數存到 download/tdx_proxy_files/cache/ratelimit.php。
 * 這樣查詢流量狀態不必額外消耗 TDX 額度；需要最新數字時才用 probe 實打一次。
 *
 * TDX（Kong）的限流是「整把金鑰共用」，不同 API 路徑扣的是同一個額度。
 */

const RATELIMIT_FILE = TDX_CACHE_DIR . '/ratelimit.php';
// probe 用的上游路徑：回應很小（約 0.5KB），只是為了拿 header
const RATELIMIT_PROBE_PATH = '/api/basic/v2/Rail/THSR/Station?$format=JSON&$top=1';
// probe 的最短間隔：避免有人反覆重整把額度燒光
// RATELIMIT_PROBE_MIN_INTERVAL：設定項目（libs/config.json）

function rateLimitLoad()
{
    $raw = @file_get_contents(RATELIMIT_FILE);
    if ($raw === false) {
        return [];
    }
    $data = json_decode(substr($raw, strpos($raw, '?>') + 2), true);
    return is_array($data) ? $data : [];
}

function rateLimitSave(array $data)
{
    if (!is_dir(TDX_CACHE_DIR) && !@mkdir(TDX_CACHE_DIR, 0755, true)) {
        return;
    }
    $tmp = RATELIMIT_FILE . '.' . getmypid() . '.tmp';
    $content = '<?php exit; ?>' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, RATELIMIT_FILE)) {
        @unlink($tmp);
    }
}

/**
 * 從 TDX 回應 header 抽出限流資訊。
 * 會收集所有 x-ratelimit-limit-<窗口> / x-ratelimit-remaining-<窗口>（second/minute/hour/day/month…），
 * 以及標準的 ratelimit-limit / ratelimit-remaining / ratelimit-reset。
 */
function rateLimitParseHeaders(array $headers)
{
    $windows = [];
    $std = [];
    foreach ($headers as $name => $line) {
        $value = trim(substr($line, strpos($line, ':') + 1));
        if (preg_match('/^x-ratelimit-(limit|remaining)-([a-z]+)$/', $name, $m)) {
            $windows[$m[2]][$m[1]] = (int) $value;
        } elseif (preg_match('/^ratelimit-(limit|remaining|reset)$/', $name, $m)) {
            $std[$m[1]] = (int) $value;
        } elseif ($name === 'retry-after') {
            $std['retry_after'] = (int) $value;
        }
    }
    return [$windows, $std];
}

/** 由 proxyToTdx() 在每次上游回應後呼叫 */
function rateLimitRecord($status, array $headers, $path)
{
    $lock = @fopen(TDX_CACHE_DIR . '/ratelimit.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        $data = rateLimitLoad();
        $now = time();
        list($windows, $std) = rateLimitParseHeaders($headers);

        if ($windows || $std) {
            $data['tdx'] = [
                'observed_at' => $now,
                'status'      => (int) $status,
                'path'        => strtok($path, '?'),
                'windows'     => $windows,
                'ratelimit'   => $std,
            ];
        }

        // 本服務自己的呼叫計數（以台灣時間切日）
        $tz = new DateTimeZone('Asia/Taipei');
        $today = (new DateTime('@' . $now))->setTimezone($tz)->format('Y-m-d');
        $minute = (int) floor($now / 60);
        $c = $data['counter'] ?? [];
        if (($c['day'] ?? '') !== $today) {
            if (isset($c['day'])) {
                $c['yesterday'] = ['day' => $c['day'], 'calls' => $c['calls'] ?? 0, 'throttled' => $c['throttled'] ?? 0];
            }
            $c['day'] = $today;
            $c['calls'] = 0;
            $c['throttled'] = 0;
            $c['peak_per_minute'] = 0;
        }
        $c['calls']++;
        if ((int) $status === 429) {
            $c['throttled']++;
            $c['last_throttled_at'] = $now;
        }
        if (($c['minute'] ?? 0) !== $minute) {
            $c['minute'] = $minute;
            $c['minute_calls'] = 0;
        }
        $c['minute_calls']++;
        $c['peak_per_minute'] = max($c['peak_per_minute'] ?? 0, $c['minute_calls']);
        $data['counter'] = $c;

        rateLimitSave($data);
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/** 整理成對外輸出的格式 */
function rateLimitReport()
{
    $data = rateLimitLoad();
    $now = time();
    $tz = new DateTimeZone('Asia/Taipei');
    $fmt = function ($ts) use ($tz) {
        return $ts ? (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s') : null;
    };

    $out = ['ok' => true, 'action' => 'ratelimit', 'now' => $fmt($now)];

    if (empty($data['tdx'])) {
        $out['tdx'] = null;
        $out['hint'] = '尚未觀察到任何 TDX 回應，帶 probe=1 實打一次即可取得';
    } else {
        $t = $data['tdx'];
        $age = $now - $t['observed_at'];
        $limits = [];
        foreach ($t['windows'] as $win => $v) {
            $limits[$win] = [
                'limit'     => $v['limit'] ?? null,
                'remaining' => $v['remaining'] ?? null,
                'used'      => isset($v['limit'], $v['remaining']) ? $v['limit'] - $v['remaining'] : null,
            ];
        }
        $reset = $t['ratelimit']['reset'] ?? null;
        $out['tdx'] = [
            'observed_at'  => $fmt($t['observed_at']),
            'age_seconds'  => $age,
            // 觀察時的剩餘量只在該窗口內有效；分鐘窗口過了 reset 秒數就已重置
            'stale'        => $reset !== null ? $age >= $reset : $age >= 60,
            'last_status'  => $t['status'],
            'last_path'    => $t['path'],
            'limits'       => $limits,
            'reset_in'     => $reset !== null ? max(0, $reset - $age) : null,
            'retry_after'  => $t['ratelimit']['retry_after'] ?? null,
        ];
    }

    $c = $data['counter'] ?? null;
    if ($c) {
        $curMinute = (int) floor($now / 60);
        $out['proxy'] = [
            'day'               => $c['day'],
            'calls_today'       => $c['calls'],
            'throttled_today'   => $c['throttled'],
            'calls_this_minute' => ($c['minute'] ?? 0) === $curMinute ? $c['minute_calls'] : 0,
            'peak_per_minute'   => $c['peak_per_minute'] ?? 0,
            'last_throttled_at' => $fmt($c['last_throttled_at'] ?? null),
            'yesterday'         => $c['yesterday'] ?? null,
        ];
    } else {
        $out['proxy'] = null;
    }
    return $out;
}

/** GET /txapi/ratelimit[?probe=1] */
function actionRateLimit()
{
    $probed = false;
    if (!empty($_GET['probe'])) {
        $data = rateLimitLoad();
        $last = $data['tdx']['observed_at'] ?? 0;
        if (time() - $last < RATELIMIT_PROBE_MIN_INTERVAL) {
            $probed = 'skipped: last observation is newer than ' . RATELIMIT_PROBE_MIN_INTERVAL . 's';
        } elseif (!isConfigured()) {
            sendJson(500, ['ok' => false, 'error' => 'Proxy is not configured with a TDX API key.']);
        } else {
            $token = getAccessToken();
            if ($token === null) {
                $t = $GLOBALS['TDX_TOKEN_FAILURE'] ?? null;
                sendJson(502, ['ok' => false, 'error' => 'Proxy failed to obtain TDX access token.',
                               'tdx' => $t && $t['error'] === null
                                   ? ['status' => $t['status'], 'body' => mb_substr((string) $t['body'], 0, 500)] : null]
                              + rateLimitReport());
            }
            $res = httpRequest('GET', TDX_HOST . RATELIMIT_PROBE_PATH, ['Authorization: Bearer ' . $token]);
            if ($res['error'] !== null) {
                sendJson(502, ['ok' => false, 'error' => 'Proxy failed to reach TDX.']);
            }
            rateLimitRecord($res['status'], $res['headers'], RATELIMIT_PROBE_PATH);
            $probed = true;
        }
    }
    $out = rateLimitReport();
    $out['probed'] = $probed;
    sendJson(200, $out);
}
