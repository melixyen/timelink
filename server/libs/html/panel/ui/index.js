/* =========================================================
   監控面板 UI 入口（不需要 build 的 Vue 3）

     index.html   importmap（vue、vue-router）＋ ui/theme.css ＋ app.js
     site.js      面板設定（站名、資料來源、更新間隔、路由清單、頁尾）
     app.js       import { createPanelApp } from './ui/index.js'
     pages/*.js   每頁一支元件，template ＋ style ＋ 邏輯寫在一起
   ========================================================= */
import { createApp, reactive, watch } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import * as base from './components.js';
import { AppNav, AppFooter, PrivacyNotice } from './shell.js';
import { initStore, store } from './store.js';

export * from './components.js';
export * from './format.js';
export { store, load, svcName } from './store.js';
export { auth, can, api, txapiUrl, checkMe, login, logout, setupAdmin } from './auth.js';
export { AppNav, AppFooter };

/* ---- 元件內 CSS：掛載時注入一次 ---- */
const injected = new Set();
export function injectStyle(name, css) {
  if (!css || injected.has(name)) return;
  injected.add(name);
  const el = document.createElement('style');
  el.setAttribute('data-comp', name);
  el.textContent = css;
  document.head.appendChild(el);
}

const styleMixin = {
  created() {
    const o = this.$options;
    if (o.style) injectStyle(o.name || 'anon', o.style);
  }
};

const registry = Object.fromEntries(
  Object.entries(base).filter(([, v]) => v && typeof v === 'object' && (v.template || v.render))
);

/** 註冊共用元件（Card、KvRow、Chip⋯）與元件內 CSS 注入（其他頁面也可共用） */
export function installUi(app) {
  app.mixin(styleMixin);
  Object.entries(registry).forEach(([name, comp]) => app.component(name, comp));
  return app;
}

/**
 * 建立並掛載監控面板。
 * @param {object}   cfg.site      { title, suffix, en, api, refreshSeconds, footer }（有 suffix 時標題改成「<商品名稱> <suffix>」）
 * @param {Array}    cfg.routes    [{ path:'list', label:'快取清單', en:'ENTRIES' }]，path 'index' 對應 '/'；hidden:true 不放選單
 * @param {Function} cfg.loadPage  (name) => import(`./pages/${name}.js`)
 */
export function createPanelApp(cfg) {
  const { routes = [], loadPage, mount = '#app' } = cfg;
  const site = reactive(cfg.site || {});

  const router = createRouter({
    history: createWebHashHistory(),
    routes: [
      ...routes.map(r => ({
        path: r.path === 'index' ? '/' : '/' + r.path,
        component: asyncPage(() => loadPage(r.path)),
        meta: { t: r.label, keepScroll: !!r.keepScroll, perm: r.perm || null }
      })),
      { path: '/:pathMatch(.*)*', redirect: '/' }
    ],
    scrollBehavior(to, from, saved) {
      if (saved) return saved;
      // 同一頁只改網址參數（例如資料庫頁換頁、篩選）時由頁面自己決定捲動位置
      if (to.path === from.path && to.meta.keepScroll) return false;
      return { top: 0 };
    }
  });
  const setTitle = () => {
    const t = router.currentRoute.value.meta.t;
    document.title = (t ? t + '｜' : '') + (site.title || '');
  };
  router.afterEach(setTitle);
  // 標題改用商品名稱（設定的 PRODUCT_NAME，來自 /txapi/monitor 的 site.product）＋ site.suffix
  watch(() => store.data && store.data.site && store.data.site.product, (p) => {
    if (p && site.suffix) { site.title = p + ' ' + site.suffix; setTitle(); }
  });

  const App = {
    name: 'App',
    components: { AppNav, AppFooter, PrivacyNotice },
    data: () => ({ site, routes }),
    template: `
<AppNav :site="site" :routes="routes" />
<main id="main"><router-view /></main>
<AppFooter :site="site" />
<PrivacyNotice />`
  };

  const app = installUi(createApp(App));
  app.use(router);
  app.mount(mount);
  initStore({ api: site.api, refreshSeconds: site.refreshSeconds });
  return { app, router };
}

/* 非同步頁面：載入中與失敗都有一致的畫面 */
function asyncPage(loader) {
  return {
    name: 'AsyncPage',
    data: () => ({ comp: null, failed: false }),
    created() {
      loader().then(m => { this.comp = m.default || m; }).catch(() => { this.failed = true; });
    },
    template: `
<component v-if="comp" :is="comp" />
<div v-else-if="failed" class="pageloading">這一頁載入失敗，請重新整理。</div>
<div v-else class="pageloading">載入中⋯</div>`
  };
}
