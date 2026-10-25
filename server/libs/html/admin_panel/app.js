/* =========================================================
   後臺 /admin_panel/（不需要 build 的 Vue 3，與 /panel/ 共用 ui/ 元件與主題）

     index.html   importmap（vue、vue-router）＋ ../panel/ui/theme.css ＋ app.js
     site.js      後臺設定（標題、選單分組、路由清單與權限）
     ui/layout.js 登入畫面、左側選單、麵包屑、個人資訊、頁尾聲明
     pages/*.js   每頁一支元件（共用元件從 ../../panel/ui/index.js 匯入）

   一進來先向 /txapi/admin_auth/me 確認登入；沒登入只顯示登入畫面。
   登入後瀏覽器保存 HttpOnly 的登入 cookie，寫入動作自動帶 X-CSRF-Token（ui/auth.js）。
   ========================================================= */
import { createApp } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import { installUi, checkMe } from '../panel/ui/index.js';
import site, { groups, routes } from './site.js';
import { AdminRoot, loadSiteInfo } from './ui/layout.js';

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    ...routes.map(r => ({
      path: r.path === 'index' ? '/' : '/' + r.path,
      component: asyncPage(() => import(`./pages/${r.path}.js`)),
      meta: { t: r.label, keepScroll: !!r.keepScroll }
    })),
    { path: '/:pathMatch(.*)*', redirect: '/' }
  ],
  scrollBehavior(to, from, saved) {
    if (saved) return saved;
    if (to.path === from.path && to.meta.keepScroll) return false;
    return { top: 0 };
  }
});
function setTitle() {
  const t = router.currentRoute.value.meta.t;
  document.title = (t ? t + '｜' : '') + site.title;
}
router.afterEach(setTitle);

const app = installUi(createApp({
  name: 'AdminApp',
  components: { AdminRoot },
  data: () => ({ site, groups, routes }),
  template: `<AdminRoot :site="site" :groups="groups" :routes="routes" />`
}));
app.use(router);
app.mount('#app');
loadSiteInfo(site, setTitle);
checkMe();

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
