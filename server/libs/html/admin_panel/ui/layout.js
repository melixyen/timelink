/* 後臺版面：未登入只顯示登入畫面；登入後是「左側直排選單＋頂端麵包屑與個人資訊＋頁尾聲明」。
   手機（窄於 960px）左側選單收成抽屜，從頂端的選單按鈕打開。 */
import { reactive } from 'vue';
import { auth, can, api, login, logout, setupAdmin } from '../../panel/ui/index.js';

/* ---- 商品名稱、單位、開發者、頁尾聲明：來自 /txapi/status 的 site（config 的 PRODUCT_NAME、ORG_NAME 等，公開資訊） ---- */
export const siteInfo = reactive({ loaded: false, product: '', org: '', org_html: '', developer: '', email: '', notice_html: '' });
/** 讀 site 資訊；有商品名稱時把 site.title 改成「<商品名稱> 後臺」，onTitle 用來更新瀏覽器分頁標題 */
export function loadSiteInfo(site, onTitle) {
  return fetch('/txapi/status', { cache: 'no-store', credentials: 'omit' })
    .then(r => r.json())
    .then(d => {
      Object.assign(siteInfo, d.site || {}, { loaded: true });
      if (site && siteInfo.product) site.title = siteInfo.product + ' ' + site.suffix;
      if (onTitle) onTitle();
    })
    .catch(() => { siteInfo.loaded = true; });
}

const Icon = {
  name: 'AdmIcon',
  props: { n: String },
  template: `
<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
  <template v-if="n === 'menu'"><path d="M4 6h16M4 12h16M4 18h16"/></template>
  <template v-else-if="n === 'close'"><path d="M6 6l12 12M18 6L6 18"/></template>
  <template v-else-if="n === 'down'"><path d="M6 9l6 6 6-6"/></template>
  <template v-else-if="n === 'out'"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></template>
</svg>`
};

export const AdminFooter = {
  name: 'AdminFooter',
  data: () => ({ siteInfo }),
  style: `
.adm-foot { color: var(--muted); font-size: 12px; line-height: 1.7; text-align: center;
  padding: 16px 16px calc(20px + env(safe-area-inset-bottom)); border-top: 1px solid var(--line); }
.adm-foot p { margin: 0 auto; max-width: 760px; }
.adm-foot .orgline { margin-top: 4px; }
`,
  template: `
<footer class="adm-foot">
  <p v-if="siteInfo.notice_html" v-html="siteInfo.notice_html"></p>
  <p v-if="siteInfo.org_html || siteInfo.developer" class="orgline">
    <template v-if="siteInfo.org_html">所屬單位 <span v-html="siteInfo.org_html"></span></template>
    <template v-if="siteInfo.developer">
      <template v-if="siteInfo.org_html">．</template>開發者 {{ siteInfo.developer }}
      <template v-if="siteInfo.email">（<a :href="'mailto:' + siteInfo.email">{{ siteInfo.email }}</a>）</template>
    </template>
  </p>
</footer>`
};

export const LoginScreen = {
  name: 'LoginScreen',
  props: { site: Object },
  components: { AdminFooter },
  // setup：首次設定狀態（/txapi/admin_auth/setup）；還沒有任何帳號時顯示「建立管理者帳號」而不是登入
  data: () => ({ siteInfo, username: '', password: '', password2: '', busy: false, error: '', setup: null }),
  style: `
.adm-login { min-height: 100vh; display: flex; flex-direction: column; }
.adm-login .box { flex: 1; display: flex; align-items: center; justify-content: center;
  padding: calc(24px + env(safe-area-inset-top)) 16px 24px; }
.adm-login .inner { width: 100%; max-width: 400px; }
.adm-login h1 { font-size: 22px; margin: 0 0 4px; text-align: center; }
.adm-login .org { text-align: center; margin: 0 0 18px; color: var(--muted); font-size: 14px; }
.adm-login form { display: grid; gap: 12px; }
.adm-login label { display: grid; gap: 4px; font-size: 14px; color: var(--muted); }
.adm-login input { width: 100%; }
.adm-login button.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.adm-login .pub { text-align: center; margin-top: 14px; font-size: 14px; }
.adm-login .note { font-size: 13px; color: var(--muted); margin: 0; line-height: 1.6; }
.adm-login .warnbox { background: var(--warn-bg); color: var(--warn); border-radius: 12px; padding: 12px 14px; font-size: 14px; line-height: 1.6; }
`,
  computed: {
    mode() { return !this.setup ? 'loading' : !this.setup.needed ? 'login' : this.setup.open ? 'setup' : 'closed'; }
  },
  created() { this.loadSetup(); },
  methods: {
    async loadSetup() {
      try { this.setup = await api('admin_auth', 'setup'); } catch (e) { this.setup = { needed: false }; }
    },
    async doSetup() {
      if (this.busy) return;
      if (this.password !== this.password2) { this.error = '兩次輸入的密碼不一樣'; return; }
      this.busy = true;
      this.error = '';
      try {
        await setupAdmin(this.username.trim(), this.password);
        this.password = this.password2 = '';
        this.$router.replace('/');
      } catch (e) {
        this.error = e.message;
        this.loadSetup();
      } finally {
        this.busy = false;
      }
    },
    async submit() {
      if (this.busy) return;
      this.busy = true;
      this.error = '';
      try {
        await login(this.username.trim(), this.password);
        this.password = '';
        this.$router.replace(auth.user && auth.user.is_default_password ? '/account' : (this.$route.fullPath || '/'));
      } catch (e) {
        this.error = e.message;
      } finally {
        this.busy = false;
      }
    }
  },
  template: `
<div class="adm-login">
  <div class="box"><div class="inner">
    <h1>{{ site.title }}</h1>
    <p class="org" v-if="siteInfo.org_html" v-html="siteInfo.org_html"></p>
    <p class="org" v-else>{{ mode === 'setup' || mode === 'closed' ? '首次設定' : '請登入後使用' }}</p>
    <div v-if="mode === 'loading'" class="pageloading" style="padding:20px 0">載入中⋯</div>
    <Card v-else-if="mode === 'setup'" title="首次設定：建立管理者帳號">
      <form @submit.prevent="doSetup">
        <p class="note">這台主機還沒有任何後臺帳號。在這裡建立的帳號擁有全部權限（manager），建立後就關閉首次設定。
          開放到 {{ setup.expires_at }}。</p>
        <label>帳號（3–40 字，英數字與 _ . -）<input v-model="username" autocomplete="username" autocapitalize="none" required minlength="3" maxlength="40"></label>
        <label>密碼（至少 8 個字）<input v-model="password" type="password" autocomplete="new-password" required minlength="8"></label>
        <label>再輸入一次密碼<input v-model="password2" type="password" autocomplete="new-password" required minlength="8"></label>
        <div v-if="error" class="err">{{ error }}</div>
        <button class="primary" type="submit" :disabled="busy">{{ busy ? '建立中⋯' : '建立帳號並登入' }}</button>
      </form>
    </Card>
    <Card v-else-if="mode === 'closed'" title="首次設定已逾時">
      <div class="warnbox">這台主機還沒有後臺帳號，但首次設定的開放時間已過（{{ setup.expires_at }}）。<br>{{ setup.reopen }}，再重新整理這一頁。</div>
      <button type="button" style="margin-top:12px" @click="loadSetup">重新檢查</button>
    </Card>
    <Card v-else title="登入">
      <form @submit.prevent="submit">
        <label>帳號<input v-model="username" autocomplete="username" autocapitalize="none" required></label>
        <label>密碼<input v-model="password" type="password" autocomplete="current-password" required></label>
        <div v-if="error" class="err">{{ error }}</div>
        <button class="primary" type="submit" :disabled="busy">{{ busy ? '登入中⋯' : '登入' }}</button>
      </form>
    </Card>
    <div class="pub"><a :href="site.publicPanel">回公開監控面板</a></div>
  </div></div>
  <AdminFooter />
</div>`
};

export const AdminLayout = {
  name: 'AdminLayout',
  props: { site: Object, groups: Array, routes: Array },
  components: { AdminFooter, Icon },
  data: () => ({ auth, siteInfo, drawer: false, menu: false }),
  style: `
.adm { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1fr); }
.adm-side { position: fixed; z-index: 80; top: 0; bottom: 0; left: 0; width: 264px; max-width: 84vw;
  background: var(--card); border-right: 1px solid var(--line); display: flex; flex-direction: column;
  transform: translateX(-100%); transition: transform .2s ease; overflow-y: auto;
  padding: env(safe-area-inset-top) 0 env(safe-area-inset-bottom); }
.adm-side.open { transform: none; box-shadow: 0 0 40px rgba(0,0,0,.25); }
.adm-mask { position: fixed; inset: 0; z-index: 70; background: rgba(0,0,0,.35); }
.adm-brand { display: flex; align-items: flex-start; gap: 8px; padding: 16px 16px 12px; border-bottom: 1px solid var(--line); }
.adm-brand .t { flex: 1; min-width: 0; }
.adm-brand a { color: var(--text); text-decoration: none; font-weight: 700; font-size: 17px; }
.adm-brand .org { font-size: 12px; margin-top: 2px; }
.adm-brand button { min-height: 36px; padding: 6px; border: 0; background: transparent; }
.adm-nav { padding: 8px 0; flex: 1; }
.adm-nav h4 { margin: 14px 16px 4px; font-size: 12px; font-weight: 600; color: var(--muted); letter-spacing: .05em; }
.adm-nav a { display: block; padding: 10px 16px; margin: 1px 8px; border-radius: 10px; color: var(--text);
  text-decoration: none; font-size: 15px; }
.adm-nav a:hover { background: var(--chip); }
.adm-nav a.cur { background: var(--accent); color: #fff; font-weight: 600; }
.adm-sidefoot { padding: 12px 16px 16px; border-top: 1px solid var(--line); display: grid; gap: 6px; font-size: 14px; }
.adm-sidefoot a { display: inline-flex; align-items: center; gap: 4px; text-decoration: none; }
.adm-main { min-width: 0; display: flex; flex-direction: column; min-height: 100vh; }
.adm-head { position: sticky; top: 0; z-index: 50; background: var(--bg); border-bottom: 1px solid var(--line);
  padding: env(safe-area-inset-top) 0 0; }
.adm-head .bar { display: flex; align-items: center; gap: 8px; padding: 8px 16px; min-height: 56px; }
.adm-head .burger { padding: 6px 8px; display: inline-flex; align-items: center; }
.adm-crumb { flex: 1; min-width: 0; display: flex; align-items: center; gap: 6px; font-size: 14px;
  white-space: nowrap; overflow: hidden; }
.adm-crumb a { color: var(--muted); text-decoration: none; }
.adm-crumb .sep { color: var(--muted); }
.adm-crumb .now { font-weight: 700; color: var(--text); overflow: hidden; text-overflow: ellipsis; }
.adm-crumb .mid { color: var(--muted); }
.adm-user { position: relative; flex: 0 0 auto; }
.adm-user > button { display: inline-flex; align-items: center; gap: 6px; padding: 4px 8px 4px 4px; border-radius: 999px; }
.adm-user .av { width: 30px; height: 30px; border-radius: 50%; background: var(--accent); color: #fff; display: grid;
  place-items: center; font-weight: 700; font-size: 14px; }
.adm-user .nm { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.adm-user .pop { position: absolute; right: 0; top: calc(100% + 6px); min-width: 220px; background: var(--card);
  border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,.15); padding: 6px; z-index: 90; }
.adm-user .pop .who { padding: 8px 10px 10px; border-bottom: 1px solid var(--line); margin-bottom: 4px; }
.adm-user .pop .who b { display: block; overflow-wrap: anywhere; }
.adm-user .pop a, .adm-user .pop button { display: block; width: 100%; text-align: left; padding: 10px; border: 0;
  background: transparent; border-radius: 8px; color: var(--text); text-decoration: none; font-size: 14px; min-height: 0; }
.adm-user .pop a:hover, .adm-user .pop button:hover { background: var(--chip); }
.adm-warn { padding: 0 16px 8px; }
.adm-warn > div { background: var(--warn-bg); color: var(--warn); border-radius: 12px; padding: 10px 14px; font-size: 14px; }
@media (max-width: 479px) { .adm-user .nm, .adm-user .dn { display: none; } .adm-user > button { padding: 4px; } }
@media (min-width: 960px) {
  .adm { grid-template-columns: 240px minmax(0, 1fr); }
  .adm-side { position: sticky; top: 0; height: 100vh; width: auto; max-width: none; transform: none; box-shadow: none; }
  .adm-side .close, .adm-head .burger, .adm-mask { display: none; }
}
`,
  computed: {
    cur() { const p = this.$route.path.replace(/^\//, ''); return p === '' ? 'index' : p; },
    curRoute() { return this.routes.find(r => r.path === this.cur) || this.routes[0]; },
    curGroup() { const g = this.curRoute.group; return g ? (this.groups.find(x => x.key === g) || {}).label : ''; },
    navGroups() {
      return this.groups
        .map(g => ({ ...g, items: this.routes.filter(r => r.group === g.key && !r.hidden && (!r.perm || can(r.perm, 'read'))) }))
        .filter(g => g.items.length);
    },
    initial() { return (this.auth.user.username || '?').slice(0, 1).toUpperCase(); }
  },
  watch: {
    $route() { this.drawer = false; this.menu = false; }
  },
  mounted() { document.addEventListener('click', this.outside); },
  unmounted() { document.removeEventListener('click', this.outside); },
  methods: {
    href(r) { return '#/' + (r.path === 'index' ? '' : r.path); },
    outside(e) { if (this.menu && !this.$refs.user.contains(e.target)) this.menu = false; },
    async doLogout() { this.menu = false; await logout(); this.$router.replace('/'); }
  },
  template: `
<div class="adm">
  <div v-if="drawer" class="adm-mask" @click="drawer = false"></div>
  <aside class="adm-side" :class="{ open: drawer }" aria-label="功能選單">
    <div class="adm-brand">
      <div class="t">
        <a href="#/">{{ site.title }}</a>
        <div v-if="siteInfo.org_html" class="org" v-html="siteInfo.org_html"></div>
      </div>
      <button type="button" class="close" aria-label="關閉選單" @click="drawer = false"><Icon n="close" /></button>
    </div>
    <nav class="adm-nav">
      <a href="#/" :class="{ cur: cur === 'index' }">{{ site.home }}</a>
      <template v-for="g in navGroups" :key="g.key">
        <h4>{{ g.label }}</h4>
        <a v-for="r in g.items" :key="r.path" :href="href(r)" :class="{ cur: cur === r.path }">{{ r.label }}</a>
      </template>
    </nav>
    <div class="adm-sidefoot">
      <a :href="site.publicPanel" target="_blank" rel="noopener">公開監控面板 <Icon n="out" /></a>
      <a href="/txapi/readme" target="_blank" rel="noopener">說明文件 <Icon n="out" /></a>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-head">
      <div class="bar">
        <button type="button" class="burger" aria-label="打開選單" @click="drawer = true"><Icon n="menu" /></button>
        <nav class="adm-crumb" aria-label="目前位置">
          <template v-if="cur === 'index'"><span class="now">{{ site.home }}</span></template>
          <template v-else>
            <a href="#/">{{ site.home }}</a><span class="sep">/</span>
            <template v-if="curGroup"><span class="mid">{{ curGroup }}</span><span class="sep">/</span></template>
            <span class="now">{{ curRoute.label }}</span>
          </template>
        </nav>
        <div class="adm-user" ref="user">
          <button type="button" :aria-expanded="menu ? 'true' : 'false'" @click="menu = !menu">
            <span class="av">{{ initial }}</span><span class="nm">{{ auth.user.username }}</span><span class="dn"><Icon n="down" /></span>
          </button>
          <div v-if="menu" class="pop" role="menu">
            <div class="who"><b>{{ auth.user.username }}</b><span class="small muted">{{ auth.user.role }}{{ auth.user.is_super ? '（全部權限）' : '' }}．{{ auth.user.via === 'pat' ? 'PAT' : '面板登入' }}</span></div>
            <a href="#/account" role="menuitem">我的帳號</a>
            <a :href="site.publicPanel" role="menuitem" target="_blank" rel="noopener">公開監控面板</a>
            <button v-if="auth.user.via === 'session'" type="button" role="menuitem" @click="doLogout">登出</button>
          </div>
        </div>
      </div>
      <div v-if="auth.user.is_default_password && cur !== 'account'" class="adm-warn">
        <div>還在使用預設密碼，建議到 <a href="#/account">我的帳號</a> 更換。</div>
      </div>
    </header>
    <main id="main"><router-view /></main>
    <AdminFooter />
  </div>
</div>`
};

/* 根元件：先確認登入狀態；沒登入只給登入畫面，不載入任何功能頁 */
export const AdminRoot = {
  name: 'AdminRoot',
  props: { site: Object, groups: Array, routes: Array },
  components: { LoginScreen, AdminLayout },
  data: () => ({ auth }),
  template: `
<div v-if="!auth.checked" class="pageloading">載入中⋯</div>
<LoginScreen v-else-if="!auth.user" :site="site" />
<AdminLayout v-else :site="site" :groups="groups" :routes="routes" />`
};
