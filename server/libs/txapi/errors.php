<?php
/**
 * 錯誤代碼表（ERROR 表）：errorCode → HTTP 狀態碼與 errorMsg。前端依 errorCode 判斷，errorMsg 可直接顯示（中文）。
 * 訊息裡的 {名稱} 由 appError() 代入；/txapi/errors 回傳整張表（JSON）。
 *
 * 回應格式：{"ok": false, "errorCode": 42901, "errorMsg": "…", "error": "…（同 errorMsg，與其他錯誤回應相容）", …其他欄位}
 */

const APP_ERRORS = [
    // 400：查詢時間範圍
    40001 => ['http' => 400, 'name' => 'QUERY_RANGE_TOO_LONG',
              'msg' => '查詢時間範圍最長 {max} 分鐘：end − start 為 {range} 分鐘，請縮短範圍'],
    40002 => ['http' => 400, 'name' => 'FOLLOW_RANGE_TOO_LONG',
              'msg' => '前後班次的時間範圍最長 {max} 分鐘：before＋after 為 {range} 分鐘，請縮短範圍'],
    40003 => ['http' => 400, 'name' => 'FOLLOW_ALL_DAY_DISABLED',
              'msg' => '不提供全天查詢（all=1）：時間範圍最長 {max} 分鐘，請改用 before／after 或 prev／next'],
    40010 => ['http' => 400, 'name' => 'ADMIN_INVALID_INPUT',
              'msg' => '輸入有誤：{detail}'],
    // 400：客端 API（public_、app_）的參數
    40020 => ['http' => 400, 'name' => 'APP_PARAM_INVALID',
              'msg' => '參數 {param} 有誤：{detail}'],
    40021 => ['http' => 400, 'name' => 'APP_PARAM_REQUIRED',
              'msg' => '缺少參數 {param}：{detail}'],
    40022 => ['http' => 400, 'name' => 'APP_FUNC_UNKNOWN',
              'msg' => '沒有這個功能：{func}'],
    40023 => ['http' => 400, 'name' => 'APP_ROUTE_INVALID',
              'msg' => '路由代碼有誤：{detail}'],
    // 401／403：後臺身分與權限（/txapi/admin_ 開頭的 action）
    40101 => ['http' => 401, 'name' => 'ADMIN_AUTH_REQUIRED',
              'msg' => '需要後臺身分：請先登入後臺，或帶 Authorization: Bearer <PAT>'],
    40102 => ['http' => 401, 'name' => 'ADMIN_LOGIN_FAILED',
              'msg' => '帳號或密碼錯誤'],
    40301 => ['http' => 403, 'name' => 'ADMIN_PERMISSION_DENIED',
              'msg' => '沒有 {route} 的{mode}權限'],
    40302 => ['http' => 403, 'name' => 'ADMIN_CSRF_INVALID',
              'msg' => '登入狀態的寫入動作需要正確的 X-CSRF-Token 標頭，請重新整理後臺頁面後再試'],
    40303 => ['http' => 403, 'name' => 'IP_BLOCKED',
              'msg' => '這個 IP（{ip}）已被封鎖'],
    40401 => ['http' => 404, 'name' => 'ADMIN_NOT_FOUND',
              'msg' => '找不到這個{what}'],
    40402 => ['http' => 404, 'name' => 'ACTION_QUERY_UNSUPPORTED',
              'msg' => '不支援 /?action= 寫法，請用 /txapi/<action>[/<func>]'],
    40420 => ['http' => 404, 'name' => 'APP_NOT_FOUND',
              'msg' => '找不到{what}：{id}'],
    40501 => ['http' => 405, 'name' => 'ADMIN_POST_REQUIRED',
              'msg' => '這個動作要用 POST'],
    40901 => ['http' => 409, 'name' => 'ADMIN_CONFLICT',
              'msg' => '無法完成：{detail}'],
    // 410：管理動作已搬到 admin_ 開頭的 action
    // 429：同一 IP 查詢次數
    42901 => ['http' => 429, 'name' => 'RATE_LIMIT_PER_MINUTE',
              'msg' => '查詢太頻繁：同一 IP 每分鐘最多 {limit} 次，請 {retry} 秒後再試'],
    42902 => ['http' => 429, 'name' => 'RATE_LIMIT_PER_HOUR',
              'msg' => '查詢太頻繁：同一 IP 每小時最多 {limit} 次，請 {retry} 秒後再試'],
    42903 => ['http' => 429, 'name' => 'ADMIN_LOGIN_RATE_LIMIT',
              'msg' => '登入失敗太多次：同一 IP 15 分鐘內最多 {max} 次，請 {retry} 秒後再試'],
    // 503：後臺無法初始化
    50301 => ['http' => 503, 'name' => 'ADMIN_SECRET_MISSING',
              'msg' => '後臺需要 APP_SECRET_KEY，但無法寫入 custom/libs/config.json；請手動加上這一項後再試'],
    50320 => ['http' => 503, 'name' => 'APP_DATA_UNAVAILABLE',
              'msg' => '離線資料還沒準備好：{detail}'],
];

/** 依錯誤代碼回應並結束；$vars 代入訊息的 {名稱}，$extra 併入回應 */
function appError($code, array $vars = [], array $extra = [])
{
    $e = APP_ERRORS[$code] ?? ['http' => 500, 'name' => 'UNKNOWN', 'msg' => 'Unknown error code ' . $code];
    $msg = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0];
    }, $e['msg']);
    sendJson($e['http'], ['ok' => false, 'errorCode' => $code, 'errorName' => $e['name'], 'errorMsg' => $msg, 'error' => $msg] + $extra);
}

/** /txapi/errors：錯誤代碼表 */
function actionErrors()
{
    $list = [];
    foreach (APP_ERRORS as $code => $e) {
        $list[] = ['errorCode' => $code, 'errorName' => $e['name'], 'http' => $e['http'], 'errorMsg' => $e['msg']];
    }
    sendJson(200, ['ok' => true, 'action' => 'errors', 'count' => count($list), 'errors' => $list,
                   'note' => 'errorMsg 的 {名稱} 在實際回應中會代入數值；限制設定在 custom/libs/config.json（QUERY_RATE_LIMIT_PER_MINUTE、QUERY_RATE_LIMIT_PER_HOUR、QUERY_MAX_RANGE_MINUTES）']);
}
