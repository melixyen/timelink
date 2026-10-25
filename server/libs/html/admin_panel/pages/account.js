/* 我的帳號：改密碼、Personal Access Token（PAT） */
import { auth, api, logout } from '../../panel/ui/index.js';

function plusDays(n) {
  const d = new Date(Date.now() + n * 86400000);
  return d.toISOString().slice(0, 10);
}

export default {
  name: 'PageAccount',
  data: () => ({
    auth,
    pw: { old: '', neu: '', again: '' }, pwMsg: '', pwErr: '', pwBusy: false,
    tokens: [], tkErr: '', tkBusy: false,
    tk: { name: '', expires: plusDays(90) }, created: null, copied: false
  }),
  style: `
.acct form { display: grid; gap: 10px; }
.acct label { display: grid; gap: 4px; font-size: 14px; color: var(--muted); }
.acct input { width: 100%; }
.acct .row2 { display: grid; gap: 10px; grid-template-columns: 1fr; }
@media (min-width: 560px) { .acct .row2 { grid-template-columns: 1fr 180px; } }
.acct .ok { background: var(--ok-bg); color: var(--ok); border-radius: 12px; padding: 10px 14px; }
.acct button.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.tk { border-top: 1px solid var(--line); padding: 10px 0; display: flex; gap: 10px; align-items: flex-start; }
.tk:first-child { border-top: 0; }
.tk .main { flex: 1; min-width: 0; }
.tk .main b { overflow-wrap: anywhere; }
.newtoken { background: var(--ok-bg); border-radius: 12px; padding: 12px; display: grid; gap: 8px; }
.newtoken code { overflow-wrap: anywhere; font-size: 13px; background: var(--card); padding: 8px; border-radius: 8px; }
.presets { display: flex; flex-wrap: wrap; gap: 6px; }
.presets button { min-height: 32px; padding: 4px 10px; font-size: 13px; }
`,
  computed: {
    activeCount() { return this.tokens.filter(t => t.status === 'active').length; },
    isAccount() { return !!this.auth.user; }
  },
  watch: {
    'auth.checked': { immediate: true, handler(v) { if (v && this.auth.user) this.loadTokens(); } }
  },
  methods: {
    plusDays,
    tone(s) { return s === 'active' ? 'ok' : (s === 'expired' ? 'warn' : 'bad'); },
    statusName(s) { return { active: '有效', expired: '已過期', revoked: '已撤銷' }[s] || s; },
    async loadTokens() {
      this.tkErr = '';
      try { this.tokens = (await api('admin_auth', 'tokens')).tokens; } catch (e) { this.tkErr = e.message; }
    },
    async changePw() {
      this.pwMsg = this.pwErr = '';
      if (this.pw.neu !== this.pw.again) { this.pwErr = '兩次輸入的新密碼不同'; return; }
      this.pwBusy = true;
      try {
        const d = await api('admin_auth', 'password', { old_password: this.pw.old, new_password: this.pw.neu });
        this.pwMsg = d.message;
        this.pw = { old: '', neu: '', again: '' };
        this.auth.user.is_default_password = false;
      } catch (e) { this.pwErr = e.message; } finally { this.pwBusy = false; }
    },
    async createToken() {
      this.tkErr = '';
      this.tkBusy = true;
      try {
        const d = await api('admin_auth', 'token_create', { name: this.tk.name.trim(), expires: this.tk.expires });
        this.created = d;
        this.copied = false;
        this.tk.name = '';
        await this.loadTokens();
      } catch (e) { this.tkErr = e.message; } finally { this.tkBusy = false; }
    },
    async revoke(t) {
      if (!confirm('撤銷 PAT「' + t.name + '」？使用它的程式會立刻失去權限。')) return;
      try { await api('admin_auth', 'token_revoke', { id: t.id }); await this.loadTokens(); } catch (e) { this.tkErr = e.message; }
    },
    async copy() {
      try { await navigator.clipboard.writeText(this.created.token); this.copied = true; } catch (e) { this.copied = false; }
    },
    async doLogout() { await logout(); this.$router.replace('/'); }
  },
  template: `
<div class="acct">
  <div v-if="!auth.checked" class="pageloading">載入中⋯</div>
  <div v-else-if="!auth.user" class="pageloading">請先登入</div>
  <div v-else class="grid">
    <Card title="帳號">
      <KvRow k="帳號">{{ auth.user.username }}</KvRow>
      <KvRow k="角色">{{ auth.user.role }}</KvRow>
      <KvRow k="登入方式">{{ { session: '面板登入', pat: 'PAT' }[auth.user.via] || auth.user.via }}</KvRow>
      <div v-if="auth.user.is_default_password" class="err" style="margin-top:10px;background:var(--warn-bg);color:var(--warn)">還在使用預設密碼，建議在下方更換。</div>
      <button v-if="auth.user.via === 'session'" type="button" style="margin-top:12px" @click="doLogout">登出</button>
    </Card>

    <Card v-if="isAccount" title="更換密碼">
      <form @submit.prevent="changePw">
        <label>目前密碼<input v-model="pw.old" type="password" autocomplete="current-password" required></label>
        <label>新密碼（至少 5 個字）<input v-model="pw.neu" type="password" autocomplete="new-password" required minlength="5"></label>
        <label>再輸入一次新密碼<input v-model="pw.again" type="password" autocomplete="new-password" required></label>
        <div v-if="pwErr" class="err">{{ pwErr }}</div>
        <div v-if="pwMsg" class="ok">{{ pwMsg }}</div>
        <button class="primary" type="submit" :disabled="pwBusy">更換密碼</button>
      </form>
    </Card>

    <Card v-if="isAccount" title="Personal Access Token（PAT）" style="grid-column: 1 / -1">
      <p class="small muted" style="margin:0 0 10px">給程式與 AI 代理呼叫 admin_ 開頭的 API：<span class="mono">Authorization: Bearer &lt;PAT&gt;</span>。權限跟著你的角色。</p>
      <form @submit.prevent="createToken">
        <div class="row2">
          <label>名稱<input v-model="tk.name" maxlength="60" placeholder="例如：資料更新排程" required></label>
          <label>到期日<input v-model="tk.expires" type="date" :min="plusDays(1)" required></label>
        </div>
        <div class="presets">
          <button type="button" @click="tk.expires = plusDays(7)">7 天</button>
          <button type="button" @click="tk.expires = plusDays(30)">30 天</button>
          <button type="button" @click="tk.expires = plusDays(90)">90 天</button>
          <button type="button" @click="tk.expires = plusDays(365)">1 年</button>
        </div>
        <button class="primary" type="submit" :disabled="tkBusy">產生 PAT</button>
      </form>
      <div v-if="created" class="newtoken" style="margin-top:12px">
        <b>新的 PAT（只顯示這一次，請立刻保存）</b>
        <code class="mono">{{ created.token }}</code>
        <div><button type="button" @click="copy">{{ copied ? '已複製' : '複製' }}</button>
          <button type="button" @click="created = null">我已保存，關閉</button></div>
      </div>
      <div v-if="tkErr" class="err" style="margin-top:10px">{{ tkErr }}</div>
      <h3 style="font-size:14px;margin:16px 0 4px">我的 PAT（有效 {{ activeCount }} 個）</h3>
      <div v-if="!tokens.length" class="empty">還沒有 PAT</div>
      <div v-for="t in tokens" :key="t.id" class="tk">
        <div class="main">
          <b>{{ t.name }}</b> <Chip :tone="tone(t.status)">{{ statusName(t.status) }}</Chip>
          <div class="small muted mono">{{ t.prefix }}</div>
          <div class="small muted">建立 {{ t.created_at }}．到期 {{ t.expires_at }}<template v-if="t.last_used_at">．最後使用 {{ t.last_used_at }}（{{ t.last_used_ip }}）</template></div>
        </div>
        <button v-if="t.status === 'active'" type="button" @click="revoke(t)">撤銷</button>
      </div>
    </Card>
  </div>
</div>`
};
