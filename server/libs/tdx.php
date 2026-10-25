<?php
/**
 * TDX 上游：access token 的取得與快取，以及把請求原樣轉給 TDX。
 */

const TDX_HOST       = 'https://tdx.transportdata.tw';
const TDX_TOKEN_PATH = '/auth/realms/TDXConnect/protocol/openid-connect/token';
const TDX_CACHE_DIR  = APP_TEMP_DIR . '/cache';   // access token、流量紀錄、靜態資料快取（download/tdx_proxy_files/cache：不在 libs/，重新佈署不會掉）
// TOKEN_MARGIN（token 到期前幾秒就視為過期）：設定項目（libs/config.json）

// 不轉發給前端的 TDX 回應 header：
//   逐段（hop-by-hop）header 與長度／分塊資訊由本機這段連線自行決定，
//   access-control-* 改用本服務自己的 CORS 設定，set-cookie 對跨網域前端無意義也不該外流。
const SKIP_RESPONSE_HEADERS = [
    'connection', 'keep-alive', 'proxy-authenticate', 'proxy-authorization', 'te', 'trailer',
    'transfer-encoding', 'upgrade', 'content-length', 'set-cookie',
];
// 不轉發給 TDX 的前端請求 header：Authorization 換成 proxy 自己的，其餘為連線層或本服務專用
const SKIP_REQUEST_HEADERS = [
    'host', 'authorization', 'cookie', 'connection', 'keep-alive', 'content-length',
    'transfer-encoding', 'upgrade', 'expect', 'te', 'trailer', 'origin', 'referer',
    'proxy-authorization', 'proxy-connection', 'x-forwarded-for', 'x-forwarded-host',
    'x-forwarded-proto', 'x-real-ip', 'x-tdx-authorization', 'x-admin-token',
];


function isConfigured()
{
    return defined('TDX_CLIENT_ID') && defined('TDX_CLIENT_SECRET')
        && TDX_CLIENT_ID !== '' && TDX_CLIENT_SECRET !== '';
}

// ================================================================
// 使用者自帶的金鑰
//
// 前端可以帶自己的 TDX 憑證，改用自己的額度：
//   API 請求   X-TDX-Authorization: Bearer <自己的 access token>（/api/ 與 /txapi/ 都適用，優先）
//              Authorization: Bearer <自己的 access token>（只有 /api/ 轉發看；/txapi/ 的 Authorization 是本站的身分：後臺 PAT、會員 token）
//   換 token   POST 換 token 路徑，client_id + client_secret 為自己的金鑰 → 轉給 TDX 換真的 token
// 沒帶、不是 Bearer（例如舊 PTX 的 hmac）、或值為下列別名時，一律改用設定（custom/libs/config.json）的金鑰：
//   auto                                  明確要求用本服務的金鑰
//   tdx-proxy                             本服務換 token 路徑發的假 token
//   FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF  rocptx library 未設定金鑰時預設送出的 token / client_id / client_secret
// 比對不分大小寫。

const PROXY_TOKEN_ALIASES = ['auto', 'tdx-proxy', 'ffffffff-ffff-ffff-ffff-ffffffffffff'];
const TDX_AUTH_HEADER = 'X-TDX-Authorization';   // 自帶 TDX token 的專用標頭

/**
 * 這次請求的 TDX 憑證情境：proxy＝/api/ 轉發（Authorization 也當成 TDX token）；
 * app＝本站自己的 API（預設；Authorization 是本站身分，不會送去 TDX）。proxyRun() 進入轉發前設定
 */
function tdxCredentialContext($set = null)
{
    static $ctx = 'app';
    if ($set !== null) {
        $ctx = $set;
    }
    return $ctx;
}

/** 這次請求實際呼叫 TDX 時用了誰的憑證：user＝前端自帶、server＝本站金鑰；還沒呼叫 TDX 回 null */
function tdxCredentialUsed($set = null)
{
    static $used = null;
    if ($set !== null) {
        $used = $set;
    }
    return $used;
}

/** 前端帶來的 Authorization header（部分主機不會放進 $_SERVER，退而用 getallheaders） */
function requestAuthorizationHeader()
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $h = $value;
                break;
            }
        }
    }
    return trim((string) $h);
}

/**
 * 前端自帶的 TDX access token；沒帶或要求用本站金鑰時回傳 null。順序：
 *   1. X-TDX-Authorization: Bearer <token>（有這個標頭就只看它；值是別名 auto 等＝明確用本站金鑰）
 *   2. 只有 /api/ 轉發：Authorization: Bearer <token>（相容 rocptx 與直接打 TDX 的寫法）
 * /txapi/ 的 Authorization 是本站身分（後臺 PAT、會員 token），一律不當成 TDX token
 */
function requestUserToken()
{
    $x = trim((string) ($_SERVER['HTTP_X_TDX_AUTHORIZATION'] ?? ''));
    if ($x !== '') {
        if (!preg_match('/^(?:Bearer\s+)?(\S+)$/i', $x, $m)) {
            return null;
        }
        return in_array(strtolower($m[1]), PROXY_TOKEN_ALIASES, true) ? null : $m[1];
    }
    if (tdxCredentialContext() !== 'proxy' || !preg_match('/^Bearer\s+(\S+)$/i', requestAuthorizationHeader(), $m)) {
        return null;
    }
    return in_array(strtolower($m[1]), PROXY_TOKEN_ALIASES, true) ? null : $m[1];
}

/**
 * 換 token 路徑：前端帶了自己的 client_id + client_secret 就轉給 TDX 換真的 token 並直接回應（結束執行）；
 * 沒帶、或 client_secret 為 auto 時什麼都不做，交回呼叫端發假 token。
 */
function tdxUserTokenRequest()
{
    $raw = file_get_contents('php://input');
    $form = [];
    parse_str((string) $raw, $form);
    $form += $_POST;
    $id = trim((string) ($form['client_id'] ?? ''));
    $secret = trim((string) ($form['client_secret'] ?? ''));
    if ($id === '' || $secret === '' || in_array(strtolower($secret), PROXY_TOKEN_ALIASES, true)) {
        return;
    }

    $res = httpRequest('POST', TDX_HOST . TDX_TOKEN_PATH, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $id,
        'client_secret' => $secret,
    ]));
    if ($res['error'] !== null) {
        header('X-Proxy-Error: network');
        sendJson(502, ['Message' => 'Proxy failed to reach TDX.']);
    }
    http_response_code($res['status']);
    header($res['headers']['content-type'] ?? 'Content-Type: application/json');
    header('Cache-Control: no-store');
    echo $res['body'];
    exit;
}

// ================================================================
// access token（client_credentials，快取於 download/tdx_proxy_files/cache/token.php，所有請求共用）

function tokenCacheFile()
{
    return TDX_CACHE_DIR . '/token.php';
}

function readCachedToken()
{
    $raw = @file_get_contents(tokenCacheFile());
    if ($raw === false) {
        return null;
    }
    // 檔案開頭是 PHP exit guard，即使被直接存取也不會吐出內容
    $data = json_decode(substr($raw, strpos($raw, '?>') + 2), true);
    if (empty($data['token']) || ($data['expires_at'] ?? 0) - TOKEN_MARGIN <= time()) {
        return null;
    }
    return $data['token'];
}

function writeCachedToken($token, $expiresAt)
{
    if (!is_dir(TDX_CACHE_DIR) && !@mkdir(TDX_CACHE_DIR, 0755, true)) {
        error_log('[tdx-proxy] cannot create cache dir: ' . TDX_CACHE_DIR);
        return;
    }
    if (!is_file(TDX_CACHE_DIR . '/.htaccess')) {
        @file_put_contents(TDX_CACHE_DIR . '/.htaccess', "Require all denied\n");
    }
    $tmp = tokenCacheFile() . '.' . getmypid() . '.tmp';
    $content = '<?php exit; ?>' . json_encode(['token' => $token, 'expires_at' => $expiresAt]);
    if (@file_put_contents($tmp, $content, LOCK_EX) === false || !@rename($tmp, tokenCacheFile())) {
        @unlink($tmp);
        error_log('[tdx-proxy] cannot write token cache: ' . tokenCacheFile());
    }
}

/**
 * 取得有效 token。$staleToken 為剛被 TDX 回 401 的 token，傳入時會略過與它相同的快取並重取。
 * 以檔案鎖序列化重取動作，避免 token 過期瞬間大量請求同時打 TDX 的 token endpoint。
 */
function getAccessToken($staleToken = null)
{
    $token = readCachedToken();
    if ($token !== null && $token !== $staleToken) {
        return $token;
    }

    if (!is_dir(TDX_CACHE_DIR)) {
        @mkdir(TDX_CACHE_DIR, 0755, true);
    }
    $lock = @fopen(TDX_CACHE_DIR . '/token.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }
    try {
        // 拿到鎖後再檢查一次，可能其他請求剛剛已經重取過
        $token = readCachedToken();
        if ($token !== null && $token !== $staleToken) {
            return $token;
        }

        $res = httpRequest('POST', TDX_HOST . TDX_TOKEN_PATH, ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => TDX_CLIENT_ID,
            'client_secret' => TDX_CLIENT_SECRET,
        ]));
        $data = $res['error'] === null ? json_decode($res['body'], true) : null;
        if ($res['status'] !== 200 || empty($data['access_token'])) {
            error_log('[tdx-proxy] token request failed: HTTP ' . $res['status'] . ' ' . ($res['error'] ?? substr($res['body'], 0, 200)));
            // 留下 TDX 的原始回應，讓 tdxSendFetchFailure() 原樣轉給前端（例如額度用完被拒）
            $GLOBALS['TDX_TOKEN_FAILURE'] = $res;
            return null;
        }

        writeCachedToken($data['access_token'], time() + (int) ($data['expires_in'] ?? 3600));
        return $data['access_token'];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

// ================================================================
// 轉發

/** 把前端送來的 header 原樣帶去 TDX（SKIP_REQUEST_HEADERS 除外） */
function collectRequestHeaders()
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') !== 0) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        if (in_array($name, SKIP_REQUEST_HEADERS, true)) {
            continue;
        }
        $headers[] = $name . ': ' . $value;
    }
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'content-type: ' . $_SERVER['CONTENT_TYPE'];
    }
    return $headers;
}

/**
 * 向 TDX 送出一次請求，並記錄流量。
 * 前端自帶 token 時用它（不重試、不計入 proxy 金鑰的流量紀錄）；
 * 否則用 proxy 的 token，遇到 401 會重取 token 再試一次。
 * 取不到 token 時回傳 null；連線失敗時 $res['error'] 不為 null。
 */
function tdxFetch($method, $pathAndQuery, array $headers, $body = null)
{
    $userToken = requestUserToken();
    tdxCredentialUsed($userToken !== null ? 'user' : 'server');
    if (!headers_sent()) {
        header('X-TDX-Credential: ' . tdxCredentialUsed());
    }
    if ($userToken !== null) {
        // 自帶的 token：不重試、不改用本站金鑰（被拒就把 TDX 的回應原樣交回）；token 不寫進任何紀錄
        $res = httpRequest($method, TDX_HOST . $pathAndQuery, array_merge($headers, ['Authorization: Bearer ' . $userToken]), $body);
        if ($res['error'] !== null) {
            error_log('[tdx-proxy] upstream error: ' . $res['error'] . ' ' . $pathAndQuery);
        }
        return $res;
    }

    $token = getAccessToken();
    if ($token === null) {
        return null;
    }
    $url = TDX_HOST . $pathAndQuery;
    $res = httpRequest($method, $url, array_merge($headers, ['Authorization: Bearer ' . $token]), $body);

    // token 可能被提前撤銷（例如在 TDX 後台重設金鑰），強制重取後再試一次
    if ($res['status'] === 401) {
        $token = getAccessToken($token);
        if ($token !== null) {
            $res = httpRequest($method, $url, array_merge($headers, ['Authorization: Bearer ' . $token]), $body);
        }
    }

    if ($res['error'] !== null) {
        error_log('[tdx-proxy] upstream error: ' . $res['error'] . ' ' . $pathAndQuery);
    } elseif (function_exists('rateLimitRecord')) {
        // 記下流量限制 header 與呼叫次數（libs/ratelimit.php）
        rateLimitRecord($res['status'], $res['headers'], $pathAndQuery);
    }
    return $res;
}

/**
 * tdxFetch() 沒拿到 TDX 的 API 回應時回給前端，然後結束：
 *   - 換 token 被 TDX 拒絕（例如額度用完、金鑰失效）：原樣轉發 TDX 換 token 的狀態碼、header、body，
 *     與前端自己向 TDX 換 token 時看到的一致；加上 X-Proxy-Error: token 標示發生在換 token 階段
 *   - 連不上 TDX（逾時、DNS…）：TDX 沒有回應可轉發，回 502 與 {"Message": ...}，X-Proxy-Error: network
 */
function tdxSendFetchFailure($res)
{
    if ($res === null) {
        $t = $GLOBALS['TDX_TOKEN_FAILURE'] ?? null;
        if ($t && $t['error'] === null && $t['status'] >= 100) {
            sendUpstreamResponse($t, ['X-Proxy-Error: token']);
            exit;
        }
        header('X-Proxy-Error: token');
        sendJson(502, ['Message' => 'Proxy failed to obtain TDX access token.']);
    }
    header('X-Proxy-Error: network');
    sendJson(502, ['Message' => 'Proxy failed to reach TDX.']);
}

/**
 * 把 TDX 的回應（狀態碼、header、body）原樣回給前端。
 * 唯一的轉換：proxy 向 TDX 要了 gzip、但前端沒有表示接受 gzip 時，解壓後再回（與前端直接呼叫 TDX 時拿到的相同）。
 */
function sendUpstreamResponse(array $res, array $extraHeaders = [])
{
    http_response_code($res['status']);
    $enc = strtolower(trim(substr($res['headers']['content-encoding'] ?? '', strlen('content-encoding:'))));
    if ($enc === 'gzip' && stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') === false) {
        $plain = @gzdecode($res['body']);
        if ($plain !== false) {
            $res['body'] = $plain;
            unset($res['headers']['content-encoding']);
        }
    }
    $exposed = [];
    foreach ($res['headers'] as $name => $line) {
        if (in_array($name, SKIP_RESPONSE_HEADERS, true) || strpos($name, 'access-control-') === 0) {
            continue;
        }
        header($line);
        $exposed[] = $name;
    }
    foreach ($extraHeaders as $line) {
        header($line);
        $exposed[] = strtolower(trim(strtok($line, ':')));
    }
    if (tdxCredentialUsed() !== null) {
        $exposed[] = 'x-tdx-credential';
    }
    // 跨網域時前端 JS 預設只讀得到少數幾個 header，這裡把實際轉發的都開放讀取
    if ($exposed) {
        header('Access-Control-Expose-Headers: ' . implode(', ', $exposed));
    }

    echo $res['body'];
}

/** 把請求轉給 TDX，並將狀態碼、header、body 原樣回給前端 */
function proxyToTdx($method, $pathAndQuery)
{
    // 軌道靜態資料走長期快取（libs/railcache.php）；處理完就結束
    if (function_exists('railCacheHandle') && railCacheHandle($method, $pathAndQuery)) {
        return;
    }

    $headers = collectRequestHeaders();
    $body = $method === 'POST' ? file_get_contents('php://input') : null;

    $res = tdxFetch($method, $pathAndQuery, $headers, $body);
    if ($res === null || $res['error'] !== null) {
        tdxSendFetchFailure($res);
    }
    sendUpstreamResponse($res);
}
