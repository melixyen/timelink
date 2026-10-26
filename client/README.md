# 前端專案說明（client/）

接軌時刻 TimeLink 的前端：Vue 3.5 + Pinia + Vite + Vue Router + vue-i18n。
所有查詢都打 TimeLink 自訂 API（`/txapi/`），路由與時刻表組合由後端計算，前端負責查詢與呈現。

## 開發

```bash
cd client
npm install
npm run dev      # http://localhost:5173
```

開發模式直接呼叫查詢服務 `https://tdx.jiapi.net`（CORS 已全面開放）；若想改走同源路徑，可把
`VITE_TDX_BASE_URL` 設成空字串，此時會使用 `vite.config.js` 內的 `/txapi` proxy。

```bash
npm run build    # 產出 dist/
npm run preview  # 預覽建置結果
```

## 路由模式

使用 **hash 模式**（`createWebHashHistory`），適合直接丟到靜態主機（沒有 rewrite 規則）：

- 網址形如 `index.html#/search?a=TRA-1000&b=TRTC-BR22`
- 進入頁面只載入一次，之後只切換 `#hash`，不會重新下載整包檔案

## 打包後的路徑（base）

`vite.config.js` 設定 `base: './'`，打包結果使用**相對路徑**：

- `dist/index.html` 引用 `./assets/index-xxx.js`、`./assets/index-xxx.css`
- 動態載入的頁面 chunk 也以相對路徑於執行時計算（`import.meta.url`），不寫死 `/assets/`
- 因此 `dist/` 可以放在靜態主機的**任意目錄**下直接使用，例如
  `https://example.com/app/`、`https://cdn.example.com/timelink/`，不需要設定站台根路徑
- 路由本身是 hash 模式，與 base 路徑無衝突（`https://example.com/app/#/lines`）
- 若要部署在站台根目錄，維持預設值即可；若要與後端同網域使用同源 `/txapi`，
  建置前設 `VITE_TDX_BASE_URL=`（空字串）

## 公共資料的載入（Pinia）

`/txapi/public_*` 的資料在第一次需要時載入一次，之後一直放在 store 裡共用，
**切換路由不會重新請求**：

| Store | 資料 | API |
| --- | --- | --- |
| `stores/publicInfo.js` | 系統／營運單位 | `/txapi/public_info/systems` |
| `stores/publicStation.js` | 車站清單、車站搜尋（依關鍵字快取） | `/txapi/public_station/list`、`/search` |
| `stores/publicLine.js` | 路線清單、路線站序（依路線代碼快取） | `/txapi/public_line/list`、`/detail` |

每個 store 都提供 `ensureLoaded()`：已載入就直接回傳，載入中則共用同一個進行中的請求，
不會重複打 API。需要強制重新載入時傳 `{ force: true }`。
查詢結果（`stores/search.js`）與站名搜尋結果也會保留，切回頁面時條件未變就不重查。

## 前端設定（`src/config/`）

```
src/config/
├─ index.js   總出口：匯出 envConfig、menuConfig 與分組工具
├─ env.js     環境變數（client/.env）── 部署環境會不同的項目
└─ menu.js    純前端顯示設定 ── 業者分組與順序、路線順序
```

### `menu.js`：業者（營運單位）分組與順序

車站選單的路線切換器、車站清單與路線頁的分組順序，都由這裡的設定決定，
**前端拿到後端資料後不依後端回傳的順序**，而是依下列設定：

```js
export const menuConfig = {
  operators: [
    { id: 'TRA', subIDs: [], lines: ['TRA-WL', 'TRA-WL-C', ...] },
    { id: 'THSR', subIDs: [], lines: ['THSR-HSRL'] },
    { id: 'TRTC', subIDs: [], lines: ['TRTC-BL', 'TRTC-R', ...] },
    { id: 'NTMC', subIDs: ['NTDLRT', 'NTALRT'], lines: [...] }, // 新北捷運：三個系統合併成一組
    { id: 'TYMC', subIDs: [], lines: ['TYMC-A'] },
    { id: 'TMRT', subIDs: [], lines: ['TMRT-G'] },
    { id: 'KRTC', subIDs: ['KLRT'], lines: [...] },              // 高雄捷運：兩個系統合併成一組
  ],
}
```

- `id`：業者代號；`subIDs`：要合併進這一組的業者／系統代號
- `lines`：該業者底下路線的顯示順序（LineUID）；未列出的路線接在後面，依原有順序
- 渲染規則：業者代號出現在 `operators` 裡的依此順序與分組渲染；
  **沒有出現在設定裡的業者，最後依後端回傳的原有順序渲染**
- 對應目前設定的顯示結果：臺鐵 → 高鐵 → 臺北捷運 → 新北捷運（3 系統合併）→
  桃園捷運 → 臺中捷運 → 高雄捷運（2 系統合併）

工具函式（`src/config/menu.js`）：
`groupByOperator(items, nameOf)`、`groupLinesByOperator(lines, nameOf)`、
`resolveOperatorId(item)`

## 環境變數（設定檔 `client/.env`）

**所有配置類項目都集中在 `client/.env`**，fork 後直接修改這個檔案即可（修改後要重新啟動
dev server 或重新 build）。程式透過 `src/config/env.js` 讀取，缺值時使用該檔的預設值。

```bash
cp .env .env.local   # 本機想覆蓋時另存 .env.local（.env.local 不進版控）
```

| 變數 | 預設值 | 說明 |
| --- | --- | --- |
| `VITE_TDX_BASE_URL` | `https://tdx.jiapi.net` | 後端查詢服務網址；自己架設後端時改成自己的。設成空字串＝使用同源 `/txapi`（前端與後端同網域時） |
| `VITE_API_TIMEOUT` | `30000` | 單次 API 逾時（毫秒） |
| `VITE_APP_NAME` | `接軌時刻` | 產品名稱（頁首、頁尾、瀏覽器標題） |
| `VITE_APP_SUBTITLE` | `TimeLink` | 產品副標題 |
| `VITE_ORG_NAME` | `極光駭客` | 發行單位／所屬組織 |
| `VITE_APP_VERSION` | `0.1.0` | 顯示用版本號 |
| `VITE_HOMEPAGE_URL` | `https://tdx.jiapi.net/` | 首頁連結（留空不顯示） |
| `VITE_CONTACT_EMAIL` | （空） | 聯絡信箱（留空不顯示） |
| `VITE_DATA_SOURCE_NAME` | `TDX 運輸資料流通服務平臺` | 資料來源名稱 |
| `VITE_DATA_SOURCE_URL` | `https://tdx.transportdata.tw/` | 資料來源連結 |
| `VITE_LICENSE_NAME` | `政府資料開放授權條款` | 授權名稱 |
| `VITE_LICENSE_URL` | `https://data.gov.tw/license` | 授權連結 |
| `VITE_DEFAULT_FROM_STATION` | `TRA-1000` | 預設出發站（車站代碼；預設為臺北） |
| `VITE_DEFAULT_TO_STATION` | `TRA-0960` | 預設到達站（車站代碼；預設為汐止） |
| `VITE_DEFAULT_START_TIME` | `07:00` | 預設乘車時間起點 |
| `VITE_DEFAULT_END_TIME` | `09:00` | 預設乘車時間終點 |
| `VITE_DEFAULT_WEEK` | `auto` | 預設星期幾：`auto`＝今天，或 0（星期日）～6（星期六） |
| `VITE_DEFAULT_RESULT_LIMIT` | `10` | 查詢結果預設顯示筆數 |
| `VITE_MAX_RANGE_MINUTES` | `180` | 查詢時段最長分鐘數（需與後端限制一致） |
| `VITE_STATION_SEARCH_LIMIT` | `10` | 站名搜尋預設筆數 |
| `VITE_DEV_PORT` | `5173` | dev server 埠號 |
| `VITE_DEV_PROXY_TARGET` | `https://tdx.jiapi.net` | 開發模式同源 `/txapi` proxy 的轉發目標 |
| `VITE_GA_ID` | （空） | Google Analytics measurement ID，留空不載入 |

## 車站選擇器（路線 → 車站 兩層式）

車站選單頂端有「路線切換器」：「全部路線」＋依系統分組的 27 條路線（chip 帶路線代表色）。
選一條路線後清單只顯示該線的車站，並依站序排列、標示可轉乘的路線；再按一次同一條路線即取消篩選。

搜尋框與路線篩選會一起作用：先在選定的路線內比對，該線找不到時自動改用全站搜尋結果
（本地比對中文／英文／站碼，本地找不到才呼叫遠端拼音搜尋）。

| 資料 | 來源 | 需求 |
| --- | --- | --- |
| 路線清單（27 條，名稱、代表色、站數） | `GET /txapi/public_line/list?system=` | 每個系統 1 支 |
| 車站 → 所屬路線（`Lines` 欄位） | `GET /txapi/public_station/list` | 已載入，**不需額外請求**即可篩選路線 |
| 路線站序、轉乘路線、區段 | `GET /txapi/public_line/detail?line=` | 選該路線時才查，結果快取 |
| 全站「路線 → 站序」一次取得（備援） | `GET /txapi/finder/rail_stations` | 1 支（約 90 KB），可一次建立全部路線的站序索引 |

`public_station/list` 的 `Lines` 欄位即為線→站的反向索引（545 站中 14 站同時屬於多條路線，
例如八堵同時屬於西部幹線與東部幹線），因此路線篩選本身不需要任何額外 API。

## 多語系（i18n）

- 語言檔放在 `client/language/`，每個檔案都是**一層的 JSON，只有 key–value 配對**：
  `zh-TW.json`、`zh-CN.json`、`en.json`、`ja.json`
- 使用 `vue-i18n`（composition 模式），在 `src/i18n/index.js` 集中註冊
- 語言判斷順序：網址參數 `?lang=` → localStorage（使用者選過的語言）→ 瀏覽器標頭
  （`navigator.languages`；`zh-Hant`／`zh-TW`／`zh-HK` → 繁中，`zh-Hans`／`zh-CN` → 簡中，
  `ja` → 日文，`en` → 英文）→ 都判斷不到時**預設英文**
- 右上角有語言選單可即時切換，切換後會同步更新 `<html lang>` 與網頁標題
- 元件用 `useI18n()` 的 `t()`；Pinia store 用 `src/i18n` 匯出的 `t()`（同樣會隨語言更新）
- 資料欄位（車站名、路線名、車種）會依語言取 API 的 `Zh_tw`／`En`／`Ja` 欄位；後端只提供單一語言的
  欄位（例如路徑文字、轉乘教學標題）則保留原文
- 新增語言：複製一份 `language/*.json`、在 `src/i18n/index.js` 的 `messages` 與 `SUPPORTED_LOCALES`
  加一筆即可

## 使用的 API

| 功能 | API |
| --- | --- |
| 系統與營運單位 | `GET /txapi/public_info/systems` |
| 車站清單（545 站） | `GET /txapi/public_station/list` |
| 車站搜尋（中文／英文／拼音／站碼） | `GET /txapi/public_station/search?q=&limit=` |
| 路線清單（依系統） | `GET /txapi/public_line/list?system=` |
| 路線站序與轉乘 | `GET /txapi/public_line/detail?line=` |
| 全站路線站序（一次取得，備援） | `GET /txapi/finder/rail_stations` |
| 時刻表轉乘方案 | `GET /txapi/app_trip/journeys?from=&to=&week=&start=&end=&by=&sort=&extra=&limit=` |

查詢限制（後端限制）：同一 IP 每分鐘 60 次、每小時 1000 次；查詢時段最長 3 小時。

## 專案結構

```
client/
├─ index.html
├─ vite.config.js
├─ .env                    所有配置類項目（後端網址、產品名稱、預設查詢條件…）
├─ language/                語言檔（一層 JSON，key–value）
│  ├─ zh-TW.json  zh-CN.json  en.json  ja.json
├─ src/
│  ├─ main.js              進入點（Pinia + Router + i18n）
│  ├─ App.vue              框架、選單、語言切換、頁尾聲明
│  ├─ config/              前端設定：index.js、env.js（環境變數）、menu.js（業者與路線順序）
│  ├─ api/txapi.js         /txapi 呼叫層（統一錯誤處理、重試、同請求合併）
│  ├─ i18n/index.js        語言註冊、瀏覽器標頭判斷、切換語言
│  ├─ router/index.js      路由：說明 / 時刻表 / 車站 / 路線
│  ├─ stores/
│  │  ├─ publicInfo.js      系統／營運單位（載入一次共用）
│  │  ├─ publicStation.js   車站清單與站名搜尋（載入一次共用、搜尋有快取）
│  │  ├─ publicLine.js      路線清單與路線站序（載入一次共用、站序有快取）
│  │  └─ search.js         查詢條件與查詢結果（Pinia）
│  ├─ components/
│  │  ├─ StationSelect.vue 可搜尋的車站下拉選單
│  │  ├─ JourneyCard.vue   組合結果卡片（區段／時刻／車站／資訊／路線）
│  │  ├─ RouteFilter.vue   路徑勾選與最快 1／2／3 切換
│  │  └─ LocaleSwitcher.vue 語言切換選單
│  ├─ views/               HomeView / SearchView / StationsView / LinesView
│  └─ utils/format.js      時間與顏色格式化
└─ .gitignore              client/ 層次往下的忽略規則
```

## 頁面

- `/` 說明：功能與資料來源說明
- `/search` 時刻表：出發站／到達站、乘車時間、星期幾、出發或到達時刻、排序、不搭的列車；
  結果依接軌時刻的呈現方式列出每一種組合（區段、列車時刻、車站、列車資訊、路線資訊），上方可勾選路徑
- `/stations` 車站：全部車站清單，可搜尋
- `/lines` 路線：全部路線清單，可展開站序

查詢條件會寫入網址參數（`a`、`b`、`w`、`startTime`、`endTime`），複製網址即可重現同一組條件。

## 資料來源

交通部 TDX 運輸資料流通服務平臺（https://tdx.transportdata.tw/），依政府資料開放授權條款提供；
查詢結果由本站整理計算，僅供參考，實際以各營運單位公告為準。
