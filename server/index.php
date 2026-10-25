<?php
/**
 * TimeLink — 進入點（TDX Proxy 服務與 /txapi/ 查詢服務）
 *
 * 前端改打本服務，路徑與 TDX 主機完全相同，例如：
 *   https://api.example.com/api/basic/v2/Rail/TRA/Station?$format=JSON
 *   → https://tdx.transportdata.tw/api/basic/v2/Rail/TRA/Station?$format=JSON
 * 自訂功能（TimeLink eXecute API）走 /txapi/<action>[/<func>]?參數，例如 /txapi/transfer/route?from=…&to=…
 *
 * 本檔只負責載入 libs/ 底下的程式再交棒出去，所有邏輯都在 libs/ 內：
 *   libs/config.php    設定項目（清單與預設值在 libs/config.json）；實際值在 custom/libs/config.json（私人，不進版本控制）
 *   libs/http.php      curl 與 JSON 回應的共用函式
 *   libs/sqlite.php    SQLite 資料存取元件（SqliteDao），資料庫放在根目錄 sqlite/（不備份、禁止網址存取）
 *   libs/tdx.php       TDX access token 與上游轉發
 *   libs/proxy.php     路由、來源檢查、CORS，入口是 proxyRun()
 *   libs/ratelimit.php TDX 流量限制紀錄與查詢
 *   libs/railcache.php 軌道、公車靜態資料長期快取
 *   libs/txapi/        自訂功能 /txapi/（txapi.php 是路由與載入器，入口 actionRun()）：
 *                      說明文件、監控、下載、finder 查詢、SQLite 瀏覽、轉乘（transfer/）、後臺（admin/）
 *
 * 要擴充功能就在 libs/ 新增檔案，再把檔名加進下面的 APP_LIBS 清單即可；/txapi/ 的功能加在 libs/txapi/txapi.php。
 */

const APP_LIBS_DIR = __DIR__ . '/libs';

// 載入順序有意義：config 先，proxy 最後
const APP_LIBS = ['config.php', 'http.php', 'sqlite.php', 'tdx.php', 'proxy.php', 'ratelimit.php', 'railcache.php', 'txapi/txapi.php'];

foreach (APP_LIBS as $lib) {
    $path = APP_LIBS_DIR . '/' . $lib;
    if (!is_file($path)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['Message' => 'Proxy library is missing: libs/' . $lib]);
        exit;
    }
    require $path;
}

// /txapi/ 路徑（或舊寫法 ?action=）走自訂功能，否則照常轉發 TDX
if (txapiResolve() !== null || isset($_GET['action'])) {
    actionRun();
} else {
    proxyRun();
}
