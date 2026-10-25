/* 流量管理（admin_traffic）：各 IP 的請求頻率與總量，封鎖或解鎖 IP（寫入權限）。 */
import { auth, can, api } from '../../panel/ui/index.js';

const SORTS = [
  { key: 'd1', label: '24 小時' },
  { key: 'h1', label: '1 小時' },
  { key: 'm1', label: '本分鐘' },
  { key: 'total', label: '總量' },
  { key: 'last', label: '最近' }
];

export default {
  name: 'PageTraffic',
  data: () => ({
    auth, SORTS, sort: 'd1', q: '', d: null, blocks: [], err: '', busy: false,
    detail: null, detailIp: '', blk: { ip: '', reason: '', hours: '' }
  }),
  style: `
.tf-sum { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
@media (min-width: 720px) { .tf-sum { grid-template-columns: repeat(5, 1fr); } }
.tf-sum .lbl { font-size: 12px; color: var(--muted); }
.tf-tools { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 8px; }
.tf-tools input { flex: 1 1 160px; min-width: 0; }
.tf-sorts { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; }
.tf-sorts button { flex: 0 0 auto; min-height: 34px; padding: 4px 10px; font-size: 13px; }
.tf-sorts button.cur { background: var(--accent); color: #fff; border-color: var(--accent); }
.ipr { border-top: 1px solid var(--line); padding: 10px 0; }
.ipr:first-child { border-top: 0; }
.ipr .head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ipr .head b { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; overflow-wrap: anywhere; }
.ipr .nums { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 6px; font-size: 13px; font-variant-numeric: tabular-nums; }
.ipr .nums span { display: block; color: var(--muted); font-size: 11px; }
.ipr .path { font-size: 12px; color: var(--muted); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ipr .acts { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.ipr .acts button { min-height: 34px; padding: 4px 10px; font-size: 13px; }
button.danger { color: var(--bad); }
button.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.bars { display: flex; align-items: flex-end; gap: 1px; height: 64px; margin: 6px 0 2px; }
.bars i { flex: 1; background: var(--accent); border-radius: 2px 2px 0 0; min-height: 1px; opacity: .85; }
.bars-axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--muted); }
.blkform { display: grid; gap: 8px; grid-template-columns: 1fr; }
@media (min-width: 640px) { .blkform { grid-template-columns: 1.2fr 2fr .8fr auto; align-items: end; } }
.blkform label { display: grid; gap: 4px; font-size: 13px; color: var(--muted); }
.blkform input { width: 100%; }
`,
  computed: {
    canRead() { return can('admin_traffic', 'read'); },
    canWrite() { return can('admin_traffic', 'write'); },
    rows() { return (this.d && this.d.rows) || []; },
    activeBlocks() { return this.blocks.filter(b => b.active); }
  },
  watch: { 'auth.checked': { immediate: true, handler(v) { if (v && this.canRead) this.load(); } } },
  methods: {
    num(n) { return Number(n || 0).toLocaleString(); },
    async load() {
      this.err = '';
      try {
        const [d, b] = await Promise.all([
          api('admin_traffic', 'list', undefined, { sort: this.sort, q: this.q.trim(), limit: 200 }),
          api('admin_traffic', 'blocks')
        ]);
        this.d = d; this.blocks = b.blocks;
      } catch (e) { this.err = e.message; }
    },
    setSort(k) { this.sort = k; this.load(); },
    async showIp(ip) {
      if (this.detailIp === ip) { this.detailIp = ''; this.detail = null; return; }
      this.detailIp = ip; this.detail = null;
      try { this.detail = await api('admin_traffic', 'ip', undefined, { ip }); } catch (e) { this.err = e.message; }
    },
    barH(v, arr) { const m = Math.max(1, ...arr); return Math.round(v / m * 100) + '%'; },
    prefill(ip) { this.blk = { ip, reason: '', hours: '' }; window.scrollTo({ top: 0, behavior: 'smooth' }); },
    async block(ip, reason, hours) {
      if (this.d && ip === this.d.my_ip && !confirm('這是你目前的 IP，確定要封鎖？（已登入的你仍可使用後臺）')) return;
      this.busy = true; this.err = '';
      try {
        this.blocks = (await api('admin_traffic', 'block', { ip, reason, hours: Number(hours) || 0 })).blocks;
        this.blk = { ip: '', reason: '', hours: '' };
        await this.load();
      } catch (e) { this.err = e.message; } finally { this.busy = false; }
    },
    async unblock(ip) {
      if (!confirm('解鎖 ' + ip + '？')) return;
      this.busy = true; this.err = '';
      try { this.blocks = (await api('admin_traffic', 'unblock', { ip })).blocks; await this.load(); }
      catch (e) { this.err = e.message; } finally { this.busy = false; }
    }
  },
  template: `
<div>
  <div v-if="!auth.checked" class="pageloading">載入中⋯</div>
  <div v-else-if="!auth.user" class="pageloading">請先登入</div>
  <div v-else-if="!canRead" class="pageloading">沒有流量管理（admin_traffic）的讀取權限</div>
  <div v-else class="stack">
    <div v-if="err" class="err">{{ err }}</div>
    <Card v-if="d" title="全站請求">
      <template #side><button type="button" style="min-height:32px;padding:4px 10px" @click="load">重新整理</button></template>
      <div class="tf-sum">
        <div><div class="lbl">本分鐘</div><BigStat :value="num(d.summary.m1)" /></div>
        <div><div class="lbl">1 小時</div><BigStat :value="num(d.summary.h1)" /></div>
        <div><div class="lbl">24 小時</div><BigStat :value="num(d.summary.d1)" /></div>
        <div><div class="lbl">IP 數（{{ d.retention_days }} 天內）</div><BigStat :value="num(d.ips)" /></div>
        <div><div class="lbl">封鎖中</div><BigStat :value="num(d.summary.blocked)" /></div>
      </div>
      <p class="small muted" style="margin:8px 0 0">你目前的 IP：<span class="mono">{{ d.my_ip }}</span>．資料時間 {{ d.now }}</p>
    </Card>

    <Card v-if="canWrite" title="封鎖 IP">
      <form class="blkform" @submit.prevent="block(blk.ip.trim(), blk.reason, blk.hours)">
        <label>IP<input v-model="blk.ip" placeholder="例如 203.0.113.5" required autocapitalize="none"></label>
        <label>原因<input v-model="blk.reason" maxlength="200"></label>
        <label>時數（空白＝永久）<input v-model="blk.hours" type="number" min="0" step="1"></label>
        <button type="submit" class="primary" :disabled="busy">封鎖</button>
      </form>
      <p class="small muted" style="margin:8px 0 0">被封鎖的 IP 所有請求都回 403；已登入後臺或帶 PAT 的請求、以及登入動作不受影響。</p>
    </Card>

    <Card :title="'封鎖中（' + activeBlocks.length + '）'">
      <div v-if="!blocks.length" class="empty">沒有封鎖的 IP</div>
      <div v-for="b in blocks" :key="b.ip" class="ipr">
        <div class="head"><b>{{ b.ip }}</b><Chip :tone="b.active ? 'bad' : ''">{{ b.active ? '封鎖中' : '已到期' }}</Chip>
          <span class="small muted">{{ b.expires_at ? '到 ' + b.expires_at : '永久' }}</span></div>
        <div class="small muted">{{ b.reason || '（沒有原因）' }}．{{ b.created_by }} 於 {{ b.created_at }}</div>
        <div v-if="canWrite" class="acts"><button type="button" :disabled="busy" @click="unblock(b.ip)">解鎖</button></div>
      </div>
    </Card>

    <Card v-if="d" :title="'各 IP（' + d.ips + '）'">
      <div class="tf-tools">
        <input v-model="q" type="search" placeholder="搜尋 IP" @keyup.enter="load" autocapitalize="none">
        <button type="button" @click="load">搜尋</button>
      </div>
      <div class="tf-sorts">
        <button v-for="s in SORTS" :key="s.key" type="button" :class="{ cur: sort === s.key }" @click="setSort(s.key)">依{{ s.label }}</button>
      </div>
      <div v-if="!rows.length" class="empty">沒有紀錄</div>
      <div v-for="r in rows" :key="r.ip" class="ipr">
        <div class="head"><b>{{ r.ip }}</b>
          <Chip v-if="r.blocked" tone="bad">封鎖中</Chip>
          <Chip v-if="r.ip === d.my_ip">你</Chip>
          <span class="small muted">最後 {{ r.last_seen }}</span></div>
        <div class="nums">
          <div><span>本分鐘</span>{{ num(r.m1) }}</div>
          <div><span>1 小時（尖峰／分）</span>{{ num(r.h1) }}（{{ r.peak_per_min }}）</div>
          <div><span>24 小時</span>{{ num(r.d1) }}</div>
          <div><span>總量</span>{{ num(r.total) }}</div>
        </div>
        <div class="path mono">{{ r.last_path }}</div>
        <div class="acts">
          <button type="button" @click="showIp(r.ip)">{{ detailIp === r.ip ? '收合' : '明細' }}</button>
          <template v-if="canWrite">
            <button v-if="r.blocked" type="button" :disabled="busy" @click="unblock(r.ip)">解鎖</button>
            <button v-else type="button" class="danger" @click="prefill(r.ip)">封鎖⋯</button>
          </template>
        </div>
        <div v-if="detailIp === r.ip">
          <div v-if="!detail" class="small muted">載入中⋯</div>
          <template v-else>
            <div class="small muted" style="margin-top:8px">最近 60 分鐘（每分鐘）</div>
            <div class="bars"><i v-for="(v, i) in detail.per_minute" :key="'m' + i" :style="{ height: barH(v, detail.per_minute) }" :title="v"></i></div>
            <div class="bars-axis"><span>60 分鐘前</span><span>現在</span></div>
            <div class="small muted" style="margin-top:8px">最近 24 小時（每小時）</div>
            <div class="bars"><i v-for="(v, i) in detail.per_hour" :key="'h' + i" :style="{ height: barH(v, detail.per_hour) }" :title="v"></i></div>
            <div class="bars-axis"><span>24 小時前</span><span>現在</span></div>
            <div class="chips" style="margin-top:8px"><Chip v-for="(n, k) in detail.kinds_24h" :key="k">{{ k }} {{ num(n) }}</Chip></div>
            <div v-if="detail.info" class="small muted" style="margin-top:6px;overflow-wrap:anywhere">第一次 {{ detail.info.first_seen }}．{{ detail.info.last_ua }}</div>
          </template>
        </div>
      </div>
      <p v-if="d.more" class="small muted">只列前 200 個，請用搜尋縮小範圍。</p>
    </Card>
  </div>
</div>`
};
