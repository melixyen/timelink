/* 面板設定（只有公開資訊，沒有登入功能）。新增頁面：在 pages/ 放一支元件，再在 routes 加一筆。 */
export default {
  title: 'TimeLink 監控',         // 載入後改成「<商品名稱> 監控」（設定的 PRODUCT_NAME）
  suffix: '監控',
  en: 'MONITOR',
  api: '/txapi/monitor',       // 監控資料來源（公開、唯讀、不含機密）
  refreshSeconds: 60,            // 自動更新間隔
  footer: '資料來源 <a href="/txapi/monitor">/txapi/monitor</a>．'
        + '說明文件 <a href="/txapi/readme">/txapi/readme</a>．'
        + '<a href="/txapi/download">下載</a>．<a href="/txapi/finder">查詢</a>．<a href="/txapi/sqlite">資料庫</a>'
};

export const routes = [
  { path: 'index', label: '總覽',     en: 'OVERVIEW' },
  { path: 'cache', label: '快取統計', en: 'CACHE' },
  { path: 'list',  label: '快取清單', en: 'ENTRIES' },
  { path: 'download', label: '下載狀態', en: 'DOWNLOAD' },
  { path: 'sqlite', label: '資料庫', en: 'SQLITE', keepScroll: true }
];
