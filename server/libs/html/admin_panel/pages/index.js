/* 後臺首頁：初始化檢查清單（admin_settings 讀取權限）、目前帳號與可用的功能 */
import { auth, can } from '../../panel/ui/index.js';
import { groups, routes } from '../site.js';
import { SetupChecklist } from '../ui/checklist.js';

const DESC = {
  finder: 'finder 各查詢功能、依賴的資料表與資料是否最新',
  try: '在面板輸入參數測試 finder 查詢',
  traffic: '各 IP 的請求頻率與總量，封鎖或解鎖 IP',
  accounts: '帳號、角色與權限、登入紀錄、PAT',
  settings: '檢視與編輯設定、整包下載 custom/'
};

export default {
  name: 'PageAdminHome',
  components: { SetupChecklist },
  data: () => ({ auth }),
  style: `
.adm-home .fn { display: block; padding: 12px 0; border-top: 1px solid var(--line); text-decoration: none; color: var(--text); }
.adm-home .fn:first-of-type { border-top: 0; }
.adm-home .fn b { display: block; color: var(--accent); }
.adm-home .fn span { font-size: 13px; color: var(--muted); }
.adm-home .perm { display: flex; flex-wrap: wrap; gap: 6px; }
`,
  computed: {
    canSetup() { return can('admin_settings', 'read'); },
    sections() {
      return groups
        .map(g => ({ ...g, items: routes.filter(r => r.group === g.key && can(r.perm, 'read')) }))
        .filter(g => g.items.length);
    },
    perms() {
      return Object.entries(auth.permissions || {}).filter(([, p]) => p.read || p.write)
        .map(([k, p]) => ({ k, mode: p.write ? '讀寫' : '讀取' }));
    }
  },
  methods: { desc(p) { return DESC[p] || ''; } },
  template: `
<div class="stack adm-home">
  <SetupChecklist v-if="canSetup" />
  <Card title="帳號">
    <KvRow k="帳號">{{ auth.user.username }}</KvRow>
    <KvRow k="角色">{{ auth.user.role }}{{ auth.user.is_super ? '（全部權限）' : '' }}</KvRow>
    <div style="margin-top:10px"><a href="#/account">我的帳號：更換密碼、PAT</a></div>
  </Card>
  <div class="grid">
    <Card v-for="g in sections" :key="g.key" :title="g.label">
      <a v-for="r in g.items" :key="r.path" class="fn" :href="'#/' + r.path"><b>{{ r.label }}</b><span>{{ desc(r.path) }}</span></a>
    </Card>
  </div>
  <div v-if="!sections.length" class="empty">這個帳號沒有任何後臺功能的權限，請找管理者設定角色。</div>
  <Card v-if="perms.length" title="我的權限">
    <div class="perm"><Chip v-for="p in perms" :key="p.k">{{ p.k }}．{{ p.mode }}</Chip></div>
  </Card>
</div>`
};
