# TimeLink 監控面板（標題用設定的 PRODUCT_NAME）

網址：`https://<本站>/panel/`（hash 路由：`#/`、`#/cache`、`#/list`、`#/download`、`#/sqlite`）

不需要 build 的 Vue 3 單頁應用：
Vue 與 Vue Router 由 CDN 經 importmap 載入，每頁一支元件（template ＋ style ＋ 邏輯）。
面板只有公開頁，資料來自公開唯讀的 `/txapi/monitor`（`#/sqlite` 另外讀 `/txapi/sqlite`），面板本身不含任何機密。
面板沒有登入功能。`ui/` 的元件、主題、`ui/auth.js`（後臺登入）與車站快查選單也給其他頁面共用，改動時要一併測試。
頁尾除了 `site.js` 的連結，還顯示 `/txapi/monitor` 的 `site`：TDX 資料來源聲明、所屬單位、開發者（設定的 `SITE_NOTICE_HTML` 等）。

```
libs/html/panel/
├── index.html        importmap ＋ ui/theme.css ＋ app.js
├── app.js            createPanelApp({ site, routes, loadPage })
├── site.js           站名、資料來源、更新間隔、路由清單、頁尾
├── ui/
│   ├── index.js      createPanelApp()：路由、元件註冊、元件 CSS 注入、非同步頁面；installUi()（後臺也用）
│   ├── theme.css     設計變數（淺色／深色）與基礎樣式
│   ├── store.js      共用的監控資料狀態、重新整理、自動更新
│   ├── auth.js       後臺登入狀態、權限、api()、txapiUrl()（寫入自動帶 X-CSRF-Token；後臺頁面用）
│   ├── format.js     容量、時間、百分比格式化與中文名稱
│   ├── components.js Card KvRow Chip Meter BigStat
│   ├── stationpicker.js （後臺查詢測試用）車站快查選單 StationPicker（營運單位 → 路線 → 站序，路線代表色）與 loadStations()、textOn()
│   └── shell.js      AppNav（頂端列＋分頁）AppFooter（連結＋聲明）
└── pages/
    ├── index.js      總覽：服務狀態、TDX 流量、快取用量
    ├── cache.js      快取統計：各模式容量與命中、快取時間設定
    ├── list.js       快取清單：搜尋、篩選、排序
    ├── download.js   下載狀態：/txapi/download 各檔案的存檔、需要更新、適用期間，以及轉入 SQLite 的狀態
    └── sqlite.js     資料庫：唯讀瀏覽 SQLite（資料表清單、結構、篩選、排序、後端分頁、關聯跳轉、單筆明細）
```

## 新增一頁

1. 在 `pages/` 放一支元件，例如 `pages/foo.js`，從 `../ui/index.js` 取 `store` 讀資料
2. 在 `site.js` 的 `routes` 加 `{ path: 'foo', label: '名稱', en: 'FOO' }`

## 網址對應

根目錄 `.htaccess` 把 `/panel/` 對應到本目錄的 `index.html`，`/panel/<檔案>` 對應到本目錄同名檔案；
`libs/html/.htaccess` 只開放靜態檔，PHP 一律拒絕。

## 資料庫頁（`#/sqlite`）

- 狀態全部在網址：`#/sqlite?db=finder&t=train_stop&f=train_key:eq:TRA-123&q=&sort=seq&dir=desc&page=2&per=50`，
  可分享、上一頁返回
- 關聯欄位（例如 `train_stop.station_uid`）點了跳到目標表並帶 `欄位:eq:值`；被參照的主鍵旁有 `↳表名` 反向連結；
  JSON 欄位內的站碼（`info.StartingStationUID` 等）在單筆明細中可跳轉。關聯登記在 `libs/txapi/finderdb.php` 的 `finderDbBrowseDocs()`
- 只讀：後端 `libs/txapi/sqlitebrowse.php` 只接受 GET、以唯讀旗標開檔，識別字白名單、值參數綁定

## 車站快查選單（後臺查詢測試頁使用）

- 查詢測試頁的參數放在網址：`#/try?func=rail_station_time&station=TRA-1000&week=1&start=08:00&end=09:00`，開啟即查詢
- 車次可點（跳到 `rail_train_time&key=`），起訖站、停靠站可點（跳到該站的車站時刻，保留星期／日期）
- `StationPicker`（`ui/stationpicker.js`）：`<StationPicker v-model="uid" label="選擇車站" />`，資料來自
  `/txapi/finder/rail_stations`（整頁共用、只載入一次）。先選營運單位，再捲動依路線、站序排列的車站，
  可輸入站名（台／臺視為相同）、英文或站碼快查，上方路線色塊可跳到該路線。
  清單依 `Sections[]` 分組（臺鐵依 railtime 路線選擇器分段，例如西部幹線拆成基隆-竹南、山線、彰化-嘉義、嘉義-高雄、屏東線），
  標題與車站列以區段代表色為底，字色由 `textOn()` 依 WCAG 相對亮度取對比較高的黑或白。
  手機為底部面板、桌面為下拉選單
