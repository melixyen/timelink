# agent/

給 AI 代理操作本站用的通則：演算法與資料規則（skills）、開發與驗證用的腳本。進版控、內容公開，
所以**只寫通則，不寫這個站的站址、主機特性、密碼、PAT**。

## 開始前：先讀私密筆記

這個站自己的細節放在主機的 `custom/agent/`（不進版控、禁止網址存取、隨 custom/ 備份）：站址、主機與部署方式、
後臺帳號與 PAT 的存放位置、排程等。操作本站前先讀那裡的 `README.md`；學到新的主機細節（例如主機的限制、部署注意事項）
也寫回 `custom/agent/`，不要寫進這個資料夾。

## 後臺 API（admin_ 開頭的 action）

需要後臺身分的操作一律是 `/txapi/admin_…`，帶 `Authorization: Bearer <PAT>`（主機拿掉 Authorization 時改用 `X-Admin-Token`）。
PAT 在後臺頁面（網址見 custom/agent/README.md）登入後的「我的帳號」產生，權限跟著帳號的角色；不接受其他任何密碼。
完整清單見 `/txapi/readme` 的「後臺」一節。腳本統一讀環境變數：`TDX_BASE`（站址）、`TDX_PAT`（後臺 PAT）。

## skills/

每個 skill 一個資料夾，`SKILL.md` 為操作說明（開頭 YAML：`name`、`description`），輔助程式放在 `scripts/`。腳本一律用 PHP 命令列（與網站同語言，不需要另裝 Python 或 Node.js）。

| skill | 用途 |
| --- | --- |
| [data-pipeline](skills/data-pipeline/SKILL.md) | 資料更新流程：離線資料下載（TDX 同步、重建資料庫）與最佳化加速清單演算，可分段或串接執行；腳本 `scripts/pipeline.php`，排程方式 |
| [metro-travel-time](skills/metro-travel-time/SKILL.md) | 捷運站間行駛時間（TDX S2STravelTime）存成路線站距表或原始起訖矩陣；獨立驗證腳本 `scripts/s2s_to_offsets.php` |
| [rail-travel-time](skills/rail-travel-time/SKILL.md) | 各營運公司、各路線的起訖時刻與站間行駛時間：擁有的資料種類（列車時刻表、車站時刻表、路線站間行駛時間、兩站間行駛時間矩陣、營運班距）、取得方法的優先順序（真實 → 真實加推算 → 完全推算）與計算、推算方法；更新資料、新增車站時照做 |
| [rail-transfer](skills/rail-transfer/SKILL.md) | 軌道轉乘：轉乘群組 TransferGroup 的產生與更新、路網結構搜尋、RAPTOR 時刻表轉乘查詢、固定路由組合的前後班次（func=follow）、車站搜尋（func=stations；字典腳本 `scripts/build_hanzi.php`），以及 railtime／rocptx 轉乘演算法的對照 |
| [rail-transfer-learn](skills/rail-transfer-learn/SKILL.md) | 離線資料更新（資料庫重建）後重算轉乘加速清單：從全部時刻表跑完整搜尋，算出推薦換車站與實際使用的地區走廊（`transfer_accel_learned.json`）；腳本 `scripts/verify_accel.php` |

## claude/

Claude 自己的比對工具、基準資料與重建指引（railtime 比對、高雄／北北基桃基準、效能基準），見 [claude/README.md](claude/README.md)。
改找路演算法或規則後，除了 rail-transfer 的回歸測試，也照那份指引跑比對。

## 網站目錄

在 git repo 裡，下面這些都在 `server/` 底下（`server/` 的內容＝網站根目錄，checkout 後可以目錄對目錄覆蓋到主機）；
`client/` 保留給未來的查詢網站（只透過公開的 `/txapi/` 與本站溝通）。

程式、通用資料、私密資料分開存放：

| 位置 | 內容 | 版控 |
| --- | --- | --- |
| `libs/` | 程式、`libs/config.json` 設定項目與預設值、`libs/txapi/transfer/data/` 人工維護的轉乘輸入與漢字字典、`libs/html/panel/` 公開面板、`libs/html/admin_panel/` 後臺 | 是 |
| `.htaccess`、`index.php` | 入口 | 是 |
| `agent/` | 本資料夾：AI 代理的通則與腳本（`agent/claude/` 是 Claude 的工具與資料） | 是 |
| `custom/libs/config.json` | 這個站的設定與私密值（金鑰、APP_SECRET_KEY、開放網域、SITE_HOST）；只放要覆蓋預設值的項目 | 否 |
| `custom/libs/txapi/transfer/data/` | 用離線資料算出的轉乘產生檔（群組、連結紀錄、里程、加速清單）與 `history/` 舊版 | 否 |
| `custom/libs/txapi/admin/admin.sqlite` | 後臺帳號、角色權限、登入紀錄、PAT（表一律 `admin_` 開頭） | 否 |
| `custom/agent/` | 私密的主機維護筆記 | 否 |
| `download/tdx/`、`sqlite/` | TDX 下載檔、finder 資料庫與快取 | 否 |
| `download/tdx_proxy_files/` | 快取類暫存（token、TDX 快取、查詢次數、分析進度），丟了會自己重建 | 否 |

`custom/`、`download/`、`sqlite/` 都禁止網址存取。重新佈署程式只換 `libs/`、`.htaccess`、`index.php`（與 `agent/`），其他都不動；
產生檔在 `custom/`，佈署不會覆蓋。
