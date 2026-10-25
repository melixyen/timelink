/* 快取清單：搜尋、篩選模式、排序 */
import { store, svcName, bytes, dur, MODE_NAME, CAT_NAME } from '../ui/index.js';

export default {
  name: 'PageList',
  data: () => ({ store, q: '', mode: '', sort: 'fetched' }),
  style: `
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.toolbar input { flex: 1 1 200px; min-width: 0; }
.toolbar select { flex: 0 0 auto; }
.entries { display: grid; gap: 8px; }
.entry { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; }
.entry .path { font-size: 12.5px; overflow-wrap: anywhere; }
.entry .meta { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-top: 6px; font-size: 12.5px; color: var(--muted); align-items: center; }
`,
  computed: {
    svc() { return svcName(); },
    all() { return (this.store.data && this.store.data.cache && this.store.data.cache.list) || []; },
    rows() {
      const q = this.q.trim().toLowerCase();
      const rows = this.all.filter(e => (!this.mode || e.mode === this.mode) && (!q || e.path.toLowerCase().includes(q)));
      const by = {
        size: (a, b) => b.size - a.size,
        fresh: (a, b) => a.fresh_for - b.fresh_for,
        path: (a, b) => a.path.localeCompare(b.path),
        fetched: (a, b) => b.fetched_at.localeCompare(a.fetched_at)
      };
      return rows.sort(by[this.sort] || by.fetched);
    },
    count() {
      return this.rows.length === this.all.length ? this.all.length + ' 筆' : this.rows.length + ' / ' + this.all.length + ' 筆';
    }
  },
  methods: { bytes, dur, modeName: k => MODE_NAME[k] || k, catName: c => CAT_NAME[c] || c },
  template: `
<div v-if="!store.data" class="pageloading">{{ store.error || '載入中⋯' }}</div>
<Card v-else :title="svc + ' 快取清單'">
  <template #side>{{ count }}</template>
  <div class="toolbar">
    <input v-model="q" type="search" placeholder="搜尋路徑，例如 THSR、Route、Taipei" autocomplete="off">
    <select v-model="mode" aria-label="模式">
      <option value="">全部模式</option><option value="rail">軌道</option><option value="bus">公車</option>
    </select>
    <select v-model="sort" aria-label="排序">
      <option value="fetched">最近抓取</option><option value="size">容量大</option>
      <option value="fresh">快過期</option><option value="path">路徑</option>
    </select>
  </div>
  <div class="entries">
    <div v-for="e in rows" :key="e.mode + e.path" class="entry">
      <div class="path mono">{{ e.path }}</div>
      <div class="meta">
        <Chip :tone="e.mode">{{ modeName(e.mode) }}</Chip>
        <Chip>{{ catName(e.cat) }}</Chip>
        <Chip :tone="e.fresh_for > 0 ? 'ok' : 'warn'">{{ e.fresh_for > 0 ? '新鮮 ' + dur(e.fresh_for) : '待確認' }}</Chip>
        <span>{{ bytes(e.size) }}</span>
        <span>抓取 {{ e.fetched_at }}</span>
        <span v-if="e.checked_at !== e.fetched_at">確認 {{ e.checked_at }}</span>
      </div>
    </div>
    <div v-if="!rows.length" class="empty">{{ all.length ? '沒有符合的項目' : '目前沒有快取' }}</div>
  </div>
</Card>`
};
