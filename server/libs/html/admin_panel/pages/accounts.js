/* 帳號管理（admin_accounts）：帳號、角色與權限、登入紀錄、所有 PAT */
import { auth, can, api } from '../../panel/ui/index.js';

const TABS = [
  { key: 'users', label: '帳號' },
  { key: 'roles', label: '角色與權限' },
  { key: 'logs', label: '登入紀錄' },
  { key: 'tokens', label: '所有 PAT' }
];

export default {
  name: 'PageAccounts',
  data: () => ({
    auth, TABS, tab: 'users', err: '', msg: '', busy: false,
    users: [], roles: [], routes: [], tokens: [],
    logs: [], logMore: false, logNext: null, logF: { user: '', ip: '', success: '' }, retention: 0,
    nu: { username: '', password: '', role_id: '' },
    edit: null,           // 正在編輯的帳號 { id, role_id, disabled, password }
    roleEdit: null        // 正在編輯／新增的角色 { id?, name, description, permissions }
  }),
  style: `
.subtabs { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; margin-bottom: 12px; }
.subtabs button { flex: 0 0 auto; }
.subtabs button.cur { background: var(--accent); color: #fff; border-color: var(--accent); }
.acc form, .acc .form { display: grid; gap: 10px; }
.acc label { display: grid; gap: 4px; font-size: 14px; color: var(--muted); }
.acc input, .acc select { width: 100%; }
.acc .cols { display: grid; gap: 10px; grid-template-columns: 1fr; }
@media (min-width: 640px) { .acc .cols { grid-template-columns: repeat(3, 1fr); } }
.acc .ok { background: var(--ok-bg); color: var(--ok); border-radius: 12px; padding: 10px 14px; }
.acc button.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.acc button.danger { color: var(--bad); }
.item { border-top: 1px solid var(--line); padding: 10px 0; }
.item:first-child { border-top: 0; }
.item .head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.item .head b { overflow-wrap: anywhere; }
.item .acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.perm { width: 100%; border-collapse: collapse; font-size: 14px; }
.perm th, .perm td { padding: 8px 4px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
.perm td.c, .perm th.c { text-align: center; width: 52px; }
.perm input[type=checkbox] { width: 22px; height: 22px; min-height: 0; }
.perm small { display: block; color: var(--muted); font-size: 12px; }
.logrow { border-top: 1px solid var(--line); padding: 8px 0; }
.logrow:first-of-type { border-top: 0; }
.logrow .head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.logrow .ua { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tablewrap { overflow-x: auto; }
`,
  computed: {
    canRead() { return can('admin_accounts', 'read'); },
    canWrite() { return can('admin_accounts', 'write'); },
    roleName() { return id => (this.roles.find(r => r.id === id) || {}).name || id; }
  },
  watch: {
    'auth.checked': { immediate: true, handler(v) { if (v && this.canRead) this.loadAll(); } },
    tab(t) { if (t === 'logs' && !this.logs.length) this.loadLogs(); if (t === 'tokens') this.loadTokens(); }
  },
  methods: {
    flash(m) { this.msg = m; this.err = ''; setTimeout(() => { if (this.msg === m) this.msg = ''; }, 4000); },
    async run(fn) {
      this.err = ''; this.busy = true;
      try { await fn(); } catch (e) { this.err = e.message; } finally { this.busy = false; }
    },
    async loadAll() {
      await this.run(async () => {
        const [u, r] = await Promise.all([api('admin_accounts', 'users'), api('admin_accounts', 'roles')]);
        this.users = u.users; this.roles = r.roles; this.routes = r.routes;
        if (!this.nu.role_id && this.roles.length) this.nu.role_id = this.roles[0].id;
      });
    },
    async loadLogs(more) {
      await this.run(async () => {
        const q = {};
        Object.entries(this.logF).forEach(([k, v]) => { if (v !== '') q[k] = v; });
        if (more && this.logNext) q.before = this.logNext;
        const d = await api('admin_accounts', 'logs', undefined, q);
        this.logs = more ? this.logs.concat(d.logs) : d.logs;
        this.logMore = d.more; this.logNext = d.next_before; this.retention = d.retention_days;
      });
    },
    async loadTokens() { await this.run(async () => { this.tokens = (await api('admin_accounts', 'tokens')).tokens; }); },
    async createUser() {
      await this.run(async () => {
        const d = await api('admin_accounts', 'user_create', { ...this.nu, role_id: Number(this.nu.role_id) });
        this.users = d.users; this.nu.username = ''; this.nu.password = '';
        this.flash('已建立帳號');
      });
    },
    startEdit(u) { this.edit = { id: u.id, role_id: u.role_id, disabled: u.disabled, password: '' }; },
    async saveUser() {
      const e = this.edit;
      const body = { id: e.id, role_id: Number(e.role_id), disabled: !!e.disabled };
      if (e.password) body.password = e.password;
      await this.run(async () => {
        this.users = (await api('admin_accounts', 'user_update', body)).users;
        this.edit = null; this.flash('已儲存');
      });
    },
    async setDisabled(u, disabled) {
      if (disabled && !confirm('停權「' + u.username + '」？它會立刻被登出，PAT 也無法使用（啟用後恢復）。')) return;
      await this.run(async () => {
        this.users = (await api('admin_accounts', 'user_update', { id: u.id, disabled })).users;
        this.flash(disabled ? '已停權' : '已啟用');
      });
    },
    async delUser(u) {
      if (!confirm('刪除帳號「' + u.username + '」？它的 PAT 也會一起刪除。')) return;
      await this.run(async () => { this.users = (await api('admin_accounts', 'user_delete', { id: u.id })).users; this.flash('已刪除'); });
    },
    newRole() {
      const permissions = {};
      this.routes.forEach(r => { permissions[r.route] = { read: false, write: false }; });
      this.roleEdit = { name: '', description: '', permissions };
    },
    startRole(r) { this.roleEdit = JSON.parse(JSON.stringify({ id: r.id, name: r.name, description: r.description, permissions: r.permissions, is_super: r.is_super })); },
    setPerm(route, mode, v) {
      const p = this.roleEdit.permissions[route];
      p[mode] = v;
      if (mode === 'write' && v) p.read = true;     // 可寫一定可讀
      if (mode === 'read' && !v) p.write = false;
    },
    async saveRole() {
      const r = this.roleEdit;
      const body = { name: r.name, description: r.description };
      if (!r.is_super) body.permissions = r.permissions;
      if (r.id) body.id = r.id;
      await this.run(async () => {
        this.roles = (await api('admin_accounts', r.id ? 'role_update' : 'role_create', body)).roles;
        this.roleEdit = null; this.flash('已儲存角色');
      });
    },
    async delRole(r) {
      if (!confirm('刪除角色「' + r.name + '」？')) return;
      await this.run(async () => { this.roles = (await api('admin_accounts', 'role_delete', { id: r.id })).roles; this.flash('已刪除角色'); });
    },
    async revoke(t) {
      if (!confirm('撤銷 ' + t.username + ' 的 PAT「' + t.name + '」？')) return;
      await this.run(async () => { await api('admin_accounts', 'token_revoke', { id: t.id }); await this.loadTokens(); });
    },
    permSummary(r) {
      if (r.is_super) return '全部路由可讀寫';
      const list = Object.entries(r.permissions).filter(([, p]) => p.read)
        .map(([k, p]) => ((this.routes.find(x => x.route === k) || {}).title || k) + (p.write ? '（讀寫）' : '（讀）'));
      return list.length ? list.join('、') : '沒有任何權限';
    },
    tone(s) { return s === 'active' ? 'ok' : (s === 'expired' ? 'warn' : 'bad'); },
    statusName(s) { return { active: '有效', expired: '已過期', revoked: '已撤銷' }[s] || s; }
  },
  template: `
<div class="acc">
  <div v-if="!auth.checked" class="pageloading">載入中⋯</div>
  <div v-else-if="!auth.user" class="pageloading">請先登入</div>
  <div v-else-if="!canRead" class="pageloading">沒有帳號管理（admin_accounts）的讀取權限</div>
  <template v-else>
    <div class="subtabs">
      <button v-for="t in TABS" :key="t.key" type="button" :class="{ cur: tab === t.key }" @click="tab = t.key">{{ t.label }}</button>
    </div>
    <div v-if="err" class="err" style="margin-bottom:12px">{{ err }}</div>
    <div v-if="msg" class="ok" style="margin-bottom:12px">{{ msg }}</div>

    <!-- 帳號 -->
    <div v-if="tab === 'users'" class="stack">
      <Card v-if="canWrite" title="新增帳號">
        <form @submit.prevent="createUser">
          <div class="cols">
            <label>帳號<input v-model="nu.username" autocapitalize="none" autocomplete="off" pattern="[A-Za-z0-9_.\\-]{3,40}" required></label>
            <label>密碼<input v-model="nu.password" type="password" autocomplete="new-password" minlength="5" required></label>
            <label>角色<select v-model="nu.role_id"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
          </div>
          <button class="primary" type="submit" :disabled="busy">建立</button>
        </form>
      </Card>
      <Card :title="'帳號（' + users.length + '）'">
        <div v-for="u in users" :key="u.id" class="item">
          <div class="head">
            <b>{{ u.username }}</b>
            <Chip :tone="u.is_super ? 'ok' : ''">{{ u.role }}</Chip>
            <Chip v-if="u.disabled" tone="bad">停用</Chip>
            <Chip v-if="u.is_default_password" tone="warn">預設密碼</Chip>
            <Chip v-if="u.id === auth.user.id">我</Chip>
          </div>
          <div class="small muted">最後登入 {{ u.last_login_at || '—' }}<template v-if="u.last_login_ip">（{{ u.last_login_ip }}）</template>．有效 PAT {{ u.active_tokens }}．建立 {{ u.created_at }}</div>
          <div v-if="edit && edit.id === u.id" class="form" style="margin-top:10px">
            <div class="cols">
              <label>角色<select v-model="edit.role_id"><option v-for="r in roles" :key="r.id" :value="r.id">{{ r.name }}</option></select></label>
              <label>重設密碼（留空不改）<input v-model="edit.password" type="password" autocomplete="new-password"></label>
              <label style="align-content:end"><span><input type="checkbox" v-model="edit.disabled" style="width:auto;min-height:0"> 停用這個帳號</span></label>
            </div>
            <div class="acts"><button class="primary" type="button" :disabled="busy" @click="saveUser">儲存</button>
              <button type="button" @click="edit = null">取消</button></div>
          </div>
          <div v-else-if="canWrite" class="acts">
            <button type="button" @click="startEdit(u)">編輯</button>
            <template v-if="u.id !== auth.user.id">
              <button v-if="u.disabled" type="button" :disabled="busy" @click="setDisabled(u, false)">啟用</button>
              <button v-else type="button" class="danger" :disabled="busy" @click="setDisabled(u, true)">停權</button>
            </template>
            <button v-if="u.id !== auth.user.id" type="button" class="danger" @click="delUser(u)">刪除</button>
          </div>
        </div>
      </Card>
    </div>

    <!-- 角色與權限 -->
    <div v-if="tab === 'roles'" class="stack">
      <Card v-if="roleEdit" :title="roleEdit.id ? '編輯角色' : '新增角色'">
        <div class="form">
          <div class="cols">
            <label>名稱<input v-model="roleEdit.name" maxlength="40" required></label>
            <label style="grid-column: span 2">說明<input v-model="roleEdit.description" maxlength="200"></label>
          </div>
          <div v-if="roleEdit.is_super" class="small muted">manager 擁有所有路由的讀寫（包含以後新增的路由），權限不能修改。</div>
          <div v-else class="tablewrap">
            <table class="perm">
              <thead><tr><th>路由</th><th class="c">讀</th><th class="c">寫</th></tr></thead>
              <tbody>
                <tr v-for="r in routes" :key="r.route">
                  <td>{{ r.title }} <span class="mono small muted">{{ r.route }}</span><small>讀：{{ r.read }}</small><small>寫：{{ r.write }}</small></td>
                  <td class="c"><input type="checkbox" :checked="roleEdit.permissions[r.route].read" @change="setPerm(r.route, 'read', $event.target.checked)"></td>
                  <td class="c"><input type="checkbox" :checked="roleEdit.permissions[r.route].write" @change="setPerm(r.route, 'write', $event.target.checked)"></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="acts"><button class="primary" type="button" :disabled="busy" @click="saveRole">儲存</button>
            <button type="button" @click="roleEdit = null">取消</button></div>
        </div>
      </Card>
      <Card :title="'角色（' + roles.length + '）'">
        <template #side><button v-if="canWrite && !roleEdit" type="button" @click="newRole">新增角色</button></template>
        <div v-for="r in roles" :key="r.id" class="item">
          <div class="head"><b>{{ r.name }}</b><Chip v-if="r.is_super" tone="ok">全部權限</Chip><span class="small muted">{{ r.users }} 個帳號</span></div>
          <div v-if="r.description" class="small muted">{{ r.description }}</div>
          <div class="small" style="margin-top:4px">{{ permSummary(r) }}</div>
          <div v-if="canWrite" class="acts">
            <button type="button" @click="startRole(r)">編輯</button>
            <button v-if="!r.is_super" type="button" class="danger" :disabled="r.users > 0" @click="delRole(r)">刪除</button>
          </div>
        </div>
      </Card>
    </div>

    <!-- 登入紀錄 -->
    <div v-if="tab === 'logs'" class="stack">
      <Card :title="'登入紀錄（保留 ' + retention + ' 天）'">
        <form @submit.prevent="loadLogs(false)" class="cols" style="margin-bottom:10px">
          <label>帳號<input v-model="logF.user" autocapitalize="none"></label>
          <label>IP<input v-model="logF.ip"></label>
          <label>結果<select v-model="logF.success"><option value="">全部</option><option value="1">成功</option><option value="0">失敗</option></select></label>
          <button type="submit" :disabled="busy">篩選</button>
        </form>
        <div v-for="l in logs" :key="l.id" class="logrow">
          <div class="head"><Chip :tone="l.success ? 'ok' : 'bad'">{{ l.success ? '成功' : '失敗' }}</Chip>
            <b>{{ l.username }}</b><span class="mono small">{{ l.ip }}</span><span class="mono small muted">{{ l.time }}</span>
            <span v-if="l.reason" class="small muted">{{ ({ bad_password: '密碼錯誤', no_user: '沒有這個帳號', disabled: '帳號停用' })[l.reason] || l.reason }}</span></div>
          <div class="small muted ua">{{ l.user_agent }}</div>
        </div>
        <div v-if="!logs.length" class="empty">沒有紀錄</div>
        <button v-if="logMore" type="button" style="margin-top:10px" :disabled="busy" @click="loadLogs(true)">載入更多</button>
      </Card>
    </div>

    <!-- 所有 PAT -->
    <div v-if="tab === 'tokens'" class="stack">
      <Card :title="'所有 PAT（' + tokens.length + '）'">
        <div v-if="!tokens.length" class="empty">沒有 PAT</div>
        <div v-for="t in tokens" :key="t.id" class="item">
          <div class="head"><b>{{ t.name }}</b><span class="small muted">{{ t.username }}</span><Chip :tone="tone(t.status)">{{ statusName(t.status) }}</Chip></div>
          <div class="small muted mono">{{ t.prefix }}</div>
          <div class="small muted">建立 {{ t.created_at }}．到期 {{ t.expires_at }}<template v-if="t.last_used_at">．最後使用 {{ t.last_used_at }}（{{ t.last_used_ip }}）</template></div>
          <div v-if="canWrite && t.status === 'active'" class="acts"><button type="button" class="danger" @click="revoke(t)">撤銷</button></div>
        </div>
      </Card>
    </div>
  </template>
</div>`
};
