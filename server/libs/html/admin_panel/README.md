# TimeLink 後臺（標題用設定的 PRODUCT_NAME）

網址：`https://<本站>/admin_panel/`（hash 路由：`#/` 首頁、`#/finder`、`#/try`、`#/traffic`、`#/accounts`、`#/settings`、`#/account`）

一進來先向 `/txapi/admin_auth/me` 確認登入狀態，沒登入只顯示登入畫面，不載入任何功能頁。
新主機還沒有任何帳號時，登入畫面換成「首次設定：建立管理者帳號」（`/txapi/admin_auth/setup`，網站第一次執行後 `ADMIN_SETUP_HOURS` 小時內開放；
逾時或要重來，在網站根目錄放 `admin-setup.txt`：內容空白＝重新開放，內容 `reset`＝清除所有帳號後重新開放）。
登入後瀏覽器保存 HttpOnly 的登入 cookie（`tdx_admin`），寫入動作自動帶 `X-CSRF-Token`；登入過期（40101）會自動回到登入畫面。
版面：左側直排選單（依 `site.js` 的 `perm` 只列有讀取權限的功能，手機收成抽屜）、頂端麵包屑與右上角個人資訊、
頁尾 TDX 資料來源聲明與所屬單位、開發者（來自 `/txapi/status` 的 `site`，即設定的 `SITE_NOTICE_HTML`、`ORG_NAME_HTML`、`DEVELOPER_NAME`、`DEVELOPER_EMAIL`）。

技術與 `/panel/` 相同：不需要 build 的 Vue 3 ＋ Vue Router（CDN importmap），共用 `../panel/ui/` 的元件、主題、登入與車站快查選單。

```
libs/html/admin_panel/
├── index.html        importmap ＋ ../panel/ui/theme.css ＋ app.js
├── app.js            路由、元件註冊（installUi）、確認登入
├── site.js           標題、選單分組、路由清單與權限
├── ui/
│   ├── checklist.js  初始化檢查清單（首頁；/txapi/admin_settings/checklist）
│   └── layout.js     AdminRoot（登入前後切換）LoginScreen AdminLayout（選單、麵包屑、個人資訊）AdminFooter
└── pages/
    ├── index.js      首頁：初始化檢查清單（admin_settings 讀取）、帳號、可用功能、我的權限
    ├── finder.js     查詢功能：/txapi/finder 可用的 func、資料表是否最新、來源檔（admin_finder）
    ├── try.js        查詢測試：輸入參數呼叫 finder，看請求網址、回應時間與結果（admin_finder_try）
    ├── traffic.js    流量管理：各 IP 請求頻率與總量、封鎖與解鎖（admin_traffic）
    ├── accounts.js   帳號管理：帳號（停權／啟用）、角色與權限、登入紀錄、所有 PAT（admin_accounts）
    ├── settings.js   設定管理：檢視、項目編輯器、整包下載 custom/（admin_settings）
    └── account.js    我的帳號：改密碼、產生與撤銷 PAT
```

## 新增一頁

1. 在 `pages/` 放一支元件，從 `../../panel/ui/index.js` 取 `auth`、`can`、`api`
2. 在 `site.js` 的 `routes` 加 `{ path: 'foo', label: '名稱', group: 'system', perm: 'admin_xxx' }`（權限路由在 `libs/txapi/admin/admin.php` 的 `ADMIN_ROUTES`）

## 網址對應

根目錄 `.htaccess` 把 `/admin_panel/` 對應到本目錄的 `index.html`，`/admin_panel/<檔案>` 對應到同名檔案；
`libs/html/.htaccess` 只開放靜態檔，PHP 一律拒絕。頁面本身不含機密，資料都要登入後由 `/txapi/admin_*` 取得。
