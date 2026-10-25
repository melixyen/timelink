<?php
/**
 * 路由、來源檢查與 CORS。入口是 proxyRun()，由 index.php 呼叫。
 *
 * 路徑對應：/api/... 原樣轉給 TDX 主機的同一路徑。
 *   /txapi/<action>[/<func>]  本站自訂 API（libs/txapi/txapi.php 的 actionRun()）
 * 其他路徑：
 *   GET /                    健康檢查 JSON（附 /txapi/readme 文件連結），不驗證來源、不消耗 TDX 額度
 *   OPTIONS 任何路徑         CORS preflight，一律 204
 *
 * CORS 一律 *；來源限制由 ALLOWED_ORIGINS 在 PHP 端判斷，不符合回 403 不處理。
 *   /auth/realms/.../token   帶自己的 client_id/secret 就轉給 TDX 換真 token；否則回傳假 token，讓舊版前端流程不會中斷
 */

function proxyRun()
{
    // 回應要原樣轉發，不能讓 PHP 再壓一次（TDX 的 Content-Encoding 會一起轉給前端）
    ini_set('zlib.output_compression', '0');
    header('X-Content-Type-Options: nosniff');
    // CORS 一律全面開放（*），瀏覽器永遠不會因 CORS 擋下；要不要處理由下面的來源檢查決定
    sendCorsHeaders();

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    list($path, $query) = parseRequestPath();

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    // /txapi/<action>[/<func>]：本站自訂 API（libs/txapi/txapi.php）
    if (function_exists('txapiResolve') && txapiResolve() !== null) {
        actionRun(null);
    }
    if (function_exists('trafficGate')) {
        trafficGate('api', $path);   // 流量紀錄、IP 封鎖（libs/txapi/admin/traffic.php）
    }

    if ($path === '/') {
        sendJson(200, function_exists('statusInfo') ? statusInfo() : [
            'service'    => defined('PRODUCT_SERVICE_NAME') ? PRODUCT_SERVICE_NAME : 'TDX Proxy',
            'configured' => isConfigured(),
        ]);
    }

    // 來源檢查：不在 ALLOWED_ORIGINS 的請求不處理（CORS 已開放，前端讀得到這個 403 訊息）
    $origin = getRequestOrigin();
    if ($origin === null || !isAllowedHost($origin['host'])) {
        sendJson(403, [
            'Message' => 'Origin not allowed.',
            'origin'  => $origin === null ? 'unrecognized' : ($origin['host'] === '' ? '(none)' : $origin['origin']),
        ]);
    }

    if ($path === TDX_TOKEN_PATH) {
        // 前端帶了自己的 client_id / client_secret 就轉給 TDX 換真的 token（libs/tdx.php）
        if (function_exists('tdxUserTokenRequest')) {
            tdxUserTokenRequest();
        }
        // 否則真正的 token 由 proxy 保管，這裡只是讓舊前端的換 token 流程能正常 resolve
        sendJson(200, [
            'access_token' => 'tdx-proxy',
            'expires_in'   => 86400,
            'token_type'   => 'Bearer',
        ]);
    }

    if (!isValidApiPath($path)) {
        sendJson(404, ['Message' => 'Only /api/ paths are proxied.']);
    }
    if ($method !== 'GET' && $method !== 'HEAD' && $method !== 'POST') {
        header('Allow: GET, HEAD, POST, OPTIONS');
        sendJson(405, ['Message' => 'Method not allowed.']);
    }
    if (function_exists('tdxCredentialContext')) {
        tdxCredentialContext('proxy');   // /api/ 轉發：Authorization 也可以是前端自帶的 TDX token
    }
    $userToken = function_exists('requestUserToken') ? requestUserToken() : null;
    if (!isConfigured() && $userToken === null) {
        sendJson(500, ['Message' => 'Proxy is not configured with a TDX API key.']);
    }

    proxyToTdx($method, $path . ($query !== '' ? '?' . $query : ''));
}

/**
 * 從 REQUEST_URI 取出相對於本服務根目錄的路徑與原始查詢字串（保留原本的 URL 編碼，
 * OData 的 $filter 等參數才能原樣轉給 TDX）
 */
function parseRequestPath()
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $pos = strpos($uri, '?');
    $path = $pos === false ? $uri : substr($uri, 0, $pos);
    $query = $pos === false ? '' : substr($uri, $pos + 1);

    // 部署在子目錄（例如 /tdx/index.php）時去掉目錄前綴
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '') {
        if ($path === $base) {
            $path = '/';
        } elseif (strpos($path, $base . '/') === 0) {
            $path = substr($path, strlen($base));
        }
    }
    if ($path === '' || $path === '/index.php') {
        $path = '/';
    }

    return [encodeUnsafeChars($path), encodeUnsafeChars($query)];
}

/** 把空白、控制字元與非 ASCII 位元組補做 percent-encoding，避免組出不合法的 URL */
function encodeUnsafeChars($s)
{
    return preg_replace_callback('/[\x00-\x20\x7f-\xff]/', function ($m) {
        return rawurlencode($m[0]);
    }, $s);
}

function isValidApiPath($path)
{
    if (strpos($path, '/api/') !== 0 || strpos($path, '//') !== false || strpos($path, '\\') !== false) {
        return false;
    }
    // 擋掉 . 與 .. 路徑片段（包含 URL 編碼形式）
    return !preg_match('#(^|/)\.{1,2}(/|$)#', rawurldecode($path));
}

/**
 * 取得請求來源：以 Origin header 為準；沒有 Origin 時退而使用 Referer。
 * 回傳 ['origin' => 'https://host:port', 'host' => 'host']；
 *   Origin: null（本機檔案 file://、沙盒 iframe）→ host 為 'null'
 *   沒有 Origin 也沒有 Referer                   → host 為 ''（空字串）
 *   無法解析（非 http/https 等）                 → 回傳 null，一律拒絕
 * 是否放行由 isAllowedHost() 對照設定（custom/libs/config.json，沒寫的用預設值）的 ALLOWED_ORIGINS 決定。
 */
function getRequestOrigin()
{
    $raw = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($raw === '') {
        $raw = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
    }
    // 直接從硬碟開啟的 HTML（file://）或沙盒 iframe：瀏覽器送 Origin: null
    if (strtolower($raw) === 'null') {
        return ['origin' => 'null', 'host' => 'null'];
    }
    // 完全沒有 Origin 與 Referer：部分瀏覽器開本機檔案、直接在網址列開、curl／Node 等
    if ($raw === '') {
        return ['origin' => '', 'host' => ''];
    }

    $u = parse_url($raw);
    if (!$u || empty($u['host']) || !in_array(strtolower($u['scheme'] ?? ''), ['http', 'https'], true)) {
        return null;
    }
    $host = strtolower(rtrim($u['host'], '.'));

    return [
        'origin' => strtolower($u['scheme']) . '://' . $host . (isset($u['port']) ? ':' . $u['port'] : ''),
        'host'   => $host,
    ];
}

function isAllowedHost($host)
{
    foreach (ALLOWED_ORIGINS as $pattern) {
        $pattern = strtolower($pattern);
        if (strpos($pattern, '*.') === 0) {
            $suffix = substr($pattern, 1); // '.example.com'
            if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        } elseif ($host === $pattern) {
            return true;
        }
    }
    return false;
}

/**
 * CORS 全面開放：任何網頁都能呼叫、也讀得到回應（包含 403 等錯誤訊息）。
 * 不使用 cookie，所以用 * 即可；Allow-Headers 回應前端實際要求的 header（Authorization 不在 * 的涵蓋範圍內）。
 * 回應不隨 Origin 改變，因此不需要 Vary: Origin，瀏覽器快取也不會跨網域用錯。
 */
function sendCorsHeaders()
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, POST, OPTIONS');
    // 前端帶的 header（例如舊 ptx.js 的 Authorization、X-Date）都會被 proxy 忽略，所以一律放行
    $reqHeaders = preg_replace('/[^A-Za-z0-9\-, ]/', '', $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '');
    header('Access-Control-Allow-Headers: ' . ($reqHeaders !== '' ? $reqHeaders : 'Authorization, X-TDX-Authorization, X-Date, Content-Type, Accept'));
    header('Access-Control-Max-Age: 86400');
}
