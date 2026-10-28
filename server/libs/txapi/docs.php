<?php
/**
 * 自訂功能的 Markdown 操作文件。
 *
 * 由 /txapi/readme 輸出。網站根路徑（GET /）維持回傳健康檢查 JSON，
 * 並在其中附上 readme 連結，讓 AI agent 或開發者知道去哪裡讀文件。
 * 文件中的快取時間、開關、允許網域都是即時讀取目前設定，改 custom/libs/config.json 後會自動反映（私人設定只顯示預設值）。
 * 不會輸出任何金鑰或密碼。
 */

function docsFmtSeconds($s)
{
    $s = (int) $s;
    if ($s % 86400 === 0) {
        return ($s / 86400) . ' 天';
    }
    if ($s % 3600 === 0) {
        return ($s / 3600) . ' 小時';
    }
    if ($s % 60 === 0) {
        return ($s / 60) . ' 分鐘';
    }
    return $s . ' 秒';
}

function docsRender()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $po = configPublicOrigins();
    $origins = implode('、', array_map(function ($o) {
        if ($o === 'null') return '`null`（直接從硬碟開啟的 HTML 等）';
        if ($o === '') return '（沒有 Origin／Referer）';
        return '`' . $o . '`';
    }, $po['shown'])) ?: '（無）';
    if ($po['customized']) {
        $origins .= '，以及設定在 custom 內的自訂網域（不公開顯示）';
    }
    $hasCache = function_exists('cachePolicy');
    $cacheOn = $hasCache && !railCacheDisabled();

    $actions = '';
    foreach (APP_ACTIONS as $name => $fn) {
        $actions .= '| `' . $name . '` | `/txapi/' . $name . '` | ' . (function_exists($fn) ? '可用' : '未載入') . " |\n";
    }

    $policy = '';
    if ($hasCache) {
        $rs = cachePolicy('rail', 'static');
        $rt = cachePolicy('rail', 'timetable');
        $bs = cachePolicy('bus', 'static');
        $bt = cachePolicy('bus', 'timetable');
        $daily = cacheSetting('RAIL_CACHE_DAILY', 3600);
        $past = cacheSetting('RAIL_CACHE_DAILY_PAST', 365 * 86400);
        $f = 'docsFmtSeconds';
        $policy = <<<MD
| 模式 | 分類 | 新鮮期（過了向 TDX 確認） | 最長保存（過了整份重抓） | config 常數 |
| --- | --- | --- | --- | --- |
| 軌道 | 靜態資料 | {$f($rs['fresh'])} | {$f($rs['max'])} | `RAIL_CACHE_STATIC_FRESH`、`RAIL_CACHE_STATIC_MAX` |
| 軌道 | 定期時刻表 | {$f($rt['fresh'])} | {$f($rt['max'])} | `RAIL_CACHE_TIMETABLE` |
| 軌道 | Today 時刻表 | 到台灣時間午夜 | 同左 | — |
| 軌道 | 今天與未來日期時刻表 | {$f($daily)} | 同左 | `RAIL_CACHE_DAILY` |
| 軌道 | 過去日期時刻表 | {$f($past)} | 同左 | `RAIL_CACHE_DAILY_PAST` |
| 公車 | 靜態資料 | {$f($bs['fresh'])} | {$f($bs['max'])} | `BUS_CACHE_STATIC_FRESH`、`BUS_CACHE_STATIC_MAX` |
| 公車 | 班表 | {$f($bt['fresh'])} | {$f($bt['max'])} | `BUS_CACHE_TIMETABLE` |
MD;
    }

    $list = function ($mode, $cat) {
        if (!defined('CACHE_RULES')) {
            return '—';
        }
        $names = array_keys(array_filter(CACHE_RULES[$mode], function ($c) use ($cat) { return $c === $cat; }));
        return implode('、', array_map(function ($n) { return '`' . $n . '`'; }, $names));
    };
    $railStatic = $list('rail', 'static');
    $railTime = $list('rail', 'timetable');
    $railDaily = $list('rail', 'daily');
    $busStatic = $list('bus', 'static');
    $busTime = $list('bus', 'timetable');
    $cacheState = $hasCache ? ($cacheOn ? '**啟用中**' : '**已停用**（`DISABLE_CACHE = true`）') : '未載入';
    $configured = isConfigured() ? '已設定' : '**未設定**';
    $product = PRODUCT_NAME;
    $service = PRODUCT_SERVICE_NAME;
    $ql = function_exists('queryLimitSettings') ? queryLimitSettings() : ['minute' => 0, 'hour' => 0, 'range' => 0];
    $qMin = queryLimitText($ql['minute']);
    $qHour = queryLimitText($ql['hour']);
    $qRange = queryLimitText($ql['range'], 'minutes');
    $depCount = APP_DEPARTURES_COUNT_DEFAULT;
    $journeyRoutes = APP_JOURNEY_ROUTES;
    $cfgTable = docsConfigTable();
    $logDays = (int) ADMIN_LOG_RETENTION_DAYS;
    $setupHours = (int) ADMIN_SETUP_HOURS;
    $trafficDays = (int) TRAFFIC_LOG_RETENTION_DAYS;
    $adminRoutes = "| 權限路由 | 讀取 | 寫入 |\n| --- | --- | --- |\n";
    foreach (ADMIN_ROUTES as $r => $x) {
        $adminRoutes .= '| `' . $r . '`（' . $x[0] . '） | ' . $x[1] . ' | ' . $x[2] . " |\n";
    }

    return <<<MD
# {$product}

{$product} 由兩部分組成：

- **{$service}**（`/api/`）：把前端對 TDX（運輸資料流通服務）的請求轉發過去，由本服務代管 access token，
  並把查過的資料存進快取以節省 TDX 用量
- **查詢服務**（`/txapi/`）：定期從 TDX 更新離線資料，建構時刻、轉乘等查詢

- TDX 金鑰：{$configured}
- 靜態資料快取：{$cacheState}

## {$service} 用法

把原本打 `https://tdx.transportdata.tw` 的網址換成 `https://{$host}`，路徑與參數完全不變：

```
https://{$host}/api/basic/v2/Rail/TRA/Station?\$format=JSON
→ https://tdx.transportdata.tw/api/basic/v2/Rail/TRA/Station?\$format=JSON
```

- 只轉發 `/api/` 開頭的路徑，方法限 GET、HEAD、POST
- **CORS 全面開放**：所有回應都帶 `Access-Control-Allow-Origin: *`，任何網頁都能呼叫、也讀得到錯誤訊息；
  preflight（OPTIONS）一律回 204
- **來源限制在伺服器端判斷**：看 `Origin`，沒有時看 `Referer`，不在允許名單內就回
  `403 {"Message":"Origin not allowed.","origin":"…"}`、不轉發。目前允許：{$origins}
- TDX 的流量限制是整把金鑰共用，可用 `/txapi/ratelimit` 查詢（只統計本服務的金鑰）

## 金鑰：用本服務的，或自帶

預設由本服務代管 TDX 金鑰，前端什麼都不用帶。想改用自己的 TDX 額度時，照原本呼叫 TDX 的方式帶上憑證即可：

| 情況 | 前端怎麼帶 | 本服務的行為 |
| --- | --- | --- |
| 用本服務的金鑰 | 不帶 `Authorization`，或 `Authorization: Bearer auto` | 用設定檔（custom/libs/config.json）的金鑰換 token 並轉發 |
| 自帶 access token | `X-TDX-Authorization: Bearer <你的 access token>`（建議），或 `Authorization: Bearer <你的 access token>` | 原樣使用你的 token 轉發；401、429 等錯誤直接回給你，不會改用本服務的金鑰 |
| 自帶金鑰換 token | POST `/auth/realms/TDXConnect/protocol/openid-connect/token`，body 帶 `grant_type=client_credentials&client_id=<ID>&client_secret=<SECRET>` | 轉給 TDX 換真的 token，原樣回傳 |
| 舊版前端換 token | 同上路徑，但不帶 `client_secret` 或值為 `auto` | 回傳假 token `tdx-proxy`，之後帶著它呼叫 API 會自動改用本服務的金鑰 |

- `Authorization` 不是 `Bearer` 格式（例如舊 PTX 的 hmac 簽章）時一律視為沒帶，改用本服務的金鑰
- `Bearer auto`、`Bearer tdx-proxy`，以及 rocptx library 未設定金鑰時預設送出的
  `Bearer FFFFFFFF-FFFF-FFFF-FFFF-FFFFFFFFFFFF`，都代表「用本服務的金鑰」；
  換 token 時 `client_secret` 為 `auto` 或這組 FFFF 預設值，也一律回傳假 token
- 因此使用 rocptx 時，只要把請求網址從 `tdx.transportdata.tw` 換成本服務即可，不需要設定金鑰或呼叫 `initToken`
- 自帶 token 的請求不計入 `/txapi/ratelimit` 的統計；可快取的資料仍會共用快取，命中時不會消耗你的額度
- 兩個標頭都有時以 `X-TDX-Authorization` 為準（值是 `auto` 等別名＝明確用本服務的金鑰）；這個標頭不會轉給 TDX
- 實際向 TDX 發出請求時，回應帶 `X-TDX-Credential`：`user`（用了你的 token）或 `server`（本服務的金鑰）；快取命中沒有呼叫 TDX 就不帶

## 自訂功能：txapi（`/txapi/`）

本站自訂的 API 都在 `/txapi/` 底下（TimeLink eXecute API），TDX 原樣轉發在 `/api/`，兩者不重疊：

```
GET https://{$host}/txapi/<action>                 例如 /txapi/readme
GET https://{$host}/txapi/<action>/<func>?參數     例如 /txapi/transfer/route?from=…&to=…
GET https://{$host}/txapi                          action 清單（JSON）
```

action 是第二層、func 是第三層（沒有 func 的 action 只有兩層），其餘參數一律放網址參數。
需要後臺身分的 action 一律以 `admin_` 開頭（帶 `Authorization: Bearer <PAT>`）。
不支援 `/?action=` 寫法（回 404，錯誤代碼 40402）。
除了 `readme` 與各 action 的說明文件（Markdown），其餘回應都是 JSON（`ok` 表示成功與否）。

| action | 網址 | 狀態 |
| --- | --- | --- |
{$actions}
### `/txapi/readme` — 本文件

```
GET https://{$host}/txapi/readme
```

回傳這份 Markdown 文件。快取時間、開關、允許網域都是即時讀取目前設定。

### `/txapi/status` — 健康檢查

```
GET https://{$host}/
GET https://{$host}/txapi/status
```

兩者相同：回傳 `service`、`configured`（是否已設定 TDX 金鑰），以及本文件的網址 `readme`。
不需要密碼，不消耗 TDX 額度。

### `/txapi/ratelimit` — 流量限制

```
GET https://{$host}/txapi/ratelimit[?probe=1]
```

每次轉發 TDX 時都會記下回應裡的限流 header，查詢時直接讀紀錄，不消耗額度。

| 參數 | 說明 |
| --- | --- |
| `probe=1` | 實際打一支很小的 TDX API 取得最新數字（消耗 1 次額度）；距上次紀錄不到 30 秒時會略過 |

回傳重點：

- `tdx.limits`：各時間窗口的 `limit`、`remaining`、`used`
- `tdx.reset_in`：窗口還有幾秒重置；`tdx.stale` 為 `true` 表示紀錄已過期、數字僅供參考
- `proxy`：今天呼叫 TDX 的次數、被限流（429）的次數、每分鐘最高呼叫數、昨天的統計

不需要密碼。

### `/txapi/monitor` — 監控資訊

```
GET https://{$host}/txapi/monitor
```

給監控面板用的公開資訊，不需要密碼，不消耗 TDX 額度，也不能清除快取：

- `service`：金鑰是否已設定、允許的網域
- `ratelimit`：與 `/txapi/ratelimit` 相同的流量紀錄
- `cache`：快取開關、各模式筆數與容量、命中統計、目前的快取時間設定，以及每一筆快取的清單

### 監控面板 `/panel/`

```
https://{$host}/panel/
```

手機優先的 Vue 3 單頁面板（CDN 載入、不需 build），讀 `/txapi/monitor` 顯示服務狀態、TDX 流量、
快取容量、快取時間設定與快取清單，可搜尋、篩選，每 60 秒自動更新。
分頁：`#/` 總覽、`#/cache` 快取統計、`#/list` 快取清單、`#/download` 下載狀態、
`#/sqlite` 資料庫（唯讀瀏覽 SQLite：資料表清單、結構、篩選、排序、分頁、關聯跳轉，資料來自 `/txapi/sqlite`）。
只顯示公開資訊，沒有登入功能。頁尾顯示 TDX 資料來源聲明與所屬單位（設定的 `SITE_NOTICE_HTML`、`ORG_NAME_HTML`）。
檔案在 `libs/html/panel/`（架構說明見其中的 `README.md`），由 `.htaccess` 對應。

### `/txapi/download` — 靜態資料下載（寫入）

```
GET https://{$host}/txapi/download                                         說明（Markdown）
GET https://{$host}/txapi/download/list?service=tdx                   可下載清單（JSON）
GET https://{$host}/txapi/download/download?service=tdx&path=/v2/Rail/TRA/Station   下載檔案
```

把 TDX 的完整靜態資料存到伺服器、以檔案下載提供，目錄結構對應 API 路徑（`download/tdx/v2/Rail/TRA/Station.json`）。
範圍為軌道轉乘查詢所需的最小基礎資料：臺鐵、高鐵、臺北捷運、新北捷運（環狀線、三鶯線、淡海輕軌、安坑輕軌）、
機場捷運、臺中捷運、高雄捷運與輕軌的車站、路線、站序與里程、轉乘、定期時刻，與捷運站間行駛時間，共 52 個檔案（約 18 MB）。
能由其他項目推導的 API 不下載，推導方式、分組與清單見 `/txapi/download` 的說明；同步在後臺 `/txapi/admin_download`（`func=sync`，分批進行）。

### `/txapi/finder` — 靜態資料查詢（讀取）

```
GET https://{$host}/txapi/finder                                           說明（Markdown）
GET https://{$host}/txapi/finder/list                                 可用功能、依賴的資料表、是否最新（JSON）
GET https://{$host}/txapi/finder/rail_station_time?station=TRA-1000&week=1     某站某星期幾全日時刻
GET https://{$host}/txapi/finder/rail_train_time?key=TRA-123                   列車與完整停靠站
GET https://{$host}/txapi/finder/rail_stations?operator=TRTC                    營運單位 → 路線 → 站序的車站清單與路線代表色
GET https://{$host}/txapi/finder/metro_travel_time?from=TRTC-BL12&to=TRTC-BL01  捷運兩站間行駛秒數（或 system= 列出累計時間表）
GET https://{$host}/txapi/finder/metro_trip_time?from=TRTC-BL12&to=TRTC-BL05&week=1  捷運兩站間每班的出發與抵達時刻
```

只從 SQLite 資料庫（`sqlite/finder.sqlite`，由 download 的檔案轉成）檢索，不讀 JSON、不呼叫 TDX、不消耗額度。
每個 func 登記它查詢的資料表，執行前先確認資料表是最新，來源檔有更新就先重建再查詢；下載資料不完整時回 503 與缺少的 path。
臺鐵、高鐵、捷運的時刻整理成同一種格式（以臺鐵 v3 欄位為基底，所有欄位一律存在、沒有值為空字串）。
車站時刻每筆帶 `TrainKey`，可再用 `rail_train_time&key=` 查該班列車；捷運沒有車次資料，列車查詢回空陣列。

### `/txapi/transfer` — 軌道轉乘

```
GET https://{$host}/txapi/transfer                                                   說明（Markdown）
GET https://{$host}/txapi/transfer/groups?station=TRA-1000                       轉乘群組（TransferGroup）；raw=1 下載完整 JSON
GET https://{$host}/txapi/transfer/network?from=TRA-0900&to=TRTC-BR22             路網結構：路線串與轉乘站（不看時刻表）
GET https://{$host}/txapi/transfer/route?from=TRA-0900&to=TRTC-BR22&week=1&start=08:00  時刻表轉乘：直通、最短、最快、最佳
GET https://{$host}/txapi/transfer/follow?week=1&time=08:00&pattern=[…]          同一路由組合的前後班次
GET https://{$host}/txapi/transfer/stations?q=台北                                車站搜尋（前 10 筆）
```

轉乘群組是可互相步行轉乘的一組車站（StationUID，跨營運單位），以及可同站換車的車站；路線以「系統-LineID」表示。
時刻表轉乘以 RAPTOR 逐輪展開臺鐵、高鐵車次與捷運整列車（各站時刻表串成），只讀 finder 資料庫與 `libs/txapi/transfer/data/*.json`。

### 查詢限制與錯誤代碼（`/txapi/transfer`、`/txapi/finder`、`public_`、`app_`）

- 同一 IP 每分鐘最多 {$qMin}、每小時最多 {$qHour}查詢（說明文件不計），超過回 HTTP 429 與 `Retry-After`；來源網域不限
- 轉乘查詢（route、follow）的出發時間範圍最長 {$qRange}
- 上限設定在 `custom/libs/config.json`（`QUERY_RATE_LIMIT_PER_MINUTE`、`QUERY_RATE_LIMIT_PER_HOUR`、`QUERY_MAX_RANGE_MINUTES`）
- 錯誤回應帶 `errorCode`、`errorName`、`errorMsg`；代碼表 `GET https://{$host}/txapi/errors`（`libs/txapi/errors.php` 的 `APP_ERRORS`）

### `/txapi/admin_download_to_sqlite` — finder 資料庫狀態與重建（後臺）

```
GET  https://{$host}/txapi/admin_download_to_sqlite                 狀態（讀取權限）
POST https://{$host}/txapi/admin_download_to_sqlite/build      重建（寫入權限）
```

把 download 的最新內容整份轉成 SQLite（先寫暫存檔，完成後一次取代舊檔，查詢中的請求不受影響）。
下載清單任何一項缺檔或不是合法 JSON 就回 409 與缺少的 `missing[].path`，不寫入。
`admin_download` 的 `func=sync` 全部完成時也會自動更新（資料沒變就不重建）。狀態見 `/txapi/finder/list`。

### `/txapi/sqlite` — SQLite 資料庫唯讀瀏覽

```
GET https://{$host}/txapi/sqlite                                            說明（Markdown）
GET https://{$host}/txapi/sqlite/tables                                資料庫與資料表清單、筆數
GET https://{$host}/txapi/sqlite/schema?db=finder&table=train          欄位、索引、關聯
GET https://{$host}/txapi/sqlite/rows?db=finder&table=train_stop&f[]=train_key:eq:TRA-123&per=50&page=1
```

監控面板 `/panel/#/sqlite` 的資料來源。只接受 GET，資料庫以唯讀方式開啟，不接受 SQL 語法：
資料庫、資料表、欄位、運算子、排序方向都以白名單驗證，篩選值一律參數綁定。
後端分頁，每頁 10、50、100、500 筆（預設 100）。瀏覽不會觸發重建。

### `/txapi/admin_cache` — 快取管理（後臺）

```
GET https://{$host}/txapi/admin_cache[?list=1]                              統計與清單（讀取權限）
GET https://{$host}/txapi/admin_cache/purge?purge=all|<路徑前綴>       清除（寫入權限）
```

| 參數 | 說明 |
| --- | --- |
| （無） | 軌道、公車分開列出筆數、占用空間、命中率、目前生效的快取時間 |
| `list=1` | 另外列出每一筆快取的路徑、大小、抓取時間、剩餘新鮮時間 |
| `purge=all` | 清除全部快取與統計 |
| `purge=<路徑前綴>` | 清除路徑以此開頭的快取，例如 `purge=/api/basic/v2/Bus/Route/City/Taipei` |

## 客端 API（`public_`、`app_` 開頭的 action）

給客端網站與 App（類似 ekitan、jorudan 的時刻與轉乘查詢）用的穩定格式，底層用 finder、transfer 的資料與演算法。

| 前綴 | 給誰 | 身分 | 規則 |
| --- | --- | --- | --- |
| `public_` | 任何人 | 不看身分 | 登不登入結果都一樣；回應帶 `Cache-Control: public` 與 `ETag`（`If-None-Match` 相同回 304） |
| `app_` | 客端網站與 App | Guest 或 Member | 同一個路徑，依 `Authorization: Bearer <token>` 判斷身分；回應帶 `X-Auth`（none／invalid／admin）、`X-Tier` |

- **自帶 TDX token**：`app_`、`public_` 的 `Authorization` 是本站的身分（會員 token、後臺 PAT），**不會**送去 TDX。需要向 TDX 取線上資料的功能
  （未來的票價、指定使用 TDX 最新時刻表等）一律看 `X-TDX-Authorization: Bearer <你的 TDX access token>`：有帶就用你的額度（被 TDX 拒絕時直接回錯誤，不改用本站金鑰），
  沒帶就用本站內建的金鑰；有呼叫 TDX 時回應帶 `X-TDX-Credential`（user／server）。與 `/api/` 共用快取，快取命中時不呼叫 TDX；TDX 失敗但有舊資料時用舊資料並註明
- 會員系統還沒開放：沒帶 token 或 token 無效都是 Guest，Guest 目前只有上面的同一 IP 查詢次數限制；後臺登入或後臺 PAT 不受限制
- 沒帶 func（例如 `/txapi/public_station`）回該 action 的功能清單（JSON），不計入查詢次數
- 成功：`{"ok": true, "action", "func", "data": …, "meta": {"dataUpdatedAt", …}}`（`app_` 的 meta 另有 `tier`）；
  失敗：`errorCode`、`errorName`、`errorMsg`（代碼 40020～40023、40420、50320 見 `/txapi/errors`）
- 欄位名稱沿用 TDX 的大寫開頭寫法（`StationUID`、`LineUID`…）

### `public_` API

```
GET https://{$host}/txapi/public_station/list?system=TRA&line=TRA-WL          車站清單（帶 line 時依站序）
GET https://{$host}/txapi/public_station/search?q=taibei&limit=10             站名搜尋（台／臺、英文、拼音、站碼）
GET https://{$host}/txapi/public_station/detail?station=TRA-1000              單一車站：路線與前後站、同站換車、轉乘群組
GET https://{$host}/txapi/public_line/list?system=TRA                         路線清單
GET https://{$host}/txapi/public_line/detail?line=TRA-WL                      路線站序、顯示區段、各站可轉乘的路線
GET https://{$host}/txapi/public_info/systems                                 系統、時刻資料性質、慢車／快車分類
GET https://{$host}/txapi/public_info/calendar                                營運日定義、資料更新時間
GET https://{$host}/txapi/public_info/route?route=…&extra=0                   解析路由代碼
```

### `app_` API（時刻）

```
GET https://{$host}/txapi/app_trip/direct?from=TRA-1000&to=TRA-1210&date=2026-10-26&start=07:00&end=09:00&extra=0&sort=depart
GET https://{$host}/txapi/app_station/timetable?station=TRA-1000&week=1&direction=1&destination=TRA-1210
GET https://{$host}/txapi/app_station/departures?station=TRTC-BL12&count=3
GET https://{$host}/txapi/app_train/stops?train=TRA-1132&week=1
GET https://{$host}/txapi/app_line/timetable?line=TRA-WL&from=TRA-1000&to=TRA-1210&week=1&start=07:00&end=09:00
```

| API | 內容 |
| --- | --- |
| `app_trip/direct` | 起訖站間行車時刻表：同一班車停兩站的所有班次（點對點時刻表）。環狀線繞遠方向的直達班次也列出，`LongWay`＝true、排在後面。`extra` 排除列車、`class=local／express` 只要慢車或快車、`line` 捷運只看某條路線；`sort`＝`depart` 最早出發（預設）、`arrival` 最早抵達、`duration` 最短時間。臺鐵繞東部（或繞西部）的直達車也列出，由使用者判斷。每班帶 `Path`（這段經過的路線：`[{LineUID, LineName, From: {StationUID…}, To: {StationUID…}}]`，每段是路線代號與該路線內的起訖站代號，例如臺北→八堵 西部幹線、八堵→臺東 東部幹線…）與 TDX 的行駛方向 `Direction`（0／1；臺鐵 0 順行＝順時針：西部北上、東部南下，1 逆行＝逆時針），`direction=0／1` 只列該方向；`Train` 可帶給 `app_train/stops` 取沿途停靠站與時刻。start、end 都省略時為全日 |
| `app_station/timetable` | 單站時刻表：依路線與方向分組（`groups`，標籤「往○○」），可用 `direction`、`line`、`destination`、`class`、`start`／`end` 篩選；不受 `extra` 影響 |
| `app_station/departures` | 即將發車：`start`（預設現在；舊名 `time` 照收）以後各方向的前 `count` 班（預設 {$depCount}） |
| `app_train/stops` | 列車各站時刻：臺鐵、高鐵用車次代碼（`TRA-1132`），捷運用時刻 API 回傳的班次代碼 |
| `app_line/timetable` | 路線時刻表：一條路線、一個方向的所有班次。`Stations` 是列（依行進方向的站序，整天沒有車停的站不列），`Trains` 是欄，每班的 `Times` 對齊 `Stations`：`{Arr, Dep}` 停靠、`{Pass: true}` 通過、`null` 不經過。`direction`（0＝依路線站序、1＝反向）或 `from`／`to`（只取一段，方向依兩站先後）；`start`／`end` 比的是班次在第一個顯示車站的時刻 `SortMinute`（中途發車的車依一般行駛時間推算），欄也依它排序；`extra`、`class` 篩選；`limit`（預設 100、上限 300），超過時 `more`＝true、`next` 是下一頁的 `start`。臺鐵海線車不算經過山線（反之亦然）；環狀線（高雄輕軌）首站接在最後；沒有真實站別時刻表的捷運路線（文湖線等）用首站發車推算，`Source`＝`estimate` |
| `app_trip/routes` | 起訖站間轉乘路由（不看時刻）：搭哪些路線、在哪裡轉乘、每段的車種等級、估計分鐘與里程，每個帶 `route`；`extra`、`max_transfers`、`via`。依轉乘次數 → 主幹的速度等級（主幹＝搭乘里程最多的營運單位；高鐵 > 臺鐵 > 各縣市捷運 > 輕軌，基本速度的差異）→ 里程 → 估計分鐘排序，繞遠的走法不排除、排在後面（`DistanceRatio`＝里程是最短路由的幾倍），真的比較快的由 `journeys` 依時刻挑出。**營運業者保底**：取前 `limit` 條時，所有路由中出現過、前 `limit` 條卻沒有的業者與主幹（行駛分鐘最多的系統；臺鐵主幹再分可搭對號、全程區間車），各補一條轉乘最少、估計最短的，擠掉排在最後的（例如新店→小港前幾十條都是高鐵時，仍會有臺鐵直達與區間車接力各一條）；捷運夾在兩段臺鐵／高鐵中間的走法（臺鐵到臺中換臺中捷運再搭高鐵）不拿來補。`journeys` 選路由時同樣保底 |
| `app_trip/route_trains` | 帶入 `route`，取時段（`start`～`end`，`by=dep` 出發／`arr` 抵達在時段內）內所有串得起來的列車組合（同一路由的前後班次），依出發排序。只照這條路由查：系統直達段（`TRA`）只查一班車坐到底的直達車，要中途換車就把換車站寫進路由；要系統自己找各種走法用 `journeys` |
| `app_trip/journeys` | 起訖站間各種轉乘列車組合：找路由、每段取時刻、串接。時段：`start`～`end`＋`by`（`dep` 出發時刻在時段內，預設；`arr` 抵達時刻在時段內，反向串接）；或 `first=1`（當天最早能到，首班）、`last=1`（當天最晚還能到，末班；反向串接），不能與時段併用。`sort`＝`arrival` 最早到達、`transfers` 最少轉乘、`duration` 最短行車時間；沒給時 by=dep／first 用 `arrival`，by=arr／last 依出發由晚到早。每個方案帶 `Tags` 與 `route`，`picks` 是各條件的代表方案（不在前 `limit` 個時也會附在後面，例如比較慢的直達仍標「最少轉乘」）；行車時間超過最短方案 1.5 倍再多 30 分、因轉乘較少而留下的方案（例如高鐵為主時的臺鐵直達），依 `arrival`、`duration` 與出發排序時一律排在其他方案後面，也不會標最早到達、最短行車時間；meta 的 `mode` 是採用的模式。`routes`＝逗號分隔的路由代碼（最多 {$journeyRoutes} 條，起訖要與 `from`、`to` 相同）時不找路、只串接這幾條，也不做跨路由的互不支配與繞遠排除，每條串得起來的班次都列（meta 帶 `fixed`＝true） |

```
GET https://{$host}/txapi/app_trip/routes?from=TRA-0950&to=TRA-1210&extra=0
GET https://{$host}/txapi/app_trip/journeys?from=TRA-0950&to=TRA-1210&date=2026-10-26&start=08:00&end=10:00&sort=arrival
GET https://{$host}/txapi/app_trip/journeys?from=TRA-0950&to=TRA-1210&date=2026-10-26&start=09:00&end=10:00&by=arr
GET https://{$host}/txapi/app_trip/route_trains?route=TRA-0950.TRA.local.TRA-1000.TRA.express.TRA-3300&week=1&start=08:00&end=10:00
```

找路與串接的做法（查詢流程依序）：

1. **路由**（核心 A）：從路網與轉乘站推導所有可能的走法，推導途中每接一段就檢查排除條件，違反的前綴整串不再展開。路線連結圖找路線串（同一鐵路系統在交會站換線不算轉乘；同一路線最多搭 2 次，例如文湖線→板南線→文湖線、山線→海線→山線；有支線的捷運路線依營運路線站序，跨支線要在分岔站換車，例如蘆洲→大橋頭→三重），再逐段選轉乘站。寫死的只有通則：**重複經過同一個車站**（上下車站與沿途通過的站都算，同一個轉乘群組視為同一站；例如汐止到忠孝復興經板橋轉板南線會重複經過臺北，不列）、跨支線不能直接搭（在分岔站換車）。其餘都在**找路規則檔** `transfer/data/transfer_route_rules.json`（受版控）：`Search` 搜尋範圍參數（深度、路線數、同路線次數、上限）；`DirectPairs` 排除名單（不這樣走）——路由經過名單上的兩站時，兩站之間必須直接搭同一條路線（一段搭乘內完成；換線、換系統、步行、繞環島都算拆開），例如臺鐵南港、松山、臺北、萬華、板橋兩兩之間不繞捷運或高鐵，高鐵南港、台北、板橋兩兩之間不繞臺鐵或捷運；`LineTransfers` 加速清單（只這樣走）——兩條路線之間只用列出的轉乘群組；`RouteBans` 經由排除——同一系統連續搭乘中，經過 `From` 範圍的站、再經過 `Via` 的站、又到 `To` 範圍的站（省略＝同 `From`）就不列（`AnySystem`＝跨系統也算，整條路由一起看）；範圍是 `[{Line, StationRange?}]`，`Line` 必填，`StationRange` [A, B] 選填，只算該路線 A 到 B 之間的車站（環狀線依路線站序從 A 往後數到 B，兩站先後決定是哪一邊的弧）；範圍清單的每一項也可以寫單一車站代碼、`{Stations: [...]}` 或 `{Regex: "^TRTC-G0[1-9]$"}`，混寫一起進同一個過濾。`RouteBans` 另一種寫法是**轉乘走法排除**：`Transfers`（依序的轉乘站，每項一個範圍清單，同轉乘群組視為同一站）＋選填的 `Ends`（`Pair` [A, B] 起站在 A、迄站在 B 或反過來；`Any` 起站或迄站在清單內；`All` 兩站都在），整條路由的轉乘站串（正反向）完全相同時不列，不轉乘的直達不受影響；railtime（接軌時刻）的 52 條「依起訖站排除的轉乘走法」（bypassStationReg、bypassBothStationReg）就是用這個寫法併進來的（`Source`＝`railtime`），其中與本站已知最佳結果衝突的 11 條停用（`Disabled`，附 `DisabledReason`）。臺鐵環島由產生器依 `RouteBanRings` 把環切成 16 個區段，兩區段之間遠的一邊里程 ≥ 近的一邊 1.5 倍時，排除經由遠的一邊（例如嘉義～大橋到八堵～浮洲不經玉里、高雄到臺東不經北部）；`NoTransferStations` 不當轉乘站的車站——資料上的別名站（臺鐵臺北-環島 `TRA-1001`，`AliasOf` 臺北 `TRA-1000`），路由不在那裡轉乘，起訖是它時直接當臺北查；排除名單比對時同一轉乘群組裡同一鐵路系統的車站視為同一站。排除名單由 `agent/skills/rail-transfer/scripts/build_route_rules.php` 依估計時間產生（直接搭比任何其他走法快至少 max(5 分, 10%) 才列入；人工項目與 `Disabled` 會保留），每筆附 `Evidence`。**不轉乘的路由（同一路線、同一鐵路系統直通）一定會列出**，即使比轉乘慢，排序在前（轉乘次數優先）。
   **系統直達段**：臺鐵、高鐵的連續搭乘合成一段，連接寫業者代碼（`TRA`、`THSR`），代表不分路線直接查兩站之間的直達班車（例如八堵不停的車也查得到）；經過的路線串放在該段的 `Paths`（第一條是里程最短的，其他是同起訖的其他路線串，例如山線、海線），前端需要時再拿來顯示；
   **補充資料**（`transfer/data/transfer_route_supplements.json`，人工維護、受版控）：自動找完後，每條路由逐一比對補充資料，條件（`Operators` 業者、`Classes` 車種等級、`From`／`To` 上下車站或路線範圍、`ViaLines`／`ViaStations`，都是陣列）百分之百符合的各多一條路由，由 `Logic` 指定的函式依 `Meta` 改寫：`TRA.LocalRelay` 臺鐵全程區間車在接力站換車、`TRA.ExpressTransfer` 區間車到大站換對號／大站換區間車、`TRA.ChangeAt` 在指定的路線交會站換車、`ClassChange` 高鐵在台中、機捷在 A8 換快車或慢車。補充路由帶 `Supplement`（補充項目名稱）與 `BaseRoute`（從哪條自動路由展開）
2. **路由層排除**：`extra` 含 2 時高鐵路線不進圖；含 1 時不產生臺鐵對號段
3. **每段取時刻**（核心 B）並**排除列車**：依該段的車種等級與 `extra`
4. **串接**：每一班接下一段「到站＋轉乘分鐘」後最早到的那班；同一班車繼續坐（交會站直通、接力站沒換車）不算轉乘、不加分鐘；同一個銜接只留最晚出發的
5. **分類排序**：所有路由的方案合在一起，留下互不支配的（出發較晚、到達較早、轉乘較少），明顯繞遠的不列，再依 `sort` 排序並標上 Fastest、ShortestTime、FewestTransfers、Direct

時段參數（`app_trip` 的 direct、route_trains、journeys 共用）：`start`～`end`（營運日 `HH:MM`），`by=dep`（出發時刻在時段內，預設）或 `by=arr`（抵達時刻在時段內）。只給 `start` 時 `end`＝`start`＋系統最長可查詢時段（`QUERY_MAX_RANGE_MINUTES`，目前 {$qRange}；不限時到營運日結束）；只給 `end` 時往前同樣長度；都省略時從現在起算（direct 為全日）。route_trains、journeys 最長沿用 `QUERY_MAX_RANGE_MINUTES`。舊參數照收：`depart`＝`start`（by=dep）、`arrive`＝`end`（by=arr），不能混用。meta 回 `by`、`start`、`end`。單站、路線時刻表的 `start`／`end` 比的是發車時刻。

- 日期：`date=YYYY-MM-DD` 或 `week=0～6`，都省略時用**現在的營運日**（凌晨 00:00～03:59 算前一天）；目前依定期時刻表的星期
- 時間參數用營運日 `HH:MM`（`04:00`～`27:59`，午夜後寫 24 時以後）；回應的 `*Time` 為 `00`～`23` 時，另附 `*Minute`＝營運日的分鐘數（可超過 1440）方便排序
- 每一班都有 `Train`（班次代碼），可直接帶給 `app_train/stops`。捷運的班次代碼含星期與資料雜湊，只在同一份離線資料內有效
- 捷運、輕軌的到站多為推算：每班帶 `DepartureSource`／`ArrivalSource` 或 `Source`、`DataTier`（real／mixed／derived），前端可標示「約」

### 路由代碼 `route`

看得懂、放進網址參數不必編碼的路由寫法，只用英數字與 `-` `.`（JS `encodeURIComponent`、`URLSearchParams`，
PHP `urlencode`、`rawurlencode`、`http_build_query` 都不會編碼這些字元）。

- 結構：`車站.連接.車站.連接 … 車站`，每兩個車站之間一個「連接」，中途的車站就是轉乘站
- 搭車：路線代碼，或業者代碼（`TRA`、`THSR`，系統直達段：不分路線查站到站直達班車；舊的臺鐵、高鐵路線代碼仍可用，只查該路線），可再接車種等級 `local`（慢車：臺鐵區間、區間快，高鐵各站停，機捷普通車）或 `express`（快車：臺鐵莒光以上、高鐵直達與跳站、機捷直達車），省略為不限
- 步行到另一個車站：`walk`，或 `walk-<分鐘>` 指定分鐘（只寫 `walk` 用轉乘群組的分鐘）
- 關鍵字（`walk`、`local`、`express`）要整個元素完全命中（名稱在第一個 `-` 之前，之後是參數）才算，其他一律當車站或路線代碼

```
TRA-0900.TRA.TRA-1000.walk.TRTC-BL12.TRTC-BL.TRTC-BL15.walk.TRTC-BR10.TRTC-BR.TRTC-BR22     基隆→東湖
TRA-0950.TRA.local.TRA-1000.TRA.express.TRA-3300                                              五堵→臺中（區間車到臺北換自強號）
```

### 排除的列車等級 `extra`

找路與時刻組合的 `app_` API 都有 `extra`（0～3，預設 0），用位元決定**不搭**哪些列車：

| bit | 名稱 | 開啟後排除 |
| --- | --- | --- |
| 1 | 不搭臺鐵對號 | 臺鐵莒光、自強（推拉式、EMU3000）、普悠瑪、太魯閣 |
| 2 | 不搭高鐵 | 高鐵 |

`extra=0`（預設）不排除；`1` 臺鐵只搭區間車與區間快；`2` 不搭高鐵；`3` 兩者都不搭。捷運、輕軌一律可搭。慢車與快車的分法在
`libs/txapi/transfer/data/transfer_seed.json` 的 `ClassChange`（臺鐵依車種代碼；高鐵、機捷依停靠站，起訖之間每站都停的是慢車）；快慢車換車站、區間車接力站在補充資料 `transfer_route_supplements.json` 的 `Meta`。

## 後臺（`admin_` 開頭的 action）

需要後臺身分的操作一律以 `admin_` 開頭，一看就知道 `Authorization: Bearer` 帶的是後臺 PAT（不是 TDX token）。

- 登入：後臺頁面（網址不公開，向管理者索取）；或 API 帶 `Authorization: Bearer <PAT>`（主機拿掉 Authorization 時改用 `X-Admin-Token`）
- PAT：登入後在「我的帳號」選名稱與到期日產生（`tdxp_` 開頭，原文只顯示一次）；權限跟著帳號的角色
- 權限：以路由（下表）為單位，角色可勾讀取、寫入；`manager` 角色擁有全部路由的讀寫。面板選單只顯示有讀取權限的路由
- 用 cookie 登入時，寫入動作要 POST 並帶 `X-CSRF-Token`（`/txapi/admin_auth/me` 回傳）
- 已登入或帶 PAT 的請求不計查詢次數，轉乘查詢也不限時間範圍
- 新主機沒有預設帳號：網站第一次執行後 {$setupHours} 小時內，打開後臺會出現「首次設定」，由你建立第一個管理者帳號（`/txapi/admin_auth/setup`）；逾時或要重來，在網站根目錄放 `admin-setup.txt`（內容空白＝重新開放；內容 `reset`＝清除所有帳號後重新開放），FTP、主機檔案管理或 git 佈署都可以
- 登入紀錄保留 {$logDays} 天

{$adminRoutes}

| action | func | 說明 |
| --- | --- | --- |
| `admin_auth` | `login`（POST username、password）、`logout`、`me`、`password`（POST old_password、new_password）、`tokens`、`token_create`（POST name、expires=YYYY-MM-DD）、`token_revoke`（POST id） | 自己的登入、密碼與 PAT，任何帳號都能用 |
| `admin_accounts` | `users`、`roles`、`routes`、`logs`、`tokens`；`user_create`、`user_update`、`user_delete`、`role_create`、`role_update`、`role_delete`、`token_revoke` | 帳號、角色與權限、登入紀錄（時間、IP）、所有 PAT |
| `admin_settings` | `view`、`checklist`；`save`（POST changes）、`download`（POST，整包 custom/） | 設定管理：檢視、初始化檢查清單（新主機上線的步驟與下一步）、項目編輯器、整包下載（不提供上傳，請用 FTP） |
| `admin_cache` | `summary`、`purge` | 見上方 |
| `admin_download` | `sync`、`prune`、`refresh` | 見 `/txapi/download` 說明 |
| `admin_download_to_sqlite` | `status`、`build` | 見上方 |
| `admin_transfer` | `build_groups`、`learn`、`history`、`restore` | 轉乘群組產生、加速清單演算、產生檔舊版與換回 |
| `admin_finder` | `status`；其餘同 `/txapi/finder` 的查詢 | 面板「查詢功能」（`admin_finder`）、「查詢測試」（`admin_finder_try`） |
| `admin_traffic` | `list`、`ip`、`blocks`；`block`、`unblock`（POST） | 各 IP 的請求頻率與總量（保留 {$trafficDays} 天）、封鎖與解鎖；被封鎖的 IP 回 403（40303），已登入或帶 PAT 的請求除外 |


## 靜態資料快取

軌道與公車的靜態資料會存在伺服器上，重複請求直接回快取，不消耗 TDX 額度。

### 會快取的 API

| 模式 | 分類 | 資源名稱 |
| --- | --- | --- |
| 軌道 | 靜態資料 | {$railStatic} |
| 軌道 | 定期時刻表 | {$railTime} |
| 軌道 | 指定日期時刻表 | {$railDaily} |
| 公車 | 靜態資料 | {$busStatic} |
| 公車 | 班表 | {$busTime} |

資源名稱指路徑中的那一段，例如 `/api/basic/v3/Rail/TRA/Station` 是 `Station`、
`/api/basic/v2/Bus/StopOfRoute/City/Taipei` 是 `StopOfRoute`。
沒列在表上的（列車與公車即時動態、到站預估、剩餘座位、`Alert`、`News` 等）一律不快取。

### 快取時間

{$policy}

- 新鮮期內直接回快取
- 過了新鮮期就用 `If-Modified-Since` 向 TDX 確認；沒變時 TDX 只回 304，不重新下載，但仍計入 TDX 流量
- 超過最長保存就整份重新下載

### 快取鍵

快取以「路徑 + 參數」區分：參數不同（例如多了 `\$filter`、`\$top`、`\$select`）就是不同資料，各存一份。
語意相同、只是寫法不同的請求會共用同一份快取：

- 參數順序不同：`\$format=JSON&\$top=30` = `\$top=30&\$format=JSON`
- 編碼不同：`%24format` = `\$format`、`%20` = `+` = 空白、路徑中的 `%E5%8F%B0` = `台`
- 參數名稱大小寫不同：`\$Format` = `\$format`；`\$format` 的值也不分大小寫（`JSON` = `json`）
- 多餘的 `&`、空參數會忽略

實際轉給 TDX 的網址仍是前端原本的寫法，正規化只用於快取比對。
`\$filter` 等參數值本身的大小寫與內容不做改動（值不同就視為不同查詢）。

### 回應 header

| Header | 說明 |
| --- | --- |
| `X-Proxy-Cache: HIT` | 直接由快取回應 |
| `X-Proxy-Cache: MISS` | 剛向 TDX 取得並存入快取 |
| `X-Proxy-Cache: REVALIDATED` | 向 TDX 確認資料未變，由快取回應 |
| `X-Proxy-Cache: STALE` | TDX 失敗或被限流，暫時回傳過期的快取 |
| `X-Proxy-Cache: BYPASS` | 不快取、直接轉發 TDX 的回應：TDX 回的是檔案附件（見下方），或這次存不下來 |
| `X-Proxy-Cache-Category` | 這筆資料的分類：`static`、`timetable`、`daily` |
| `Age` | 距離上次向 TDX 取得或確認過了幾秒 |

以上 header 都已開放跨網域讀取。瀏覽器端最多再快取 10 分鐘（`Cache-Control: private`）。

TDX 少數大型整包資料（例如不帶起訖站的 `/v3/Rail/TRA/ODFare`）本身是 **`.json.gz` 檔案附件**
（`Content-Type: application/octet-stream`、`Content-Disposition: attachment; filename=….json.gz`），
不是一般 JSON。這類回應**不快取**，每次都直接轉發（header 與內容和直連 TDX 相同，也會消耗 TDX 額度），
前端需自行解壓一次 gzip 才是 JSON。

## 設定（custom/libs/config.json）

程式、通用資料、私密資料分開存放：`libs/` 只有程式、預設值與人工維護的資料（隨程式佈署）；
所有設定項目、預設值與說明在 `libs/config.json`（隨程式佈署）；這個站自己的設定與私密值寫在資料根目錄（預設＝網站根目錄，網站根目錄的 `env_path.php` 可以改到別處）的
`custom/libs/config.json`：一個 JSON 物件，只放要覆蓋的項目（例如 `{"QUERY_RATE_LIMIT_PER_MINUTE": 30}`），
有的名稱就用它的值，沒有才用預設值；底線開頭的名稱是註記。不存在時自動建立（只有一組隨機的 `APP_SECRET_KEY`）。
後臺「設定管理」只改這個檔裡被編輯的項目，舊版存在 `custom/libs/config_history/`；舊格式 `custom/libs/config.php` 會自動轉成 JSON。

`custom/`（禁止網址存取、不進版控、要備份）：`libs/config.json` 覆蓋值與私密值、`libs/txapi/transfer/data/` 轉乘產生檔與舊版、
`libs/txapi/admin/` 後臺帳號資料庫、`agent/` 私密的維護筆記。快取類暫存在 `download/tdx_proxy_files/`，丟了會自己重建。
重新佈署程式時只換 `libs/`、`.htaccess`、`index.php`，其他都不動。

目前的設定（公開版：私人設定只顯示預設值與「custom 有自訂」，金鑰只顯示是否已設定；實際值要後臺 `admin_settings` 讀取權限）：

{$cfgTable}
MD;
}

/** 設定表（Markdown，公開版）：私人設定只顯示預設值與是否自訂，金鑰只顯示已設定／未設定 */
function docsConfigTable()
{
    $md = "| 常數 | 值 | 說明 |\n| --- | --- | --- |\n";
    $items = configItems();
    foreach (configReport(false) as $name => $r) {
        if ($r['level'] === 'secret') {
            $v = '`' . $r['value'] . '`';
        } else {
            $v = '`' . str_replace('|', '\\|', json_encode($r['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '`';
            if ($r['level'] === 'private') {
                $v = '預設 ' . $v . ($r['customized'] ? '（custom 有自訂）' : '');
            } elseif ($r['source'] === 'custom') {
                $v .= '（custom）';
            }
        }
        $desc = str_replace(["\n", '|'], [' ', '\\|'], $items[$name]['desc']);
        $md .= '| `' . $name . '` | ' . $v . ' | ' . $desc . " |\n";
    }
    if (configCustomError()) {
        $md .= "\n**注意**：自訂設定檔讀取失敗，目前全部用預設值（詳細見後臺設定頁）\n";
    }
    return $md;
}

/** GET /txapi/readme */
function actionReadme()
{
    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    header('Cache-Control: no-store');
    echo docsRender();
    exit;
}

/** 健康檢查內容：根路徑與 /txapi/status 共用，附上文件連結。設定值依權限：有 admin_settings 讀取權限才顯示實際值 */
function statusInfo()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $full = function_exists('adminCan') && adminCan('admin_settings', 'read');
    $settings = ['file' => 'custom/libs/config.json', 'scope' => $full ? 'full' : 'public', 'values' => configReport($full)];
    if ($full) {
        $settings['unknownKeys'] = configUnknownKeys();
        $settings['error'] = configCustomError();
    } elseif (configCustomError()) {
        $settings['error'] = '自訂設定檔讀取失敗（詳細見後臺）';
    }
    return [
        'product'    => PRODUCT_NAME,
        'service'    => PRODUCT_SERVICE_NAME,
        'configured' => isConfigured(),
        'readme'     => 'https://' . $host . '/txapi/readme',
        'panel'      => 'https://' . $host . '/panel/',
        'site'       => siteInfo(),
        'settings'   => $settings,
        'hint'       => 'AI agents and developers: GET the readme URL for full Markdown documentation of this proxy, '
            . 'its custom API under /txapi/<action>/<func> (TimeLink eXecute API), caching rules and settings. Admin actions start with admin_ and need a PAT (Authorization: Bearer). '
            . '使用說明請讀 readme 網址（Markdown）。',
    ];
}

/** 商品名稱、單位、開發者、頁尾聲明與資料收集提示（config 的 PRODUCT_NAME、ORG_NAME、PRIVACY_NOTICE_HTML、ORG_NAME_HTML、DEVELOPER_NAME、DEVELOPER_EMAIL、SITE_NOTICE_HTML），給面板與後臺頁尾用 */
function siteInfo()
{
    $org = (string) ORG_NAME;
    return [
        'product'     => (string) PRODUCT_NAME,
        'service'     => (string) PRODUCT_SERVICE_NAME,
        'org'         => $org,
        'org_html'    => (string) ORG_NAME_HTML !== '' ? (string) ORG_NAME_HTML : htmlspecialchars($org, ENT_QUOTES, 'UTF-8'),
        'developer'   => (string) DEVELOPER_NAME,
        'email'       => (string) DEVELOPER_EMAIL,
        'notice_html' => (string) SITE_NOTICE_HTML,
        'privacy_html' => str_replace('{days}', (string) (int) TRAFFIC_LOG_RETENTION_DAYS, (string) PRIVACY_NOTICE_HTML),
    ];
}

/** GET /txapi/status */
function actionStatus()
{
    sendJson(200, statusInfo());
}
