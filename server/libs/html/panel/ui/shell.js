/* 版面外殼：頂端列（站名、更新時間、自動更新、重新整理、分頁）、頁尾（連結＋聲明）與底部的資料收集提示。 */
import { reactive } from 'vue';
import { store, load, setAuto } from './store.js';

export const AppNav = {
  name: 'AppNav',
  props: { site: Object, routes: { type: Array, default: () => [] } },
  data: () => ({ store }),
  style: `
.appnav { position: sticky; top: 0; z-index: 50; background: var(--bg);
  padding-top: env(safe-area-inset-top); border-bottom: 1px solid var(--line); }
.appnav-bar { max-width: var(--maxw); margin: 0 auto; padding: 10px 16px 8px; display: flex; align-items: center; gap: 8px; }
.appnav-title { flex: 1; min-width: 0; }
.appnav-title a { color: var(--text); text-decoration: none; font-weight: 700; font-size: 18px; }
.appnav-title small { display: block; font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.appnav-tabs { max-width: var(--maxw); margin: 0 auto; padding: 0 16px; display: flex; gap: 4px; overflow-x: auto; scrollbar-width: none; }
.appnav-tabs::-webkit-scrollbar { display: none; }
.appnav-tabs a { flex: 0 0 auto; padding: 8px 12px 10px; color: var(--muted); text-decoration: none; font-size: 14px;
  font-weight: 600; border-bottom: 2px solid transparent; }
.appnav-tabs a.cur { color: var(--text); border-bottom-color: var(--accent); }
.appnav-err { max-width: var(--maxw); margin: 8px auto 0; padding: 0 16px; }
@media (max-width: 420px) { .appnav-bar button.auto { display: none; } }
`,
  computed: {
    pub() { return this.routes.filter(r => !r.hidden); },
    cur() {
      const p = this.$route.path.replace(/^\//, '');
      return p === '' ? 'index' : p;
    },
    updated() {
      if (this.store.loading && !this.store.data) return '載入中…';
      if (this.store.error && !this.store.data) return '更新失敗';
      return this.store.data ? '更新於 ' + this.store.data.now : '';
    }
  },
  methods: {
    refresh() { load(); },
    toggle() { setAuto(!this.store.auto); }
  },
  template: `
<nav class="appnav">
  <div class="appnav-bar">
    <div class="appnav-title">
      <a href="#/">{{ site.title }}</a>
      <small>{{ updated }}</small>
    </div>
    <button type="button" class="auto" :aria-pressed="store.auto ? 'true' : 'false'" @click="toggle">
      {{ store.auto ? '自動 ' + store.interval + 's' : '自動 關' }}
    </button>
    <button type="button" :disabled="store.loading" @click="refresh">重新整理</button>
  </div>
  <div class="appnav-tabs">
    <a v-for="r in pub" :key="r.path" :href="'#/' + (r.path === 'index' ? '' : r.path)"
       :class="{ cur: cur === r.path }">{{ r.label }}</a>
  </div>
  <div v-if="store.error" class="appnav-err"><div class="err">{{ store.error }}</div></div>
</nav>`
};

export const AppFooter = {
  name: 'AppFooter',
  props: { site: Object },
  data: () => ({ store }),
  style: `
.appfoot { color: var(--muted); font-size: 12px; line-height: 1.7; text-align: center; padding: 8px 16px calc(20px + env(safe-area-inset-bottom)); }
.appfoot p { margin: 0 auto; max-width: 760px; }
.appfoot .notice { margin-top: 6px; }
`,
  computed: {
    // 單位、開發者、聲明來自 /txapi/monitor 的 site（config 的 ORG_NAME、SITE_NOTICE_HTML 等）
    info() { return (this.store.data && this.store.data.site) || null; }
  },
  methods: { showPrivacy() { privacy.open = true; } },
  template: `
<footer class="appfoot">
  <p v-html="site.footer"></p>
  <p v-if="info && info.privacy_html"><a href="#" @click.prevent="showPrivacy">資料收集說明</a></p>
  <template v-if="info">
    <p v-if="info.notice_html" class="notice" v-html="info.notice_html"></p>
    <p v-if="info.org_html || info.developer">
      <template v-if="info.org_html">所屬單位 <span v-html="info.org_html"></span></template>
      <template v-if="info.developer"><template v-if="info.org_html">．</template>開發者 {{ info.developer }}<template v-if="info.email">（<a :href="'mailto:' + info.email">{{ info.email }}</a>）</template></template>
    </p>
  </template>
</footer>`
};

/* ---- 資料收集提示：第一次開啟時顯示在底部；按「我知道了」後記在 localStorage（內容改了會再顯示） ---- */
const PRIVACY_KEY = 'timelink.privacy_ack';
const privacy = reactive({ open: false, checked: false });

function noticeHash(s) {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return String(h >>> 0);
}
function readAck() { try { return localStorage.getItem(PRIVACY_KEY) || ''; } catch (e) { return ''; } }
function writeAck(v) { try { localStorage.setItem(PRIVACY_KEY, v); } catch (e) { /* 無痕模式等不能寫就算了 */ } }

export const PrivacyNotice = {
  name: 'PrivacyNotice',
  data: () => ({ store, privacy }),
  style: `
.privacy { position: fixed; z-index: 90; left: 0; right: 0; bottom: 0; padding: 0 12px calc(12px + env(safe-area-inset-bottom)); pointer-events: none; }
.privacy .box { pointer-events: auto; max-width: 760px; margin: 0 auto; background: var(--card); color: var(--text); border: 1px solid var(--line);
  border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,.18); padding: 14px 16px; display: flex; gap: 12px; align-items: center; }
.privacy .box b { display: block; font-size: 14px; margin-bottom: 4px; }
.privacy .box p { margin: 0; font-size: 13px; line-height: 1.6; color: var(--muted); }
.privacy .box button { flex: 0 0 auto; background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
@media (max-width: 560px) { .privacy .box { flex-direction: column; align-items: stretch; } .privacy .box button { width: 100%; } }
`,
  computed: {
    html() { return (this.store.data && this.store.data.site && this.store.data.site.privacy_html) || ''; },
    show() { return this.privacy.open && !!this.html; }
  },
  watch: {
    html: {
      immediate: true,
      handler(v) {
        if (!v || this.privacy.checked) return;
        this.privacy.checked = true;
        if (readAck() !== noticeHash(v)) this.privacy.open = true;
      }
    },
    show(v) { this.$nextTick(() => this.pad(v)); }
  },
  methods: {
    ack() { writeAck(noticeHash(this.html)); this.privacy.open = false; },
    // 提示蓋在底部時，頁面底部留出同樣的高度，頁尾不會被擋住
    pad(on) { document.body.style.paddingBottom = on && this.$refs.box ? (this.$refs.box.offsetHeight + 24) + 'px' : ''; }
  },
  template: `
<div v-if="show" class="privacy" role="dialog" aria-live="polite" aria-label="資料收集說明">
  <div class="box" ref="box">
    <div><b>資料收集說明</b><p v-html="html"></p></div>
    <button type="button" @click="ack">我知道了</button>
  </div>
</div>`
};
