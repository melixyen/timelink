/* 後臺登入狀態與 txapi 呼叫（/txapi/<action>/<func>?參數）：cookie 登入（same-origin），寫入動作自動帶 X-CSRF-Token。
   未登入時面板只顯示公開資訊；後臺頁面依 permissions 的讀取權限顯示在選單。 */
import { reactive } from 'vue';

export const auth = reactive({
  checked: false,     // 已向伺服器確認過登入狀態
  user: null,         // { id, username, role, is_super, is_default_password, via }
  permissions: {},    // { admin_xxx: { read, write } }
  routes: [],         // 權限路由清單（給角色設定）
  csrf: ''
});

function setMe(d) {
  auth.user = d.user || null;
  auth.permissions = d.permissions || {};
  auth.routes = d.routes || [];
  auth.csrf = d.csrf || '';
}

export function can(route, mode = 'read') {
  return !!(auth.user && auth.permissions[route] && auth.permissions[route][mode]);
}

/** txapi 網址：txapiUrl('transfer', 'route', { from: … }) → /txapi/transfer/route?from=… */
export function txapiUrl(action, func, query) {
  const qs = query instanceof URLSearchParams ? query.toString() : new URLSearchParams(query || {}).toString();
  return '/txapi/' + action + (func ? '/' + func : '') + (qs ? '?' + qs : '');
}

/** 呼叫後臺 API：api('admin_accounts', 'users')、api('admin_accounts', 'user_create', { ... }) */
export async function api(action, func, body, query = {}) {
  const opt = { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
  if (body !== undefined) {
    opt.method = 'POST';
    opt.headers['Content-Type'] = 'application/json';
    if (auth.csrf) opt.headers['X-CSRF-Token'] = auth.csrf;
    opt.body = JSON.stringify(body);
  }
  let r, d;
  try {
    r = await fetch(txapiUrl(action, func, query), opt);
    d = await r.json();
  } catch (e) {
    throw new Error('連線失敗：' + e.message);
  }
  if (!d || d.ok === false) {
    if (d && d.errorCode === 40101 && auth.user) { auth.user = null; auth.permissions = {}; }
    const err = new Error((d && (d.errorMsg || d.error)) || ('HTTP ' + r.status));
    err.code = d && d.errorCode;
    throw err;
  }
  return d;
}

export async function checkMe() {
  try {
    setMe(await api('admin_auth', 'me'));
  } catch (e) {
    auth.user = null;
    auth.permissions = {};
  } finally {
    auth.checked = true;
  }
}

export async function login(username, password) {
  const d = await api('admin_auth', 'login', { username, password });
  setMe(d);
  return d;
}

/** 首次設定（還沒有任何帳號時）：建立第一個管理者並直接登入 */
export async function setupAdmin(username, password) {
  const d = await api('admin_auth', 'setup', { username, password });
  setMe(d);
  return d;
}

export async function logout() {
  try { await api('admin_auth', 'logout', {}); } catch (e) { /* 已登出也沒關係 */ }
  auth.user = null;
  auth.permissions = {};
  auth.csrf = '';
}
