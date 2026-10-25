/* 監控資料的共用狀態：所有頁面讀同一份資料，由頂端列負責重新整理與自動更新。 */
import { reactive } from 'vue';

export const store = reactive({
  api: '/txapi/monitor',
  data: null,        // 最近一次成功取得的 /txapi/monitor 回應
  loading: false,
  error: '',
  auto: true,
  interval: 60
});

let timer = null;

/** Proxy 服務名稱（設定的 PRODUCT_SERVICE_NAME，來自 /txapi/monitor 的 site.service），標在屬於 Proxy 的區塊 */
export function svcName() {
  return (store.data && store.data.site && store.data.site.service) || 'TDX Proxy';
}

export function load() {
  if (store.loading) return Promise.resolve();
  store.loading = true;
  return fetch(store.api, { cache: 'no-store', credentials: 'omit' })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(d => {
      if (!d || !d.ok) throw new Error((d && d.error) || '回應格式錯誤');
      store.data = d;
      store.error = '';
    })
    .catch(e => { store.error = '無法取得監控資料：' + e.message; })
    .finally(() => { store.loading = false; });
}

export function setAuto(on) {
  store.auto = on;
  clearInterval(timer);
  if (on) timer = setInterval(() => { if (!document.hidden) load(); }, store.interval * 1000);
}

export function initStore({ api, refreshSeconds } = {}) {
  if (api) store.api = api;
  if (refreshSeconds) store.interval = refreshSeconds;
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && store.auto) load();
  });
  setAuto(true);
  return load();
}
