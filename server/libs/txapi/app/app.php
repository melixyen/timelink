<?php
/**
 * 客端 API 的共用層（public_ 與 app_ 開頭的 action）。
 *
 *   public_  任何人、不看身分：結果只隨離線資料更新而變，回應可被 CDN 與瀏覽器快取（ETag、Cache-Control: public）
 *   app_     客端網站與 App：同一個路徑，依 Authorization 判斷 Guest／Member／後臺身分，之後依等級套用限制
 *
 * 共用的回應格式：{"ok": true, "action", "func", "data": …, "meta": {"tier", "dataUpdatedAt", …}}；
 * 失敗沿用 errors.php 的 errorCode／errorName／errorMsg。欄位名稱沿用 TDX 的大寫開頭寫法（StationUID、LineUID…）。
 *
 * 模組：
 *   app.php         身分、回應、參數（extra 等）、子功能分派
 *   trainclass.php  列車的慢車／快車分類與 extra 判斷（設定在 transfer_seed.json 的 ClassChange）
 *   route.php       路由代碼（route）的解析、產生、驗證與顯示文字
 *   public.php      public_station、public_line、public_info
 *   timetable.php   核心 B（點對點時刻表）與 app_trip/direct、app_station、app_train
 *   line.php        app_line/timetable（路線時刻表）
 *   supplement.php  路由補充資料（transfer_route_supplements.json：區間車接力、轉對號、快慢車換車）
 *   routing.php     核心 A（路由演算法：路網層＋同系統快慢車展開）
 *   journey.php     組合：app_trip/routes、route_trains、journeys（串接與分類排序）
 */

require_once __DIR__ . '/trainclass.php';
require_once __DIR__ . '/route.php';
require_once __DIR__ . '/public.php';
require_once __DIR__ . '/timetable.php';
require_once __DIR__ . '/routing.php';
require_once __DIR__ . '/supplement.php';
require_once __DIR__ . '/journey.php';
require_once __DIR__ . '/line.php';

/** 會員 token 的前綴（會員系統還沒做；後臺 PAT 是 ADMIN_PAT_PREFIX） */
const APP_MEMBER_TOKEN_PREFIX = 'tlm_';

/** extra 位元：排除哪些列車（0＝不排除） */
const APP_EXTRA_NO_EXPRESS = 1;   // 不搭臺鐵對號快車（莒光、自強以上）
const APP_EXTRA_NO_HSR = 2;       // 不搭高鐵
const APP_EXTRA_MAX = 3;

/** public_ 回應的快取秒數（離線資料一天更新一次左右；ETag 讓過期後的重新驗證不必重傳） */
// APP_PUBLIC_MAX_AGE（public_ 回應的 Cache-Control 秒數）：設定項目（libs/config.json）

/** 前端要讀得到的回應標頭（CORS） */
const APP_EXPOSE_HEADERS = 'X-Auth, X-Tier, ETag, X-Query-Limit-Minute, X-Query-Remaining-Minute, X-Query-Limit-Hour, X-Query-Remaining-Hour, X-TDX-Credential';

/**
 * 這次請求的身分：['tier' => guest|member|admin, 'auth' => none|invalid|member|admin]
 *   - 沒帶 token：guest（X-Auth: none）
 *   - 後臺登入或有效的後臺 PAT：admin（不受客端限制）
 *   - 帶了 token 但無效、過期，或會員 token（會員系統還沒開放）：guest（X-Auth: invalid），前端據此提示重新登入
 */
function appIdentity()
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $h = trim(function_exists('requestAuthorizationHeader') ? requestAuthorizationHeader() : (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    $hasToken = $h !== '' || trim((string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '')) !== '';
    if (function_exists('adminCurrent') && adminCurrent() !== null) {
        return $id = ['tier' => 'admin', 'auth' => 'admin'];
    }
    return $id = ['tier' => 'guest', 'auth' => $hasToken ? 'invalid' : 'none'];
}

/** 每個 public_／app_ 回應共用的標頭 */
function appHeaders($public)
{
    header('X-Content-Type-Options: nosniff');
    header('Access-Control-Expose-Headers: ' . APP_EXPOSE_HEADERS);
    if (!$public) {
        $id = appIdentity();
        header('X-Auth: ' . $id['auth']);
        header('X-Tier: ' . $id['tier']);
    }
}

/** 目前這次請求的 action 與 func（appDispatch 設定） */
function appContext($action = null, $func = null)
{
    static $ctx = ['action' => '', 'func' => ''];
    if ($action !== null) {
        $ctx = ['action' => $action, 'func' => $func];
    }
    return $ctx;
}

/** 離線資料的建置時間（ISO 8601）；沒有資料庫回 null */
function appDataUpdatedAt(?SqliteDao $db = null)
{
    static $v = false;
    if ($v === false) {
        $v = null;
        try {
            $db = $db ?? SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
            $v = json_decode((string) $db->value("SELECT value FROM _meta WHERE key = '_built_at'"), true) ?: null;
        } catch (Throwable $e) {
            $v = null;
        }
    }
    return $v;
}

/**
 * 成功回應並結束。public_ 加上快取標頭與 ETag（If-None-Match 相同回 304）；app_ 一律不快取（結果可能依身分不同）
 */
function appSend($data, array $meta = [])
{
    $ctx = appContext();
    $public = strpos($ctx['action'], 'public_') === 0;
    appHeaders($public);
    $meta = ($public ? [] : ['tier' => appIdentity()['tier']]) + ['dataUpdatedAt' => appDataUpdatedAt()] + $meta;
    $body = json_encode(['ok' => true, 'action' => $ctx['action'], 'func' => $ctx['func'], 'data' => $data, 'meta' => $meta],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Type: application/json; charset=utf-8');
    if ($public) {
        $etag = '"' . substr(sha1($body), 0, 20) . '"';
        header('Cache-Control: public, max-age=' . APP_PUBLIC_MAX_AGE);
        header('ETag: ' . $etag);
        $inm = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && in_array($etag, array_map('trim', explode(',', $inm)), true)) {
            http_response_code(304);
            exit;
        }
    } else {
        header('Cache-Control: no-store');
    }
    http_response_code(200);
    echo $body;
    exit;
}

/** 錯誤回應（errors.php 的代碼）；加上客端共用標頭 */
function appFail($code, array $vars = [], array $extra = [])
{
    $ctx = appContext();
    appHeaders(strpos($ctx['action'], 'public_') === 0);
    appError($code, $vars, $extra);
}

/**
 * 子功能分派：$funcs = ['list' => [處理函式, 說明, 參數說明, 範例], …]。
 * 沒帶 func 回功能清單（JSON），不認得的 func 回 40022
 */
function appDispatch($action, array $funcs)
{
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    appContext($action, $func);
    if ($func === '') {
        $list = [];
        foreach ($funcs as $name => $f) {
            $list[] = ['func' => $name, 'url' => txapiUrl($action, $name), 'desc' => $f[1], 'params' => $f[2], 'example' => $f[3]];
        }
        appSend(['funcs' => $list, 'readme' => txapiUrl('readme')]);
    }
    if (!isset($funcs[$func])) {
        appFail(40022, ['func' => $action . '/' . $func], ['funcs' => array_keys($funcs)]);
    }
    call_user_func($funcs[$func][0]);
}

// ================================================================
// 參數

/** 字串參數（去掉前後空白）；沒給回 $default */
function appParam($name, $default = null)
{
    $v = $_GET[$name] ?? null;
    if (is_array($v)) {
        appFail(40020, ['param' => $name, 'detail' => '只能給一個值']);
    }
    $v = $v === null ? '' : trim((string) $v);
    return $v === '' ? $default : $v;
}

/** 必填參數 */
function appParamRequired($name, $hint)
{
    $v = appParam($name);
    if ($v === null) {
        appFail(40021, ['param' => $name, 'detail' => $hint]);
    }
    return $v;
}

/** 整數參數（含上下限）；沒給回 $default */
function appParamInt($name, $default, $min, $max)
{
    $v = appParam($name);
    if ($v === null) {
        return $default;
    }
    if (!preg_match('/^-?\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
        appFail(40020, ['param' => $name, 'detail' => "要是 {$min}～{$max} 的整數"]);
    }
    return (int) $v;
}

/** 逗號分隔的清單參數（大寫、去重） */
function appParamList($name)
{
    $v = appParam($name);
    if ($v === null) {
        return [];
    }
    return array_values(array_unique(array_filter(array_map('trim', explode(',', strtoupper($v))), 'strlen')));
}

/** extra：排除的列車等級位元（0～3，預設 0＝不排除）。bit 1＝不搭臺鐵對號快車（莒光、自強以上），bit 2＝不搭高鐵 */
function appParamExtra()
{
    $v = appParam('extra', '0');
    if (!preg_match('/^[0-9]$/', $v) || (int) $v > APP_EXTRA_MAX) {
        appFail(40020, ['param' => 'extra', 'detail' => '要是 0～3：0＝不排除，1＝不搭臺鐵對號快車（莒光、自強以上），2＝不搭高鐵，3＝兩者都不搭']);
    }
    return (int) $v;
}

/** 系統代碼清單參數（system=TRA,TRTC）；不認得的回 40020 */
function appParamSystems($name = 'system')
{
    $list = appParamList($name);
    $all = array_merge(FINDER_TRAIN_SYSTEMS, FINDERDB_METRO_SYSTEMS);
    foreach ($list as $s) {
        if (!in_array($s, $all, true)) {
            appFail(40020, ['param' => $name, 'detail' => '不認得的系統代碼 ' . $s . '，可用：' . implode('、', $all)]);
        }
    }
    return $list;
}

// ================================================================
// 資料

/** 開啟離線資料庫（唯讀）與轉乘群組；還沒準備好回 503（50320） */
function appDb(?array $tables = null)
{
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    try {
        finderDbEnsure($tables ?? TRANSFER_TABLES);
        $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
    } catch (FinderDbIncomplete $e) {
        appFail(50320, ['detail' => '靜態資料還沒下載完整'], ['missing' => $e->missing]);
    } catch (Throwable $e) {
        appFail(50320, ['detail' => '離線資料庫無法開啟']);
    }
    if (!transferLoadGroups()) {
        appFail(50320, ['detail' => '轉乘群組還沒產生']);
    }
    return $db;
}

/** 路網模型（車站名稱、路線站序、轉乘群組、步行分鐘…，見 transfer/network.php） */
function appModel()
{
    return transferNetworkModel(appDb());
}

/** 車站參數 → StationUID（接受 TDX 寫法與大小寫差異）；找不到回 404（40420） */
function appStationParam($name, $hint = '車站代碼 StationUID，例如 TRA-1000')
{
    $v = appParamRequired($name, $hint);
    $p = finderParseUID($v);
    $row = $p ? finderStation(appDb(), $p[0], $p[1]) : null;
    if (!$row) {
        appFail(40420, ['what' => '車站', 'id' => $v]);
    }
    return $row['uid'];
}

/** 路線參數 → LineUID（不分大小寫）；找不到回 404（40420） */
function appLineParam($name, $hint = '路線代碼 LineUID，例如 TRA-WL、TRTC-BL')
{
    $v = appParamRequired($name, $hint);
    $m = appModel();
    foreach (array_keys($m['lineStations']) as $lu) {
        if (strcasecmp($lu, $v) === 0) {
            return $lu;
        }
    }
    appFail(40420, ['what' => '路線', 'id' => $v]);
}
