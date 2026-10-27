<?php
/**
 * 靜態資料下載：/txapi/download
 *
 * 把 TDX 的完整靜態資料（不帶任何篩選參數）存到網站根目錄的 download/<service>/，
 * 目錄結構對應 API 路徑，例如 /v2/Rail/Metro/Station/TRTC → download/tdx/v2/Rail/Metro/Station/TRTC.json。
 * download/ 只由本 API 建立與覆蓋，不需要備份（隨時可重新下載）。
 * 可下載的路徑只限 downloadCatalog() 產生的清單（同時防止任意路徑存取）。
 *
 *   /txapi/download                                   說明文件（Markdown），同 func=help
 *   /txapi/download/list?service=tdx             可下載清單、檔案狀態、時刻表週次（JSON）
 *   /txapi/download/download?service=tdx&path=…  下載檔案（沒有存檔時先向 TDX 取得並存檔）
 * 管理動作在 /txapi/admin_download（後臺身分，見 libs/txapi/admin/）：
 *   /txapi/admin_download/sync?service=tdx             分批向 TDX 取得並存檔（寫入權限，重複呼叫直到 done）
 *   /txapi/admin_download/prune?service=tdx            列出（讀取）／刪除（confirm=1，寫入）不在清單內的舊存檔
 *   /txapi/admin_download/refresh?service=tdx&path=…   重新向 TDX 取得並回傳檔案（寫入）
 *
 * 清單範圍：軌道轉乘查詢（接軌時刻 TimeLink）所需的最小基礎資料：臺鐵、高鐵、臺北捷運、新北捷運（環狀線、三鶯線、
 * 淡海輕軌、安坑輕軌）、桃園機場捷運、臺中捷運、高雄捷運、高雄輕軌的車站、路線、站序與里程、轉乘、定期時刻。
 * 不含阿里山林鐵、貓空纜車、業者資料。能由其他項目推導的 API 不下載，推導方式寫在 func=help「省略的 API 與推導方式」。
 */

define('DOWNLOAD_DIR', appEnvPath('download'));   // 資料根目錄的 download/（預設＝網站根目錄，見 libs/env_path.php）

// 靜態資料超過幾秒視為過期，func=sync 會重新取得
// DOWNLOAD_MAX_AGE：設定項目（libs/config.json）
// 同步時保留給一般 proxy 使用者的 TDX 每分鐘額度（剩餘次數低於等於此值就暫停同步）
// DOWNLOAD_RATE_RESERVE：設定項目（libs/config.json）
// 下載功能自己向 TDX 發出的請求，每 60 秒最多幾次（目前金鑰每分鐘上限 5 次，保留餘裕給一般 proxy 使用者）
// DOWNLOAD_MAX_PER_MINUTE：設定項目（libs/config.json）
// 上述請求的時間紀錄（TDX_CACHE_DIR：download/tdx_proxy_files/cache）
const DOWNLOAD_CALL_LOG = TDX_CACHE_DIR . '/download_calls.json';
// 單一檔案下載逾時（秒）；最大的檔案約 5.4 MB（臺北捷運站別時刻表）
// DOWNLOAD_FETCH_TIMEOUT：設定項目（libs/config.json）

// 營運業者 → 捷運／輕軌系統代碼（/v2/Rail/Metro/.../{RailSystem}）
const DOWNLOAD_METRO_SYSTEMS = [
    'TRTC' => ['TRTC'],                       // 臺北捷運（不含貓空纜車）
    'NTMC' => ['NTMC', 'NTDLRT', 'NTALRT'],   // 新北捷運（環狀線）、淡海輕軌、安坑輕軌
    'KRTC' => ['KRTC', 'KLRT'],               // 高雄捷運、高雄輕軌
    'TYMC' => ['TYMC'],                       // 桃園捷運
    'TMRT' => ['TMRT'],                       // 臺中捷運
];

// 捷運 API → [分類, 標題, 說明, 要下載的系統]
// 清單已最小化：能由其他項目推導的不下載（推導方式見 func=help）。
// 有站別時刻表（StationTimeTable）的系統，首末班車、班距由時刻表推導；
// 臺中捷運與臺北捷運的文湖線（BR）沒有站別時刻表，所以這兩個系統要下載這些 API。
// 站間行駛時間（S2STravelTime）TDX 有提供的系統全部下載，存成路線站距表或原始起訖矩陣，供 finder 的 metro_travel_time 查詢。
const DOWNLOAD_METRO_APIS = [
    'Line'               => ['line',      '路線',             '實體路線代碼、四語名稱、路線顏色、是否為支線',
                             ['TRTC', 'KRTC', 'TYMC', 'KLRT', 'NTDLRT', 'TMRT', 'NTMC', 'NTALRT']],
    'Station'            => ['station',   '車站',             '車站代碼、四語名稱、座標、地址、縣市鄉鎮',
                             ['TRTC', 'KRTC', 'TYMC', 'KLRT', 'NTDLRT', 'TMRT', 'NTMC', 'NTALRT']],
    'StationOfRoute'     => ['route',     '營運路線車站',     '各營運路線（區間）每個方向依序停靠的車站、路線名稱與累計里程',
                             ['TRTC', 'KRTC', 'TYMC', 'NTDLRT', 'TMRT', 'NTMC', 'NTALRT', 'KLRT']],
    'LineTransfer'       => ['transfer',  '路線轉乘',         '站內跨路線轉乘與轉乘時間',
                             ['TRTC', 'KRTC', 'NTMC']],
    'StationTransfer'    => ['transfer',  '跨運具轉乘',       '車站到其他軌道業者車站（臺鐵、高鐵、其他捷運）的最小轉乘時間與說明；TDX 只提供這 3 個系統',
                             ['TRTC', 'NTMC', 'TYMC']],
    'StationTimeTable'   => ['timetable', '站別時刻表',       '各站、各營運路線、各終點的發車時刻，ServiceDay 標示適用的星期',
                             ['TRTC', 'KRTC', 'TYMC', 'KLRT', 'NTDLRT', 'NTALRT', 'NTMC']],
    'StoppingPattern'    => ['timetable', '停站模式',         '普通車、直達車等停站模式的名稱與停靠站',
                             ['TYMC']],
    'S2STravelTime'      => ['traveltime', '站間行駛時間',    '各營運路線相鄰兩站間的行駛秒數與停站秒數；TDX 只提供這 6 個系統（淡海、安坑輕軌沒有）',
                             ['TRTC', 'KRTC', 'TYMC', 'KLRT', 'TMRT', 'NTMC']],
    'FirstLastTimetable' => ['timetable', '首末班車時刻',     '各站首末班車時刻；只下載有路線沒有站別時刻表的系統（臺北捷運為文湖線）',
                             ['TMRT', 'TRTC']],
    'Frequency'          => ['timetable', '發車班距',         '各時段發車間隔；只下載有路線沒有站別時刻表的系統（臺北捷運為文湖線）',
                             ['TMRT', 'TRTC']],
];

// 里程一律以路線里程（StationOfLine／StationOfRoute 的累計里程）經由指定轉乘站分段累加（見 func=help「里程計算方式」）。
// 高鐵的站序與里程改由站間里程累加（高鐵 StationOfLine 不下載：其嘉義累計里程有誤，218.88 應為 254.88）
const DOWNLOAD_DISTANCE_SYSTEMS = [
    'S2SDistance' => ['THSR' => 'THSR'],
];

// 分組（業者）顯示名稱
const DOWNLOAD_GROUPS = [
    'TRTC'   => '臺北捷運',
    'NTMC'   => '新北捷運（環狀線、三鶯線、淡海輕軌、安坑輕軌）',
    'KRTC'   => '高雄捷運（含高雄輕軌）',
    'TYMC'   => '桃園捷運',
    'TMRT'   => '臺中捷運',
    'TRA'    => '臺鐵',
    'THSR'   => '台灣高鐵',
];

const DOWNLOAD_CATEGORIES = [
    'network'    => '路網',
    'line'       => '路線',
    'station'    => '車站',
    'route'      => '營運路線',
    'transfer'   => '轉乘',
    'timetable'  => '時刻表',
    'traveltime' => '站間行駛時間',
    'distance'   => '站間里程',
];

// ================================================================
// 清單

/** 產生某個 service 的完整清單：path => [level, group, category, title, desc]；順序即 func=sync 的處理順序 */
function downloadCatalog($service)
{
    static $cache = [];
    if (isset($cache[$service])) {
        return $cache[$service];
    }
    $c = [];
    if ($service !== 'tdx') {
        return $cache[$service] = $c;
    }
    $add = function ($path, $group, $cat, $title, $desc, $extra = []) use (&$c) {
        $c[$path] = ['level' => 'basic', 'group' => $group, 'category' => $cat, 'title' => $title, 'desc' => $desc] + $extra;
    };

    // --- 捷運／輕軌（依業者分組）
    foreach (DOWNLOAD_METRO_SYSTEMS as $op => $systems) {
        foreach (DOWNLOAD_METRO_APIS as $api => $m) {
            foreach ($systems as $sys) {
                if (in_array($sys, $m[3], true)) {
                    $add("/v2/Rail/Metro/{$api}/{$sys}", $op, $m[0], "{$m[1]}（{$sys}）", $m[2]);
                }
            }
        }
    }

    // --- 捷運共站站點（系統代碼不是單一系統：臺北捷運與新北捷運合併為 TRTC_NTMC）
    foreach (['TRTC_NTMC' => 'TRTC', 'KRTC' => 'KRTC'] as $sys => $op) {
        $add("/v2/Rail/Metro/TransferStations/{$sys}", $op, 'transfer', "共站站點（{$sys}）",
            '同一實體車站在各路線的車站代碼（例如板橋 BL07／Y16）；TDX 只提供這 2 組');
    }

    // --- 臺鐵（v2、v3 內容相同時只留一份）
    foreach ([
        ['/v3/Rail/TRA/Line', 'line', '路線', '路線代碼、名稱、區段、是否為支線、路線群組'],
        ['/v2/Rail/TRA/Station', 'station', '車站', '車站代碼、名稱、座標、地址、電話、車站等級、縣市鄉鎮（v3 沒有縣市鄉鎮，故用 v2）'],
        ['/v3/Rail/TRA/StationOfLine', 'station', '路線車站', '各路線依序的車站與累計里程'],
        ['/v3/Rail/TRA/GeneralTrainTimetable', 'timetable', '定期時刻表', '所有定期車次的停靠站時刻與車種，ServiceDay 標示行駛的星期'],
        ['/v3/Rail/TRA/LineTransfer', 'transfer', '路線轉乘', '路線交會站（例如竹南 WL↔WL-C、北新竹 WL↔NW）的轉乘時間與月臺說明'],
        ['/v3/Rail/TRA/StationTransfer', 'transfer', '跨運具轉乘', '車站到捷運、高鐵等其他軌道車站的最小轉乘時間（另含公車、自行車等，不使用）'],
    ] as $r) {
        $add($r[0], 'TRA', $r[1], $r[2], $r[3]);
    }

    // --- 高鐵
    foreach ([
        ['/v2/Rail/THSR/Station', 'station', '車站', '車站代碼、名稱、座標、地址'],
        ['/v2/Rail/THSR/GeneralTimetable', 'timetable', '定期時刻表', '所有定期車次的停靠站時刻，ServiceDay 標示行駛的星期'],
    ] as $r) {
        $add($r[0], 'THSR', $r[1], $r[2], $r[3]);
    }

    // --- 站間里程（相鄰站）與起迄站距離
    foreach (DOWNLOAD_DISTANCE_SYSTEMS['S2SDistance'] as $sys => $op) {
        $add("/v2/Rail/S2SDistance/{$sys}", $op, 'distance', "相鄰站間里程（{$sys}）",
            '相鄰兩站之間的距離（公里）；高鐵的站序與累計里程由此依序累加');
    }

    return $cache[$service] = $c;
}

/** 名單內的項目；不分大小寫比對，回傳 [正規 path, 設定] 或 null */
function downloadFind($service, $path)
{
    $path = '/' . ltrim(trim((string) $path), '/');
    foreach (downloadCatalog($service) as $p => $cfg) {
        if (strcasecmp($p, $path) === 0) {
            return [$p, $cfg];
        }
    }
    return null;
}

// ================================================================
// 檔案

/** 存檔位置：download/<service>/<path>.json（path 只會來自清單，不含 . 或 ..） */
function downloadFile($service, $path)
{
    return DOWNLOAD_DIR . '/' . $service . $path . '.json';
}

function downloadRelPath($service, $path)
{
    return 'download/' . $service . $path . '.json';
}

function downloadFmtTime($ts)
{
    return (new DateTime('@' . $ts))->setTimezone(new DateTimeZone('Asia/Taipei'))->format('Y-m-d H:i:s');
}

/** 需要（重新）取得嗎：沒有存檔，或存檔超過 DOWNLOAD_MAX_AGE */
function downloadNeedsFetch($service, $path, $cfg)
{
    $file = downloadFile($service, $path);
    if (!is_file($file)) {
        return true;
    }
    return time() - filemtime($file) > DOWNLOAD_MAX_AGE;
}

/**
 * 時刻表存檔的適用期間：只讀檔頭 4 KB 找 EffectiveDate／ExpireDate（高鐵為 ExpiringDate），不解析整份大檔。
 * 臺鐵的定期時刻表是「目前這一版」，連假前後會出現只適用幾天的臨時版本，看這裡可判斷抓到的是不是平常版本。
 */
function downloadValidity($file)
{
    $head = (string) @file_get_contents($file, false, null, 0, 4096);
    $out = [];
    foreach (['effective' => 'EffectiveDate', 'expire' => 'Expire(?:ing)?Date'] as $k => $re) {
        if (preg_match('/"' . $re . '"\s*:\s*"([^"]*)"/', $head, $m) && $m[1] !== '') {
            $out[$k] = substr($m[1], 0, 10);
        }
    }
    return $out ?: null;
}

function downloadInfo($service, $path, $cfg)
{
    $file = downloadFile($service, $path);
    $exists = is_file($file);
    $info = [
        'path'       => $path,
        'group'      => $cfg['group'],
        'category'   => $cfg['category'],
        'title'      => $cfg['title'],
        'desc'       => $cfg['desc'],
        'tdx_url'    => TDX_HOST . '/api/' . $cfg['level'] . $path . '?$format=JSON',
        'file'       => downloadRelPath($service, $path),
        'downloaded' => $exists,
        'size'       => $exists ? filesize($file) : null,
        'saved_at'   => $exists ? downloadFmtTime(filemtime($file)) : null,
        'outdated'   => $exists && downloadNeedsFetch($service, $path, $cfg),
        'download'   => '/txapi/download/download?service=' . $service . '&path=' . $path,
    ];
    if ($exists && $cfg['category'] === 'timetable') {
        $info['validity'] = downloadValidity($file);
    }
    return $info;
}

/**
 * 下載功能的每分鐘呼叫預算（DOWNLOAD_MAX_PER_MINUTE）。
 * $take=true 時若還有額度就記一筆並回傳 true；回傳 ['left'=>剩餘次數, 'next_in'=>幾秒後會多出一次額度]
 */
function downloadCallBudget($take = false)
{
    $dir = dirname(DOWNLOAD_CALL_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $fp = @fopen(DOWNLOAD_CALL_LOG, 'c+');
    if (!$fp) {
        return ['left' => DOWNLOAD_MAX_PER_MINUTE, 'next_in' => 0, 'taken' => $take];
    }
    flock($fp, LOCK_EX);
    $now = time();
    $log = json_decode((string) stream_get_contents($fp), true);
    $log = array_values(array_filter(is_array($log) ? $log : [], function ($t) use ($now) { return $now - (int) $t < 60; }));
    $taken = false;
    if ($take && count($log) < DOWNLOAD_MAX_PER_MINUTE) {
        $log[] = $now;
        $taken = true;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($log));
    flock($fp, LOCK_UN);
    fclose($fp);
    $left = DOWNLOAD_MAX_PER_MINUTE - count($log);
    return ['left' => $left, 'next_in' => $left > 0 ? 0 : max(1, 60 - ($now - min($log))), 'taken' => $taken];
}

/** TDX 目前每分鐘剩餘額度（依最近一次觀察；觀察已過期則視為額度已重置，回傳 null） */
function downloadRateRemaining()
{
    if (!function_exists('rateLimitLoad')) {
        return null;
    }
    $t = rateLimitLoad()['tdx'] ?? null;
    if (!$t || !isset($t['windows']['minute']['remaining'])) {
        return null;
    }
    $reset = $t['ratelimit']['reset'] ?? 60;
    $age = time() - $t['observed_at'];
    if ($age >= $reset) {
        return null;
    }
    return ['remaining' => (int) $t['windows']['minute']['remaining'], 'reset_in' => max(1, $reset - $age)];
}

/**
 * 向 TDX 取得完整資料，直接串流寫進暫存檔（不佔記憶體），驗證後置換成正式檔。
 * 一律使用本服務的金鑰。成功回傳 ['ok'=>true,'size'=>…]，失敗回傳 ['ok'=>false,'status'=>…,'error'=>…]。
 */
function downloadFetch($service, $path, $cfg)
{
    if ($service !== 'tdx') {
        return ['ok' => false, 'status' => 400, 'error' => 'Unsupported service'];
    }
    $file = downloadFile($service, $path);
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return ['ok' => false, 'status' => 500, 'error' => 'Cannot create directory for ' . downloadRelPath($service, $path)];
    }
    if (!is_file(DOWNLOAD_DIR . '/.htaccess')) {
        @file_put_contents(DOWNLOAD_DIR . '/.htaccess', "Require all denied\n");
    }

    $pathAndQuery = '/api/' . $cfg['level'] . $path . '?$format=JSON';
    $tmp = $file . '.' . getmypid() . '.tmp';
    $token = getAccessToken();
    if ($token === null) {
        // 換 token 被 TDX 拒絕時帶回 TDX 的狀態碼與內容（例如額度用完）
        $t = $GLOBALS['TDX_TOKEN_FAILURE'] ?? null;
        return $t && $t['error'] === null
            ? ['ok' => false, 'status' => $t['status'], 'error' => 'TDX rejected the access token request', 'body' => mb_substr((string) $t['body'], 0, 1000)]
            : ['ok' => false, 'status' => 502, 'error' => 'Proxy failed to obtain TDX access token.'];
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $fh = @fopen($tmp, 'wb');
        if (!$fh) {
            return ['ok' => false, 'status' => 500, 'error' => 'Cannot write temp file'];
        }
        $headers = [];
        $ch = curl_init(TDX_HOST . $pathAndQuery);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fh,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_ENCODING       => '',          // 接受 gzip，curl 自動解壓
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => DOWNLOAD_FETCH_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
                if (strpos($line, 'HTTP/') === 0) {
                    $headers = [];
                } elseif (($p = strpos($line, ':')) !== false) {
                    $name = trim(substr($line, 0, $p));
                    $headers[strtolower($name)] = $name . ': ' . trim(substr($line, $p + 1));
                }
                return strlen($line);
            },
        ]);
        $ok = curl_exec($ch);
        $err = $ok === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($fh);

        if ($err === null && function_exists('rateLimitRecord')) {
            rateLimitRecord($status, $headers, $pathAndQuery);
        }
        if ($status === 401 && $attempt === 0) {
            $token = getAccessToken($token);
            if ($token === null) {
                break;
            }
            continue;
        }
        break;
    }

    $size = is_file($tmp) ? filesize($tmp) : 0;
    $fail = function ($st, $msg, $extra = []) use ($tmp) {
        $body = is_file($tmp) ? (string) @file_get_contents($tmp, false, null, 0, 300) : '';
        @unlink($tmp);
        return ['ok' => false, 'status' => $st, 'error' => $msg] + $extra + ($body !== '' ? ['body' => $body] : []);
    };
    if ($err !== null) {
        return $fail(502, 'Proxy failed to reach TDX: ' . $err);
    }
    if ($status !== 200) {
        $extra = [];
        if ($status === 429) {
            $ra = isset($headers['retry-after']) ? (int) trim(substr($headers['retry-after'], strpos($headers['retry-after'], ':') + 1)) : 0;
            $extra['retry_after'] = $ra > 0 ? $ra : 60;
        }
        return $fail($status ?: 502, 'TDX responded HTTP ' . $status, $extra);
    }
    if (!downloadLooksLikeJson($tmp, $size)) {
        return $fail(502, 'TDX response is not valid JSON');
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return ['ok' => false, 'status' => 500, 'error' => 'Cannot write ' . downloadRelPath($service, $path)];
    }
    clearstatcache(true, $file);
    return ['ok' => true, 'size' => $size];
}

/** 檢查是否為 JSON：小檔完整解析；大檔只檢查頭尾字元，避免吃掉大量記憶體 */
function downloadLooksLikeJson($file, $size)
{
    if ($size <= 0) {
        return false;
    }
    if ($size <= 2 * 1024 * 1024) {
        json_decode((string) @file_get_contents($file));
        return json_last_error() === JSON_ERROR_NONE;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return false;
    }
    $head = ltrim((string) fread($fh, 64));
    fseek($fh, -64, SEEK_END);
    $tail = rtrim((string) fread($fh, 64));
    fclose($fh);
    $h = $head[0] ?? '';
    $t = substr($tail, -1);
    return ($h === '[' && $t === ']') || ($h === '{' && $t === '}');
}

// ================================================================
// 入口

function actionDownload()
{
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    $service = strtolower(trim((string) ($_GET['service'] ?? '')));
    $services = ['tdx'];

    if ($func === 'help' || ($func === '' && $service === '' && !isset($_GET['path']))) {
        downloadSendHelp();
    }
    if (!in_array($service, $services, true)) {
        sendJson(400, ['ok' => false, 'error' => $service === '' ? 'Missing service' : 'Unknown service: ' . $service,
                       'services' => $services, 'help' => '/txapi/download/help']);
    }

    switch ($func) {
        case 'list':
            downloadList($service);
        case 'download':
            downloadSend($service, false);
        default:
            sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func, 'funcs' => ['help', 'list', 'download'],
                           'admin' => '/txapi/admin_download（sync、prune、refresh）', 'help' => '/txapi/download/help']);
    }
}

/**
 * /txapi/admin_download：下載的管理動作（路由權限 admin_download）
 *   func=sync     分批同步（寫入）
 *   func=prune    列出清單外的舊存檔（讀取）；confirm=1 實際刪除（寫入）
 *   func=refresh  重新向 TDX 取得 path 並回傳檔案（寫入）
 */
function actionAdminDownload()
{
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    $service = strtolower(trim((string) ($_GET['service'] ?? 'tdx')));
    if (!in_array($func, ['sync', 'prune', 'refresh'], true)) {
        adminRequire('admin_download', 'read');
        sendJson(400, ['ok' => false, 'error' => $func === '' ? 'Missing func' : 'Unknown func: ' . $func,
                       'funcs' => ['sync', 'prune', 'refresh'], 'help' => '/txapi/download/help']);
    }
    if ($service !== 'tdx') {
        sendJson(400, ['ok' => false, 'error' => 'Unknown service: ' . $service, 'services' => ['tdx']]);
    }
    adminRequire('admin_download', $func === 'prune' && empty($_GET['confirm']) ? 'read' : 'write');
    switch ($func) {
        case 'sync':
            downloadSync($service);
        case 'prune':
            downloadPrune($service);
        case 'refresh':
            downloadSend($service, true);
    }
}

/** 有沒有後臺身分（面板登入或 PAT）：查詢次數與時間範圍不受限制 */
function downloadAdminOk()
{
    return function_exists('adminCurrent') && adminCurrent() !== null;
}

/** 依 group／category 參數篩選清單 */
function downloadFiltered($service)
{
    $group = trim((string) ($_GET['group'] ?? ''));
    $cat = trim((string) ($_GET['category'] ?? ''));
    $out = [];
    foreach (downloadCatalog($service) as $path => $cfg) {
        if (($group === '' || strcasecmp($group, $cfg['group']) === 0) && ($cat === '' || strcasecmp($cat, $cfg['category']) === 0)) {
            $out[$path] = $cfg;
        }
    }
    return $out;
}

/** func=list：可下載清單、檔案狀態與統計 */
function downloadList($service)
{
    sendJson(200, downloadListData($service));
}

/** 清單資料（func=list 與監控面板共用） */
function downloadListData($service)
{
    $items = [];
    $sum = ['count' => 0, 'downloaded' => 0, 'outdated' => 0, 'bytes' => 0];
    $groups = [];
    foreach (downloadFiltered($service) as $path => $cfg) {
        $i = downloadInfo($service, $path, $cfg);
        $items[] = $i;
        $g = &$groups[$cfg['group']];
        $g = ($g ?? ['name' => DOWNLOAD_GROUPS[$cfg['group']] ?? $cfg['group'], 'count' => 0, 'downloaded' => 0, 'bytes' => 0]);
        $g['count']++;
        $sum['count']++;
        if ($i['downloaded']) {
            $g['downloaded']++;
            $g['bytes'] += $i['size'];
            $sum['downloaded']++;
            $sum['bytes'] += $i['size'];
        }
        if ($i['outdated']) {
            $sum['outdated']++;
        }
        unset($g);
    }
    return [
        'ok'         => true,
        'action'     => 'download',
        'service'    => $service,
        'root'       => 'download/' . $service . '/',
        'summary'    => $sum,
        'groups'     => $groups,
        'categories' => DOWNLOAD_CATEGORIES,
        'items'      => $items,
    ];
}

/** func=download：回傳檔案；沒有存檔（或 /txapi/admin_download/refresh）時先向 TDX 取得並存檔 */
function downloadSend($service, $refresh = false)
{
    $found = downloadFind($service, $_GET['path'] ?? '');
    if ($found === null) {
        sendJson(404, ['ok' => false, 'error' => 'Path not in download list: ' . (string) ($_GET['path'] ?? ''),
                       'list' => '/txapi/download/list?service=' . $service]);
    }
    list($path, $cfg) = $found;
    $file = downloadFile($service, $path);


    $source = 'stored';
    if ($refresh || !is_file($file)) {
        $rate = downloadRateRemaining();
        if ($rate !== null && $rate['remaining'] <= DOWNLOAD_RATE_RESERVE) {
            if (!is_file($file)) {
                header('Retry-After: ' . $rate['reset_in']);
                sendJson(503, ['ok' => false, 'error' => 'TDX rate limit nearly used up; try again later', 'retry_after' => $rate['reset_in']]);
            }
        } elseif (!($budget = downloadCallBudget(true))['taken']) {
            if (!is_file($file)) {
                header('Retry-After: ' . $budget['next_in']);
                sendJson(503, ['ok' => false, 'error' => 'Download budget reached (' . DOWNLOAD_MAX_PER_MINUTE . ' TDX calls per minute); try again later',
                               'retry_after' => $budget['next_in']]);
            }
        } else {
            @set_time_limit(DOWNLOAD_FETCH_TIMEOUT + 30);
            $r = downloadFetch($service, $path, $cfg);
            if (!$r['ok'] && !is_file($file)) {
                sendJson($r['status'] >= 400 ? $r['status'] : 502, ['ok' => false, 'path' => $path] + $r);
            }
            $source = $r['ok'] ? 'fetched' : 'stale';
        }
    }

    clearstatcache(true, $file);
    $name = $service . '_' . str_replace('/', '_', ltrim($path, '/')) . '.json';
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($file));
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
    header('Cache-Control: no-store');
    header('X-Download-Source: ' . $source);
    header('Access-Control-Expose-Headers: content-disposition, last-modified, x-download-source');
    readfile($file);
    exit;
}

/**
 * func=sync：分批向 TDX 取得並存檔（admin_download 寫入權限）。
 * 一次最多處理 limit 個（預設 4、上限 10）需要更新的項目，TDX 每分鐘剩餘額度低於等於 DOWNLOAD_RATE_RESERVE 就先停，
 * 回應 done=false 與 next_in（建議幾秒後再呼叫）。重複呼叫直到 done=true。
 *   force=1  忽略存檔時間，全部重新取得（仍分批）
 *   group=…、category=…  只處理符合的項目
 */
function downloadSync($service)
{
    $limit = max(1, min(10, (int) ($_GET['limit'] ?? 4)));
    $force = !empty($_GET['force']);
    $since = time();   // force 時以本次同步開始前的檔案為「舊」

    $todo = [];
    foreach (downloadFiltered($service) as $path => $cfg) {
        $file = downloadFile($service, $path);
        if ($force ? (!is_file($file) || filemtime($file) < (int) ($_GET['since'] ?? $since)) : downloadNeedsFetch($service, $path, $cfg)) {
            $todo[$path] = $cfg;
        }
    }

    @set_time_limit($limit * (DOWNLOAD_FETCH_TIMEOUT + 10));
    $results = [];
    $stopReason = null;
    $nextIn = 0;
    foreach ($todo as $path => $cfg) {
        if (count($results) >= $limit) {
            $stopReason = 'batch limit reached';
            break;
        }
        $rate = downloadRateRemaining();
        if ($rate !== null && $rate['remaining'] <= DOWNLOAD_RATE_RESERVE) {
            $stopReason = 'TDX rate limit reserve reached';
            $nextIn = $rate['reset_in'];
            break;
        }
        $budget = downloadCallBudget(true);
        if (!$budget['taken']) {
            $stopReason = 'download budget reached (' . DOWNLOAD_MAX_PER_MINUTE . ' TDX calls per minute)';
            $nextIn = $budget['next_in'];
            break;
        }
        $t0 = microtime(true);
        $r = downloadFetch($service, $path, $cfg);
        $results[] = ['path' => $path, 'file' => downloadRelPath($service, $path), 'secs' => round(microtime(true) - $t0, 1)] + $r;
        if (!$r['ok'] && $r['status'] === 429) {
            $stopReason = 'TDX rate limited (429)';
            $nextIn = $r['retry_after'] ?? 60;
            break;
        }
    }

    $remaining = 0;
    foreach (downloadFiltered($service) as $path => $cfg) {
        $file = downloadFile($service, $path);
        if ($force ? (!is_file($file) || filemtime($file) < (int) ($_GET['since'] ?? $since)) : downloadNeedsFetch($service, $path, $cfg)) {
            $remaining++;
        }
    }
    $failed = count(array_filter($results, function ($r) { return !$r['ok']; }));
    if ($remaining > 0 && $nextIn === 0) {
        $nextIn = $stopReason === 'batch limit reached' ? 5 : 15;
    }
    $out = [
        'ok'        => $failed === 0,
        'action'    => 'download',
        'func'      => 'sync',
        'service'   => $service,
        'processed' => count($results),
        'failed'    => $failed,
        'remaining' => $remaining,
        'done'      => $remaining === 0,
        'stopped'   => $stopReason,
        'next_in'   => $remaining === 0 ? null : $nextIn,
        'results'   => $results,
    ];
    if ($force && $remaining > 0) {
        $out['continue'] = '/txapi/admin_download/sync?service=' . $service . '&force=1&since=' . (int) ($_GET['since'] ?? $since);
    }
    // 全部同步完成：資料有變就把 download 轉進 SQLite（finder 查詢用）
    if ($remaining === 0 && function_exists('finderDbEnsure')) {
        try {
            $r = finderDbEnsure();
            $out['sqlite'] = ['ok' => true, 'rebuilt' => $r['rebuilt'], 'report' => $r['report']];
        } catch (FinderDbIncomplete $e) {
            $out['sqlite'] = ['ok' => false, 'error' => $e->getMessage(), 'missing' => $e->missing];
        } catch (Throwable $e) {
            $out['sqlite'] = ['ok' => false, 'error' => $e->getMessage()];
        }
    }
    sendJson(200, $out);
}

/**
 * func=prune：找出 download/<service>/ 內不在清單上的檔案（例如清單調整後留下的舊時刻表），admin_download 權限。
 * 預設只列出；confirm=1 才刪除，並一併移除變空的資料夾。.htaccess 與清單內的檔案不會動。
 */
function downloadPrune($service)
{
    $root = DOWNLOAD_DIR . '/' . $service;
    $keep = [];
    foreach (downloadCatalog($service) as $path => $cfg) {
        $keep[downloadRelPath($service, $path)] = true;
    }
    $confirm = !empty($_GET['confirm']);
    $orphans = [];
    $bytes = 0;
    $deleted = 0;
    if (is_dir($root)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                                            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $rel = 'download/' . $service . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            if ($f->isDir()) {
                if ($confirm) {
                    @rmdir($f->getPathname());   // 只有空資料夾會成功
                }
                continue;
            }
            if (isset($keep[$rel]) || $f->getFilename() === '.htaccess') {
                continue;
            }
            $size = $f->getSize();
            $orphans[] = ['file' => $rel, 'size' => $size];
            $bytes += $size;
            if ($confirm && @unlink($f->getPathname())) {
                $deleted++;
            }
        }
    }
    usort($orphans, function ($a, $b) { return strcmp($a['file'], $b['file']); });
    sendJson(200, [
        'ok'      => !$confirm || $deleted === count($orphans),
        'action'  => 'download',
        'func'    => 'prune',
        'service' => $service,
        'confirm' => $confirm,
        'count'   => count($orphans),
        'bytes'   => $bytes,
        'deleted' => $deleted,
        'files'   => $orphans,
        'hint'    => $confirm ? null : '加上 &confirm=1 才會刪除',
    ]);
}

// ================================================================
// 說明文件

function downloadSendHelp()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $groupRows = '';
    $counts = [];
    $total = ['n' => 0, 'd' => 0, 'b' => 0];
    foreach (downloadCatalog('tdx') as $path => $cfg) {
        $c = &$counts[$cfg['group']];
        $c = $c ?? ['n' => 0, 'd' => 0, 'b' => 0, 'cats' => []];
        $c['n']++;
        $c['cats'][$cfg['category']] = ($c['cats'][$cfg['category']] ?? 0) + 1;
        $total['n']++;
        $f = downloadFile('tdx', $path);
        if (is_file($f)) {
            $c['d']++;
            $c['b'] += filesize($f);
            $total['d']++;
            $total['b'] += filesize($f);
        }
        unset($c);
    }
    foreach ($counts as $g => $c) {
        $cats = implode('、', array_map(function ($k, $n) { return (DOWNLOAD_CATEGORIES[$k] ?? $k) . ' ' . $n; }, array_keys($c['cats']), $c['cats']));
        $groupRows .= "| `{$g}` | " . (DOWNLOAD_GROUPS[$g] ?? $g) . " | {$c['n']} | {$cats} | {$c['d']} | " . number_format($c['b'] / 1048576, 2) . " MB |\n";
    }
    $groupRows .= "| **合計** | | **{$total['n']}** | | **{$total['d']}** | **" . number_format($total['b'] / 1048576, 2) . " MB** |\n";
    $totalMb = number_format($total['b'] / 1048576, 1);

    $catRows = '';
    foreach (DOWNLOAD_CATEGORIES as $k => $v) {
        $catRows .= "| `{$k}` | {$v} |\n";
    }
    $ttRows = '';
    foreach (downloadCatalog('tdx') as $path => $cfg) {
        if ($cfg['category'] !== 'timetable' || strpos($path, '/Metro/') !== false) {
            continue;
        }
        $f = downloadFile('tdx', $path);
        $v = is_file($f) ? downloadValidity($f) : null;
        $range = $v ? (($v['effective'] ?? '?') . ' ～ ' . ($v['expire'] ?? '（未定）')) : (is_file($f) ? '—' : '尚未存檔');
        $ttRows .= "| `{$path}` | {$range} |\n";
    }
    $maxAgeDays = DOWNLOAD_MAX_AGE / 86400;
    $reserve = DOWNLOAD_RATE_RESERVE;

    $perMin = DOWNLOAD_MAX_PER_MINUTE;
    $md = <<<MD
# /txapi/download：靜態資料下載

把 TDX 的**完整**靜態資料（不帶任何篩選參數）存到伺服器上，以檔案下載的方式提供。
存檔目錄對應 API 路徑，起始點為 `download/<service>/`，例如：

```
/v2/Rail/Metro/Station/TRTC                       →  download/tdx/v2/Rail/Metro/Station/TRTC.json
/v3/Rail/TRA/GeneralTrainTimetable               →  download/tdx/v3/Rail/TRA/GeneralTrainTimetable.json
```

範圍：**軌道轉乘查詢（接軌時刻 TimeLink）所需的最小基礎資料**。涵蓋臺鐵、高鐵、臺北捷運、新北捷運
（環狀線、三鶯線、淡海輕軌、安坑輕軌）、桃園機場捷運、臺中捷運、高雄捷運、高雄輕軌的車站、路線、站序與里程、
轉乘、定期時刻。**不含**阿里山林鐵、貓空纜車、業者資料，也不含只能套用在特定日期的臺鐵加班車（查詢只選星期）。

清單**已最小化**以降低維護成本：v2、v3 內容相同時只留一份，能由其他項目推導的 API 不下載
（推導方式見下方「省略的 API 與推導方式」）。只有清單內的 path 可以下載，清單以外一律回 404。

## 參數

| 參數 | 必填 | 支援的值 | 說明 |
| --- | --- | --- | --- |
| `action` | 是 | `download` | 固定 |
| `func` | 否 | `help`、`list`、`download`（`admin_download`：`sync`、`prune`、`refresh`） | 省略且沒有其他參數時等同 `help` |
| `service` | `help` 以外必填 | `tdx` | 資料來源：TDX 運輸資料流通服務 |
| `path` | `download` 必填 | 見 `func=list` | API 路徑，不分大小寫；不含 `/api/basic` 與查詢參數 |
| `group` | 否 | 見下方「分組」 | `list`、`sync` 只處理該業者 |
| `category` | 否 | 見下方「分類」 | `list`、`sync` 只處理該分類 |
| `limit` | 否 | `1`～`10`，預設 `4` | 配合 `sync`：這次最多處理幾個 |
| `force` | 否 | `1` | 配合 `sync`：忽略存檔時間全部重新取得（依回應的 `continue` 網址繼續） |
| `confirm` | 否 | `1` | 配合 `prune`：實際刪除（省略時只列出） |
| header `Authorization: Bearer <PAT>` | `admin_download` 必填 | 後臺 PAT | 或登入後臺 |

## func

### `func=help` — 本說明

```
GET https://{$host}/txapi/download
```

### `func=list` — 清單與狀態（JSON）

```
GET https://{$host}/txapi/download/list?service=tdx[&group=TRA][&category=timetable]
```

- `summary`：總數、已存檔數、過期數、總容量
- `groups`：各業者的項目數、已存檔數與容量
- `items`：每一項的 `path`、`group`、`category`、`title`、`desc`、`tdx_url`、`file`、`downloaded`、
  `size`、`saved_at`、`outdated`、`download`（下載網址）；已存檔的時刻表另有 `validity`
  （`effective`／`expire`：該版時刻表的適用期間，見下方「時刻表」）

### `func=download` — 下載檔案

```
GET https://{$host}/txapi/download/download?service=tdx&path=/v2/Rail/Metro/Station/TRTC
```

- 回應是**檔案下載**（`Content-Disposition: attachment`），檔名如 `tdx_v2_Rail_Metro_Station_TRTC.json`
- 已存檔時直接提供，不消耗 TDX 額度；尚未存檔時先向 TDX 取得、存檔後再提供
  （TDX 額度快用完時回 503 與 `Retry-After`）
- header `X-Download-Source`：`stored`（既有存檔）、`fetched`（剛取得）、`stale`（重新取得失敗，提供舊檔）
- 錯誤時才回 JSON（例如 path 不在清單：404）

### `func=sync` — 分批同步（`/txapi/admin_download`，寫入權限）

```
GET https://{$host}/txapi/admin_download/sync?service=tdx[&limit=4][&group=…][&force=1]
Authorization: Bearer <PAT>
```

TDX 金鑰每分鐘只有少量額度，全部項目無法一次取完，因此採**分批**：

- 每次最多處理 `limit` 個需要更新的項目（沒有存檔，或存檔超過 {$maxAgeDays} 天）
- **下載功能每 60 秒最多向 TDX 發出 {$perMin} 次請求**（`func=sync` 與 `func=download` 合計）；用完就停下，
  `stopped` 為 `download budget reached`，`next_in` 是額度恢復的秒數
- TDX 每分鐘剩餘額度 ≤ {$reserve} 時也先停下，保留給一般 proxy 使用者
- `category=traveltime` 這類篩選可以只同步某一類（例如只更新站間行駛時間）
- 回應 `done`、`remaining`、`next_in`（建議幾秒後再呼叫）；**重複呼叫直到 `done: true`**
- 全部完成（`done: true`）時自動把 download 轉進 SQLite（`/txapi/finder` 查詢用），資料沒變就不重建；
  結果在回應的 `sqlite`（下載資料不完整時列出缺少的 `missing[].path`，不寫入）。也可用
  `/txapi/admin_download_to_sqlite/build`（寫入權限）手動整份重建

### `func=prune` — 清除清單外的舊檔（`/txapi/admin_download`）

```
GET https://{$host}/txapi/admin_download/prune?service=tdx[&confirm=1]
Authorization: Bearer <PAT>
```

- 列出 `download/tdx/` 內不在清單上的檔案（例如清單調整後留下的舊檔）與總容量
- 只列出需要讀取權限；加 `confirm=1` 才刪除（寫入權限），並移除變空的資料夾；清單內的檔案不會動

### `func=refresh` — 重新取得一個檔案（`/txapi/admin_download`，寫入權限）

```
GET https://{$host}/txapi/admin_download/refresh?service=tdx&path=/v2/Rail/Metro/Station/TRTC
Authorization: Bearer <PAT>
```

- 先向 TDX 重新取得並存檔，再像 `func=download` 一樣回傳檔案

## 時刻表

只下載**定期時刻表**，不下載指定日期的每日時刻表（體積大、而且可以推導）：

- 每個車次都帶 `ServiceDay`（`Monday`～`Sunday` 為 1 表示該星期行駛）
- **某星期幾的全線車次時刻表**：篩選 `ServiceDay.<星期> = 1` 的車次
- **某車站某星期幾的時刻表**：把上面篩出的車次的 `StopTimes` 依 `StationID` 展開、依時刻排序
- 臺鐵車種代碼與名稱在每個車次的 `TrainInfo`（`TrainTypeID`、`TrainTypeCode`、`TrainTypeName`）
- 高鐵定期表的首站沒有 `ArrivalTime`、末站沒有 `DepartureTime`

定期時刻表反映的是 TDX 上**目前生效的那一版**。臺鐵在連假（例如中秋、春節）前後會發布只適用幾天的臨時版本，
車次與 `ServiceDay` 會因應連假調整；請在平常時段同步，並用下表或 `func=list` 的 `validity` 確認適用期間。
與每日時刻表比對，平常時段高鐵週一～週四完全一致，週末會有少數加開或停駛的車次。

| 時刻表 | 適用期間（依目前存檔） |
| --- | --- |
{$ttRows}
### 捷運、輕軌

TDX 的捷運**沒有車次時刻表**，只有站別時刻表 `StationTimeTable`：每一站 × 營運路線 × 終點 × 平日／假日一組發車時刻，
`ServiceDay` 標示適用星期，但**沒有車次編號**。桃園捷運另有 `StoppingPattern`（普通車、直達車的停靠站）。

- **某站某星期幾的時刻表**：篩選 `ServiceDay.<星期> = true` 的站別時刻表
- **近似的車次表**：依 `StationOfRoute` 的站序，把相鄰兩站的發車時刻以固定的行駛時間（取最常見的時間差）串起來。
  實測可串上的比例：高雄捷運、安坑輕軌 99.9%，高雄輕軌 98.5%，環狀線 95.7%，淡海輕軌 94.6%，
  臺北捷運 89.1%，桃園捷運 88.8%（直達車跳站，改依 `StoppingPatternID` 分開串較準）；
  串不上的多為從機廠在中途站發車、區間折返，或行駛時間差超過 1 分鐘
- 臺中捷運與臺北捷運的**文湖線**沒有站別時刻表，改下載首末班車、發車班距，轉成**模擬時刻表**：每一站、每個終點從首班車起，
  依該時段班距的平均值 (最小+最大)÷2 往後排到末班車（比照接軌時刻），存進 SQLite 並標 `simulated`，供 finder 的 `metro_trip_time` 使用
- **補站的優先順序**：某站某方向沒有真實站別時刻表時，先由同營運路線、同方向最近一個有真實時刻表的車站平移
  （加減站間行駛時間，`source: shifted`，mixed），整條路線都沒有真實時刻表才用上面的班距模擬（`source: simulated`，derived）

### 站間行駛時間（S2STravelTime → finder `metro_travel_time`）

TDX 只有 6 個系統提供：臺北捷運、新北捷運（環狀線、三鶯線）、高雄捷運、高雄環狀輕軌、桃園機場捷運、臺中捷運，
**淡海輕軌、安坑輕軌沒有**（TDX 回 400），改由站別時刻表逐班串車推算起訖矩陣（見下方）。TDX 的原始資料有兩種形狀：

| 形狀 | 系統 | 內容 |
| --- | --- | --- |
| 相鄰站鏈 | 臺北捷運、新北捷運、高雄捷運、臺中捷運 | 每筆是相鄰兩站：`RunTime` 行駛秒數、`StopTime` 在出發站的停站秒數。臺北捷運、新北捷運只有一個方向 |
| 起訖矩陣 | 機場捷運（普通車 462 筆、直達車 42 筆）、高雄環狀輕軌（1,482 筆） | 每筆是任兩站間的秒數；環狀輕軌同一對會有順向與繞遠路兩個值 |

**儲存型態**（每個系統 × 路線 × 車種選一種，記在 `metro_travel_set`）：

- **路線站距表**（`route`，資料表 `metro_travel`）：每條營運路線 × 方向一份，依站序存相鄰兩站的秒數與累計秒數；
  兩站間時間 = 從起站累加經過的車站到迄站（迄站 `arr` − 起站 `dep`）
- **起訖矩陣**（`matrix`，資料表 `metro_travel_od`）：原樣存 TDX 的任兩站秒數，A→B 直接查

**判斷規則：真實資料優先，逐段依優先順序取值**（`tdx` → `reversed` → `timetable` → `timetable-reversed`，見 finder 說明的「資料來源的優先順序」）

1. 原始為相鄰站鏈：依 `StationOfRoute` 完整站序逐段取值，存路線站距表；停站用 TDX 的 `StopTime`（出發站的停站）。
   某段這個方向沒有、反方向有時，以反方向的區間時間倒過來用，標 `Source: reversed`（停站取反方向「本站→前一站」的 `StopTime`）；
   S2STravelTime 完全沒有的段落（新站、延伸段、中間新增的站）由站別時刻表的離站時間差補上（`timetable`／`timetable-reversed`，
   時間差已含下一站停站，該站停站記 0）
2. 原始為起訖矩陣：取矩陣中相鄰兩站的值（同一對取最小正值），依 `StationOfRoute` 每條營運路線、每個方向的站序累加成路線站距表，
   **不補停站時間、不倒用**；再拿矩陣的**每一筆**原始值比對（環狀線同一站出現兩次時任一對算數）。全部一致、且路線上沒有矩陣缺少的車站
   才存路線站距表，**有任何一筆不同就存原始矩陣**；矩陣缺少的車站再由站別時刻表串車推算，只補含該站的起訖對（`source: timetable`）
3. 整條路線都沒有 S2STravelTime（淡海、安坑輕軌，或 TDX 系統裡的新路線）：由站別時刻表串車推算起訖矩陣（見下方）
4. 路線站距表中被同方向較長鏈完整包含的站序（區間車）略過

**目前結果**（2026-10）：

| 系統 | 原始形狀 | 比對 | 儲存 |
| --- | --- | --- | --- |
| 高雄環狀輕軌 | 起訖矩陣 | 1,482 筆全部一致（矩陣值不含停站，一圈順向 5400 秒、逆向 5340 秒） | 路線站距表 |
| 機場捷運普通車 | 起訖矩陣 | 462 筆中 387 筆不同（矩陣值含中間停站，相鄰加總全程 68 分、矩陣 83 分） | 起訖矩陣 |
| 機場捷運直達車 | 起訖矩陣 | 42 筆中 30 筆不同 | 起訖矩陣 |
| 臺北、新北、高雄捷運、臺中捷運 | 相鄰站鏈 | — | 路線站距表 |
| 淡海輕軌、安坑輕軌 | 站別時刻表串車推算 | 淡海 136 組中 75 組班次間有 ±1 分差；安坑 56 組全部一致 | 起訖矩陣（`source: timetable`） |

**淡海、安坑輕軌**（`raw_shape: timetable`）：每條營運路線、每個營運日先算相鄰兩站發車時間差的眾數，再從起點站每一班出發，
逐站找同終點、與「前站時刻 + 眾數」相差 1 分內、未用過的班次串成一整班車；每兩站取同一班車兩站時刻相減的眾數存成起訖矩陣。
終點站沒有往終點方向的發車時刻，「某站→終點站」的最後一段以反方向（終點站→前一站）的時間推估補上（`source: timetable-reversed`）。各營運公司、各路線的完整資料與算法見網站的 `agent/skills/rail-travel-time`。

檔案大小：機場捷運原始矩陣 165 KB（gzip 6 KB），只留相鄰兩站約 19 KB（gzip 2 KB）；壓縮後差距很小，所以以正確為先存原始矩陣。

## 省略的 API 與推導方式

以下 API 不在清單內，需要時由已下載的資料推導。「一致率」是以 2026-09 的完整資料實際比對的結果。

| 省略的 API | 推導方式 | 一致率／差異 |
| --- | --- | --- |
| 臺鐵、高鐵每日時刻表（`DailyTimetable`、`DailyTrainTimetable`、`DailyStationTimetable`） | 定期時刻表依 `ServiceDay` 篩選星期（見上方「時刻表」） | 平常時段高鐵週一～週四完全一致，週末差 10～13 班加開或停駛 |
| 臺鐵 `GeneralStationTimetable` | `GeneralTrainTimetable` 的 `StopTimes` 依車站展開 | 以每日時刻表驗證同法：20,945 筆完全一致 |
| 臺鐵 v2 `GeneralTimetable`、`GeneralTrainInfo` | 與 v3 `GeneralTrainTimetable` 同一批車次（953 班），只是欄位名稱不同 | 內容相同 |
| 臺鐵 `TrainType`（v2、v3） | 定期時刻表各車次 `TrainInfo` 的 `TrainTypeID`、`TrainTypeCode`、`TrainTypeName` | 時刻表用到 10 種，名稱與代碼完全一致；其餘 45 種沒有車次使用 |
| 高鐵 `StationOfLine` | 高鐵 `S2SDistance` 依序累加：第一段的 `From` 為起點，之後每段的 `To` 依序排列，里程逐段相加；站碼要補前導 0（`990` → `0990`） | 12 站站序相同；里程只有嘉義不同：TDX 的 `StationOfLine` 寫 218.88（錯誤），累加為 254.88 |
| 臺鐵 v2 `Line`、`StationOfLine`；v3 `Station` | 與保留的另一版內容相同 | v3 `Station` 的 `StationURL` = `https://www.trc.com.tw/tra-tip-web/tip/tip00H/tipH41/viewStaInfo/{StationID}`（245 站全部符合）；v3 多一個非營業站 1998 樹林調車場（不在任何路線與時刻表內） |
| 臺鐵 `LineNetwork` | 路線區段 = `StationOfLine` 相鄰兩站，距離為累計里程相減；`SegmentType` B/M = `Line.IsBranch` | 244 段完全一致 |
| 各系統 `Network`（臺鐵 v2／v3、捷運） | 路網內的路線清單 = `Line` | 路線清單完全一致；**路網名稱與路網圖網址會遺失** |
| 捷運 `StationOfLine` | 同一路線所有營運路線（`StationOfRoute`）的車站合併 | 車站 100% 一致；臺北捷運 27 站、高雄捷運 33 站累計里程差 0.02～0.1 km（起算點不同） |
| 捷運 `Route` | 名稱、方向、起訖站取自 `StationOfRoute`；`RouteLength` ≈ 末站累計里程；全程時間由站別時刻表計算；`RailRouteType` 依系統固定（捷運 33、機場捷運 34、輕軌 35） | 起訖站與名稱 100% 一致；安坑輕軌、臺中捷運的 `RouteLength` 比末站累計里程多 0.3～1 km |
| 站別時刻表涵蓋全線之系統的 `FirstLastTimetable`、`Frequency`（臺北捷運因文湖線沒有站別時刻表而保留） | 站別時刻表的第一班、最後一班、相鄰班次時間差 | 多數一致，部分首末班相差 1～4 分鐘 |
| 所有 `ODDistance`（任兩站距離） | 路線里程經由指定轉乘站分段累加（見下方「里程計算方式」） | 與 TDX 的值不一定相同：TDX 是逐段四捨五入的站間里程相加，例如臺北→臺東 TDX 344.9、路線里程累加 345.2 |
| 臺鐵、捷運、輕軌的 `S2SDistance` | 同一路線相鄰兩站累計里程相減 | 捷運、輕軌 100% 一致（臺北捷運只有「萬芳醫院－辛亥」不同，TDX 寫 1.14 疑為誤植，累計里程相減為 0.76）；臺鐵 244 段中 93 段差 0.1～0.2 km，各路線全長差 0～0.4 km（例如沙崙線 TDX 5.3、路線里程 5.7），以路線里程為準 |
| （保留）高鐵的 `S2SDistance` | — | 高鐵的站序與累計里程由它推導（見上方高鐵 `StationOfLine`） |
| 業者資料（`/v2/Rail/Operator`、`/v2/Basic/Operator`、臺鐵 v3 `Operator`） | 不參與轉乘計算 | 範圍外 |
| 臺鐵 `SpecificTrainTimetable` | 加班車以日期（`SpecialDay`）標示，查詢只選星期，無法套用 | 範圍外 |
| 阿里山林鐵、貓空纜車全部 API | TimeLink 不含這兩個系統 | 範圍外 |

## 里程計算方式

任兩站距離一律以**路線里程**（臺鐵 `StationOfLine`、捷運與輕軌 `StationOfRoute` 的 `CumulativeDistance`、高鐵由 `S2SDistance` 累加）
**經由指定轉乘站分段累加**：每一段取同一條路線上兩站累計里程的差，再全部加總。

```
臺北→臺東（經八堵）＝ 西部幹線｜臺北 − 八堵｜＋ 東部幹線｜八堵 − 臺東｜
                    ＝ (28.5 − 3.9) ＋ (320.6 − 0) ＝ 345.2 km
```

- **臺鐵**：12 條路線各自從 0 起算，接軌站在各路線使用**同一個車站代碼**（例如八堵 `0920`：西部幹線 3.9、東部幹線 0），
  直接以車站代碼找出轉乘點
- **高鐵**：單一路線；累計里程由 `S2SDistance` 依序累加（嘉義為 254.88）
- **捷運、輕軌**：同一系統內轉乘站在各線代碼不同（例如臺北車站 `BL12`／`R10`），以 `LineTransfer` 對應；
  支線分岔點（大橋頭 `O12`、七張 `G03`、北投 `R22`）也列在 `LineTransfer`。
  累計里程要用含這兩站的同一條營運路線（`StationOfRoute`）
- **跨系統轉乘**由轉乘群組（TransferGroup，見 `/txapi/transfer`）處理：成員車站與步行分鐘優先取 TDX 的
  捷運 `StationTransfer`、`TransferStations`、`LineTransfer` 與臺鐵 `LineTransfer`、`StationTransfer`，
  TDX 沒有的再以 railtime／rocptx 的資料或座標距離推算；車站同名不可自動配對（臺中與臺北都有「市政府」）
- 不同系統的里程互不相通，臺鐵、高鐵、捷運之間不做累加

## 分組（業者）

| group | 名稱 | 項目數 | 內容 | 已存檔 | 容量 |
| --- | --- | --- | --- | --- | --- |
{$groupRows}
## 分類

| category | 說明 |
| --- | --- |
{$catRows}
## 注意

- 存檔只接受 TDX 回 200 且內容為合法 JSON 的回應，錯誤訊息不會被存成資料
- 下載使用本服務的 TDX 金鑰
- 存檔位置在網站根目錄的 `download/`，只由本 API 建立與覆蓋，不納入備份；無法直接以網址存取，只能透過本 action 下載
- 全部 {$total['n']} 項，目前已存檔 {$totalMb} MB；容量主要在捷運站別時刻表

MD;

    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Cache-Control: no-store');
    echo $md;
    exit;
}
