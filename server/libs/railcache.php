<?php
/**
 * TDX 靜態資料長期快取（軌道 Rail、公車 Bus）。
 *
 * proxyToTdx() 轉發前先呼叫 railCacheHandle()：路徑屬於可快取的 API 就由這裡處理
 * （命中直接回、過期就向 TDX 重抓或用 If-Modified-Since 確認），否則回傳 false 交回一般轉發。
 *
 * 分類（依路徑中的資源名稱判斷，例如 /api/basic/v3/Rail/TRA/Station → Station、/api/basic/v2/Bus/Route/City/Taipei → Route）：
 *   static    站點、路線、站牌、設施、線形、票價…  新鮮期過後向 TDX 確認，超過最長保存就整份重抓
 *   timetable 定期時刻表／班表
 *   daily     指定日期時刻表（僅軌道）            Today 快取到台灣時間午夜；過去日期長期保存；今天與未來短期
 *   即時資料（LiveBoard、到站預估、座位、Alert、News…）一律不快取
 * 各項時間都在 custom/libs/config.json 的 RAIL_CACHE_* / BUS_CACHE_* 常數設定（沒設定時用這裡的預設值）。
 *
 * 回應會帶 X-Proxy-Cache：HIT（快取）、MISS（剛抓）、REVALIDATED（確認未變）、STALE（TDX 失敗時用舊資料）。
 * 管理：/txapi/admin_cache（統計，?list=1 列出）、/txapi/admin_cache/purge?purge=all|<路徑前綴>（清除），需後臺權限（admin_cache）。
 * 停用：custom/libs/config.json 設 "DISABLE_CACHE": true，所有請求改為直接轉發（快取檔保留）。
 */

const RAILCACHE_DAY = 86400;

// 模式 → 資源名稱 → 分類。沒列在這裡的一律不快取（即時資料、公告等）
const CACHE_RULES = [
    'rail' => [
        // 長期靜態
        'Operator' => 'static', 'Network' => 'static', 'Line' => 'static', 'Route' => 'static',
        'Station' => 'static', 'StationOfLine' => 'static', 'StationOfRoute' => 'static',
        'StationExit' => 'static', 'StationFacility' => 'static', 'StationPlatform' => 'static',
        'LineTransfer' => 'static', 'StationTransfer' => 'static', 'Shape' => 'static',
        'TrainType' => 'static', 'ODFare' => 'static', 'S2STravelTime' => 'static',
        // 定期時刻表
        'GeneralTrainTimetable' => 'timetable', 'GeneralStationTimetable' => 'timetable',
        'GeneralTrainInfo' => 'timetable', 'GeneralTimetable' => 'timetable',
        'StationTimeTable' => 'timetable', 'FirstLastTimetable' => 'timetable',
        'Frequency' => 'timetable', 'StoppingPattern' => 'timetable',
        'SpecificTrainTimetable' => 'timetable',
        // 指定日期時刻表
        'DailyTrainTimetable' => 'daily', 'DailyStationTimetable' => 'daily',
        'DailyTimetable' => 'daily', 'DailyTrainInfo' => 'daily',
    ],
    'bus' => [
        // 路線、站牌等靜態資料
        'Route' => 'static', 'Stop' => 'static', 'Station' => 'static', 'StationGroup' => 'static',
        'StopOfRoute' => 'static', 'DisplayStopOfRoute' => 'static', 'Shape' => 'static',
        'Operator' => 'static', 'RouteFare' => 'static', 'Vehicle' => 'static',
        'S2STravelTime' => 'static', 'Network' => 'static',
        // 班表
        'Schedule' => 'timetable', 'FirstLastTripInfo' => 'timetable',
    ],
];

/** 讀設定（libs/config.json＋custom/libs/config.json）的時間設定，沒定義就用預設值 */
function cacheSetting($name, $default)
{
    return defined($name) ? (int) constant($name) : $default;
}

/** 各模式、各分類的新鮮期（過了就向 TDX 確認）與最長保存（過了就整份重抓），單位秒 */
function cachePolicy($mode, $cat)
{
    $p = $mode === 'bus' ? 'BUS_CACHE_' : 'RAIL_CACHE_';
    if ($cat === 'static') {
        return [
            'fresh' => cacheSetting($p . 'STATIC_FRESH', 7 * RAILCACHE_DAY),
            'max'   => cacheSetting($p . 'STATIC_MAX', 30 * RAILCACHE_DAY),
        ];
    }
    $t = cacheSetting($p . 'TIMETABLE', RAILCACHE_DAY);
    return ['fresh' => $t, 'max' => $t];
}

function cacheDir($mode)
{
    return TDX_CACHE_DIR . '/' . $mode;
}

/**
 * 判斷路徑是否可快取。回傳 ['mode', 'cat', 'fresh', 'max']，不可快取回傳 null。
 * 軌道：/api/basic/v{n}/Rail/{系統}/{資源}/…、/api/basic/v{n}/Rail/Operator
 * 公車：/api/basic/v{n}/Bus/{資源}/…
 */
function railCacheRule($path)
{
    if (preg_match('#^/api/basic/v\d+/Rail/(?:(?:TRA|THSR|Metro|AFR)/)?([A-Za-z0-9]+)(?:/|$)#i', $path, $m)) {
        $mode = 'rail';
    } elseif (preg_match('#^/api/basic/v\d+/Bus/([A-Za-z0-9]+)(?:/|$)#i', $path, $m)) {
        $mode = 'bus';
    } else {
        return null;
    }
    $cat = null;
    foreach (CACHE_RULES[$mode] as $name => $c) {
        if (strcasecmp($name, $m[1]) === 0) {
            $cat = $c;
            break;
        }
    }
    if ($cat === null) {
        return null;
    }
    if ($cat !== 'daily') {
        return ['mode' => $mode, 'cat' => $cat] + cachePolicy($mode, $cat);
    }

    // 指定日期：Today 到台灣時間午夜；過去日期不會再變；今天與未來可能加開停駛，只留短期
    $tz = new DateTimeZone('Asia/Taipei');
    $now = new DateTime('now', $tz);
    $midnight = (clone $now)->modify('tomorrow')->getTimestamp() - $now->getTimestamp();
    if (preg_match('#/Today(/|$)#i', $path)) {
        $ttl = max(60, $midnight);
    } elseif (preg_match('#/(\d{4}-\d{2}-\d{2})(/|$)#', $path, $d) && $d[1] < $now->format('Y-m-d')) {
        $ttl = cacheSetting('RAIL_CACHE_DAILY_PAST', 365 * RAILCACHE_DAY);
    } else {
        $ttl = min(cacheSetting('RAIL_CACHE_DAILY', 3600), max(60, $midnight));
    }
    return ['mode' => $mode, 'cat' => 'daily', 'fresh' => $ttl, 'max' => $ttl];
}

/** 設定的 DISABLE_CACHE 為 true 時停用快取（沒定義視同啟用） */
function railCacheDisabled()
{
    return defined('DISABLE_CACHE') && DISABLE_CACHE;
}

/**
 * 語意相同的請求算成同一個快取鍵：
 *   - 路徑各段先解碼再統一編碼（%E5%8F%B0 與「台」視為相同）
 *   - 參數名稱與值先解碼（`%24format` = `\$format`、`+` 與 `%20` 都是空白），名稱不分大小寫
 *   - `\$format` 的值不分大小寫（JSON = json）
 *   - 去掉空參數後依名稱、值排序，順序不同視為相同
 * 實際打 TDX 的網址仍是前端原本的寫法，這裡只影響快取比對。
 */
function railCacheCanonical($pathAndQuery)
{
    $pos = strpos($pathAndQuery, '?');
    $path = $pos === false ? $pathAndQuery : substr($pathAndQuery, 0, $pos);
    $query = $pos === false ? '' : substr($pathAndQuery, $pos + 1);

    $path = implode('/', array_map(function ($seg) {
        return rawurlencode(rawurldecode($seg));
    }, explode('/', $path)));

    $pairs = [];
    foreach (explode('&', $query) as $part) {
        if ($part === '') {
            continue;
        }
        $eq = strpos($part, '=');
        $name = urldecode($eq === false ? $part : substr($part, 0, $eq));
        $value = $eq === false ? '' : urldecode(substr($part, $eq + 1));
        $name = strtolower(trim($name));
        if ($name === '') {
            continue;
        }
        $value = trim($value);
        if ($name === '$format') {
            $value = strtolower($value);
        }
        $pairs[] = [$name, $value];
    }
    usort($pairs, function ($a, $b) {
        return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
    });

    $q = implode('&', array_map(function ($p) {
        return rawurlencode($p[0]) . '=' . rawurlencode($p[1]);
    }, $pairs));
    return $q === '' ? $path : $path . '?' . $q;
}

function railCacheKey($pathAndQuery)
{
    return sha1(railCacheCanonical($pathAndQuery));
}

function railCacheFiles($key, $mode)
{
    $dir = cacheDir($mode) . '/' . substr($key, 0, 2);
    return [$dir, $dir . '/' . $key . '.meta.php', $dir . '/' . $key . '.body'];
}

function railCacheLoad($key, $mode)
{
    list(, $metaFile, $bodyFile) = railCacheFiles($key, $mode);
    $raw = @file_get_contents($metaFile);
    if ($raw === false || !is_file($bodyFile)) {
        return null;
    }
    $meta = json_decode(substr($raw, strpos($raw, '?>') + 2), true);
    if (!is_array($meta)) {
        return null;
    }
    $meta['mode'] = $meta['mode'] ?? $mode;   // 舊版快取沒有記 mode
    return $meta;
}

function railCacheWriteFile($file, $content)
{
    $tmp = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function railCacheSaveMeta($key, array $meta)
{
    list(, $metaFile) = railCacheFiles($key, $meta['mode']);
    return railCacheWriteFile($metaFile, '<?php exit; ?>' . json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** 存一份新的回應。body 一律以 gzip 存放，省空間，也方便直接回給支援 gzip 的前端 */
function railCacheStore($key, $pathAndQuery, array $rule, array $res)
{
    list($dir, , $bodyFile) = railCacheFiles($key, $rule['mode']);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return null;
    }
    if (!is_file(TDX_CACHE_DIR . '/.htaccess')) {
        @file_put_contents(TDX_CACHE_DIR . '/.htaccess', "Require all denied\n");
    }

    $hdr = function ($name) use ($res) {
        $line = $res['headers'][$name] ?? null;
        return $line === null ? null : trim(substr($line, strpos($line, ':') + 1));
    };
    $body = $res['body'];
    $enc = strtolower((string) $hdr('content-encoding'));
    if ($enc === 'gzip') {
        $gz = $body;
    } elseif ($enc === '' || $enc === 'identity') {
        $gz = gzencode($body, 6);
    } else {
        return null; // 其他壓縮格式（br…）不存
    }
    if (!railCacheWriteFile($bodyFile, $gz)) {
        return null;
    }

    $now = time();
    $meta = [
        'path'          => $pathAndQuery,
        'mode'          => $rule['mode'],
        'cat'           => $rule['cat'],
        'fetched_at'    => $now,
        'checked_at'    => $now,
        'fresh_until'   => $now + $rule['fresh'],   // 過了就向 TDX 確認
        'max_until'     => $now + $rule['max'],     // 過了就整份重抓
        'content_type'  => $hdr('content-type') ?? 'application/json; charset=utf-8',
        'last_modified' => $hdr('last-modified'),
        'size'          => strlen($gz),
        'sha1'          => sha1($gz),
    ];
    railCacheSaveMeta($key, $meta);
    return $meta;
}

/**
 * TDX 的回應是否為檔案附件而非一般 JSON：例如不帶起訖站的 /v3/Rail/TRA/ODFare 會回
 * .json.gz 檔（Content-Type: application/octet-stream、Content-Disposition: attachment），
 * body 本身就是 gzip。這類大型整包資料不快取，直接轉發。
 */
function railCacheIsFile(array $res)
{
    $enc = strtolower(trim(substr($res['headers']['content-encoding'] ?? '', strlen('content-encoding:'))));
    return isset($res['headers']['content-disposition'])
        || (($enc === '' || $enc === 'identity') && strncmp($res['body'], "\x1f\x8b", 2) === 0);
}

/** 從快取回應前端 */
function railCacheServe($key, array $meta, $state, $method, array $extraHeaders = [])
{
    list(, , $bodyFile) = railCacheFiles($key, $meta['mode']);
    $gz = @file_get_contents($bodyFile);
    if ($gz === false) {
        return false;
    }
    $acceptGzip = stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false;
    $body = $acceptGzip ? $gz : gzdecode($gz);
    if ($body === false) {
        return false;
    }

    $age = max(0, time() - $meta['checked_at']);
    http_response_code(200);
    header('Content-Type: ' . $meta['content_type']);
    if ($acceptGzip) {
        header('Content-Encoding: gzip');
    }
    header('Vary: Accept-Encoding');
    if (!empty($meta['last_modified'])) {
        header('Last-Modified: ' . $meta['last_modified']);
    }
    header('X-Proxy-Cache: ' . $state);
    header('X-Proxy-Cache-Category: ' . $meta['cat']);
    header('Age: ' . $age);
    // 瀏覽器端最多再留 10 分鐘；用 private 避免主機 CDN 共用快取而略過 PHP 的來源檢查
    header('Cache-Control: private, max-age=' . max(0, min(600, $meta['fresh_until'] - time())));
    $expose = ['content-type', 'last-modified', 'age', 'x-proxy-cache', 'x-proxy-cache-category'];
    if (function_exists('tdxCredentialUsed') && tdxCredentialUsed() !== null) {
        $expose[] = 'x-tdx-credential';
    }
    foreach ($extraHeaders as $line) {
        header($line);
        $expose[] = strtolower(trim(strtok($line, ':')));
    }
    header('Access-Control-Expose-Headers: ' . implode(', ', $expose));
    header('Content-Length: ' . strlen($body));
    if ($method !== 'HEAD') {
        echo $body;
    }
    return true;
}

function railCacheCount($mode, $field)
{
    $file = cacheDir($mode) . '/stats.json';
    if (!is_dir(cacheDir($mode))) {
        @mkdir(cacheDir($mode), 0755, true);
    }
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    $stats = json_decode(stream_get_contents($fp), true) ?: [];
    $stats[$field] = ($stats[$field] ?? 0) + 1;
    $stats['since'] = $stats['since'] ?? time();
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($stats));
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * 處理一個請求。回傳 true 表示已回應完畢；false 表示這個路徑不快取，交回一般轉發。
 */
function railCacheHandle($method, $pathAndQuery)
{
    if (railCacheDisabled()) {
        return false;
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        return false;
    }
    $path = strtok($pathAndQuery, '?');
    $rule = railCacheRule($path);
    if ($rule === null) {
        return false;
    }

    $key = railCacheKey($pathAndQuery);
    $mode = $rule['mode'];
    $meta = railCacheLoad($key, $mode);
    $now = time();

    // 1. 新鮮：直接回
    if ($meta && $now < $meta['fresh_until'] && $now < $meta['max_until']) {
        if (railCacheServe($key, $meta, 'HIT', $method)) {
            railCacheCount($mode, 'hit');
            return true;
        }
    }

    // 2. 向 TDX 要資料：還在最長保存期內且有 Last-Modified，就用條件請求確認
    $headers = ['Accept-Encoding: gzip'];
    foreach (['accept', 'accept-language'] as $h) {
        $v = $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $h))] ?? '';
        if ($v !== '') {
            $headers[] = $h . ': ' . $v;
        }
    }
    $canRevalidate = $meta && !empty($meta['last_modified']) && $now < $meta['max_until'];
    if ($canRevalidate) {
        $headers[] = 'If-Modified-Since: ' . $meta['last_modified'];
    }
    $res = tdxFetch('GET', $pathAndQuery, $headers);

    if ($res !== null && $res['error'] === null) {
        if ($res['status'] === 304 && $canRevalidate) {
            $meta['checked_at'] = $now;
            $meta['fresh_until'] = min($meta['max_until'], $now + $rule['fresh']);
            railCacheSaveMeta($key, $meta);
            if (railCacheServe($key, $meta, 'REVALIDATED', $method)) {
                railCacheCount($mode, 'revalidated');
                return true;
            }
        }
        if ($res['status'] === 200 && railCacheIsFile($res)) {
            // TDX 回的是 .json.gz 這類檔案附件（大型整包資料）：不快取，直接轉發，與 TDX 完全相同
            railCacheCount($mode, 'bypass');
            sendUpstreamResponse($res, ['X-Proxy-Cache: BYPASS']);
            return true;
        }
        if ($res['status'] === 200) {
            $newMeta = railCacheStore($key, $pathAndQuery, $rule, $res);
            railCacheCount($mode, 'miss');
            if ($newMeta && railCacheServe($key, $newMeta, 'MISS', $method)) {
                return true;
            }
            // 存不了就直接轉發原始回應
            sendUpstreamResponse($res, ['X-Proxy-Cache: BYPASS']);
            return true;
        }
    }

    // 3. TDX 失敗、被限流或回錯誤：有舊資料就先用舊的，並以 X-Proxy-Upstream-Status 告知 TDX 這次的狀態
    //    （TDX 回的 HTTP 狀態碼；「token 狀態碼」表示換 token 被拒；network 表示連不上 TDX）
    if ($meta) {
        $t = $GLOBALS['TDX_TOKEN_FAILURE'] ?? null;
        $up = $res === null ? 'token' . ($t && $t['error'] === null ? ' ' . $t['status'] : '')
            : ($res['error'] !== null ? 'network' : (string) $res['status']);
        if (railCacheServe($key, $meta, 'STALE', $method, ['X-Proxy-Upstream-Status: ' . $up])) {
            railCacheCount($mode, 'stale');
            return true;
        }
    }
    if ($res === null || $res['error'] !== null) {
        tdxSendFetchFailure($res);
    }
    railCacheCount($mode, 'error');
    sendUpstreamResponse($res, ['X-Proxy-Cache: MISS']);
    return true;
}

// ================================================================
// 本站 API 用：取 TDX 資料回傳給程式（不直接回應前端）

/**
 * GET 一個 TDX 路徑（/api/... 含查詢字串），回傳解碼後的 JSON，給 /txapi/ 需要線上資料時用（例如票價、指定日期的最新時刻表）：
 *   - 可快取的路徑（railCacheRule）與 /api/ 轉發共用同一份快取；$forceCheck＝true 時不管新鮮期一律向 TDX 確認（有 Last-Modified 用條件請求）
 *   - 憑證：前端帶 X-TDX-Authorization 就用它（被拒時不改用本站金鑰），否則用本站金鑰；/txapi/ 的 Authorization 不會送去 TDX
 *   - TDX 失敗但有舊快取時回舊資料（source＝stale，upstream＝TDX 這次的狀態）
 * 回傳 ['ok', 'status', 'data', 'source' => cache|revalidated|tdx|stale|error, 'upstream', 'credential' => user|server|null]
 */
function railCacheFetchData($pathAndQuery, $forceCheck = false)
{
    $rule = railCacheDisabled() ? null : railCacheRule(strtok($pathAndQuery, '?'));
    $key = $rule ? railCacheKey($pathAndQuery) : null;
    $meta = $rule ? railCacheLoad($key, $rule['mode']) : null;
    $now = time();
    $read = function (array $meta) use ($key) {
        list(, , $bodyFile) = railCacheFiles($key, $meta['mode']);
        $gz = @file_get_contents($bodyFile);
        $body = $gz === false ? false : @gzdecode($gz);
        return $body === false ? null : json_decode($body, true);
    };
    $out = function ($ok, $status, $data, $source, $upstream = null) {
        return ['ok' => $ok, 'status' => $status, 'data' => $data, 'source' => $source, 'upstream' => $upstream,
                'credential' => function_exists('tdxCredentialUsed') ? tdxCredentialUsed() : null];
    };
    if ($meta && !$forceCheck && $now < $meta['fresh_until'] && $now < $meta['max_until'] && ($d = $read($meta)) !== null) {
        railCacheCount($rule['mode'], 'hit');
        return $out(true, 200, $d, 'cache');
    }
    $headers = ['Accept: application/json', 'Accept-Encoding: gzip'];
    $canRevalidate = $meta && !empty($meta['last_modified']) && $now < $meta['max_until'];
    if ($canRevalidate) {
        $headers[] = 'If-Modified-Since: ' . $meta['last_modified'];
    }
    $res = tdxFetch('GET', $pathAndQuery, $headers);
    if ($res !== null && $res['error'] === null) {
        if ($res['status'] === 304 && $canRevalidate && ($d = $read($meta)) !== null) {
            $meta['checked_at'] = $now;
            $meta['fresh_until'] = min($meta['max_until'], $now + $rule['fresh']);
            railCacheSaveMeta($key, $meta);
            railCacheCount($rule['mode'], 'revalidated');
            return $out(true, 200, $d, 'revalidated');
        }
        if ($res['status'] === 200) {
            $enc = strtolower(trim(substr($res['headers']['content-encoding'] ?? '', strlen('content-encoding:'))));
            $body = $enc === 'gzip' ? @gzdecode($res['body']) : $res['body'];
            $d = $body === false ? null : json_decode($body, true);
            if ($d !== null && $rule && !railCacheIsFile($res) && railCacheStore($key, $pathAndQuery, $rule, $res)) {
                railCacheCount($rule['mode'], 'miss');
            }
            return $out($d !== null, 200, $d, 'tdx', $d === null ? 'invalid-json' : null);
        }
    }
    $up = $res === null ? 'token' : ($res['error'] !== null ? 'network' : (string) $res['status']);
    if ($meta && ($d = $read($meta)) !== null) {
        railCacheCount($rule['mode'], 'stale');
        return $out(true, 200, $d, 'stale', $up);
    }
    $status = $res === null ? 502 : ($res['error'] !== null ? 502 : $res['status']);
    return $out(false, $status, $res && $res['error'] === null ? json_decode((string) $res['body'], true) : null, 'error', $up);
}

// ================================================================
// 管理：/txapi/admin_cache

function railCacheEntries()
{
    $out = [];
    foreach (array_keys(CACHE_RULES) as $mode) {
        foreach (glob(cacheDir($mode) . '/*/*.meta.php') ?: [] as $file) {
            $key = basename($file, '.meta.php');
            $meta = railCacheLoad($key, $mode);
            if ($meta) {
                $out[$mode . ':' . $key] = $meta + ['key' => $key];
            }
        }
    }
    return $out;
}

function railCacheDelete($key, $mode)
{
    list(, $metaFile, $bodyFile) = railCacheFiles($key, $mode);
    @unlink($metaFile);
    @unlink($bodyFile);
}

/**
 * 快取摘要：各模式的筆數、容量、命中統計、目前生效的快取時間；$withList 時附上每一筆的清單。
 * 只含路徑、大小、時間等基本資訊，不含金鑰或密碼。/txapi/admin_cache 與 /txapi/monitor 共用。
 */
function railCacheSummary($withList)
{
    $entries = railCacheEntries();
    $now = time();
    $tz = new DateTimeZone('Asia/Taipei');
    $fmt = function ($ts) use ($tz) {
        return (new DateTime('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i:s');
    };

    $modes = [];
    foreach (array_keys(CACHE_RULES) as $mode) {
        $stats = json_decode((string) @file_get_contents(cacheDir($mode) . '/stats.json'), true) ?: [];
        $hits = ($stats['hit'] ?? 0) + ($stats['revalidated'] ?? 0) + ($stats['stale'] ?? 0);
        $total = $hits + ($stats['miss'] ?? 0) + ($stats['error'] ?? 0);
        $modes[$mode] = [
            'entries'     => 0,
            'bytes'       => 0,
            'by_category' => [],
            'stats'       => [
                'since'       => isset($stats['since']) ? $fmt($stats['since']) : null,
                'hit'         => $stats['hit'] ?? 0,
                'revalidated' => $stats['revalidated'] ?? 0,
                'stale'       => $stats['stale'] ?? 0,
                'miss'        => $stats['miss'] ?? 0,
                'error'       => $stats['error'] ?? 0,
                'bypass'      => $stats['bypass'] ?? 0,   // TDX 回檔案附件，不快取直接轉發
                'hit_rate'    => $total ? round($hits / $total, 3) : null,
            ],
            'policy'      => [
                'static'    => cachePolicy($mode, 'static'),
                'timetable' => cachePolicy($mode, 'timetable'),
            ],
        ];
    }
    $modes['rail']['policy']['daily'] = [
        'today'  => '到台灣時間午夜',
        'future' => cacheSetting('RAIL_CACHE_DAILY', 3600),
        'past'   => cacheSetting('RAIL_CACHE_DAILY_PAST', 365 * RAILCACHE_DAY),
    ];
    $bytes = 0;
    foreach ($entries as $meta) {
        $m = &$modes[$meta['mode']];
        $m['entries']++;
        $m['bytes'] += $meta['size'];
        $m['by_category'][$meta['cat']] = ($m['by_category'][$meta['cat']] ?? 0) + 1;
        unset($m);
        $bytes += $meta['size'];
    }

    $out = [
        'enabled' => !railCacheDisabled(),
        'entries' => count($entries),
        'bytes'   => $bytes,
        'modes'   => $modes,
    ];
    if ($withList) {
        $list = [];
        foreach ($entries as $meta) {
            $list[] = [
                'path'       => $meta['path'],
                'mode'       => $meta['mode'],
                'cat'        => $meta['cat'],
                'size'       => $meta['size'],
                'fetched_at' => $fmt($meta['fetched_at']),
                'checked_at' => $fmt($meta['checked_at']),
                'fresh_for'  => max(0, $meta['fresh_until'] - $now),
            ];
        }
        usort($list, function ($a, $b) { return strcmp($a['path'], $b['path']); });
        $out['list'] = $list;
    }
    return $out;
}
