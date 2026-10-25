<?php
/**
 * txapi（TimeLink eXecute API）：本站自訂 API 的入口與路由。
 *
 *   /txapi/<action>                 例如 /txapi/readme、/txapi/status
 *   /txapi/<action>/<func>?參數     例如 /txapi/transfer/route?from=…&to=…、/txapi/admin_transfer/learn
 *   /txapi                          action 清單（JSON）
 * 其餘參數一律放網址參數。/?action= 寫法不再支援（回 404，錯誤代碼 40402）。
 * TDX 原樣轉發在 /api/（libs/proxy.php），兩者不重疊。
 *
 * 命名：需要後臺身分（面板登入、PAT）的 action 一律以 admin_ 開頭，一看就知道 Bearer 帶的是後臺 PAT。
 *       客端用的 action：public_ 不分身分（基礎資料、可快取），app_ 依 Authorization 判斷 Guest／Member（libs/txapi/app/）。
 * 新增功能：在 libs/txapi/ 寫一支模組定義處理函式，由這支 require，再把 action 名稱對應到函式名加進 APP_ACTIONS。
 *
 * 模組（libs/txapi/）：errors、querylimit、docs（readme、status）、monitor、download、finderdb、finder、sqlitebrowse、
 * transfer/、admin/（帳號、權限、PAT、流量、設定、快取管理）。TDX 轉發本身（proxy、tdx、railcache、ratelimit）在 libs/。
 */

require_once __DIR__ . '/errors.php';         // 錯誤代碼表（APP_ERRORS）
require_once __DIR__ . '/querylimit.php';     // 自訂查詢 API 的同一 IP 次數限制
require_once __DIR__ . '/docs.php';           // readme、status
require_once __DIR__ . '/monitor.php';        // monitor（面板公開資料）
require_once __DIR__ . '/download.php';       // download、admin_download
require_once __DIR__ . '/finderdb.php';       // download → SQLite、admin_download_to_sqlite
require_once __DIR__ . '/finder.php';         // finder
require_once __DIR__ . '/sqlitebrowse.php';   // sqlite（唯讀瀏覽）
require_once __DIR__ . '/transfer/transfer.php';   // transfer、admin_transfer
require_once __DIR__ . '/admin/actions.php';  // admin_auth、admin_accounts、admin_settings、admin_finder、admin_traffic、admin_cache
require_once __DIR__ . '/app/app.php';        // 客端 API：public_（不分身分的基礎資料）、app_（Guest／Member）

const TXAPI_PREFIX = '/txapi';

const APP_ACTIONS = [
    'readme'    => 'actionReadme',      // Markdown 操作文件
    'status'    => 'actionStatus',      // 健康檢查（與根路徑相同）
    'ratelimit' => 'actionRateLimit',   // 查詢目前金鑰的 TDX 流量限制與使用量
    'monitor'   => 'actionMonitor',     // 監控面板用的公開資訊（服務狀態、流量、快取容量／設定／清單）
    'download'  => 'actionDownload',    // 靜態資料下載：清單、檔案下載、同步（說明見 /txapi/download）
    'finder'    => 'actionFinder',      // 從 SQLite 查詢已下載的靜態資料：車站時刻、列車時刻（說明見 /txapi/finder）
    'sqlite'    => 'actionSqlite',      // SQLite 資料庫唯讀瀏覽：資料表、結構、篩選與分頁（說明見 /txapi/sqlite）
    'transfer'  => 'actionTransfer',    // 軌道轉乘：轉乘群組、路網結構搜尋、時刻表轉乘查詢（說明見 /txapi/transfer）
    'errors'    => 'actionErrors',      // 錯誤代碼表（errorCode → errorMsg）

    // 客端 API：public_ 不分身分、可快取；app_ 依 Authorization 判斷 Guest／Member（說明見 /txapi/readme）
    'public_station' => 'actionPublicStation',   // 車站清單、站名搜尋、單一車站
    'public_line'    => 'actionPublicLine',      // 路線清單、路線站序
    'public_info'    => 'actionPublicInfo',      // 系統、營運日與資料更新時間、路由代碼解析
    'app_trip'       => 'actionAppTrip',         // 起訖站間：直達時刻表、轉乘路由、轉乘列車組合
    'app_station'    => 'actionAppStation',      // 單站時刻表、即將發車
    'app_train'      => 'actionAppTrain',        // 列車各站時刻
    'app_line'       => 'actionAppLine',         // 路線時刻表

    // 後臺：admin_ 開頭的 action 都要後臺身分（後臺登入或 Authorization: Bearer <PAT>）
    'admin_auth'               => 'actionAdminAuth',             // 登入、登出、目前身分、自己的密碼與 PAT
    'admin_accounts'           => 'actionAdminAccounts',         // 帳號、角色與權限、登入紀錄、所有 PAT
    'admin_settings'           => 'actionAdminSettings',         // 設定管理：檢視、編輯、整包下載 custom/
    'admin_cache'              => 'actionAdminCache',            // 快取統計、清單、清除
    'admin_download'           => 'actionAdminDownload',         // 下載同步、強制更新、清理
    'admin_download_to_sqlite' => 'actionAdminDownloadToSqlite', // finder 資料庫狀態與重建
    'admin_transfer'           => 'actionAdminTransfer',         // 轉乘群組產生、加速清單演算、產生檔舊版與換回
    'admin_finder'             => 'actionAdminFinder',           // 面板「查詢功能」「查詢測試」
    'admin_traffic'            => 'actionAdminTraffic',          // 各 IP 流量、封鎖與解鎖
];

/** txapi 網址：txapiUrl('transfer', 'route', ['from' => …]) → /txapi/transfer/route?from=… */
function txapiUrl($action, $func = null, array $params = [])
{
    return TXAPI_PREFIX . '/' . $action . ($func !== null && $func !== '' ? '/' . $func : '') . ($params ? '?' . http_build_query($params) : '');
}

/** 這次請求的路徑（去掉查詢字串與部署子目錄） */
function txapiRequestPath()
{
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $path = rawurldecode(explode('?', $uri, 2)[0]);
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if ($base !== '' && strpos($path, $base . '/') === 0) {
        $path = substr($path, strlen($base));
    }
    return $path;
}

/**
 * 路徑是 /txapi 開頭時解析成 [action, func]（func 可為 null；/txapi 本身 action 為 ''）；不是 txapi 路徑回 null。
 * 格式不對（超過三層、含不允許的字元）回 false
 */
function txapiResolve()
{
    $path = txapiRequestPath();
    if ($path !== TXAPI_PREFIX && strpos($path, TXAPI_PREFIX . '/') !== 0) {
        return null;
    }
    $rest = trim(substr($path, strlen(TXAPI_PREFIX)), '/');
    if ($rest === '') {
        return ['', null];
    }
    if (!preg_match('#^([A-Za-z0-9_]+)(?:/([A-Za-z0-9_]+))?$#', $rest, $m)) {
        return false;
    }
    return [strtolower($m[1]), isset($m[2]) ? strtolower($m[2]) : null];
}

/** GET /txapi：action 清單 */
function txapiIndex()
{
    $list = [];
    foreach (APP_ACTIONS as $name => $fn) {
        $list[$name] = TXAPI_PREFIX . '/' . $name;
    }
    sendJson(200, ['ok' => true, 'service' => 'txapi', 'description' => 'TimeLink eXecute API：/txapi/<action>[/<func>]?參數',
                   'actions' => $list, 'readme' => TXAPI_PREFIX . '/readme', 'errors' => TXAPI_PREFIX . '/errors']);
}

/**
 * 入口：/txapi/<action>[/<func>] 由 proxyRun() 轉來（$action＝null，從路徑解析）；
 * ?action=<action> 由 index.php 轉進來，一律回 404（40402）
 */
function actionRun($action = null)
{
    header('X-Content-Type-Options: nosniff');

    // 自訂功能是公開資訊或自行驗證密碼，任何來源都可呼叫；CORS 一律開放
    sendCorsHeaders();
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $r = txapiResolve();
    if ($r === false) {
        sendJson(404, ['ok' => false, 'error' => 'Invalid txapi path: use /txapi/<action>[/<func>] and put other parameters in the query string',
                       'index' => TXAPI_PREFIX]);
    }
    if ($r !== null) {
        list($action, $func) = $r;
        if ($action === '') {
            txapiIndex();
        }
        if ($func !== null) {
            $q = strtolower(trim((string) ($_GET['func'] ?? '')));
            if ($q !== '' && $q !== $func) {
                sendJson(400, ['ok' => false, 'error' => 'func is given twice (path: ' . $func . ', query: ' . $q . ')']);
            }
            $_GET['func'] = $func;
        }
        $_GET['action'] = $action;
    } else {
        appError(40402);   // /?action= 寫法不支援，一律用 /txapi/<action>[/<func>]
    }
    $action = strtolower(trim((string) $action));
    if (!isset(APP_ACTIONS[$action]) || !function_exists(APP_ACTIONS[$action])) {
        sendJson(400, [
            'ok'      => false,
            'error'   => 'Unknown action: ' . $action,
            'actions' => array_keys(APP_ACTIONS),
            'readme'  => TXAPI_PREFIX . '/readme',
        ]);
    }
    trafficGate($action, txapiRequestPath() . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));   // 流量紀錄、IP 封鎖（libs/txapi/admin/traffic.php）
    queryLimitCheck($action);   // 同一 IP 查詢次數（transfer、finder）
    call_user_func(APP_ACTIONS[$action]);
}
