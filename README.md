# TimeLink

台灣軌道運輸的時刻與轉乘查詢服務，資料來自交通部 TDX 運輸資料流通服務平臺。所屬單位：極光駭客。

TimeLink 由兩部分組成：

- **TDX Proxy**（`/api/`）：代管 TDX access token、把查過的資料存進快取，節省 TDX 的呼叫次數；也可以帶自己的 TDX token 轉發
- **查詢服務 txapi**（`/txapi/`，TimeLink eXecute API）：用定期從 TDX 更新的離線資料建構，提供車站、路線、單站與路線時刻表、列車停靠時刻、起訖站間直達班次、轉乘路由與轉乘列車組合

涵蓋臺鐵、高鐵、臺北捷運、新北捷運、桃園機場捷運、臺中捷運、高雄捷運與各輕軌。目標是做出台灣版的乘換案內（類似 jorudan、ekitan），只做時間與路線，不做票價推算。

API 說明在網站的 `/txapi/readme`（內容由 `server/libs/txapi/docs.php` 產生，與程式同步）。

## Repo 結構

| 位置 | 內容 |
| --- | --- |
| `server/` | 網站本體（PHP）。`server/` 的內容＝網站根目錄，checkout 後可以目錄對目錄覆蓋到主機 |
| `server/libs/` | 程式、`config.json` 設定項目與預設值、轉乘的人工維護資料、公開面板與後臺頁面 |
| `server/agent/` | 給 AI 代理的通則、skills 與腳本；`server/agent/claude/` 是 Claude 的比對工具與基準資料 |
| `client/` | 保留給未來的查詢網站（只透過公開的 `/txapi/` 與 server 溝通） |

不進版控（見 `.gitignore`）：`server/` 底下的 `custom/`（站台設定與私密值、轉乘產生檔、後臺資料庫、私密維護筆記）、`download/`、`sqlite/`（TDX 離線資料、資料庫與快取）、`backup/`，以及根目錄除了 `index.php` 以外的 `*.php`。

## 安裝

需求：PHP 8.1 以上（目前在 8.3 運行），擴充 `pdo_sqlite`、`curl`、`mbstring`、`zip`、`zlib`；Apache（使用 `.htaccess`）。

1. 把 `server/` 的內容放到網站根目錄
2. 建立 `custom/libs/config.json`，只寫要覆蓋預設值的項目（完整清單與說明在 `libs/config.json`），至少要有 `TDX_CLIENT_ID`、`TDX_CLIENT_SECRET`、`APP_SECRET_KEY`、`SITE_HOST`、`ALLOWED_ORIGINS`
3. 第一次執行時會自動建立 `custom/`、`download/`、`sqlite/`（預設在網站根目錄，要放到別處見下面「資料目錄的位置」）；後臺的第一個管理者帳號在首次執行後限定時間內建立（細節見 `/txapi/readme` 的「後臺」一節）
4. 下載離線資料、建立資料庫與轉乘加速清單：見 `server/agent/skills/data-pipeline/SKILL.md`

更新程式時只換 `libs/`、`agent/`、`index.php`、`.htaccess`；設定、資料庫、離線資料與產生檔都在其他目錄，不會被覆蓋。

### 資料目錄的位置（env_path.php）

`custom/`、`download/`（含快取 `download/tdx_proxy_files/`）、`sqlite/` 都放在「資料根目錄」底下，路徑一律由 `libs/env_path.php` 的 `appEnvPath('sqlite')` 這類寫法組出來（資料根目錄本身是常數 `APP_ENV_PATH`）。資料根目錄預設是 `libs/` 的上一層 `../`，也就是網站根目錄。

要把資料庫與設定放到別處（例如網站根目錄以外，或開發環境的 home 底下），在網站根目錄（`server/`，`libs/` 的上一層）建立 `env_path.php`，回傳資料根目錄：

```php
<?php
return '/home/me/timelink-data';      // 絕對路徑
// return '../../timelink-data';      // 相對路徑：和預設值 ../ 一樣，相對於 libs/
```

- 只 `return` 路徑字串，不要輸出任何內容（網站根目錄的 `.php` 可以直接從網址執行）；回傳的不是非空字串時忽略、改用預設值
- `server/env_path.php` 不進版控（`.gitignore` 有列），每個環境自己建立
- 搬家時把 `custom/`、`download/`、`sqlite/` 整個搬過去，並保留檔案的修改時間（例如 `cp -a`），否則離線資料會被當成比資料庫新而重建；目錄不存在時第一次執行會自動建立
- 後臺首次設定用的 `admin-setup.txt` 是證明能改網站的觸發檔，仍放在網站根目錄

## 資料來源與授權

- 程式：MIT License，見 [LICENSE](LICENSE)
- 運輸資料：交通部 [TDX 運輸資料流通服務平臺](https://tdx.transportdata.tw/)，依[政府資料開放授權條款](https://data.gov.tw/license)提供。網站頁尾依 TDX 使用規範標示資料來源與平臺標章（`SITE_NOTICE_HTML`）。查詢結果由本服務整理計算，僅供參考，實際以各營運單位公告為準
- 漢字讀音與異體字：`server/libs/txapi/transfer/data/hanzi_pinyin.json`（車站搜尋的拼音、簡繁與異體字歸一）由 [Unicode Unihan Database](https://www.unicode.org/charts/unihan.html) 產生（產生腳本 `server/agent/skills/rail-transfer/scripts/build_hanzi.php`），依 Unicode License V3 使用，授權全文見下方
- 部分轉乘走法排除規則改寫自 [railtime（接軌時刻）](https://github.com/melixyen/railtime)，同一作者的專案

### Unicode License V3

```
UNICODE LICENSE V3

COPYRIGHT AND PERMISSION NOTICE

Copyright © 1991-2026 Unicode, Inc.

NOTICE TO USER: Carefully read the following legal agreement. BY
DOWNLOADING, INSTALLING, COPYING OR OTHERWISE USING DATA FILES, AND/OR
SOFTWARE, YOU UNEQUIVOCALLY ACCEPT, AND AGREE TO BE BOUND BY, ALL OF THE
TERMS AND CONDITIONS OF THIS AGREEMENT. IF YOU DO NOT AGREE, DO NOT
DOWNLOAD, INSTALL, COPY, DISTRIBUTE OR USE THE DATA FILES OR SOFTWARE.

Permission is hereby granted, free of charge, to any person obtaining a
copy of data files and any associated documentation (the "Data Files") or
software and any associated documentation (the "Software") to deal in the
Data Files or Software without restriction, including without limitation
the rights to use, copy, modify, merge, publish, distribute, and/or sell
copies of the Data Files or Software, and to permit persons to whom the
Data Files or Software are furnished to do so, provided that either (a)
this copyright and permission notice appear with all copies of the Data
Files or Software, or (b) this copyright and permission notice appear in
associated Documentation.

THE DATA FILES AND SOFTWARE ARE PROVIDED "AS IS", WITHOUT WARRANTY OF ANY
KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT OF
THIRD PARTY RIGHTS.

IN NO EVENT SHALL THE COPYRIGHT HOLDER OR HOLDERS INCLUDED IN THIS NOTICE
BE LIABLE FOR ANY CLAIM, OR ANY SPECIAL INDIRECT OR CONSEQUENTIAL DAMAGES,
OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS,
WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION,
ARISING OUT OF OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THE DATA
FILES OR SOFTWARE.

Except as contained in this notice, the name of a copyright holder shall
not be used in advertising or otherwise to promote the sale, use or other
dealings in these Data Files or Software without prior written
authorization of the copyright holder.

SPDX-License-Identifier: Unicode-3.0
```

---

## 開發接續（給 AI 代理與新的開發對話）

這一節是給 AI 代理的操作指引：開始前要讀的文件、協作規則與已定案的設計決策，讓新的對話不必重新討論。目前狀態與待辦是工作交接，寫在 [`server/agent/claude_session_transger_task.md`](server/agent/claude_session_transger_task.md)，不寫在這裡。這裡是公開 repo，**只寫通則**；站址、主機操作方式、密碼與 PAT 都不在 repo 裡。

### 開始前依序讀

1. 本節
2. [`server/agent/README.md`](server/agent/README.md)：agent 資料夾的規則、目錄與私密資料的分工
3. 主機上的 `custom/agent/README.md`（私密筆記，不進版控）：站址、主機操作與部署、後臺帳號、本機開發環境的重建方式。連線方式與憑證由 Melix 在對話開頭提供，憑證只放環境變數
4. [`server/agent/claude_session_transger_task.md`](server/agent/claude_session_transger_task.md)：工作交接（接手步驟、目前狀態、待辦、操作上踩過的坑）
5. 要改哪一塊就讀哪個 skill：`server/agent/skills/*/SKILL.md`（找路與轉乘的決策都在 `rail-transfer`）
6. [`server/agent/claude/README.md`](server/agent/claude/README.md)：比對工具、基準與驗證流程
7. 網站的 `/txapi/readme`：對外 API 的完整說明

### 協作規則

- 用繁體中文回覆
- 要**取代**既有的設計或行為之前，先列出改動、問過 Melix 再做；最佳化是補充原本的設計，不是取代
- 改線上一律：先備份 → 佈署 → 線上驗證（同樣的查詢本機與線上輸出一致）
- 程式推到 `dev` 分支
- 私密資訊（金鑰、密碼、PAT、站址、自訂開放網域、主機商）只放 `custom/` 或環境變數，不進 repo、不寫進任何進版控的檔案
- 腳本一律用 PHP（與網站同語言）；只有讀 railtime 的比對工具用 node
- railtime、rocptx 兩個專案只讀、不修改
- 網站自己呼叫 TDX 每分鐘最多 3 次
- 票價只用官方公式文件或 TDX API，不做反推的公式
- Melix 的規劃文件只留尚未解決與新發現的項目，完成的就移除（說明寫進 `/txapi/readme` 與 agent 文件）
- 網頁以手機優先的 RWD 設計

### 已定案的設計決策

**找路演算法**

- 核心從路網與轉乘站推導所有走法，推導途中依排除名單剪枝；寫死的只有通則（重複經過同一車站、跨支線要在分岔站換車），其他都放在設定檔（改排除名單，不加程式）
- 不轉乘的直達路由一定列出，即使比較慢；已知的最佳結果不能遺失；不設距離上限，繞遠的走法依里程排在後面
- 找路規則（`transfer_route_rules.json` 的 Search）與 Router 參數（`transfer_settings.json`）留在轉乘的 JSON，不搬進 `config.json`
- RouteBans 的轉乘走法排除支援多種寫法（StationUID、`{Stations}`、`{Regex}`、`{Line, StationRange}`）一次進同一個過濾流程；同性質的功能只寫一次。railtime 的 52 條 bypass 已合併進來（11 條因擋掉本站的最佳結果而停用，原因寫在 `DisabledReason`）
- **不用行駛方向剪枝**：同一條路線、同一方向搭兩次可能更快而且必要，例如新店→松山在古亭轉中和新蘆線到松江南京再接松山新店線；南港展覽館→土城在南港轉高鐵到板橋再接板南線
- 捷運不使用 TDX 的 Direction 找路

**API**

- 時間參數一律 `start`／`end`，加 `by=dep|arr` 指定比對出發還是抵達時刻；只帶 `start` 時 `end`＝`start`＋系統最長查詢區間（`QUERY_MAX_RANGE_MINUTES`）
- 時刻不寫 24 時以後，一律 00–23 時，另外給分鐘數欄位（`*Minute`），前端用分鐘數判斷跨日
- 路線時刻表支援 `by=arr`；單站時刻表不需要
- `app_trip/direct` 也列繞東部等遠路的直達車，由使用者判斷；每班帶 `Path`（路線代號與該路線內的起訖站代號）與 TDX 的 `Direction`（臺鐵 0＝順行＝順時針），可用 `direction=0|1` 篩選
- 自帶 TDX token 用 `X-TDX-Authorization: Bearer <TDX access token>`：有帶就用使用者自己的額度，被拒絕時直接回錯誤，不改用伺服器的金鑰；未來的票價、指定使用 TDX 最新時刻表都走這個標頭
- 可依主機等級調整的上限與逾時都在 `libs/config.json`，各站在 `custom/libs/config.json` 或後臺「設定管理」覆蓋
- 離線資料只分星期幾，不分實際日期

**Repo 與佈署**

- 單一 public repo，`server/` 放網站、`client/` 留給未來的查詢網站
- 程式放在 `server/` 子目錄，所以主機商內建的 Git 佈署（拉整個 repo）不適用
