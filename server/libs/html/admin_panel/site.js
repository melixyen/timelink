/* 後臺設定。新增頁面：在 pages/ 放一支元件，再在 routes 加一筆。
   perm：該頁需要的權限路由（admin_ 開頭），有讀取權限才出現在左側選單；group：選單分組；hidden：不放選單。 */
import { reactive } from 'vue';

// title 載入後會改成「<商品名稱> 後臺」（設定的 PRODUCT_NAME，來自 /txapi/status 的 site.product）
export default reactive({
  title: '後臺',
  suffix: '後臺',
  short: '後臺',
  home: '首頁',
  publicPanel: '/panel/'   // 公開監控面板
});

export const groups = [
  { key: 'query',  label: '查詢' },
  { key: 'system', label: '系統管理' }
];

export const routes = [
  { path: 'index',    label: '首頁' },
  { path: 'finder',   label: '查詢功能', group: 'query',  perm: 'admin_finder' },
  { path: 'try',      label: '查詢測試', group: 'query',  perm: 'admin_finder_try' },
  { path: 'traffic',  label: '流量管理', group: 'system', perm: 'admin_traffic', keepScroll: true },
  { path: 'accounts', label: '帳號管理', group: 'system', perm: 'admin_accounts', keepScroll: true },
  { path: 'settings', label: '設定管理', group: 'system', perm: 'admin_settings' },
  { path: 'account',  label: '我的帳號', hidden: true }
];
