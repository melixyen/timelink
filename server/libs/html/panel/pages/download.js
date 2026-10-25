/* 下載狀態：/txapi/download 的清單、各業者容量、每個檔案的存檔時間與時刻表適用期間，以及轉進 SQLite 的狀態 */
import { store, bytes } from '../ui/index.js';

const GROUP_NAME = {
  TRA: '臺鐵', THSR: '高鐵', TRTC: '臺北捷運', NTMC: '新北捷運', KRTC: '高雄捷運', TYMC: '桃園捷運', TMRT: '臺中捷運'
};

export default {
  name: 'PageDownload',
  data: () => ({ store, q: '', group: '', state: '' }),
  style: `
.dl-sum { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
@media (min-width: 640px) { .dl-sum { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
.dl-sum .lbl { font-size: 12px; color: var(--muted); }
.dl-groups { display: grid; gap: 6px; }
.dl-group { display: flex; align-items: center; gap: 8px; font-size: 14px; padding: 4px 0; border-bottom: 1px solid var(--line); }
.dl-group:last-child { border-bottom: 0; }
.dl-group .name { flex: 1; min-width: 0; }
.dl-group .num { color: var(--muted); font-variant-numeric: tabular-nums; white-space: nowrap; }
.toolbar { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
.toolbar input { flex: 1 1 200px; min-width: 0; }
.toolbar select { flex: 0 0 auto; }
.entries { display: grid; gap: 8px; }
.entry { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; }
.entry .ttl { font-size: 14px; font-weight: 600; }
.entry .path { font-size: 12.5px; overflow-wrap: anywhere; color: var(--muted); margin-top: 2px; }
.entry .meta { display: flex; flex-wrap: wrap; gap: 6px 12px; margin-top: 6px; font-size: 12.5px; color: var(--muted); align-items: center; }
.missing { margin: 8px 0 0; padding-left: 18px; font-size: 13px; }
.missing li { overflow-wrap: anywhere; }
`,
  computed: {
    dl() { return this.store.data && this.store.data.download; },
    fd() { return this.store.data && this.store.data.finder; },
    items() { return (this.dl && this.dl.items) || []; },
    groups() {
      const g = (this.dl && this.dl.groups) || {};
      return Object.keys(g).map(k => ({ key: k, ...g[k] }));
    },
    rows() {
      const q = this.q.trim().toLowerCase();
      return this.items.filter(i => {
        if (this.group && i.group !== this.group) return false;
        if (this.state === 'missing' && i.downloaded) return false;
        if (this.state === 'outdated' && !i.outdated) return false;
        if (this.state === 'ok' && (!i.downloaded || i.outdated)) return false;
        return !q || (i.path + ' ' + i.title + ' ' + i.desc).toLowerCase().includes(q);
      });
    },
    count() {
      return this.rows.length === this.items.length ? this.items.length + ' 項' : this.rows.length + ' / ' + this.items.length + ' 項';
    },
    sqliteTone() {
      if (!this.fd || !this.fd.sqlite) return '';
      if (!this.fd.sqlite.exists) return 'bad';
      return this.fd.sqlite.fresh ? 'ok' : 'warn';
    },
    sqliteText() {
      const s = this.fd && this.fd.sqlite;
      if (!s) return '—';
      if (!s.exists) return '尚未建立';
      return s.fresh ? '已是最新' : '待更新（下次查詢或同步完成時自動重建）';
    }
  },
  methods: {
    bytes,
    groupName(k) { return GROUP_NAME[k] || k; },
    catName(k) { return (this.dl && this.dl.categories && this.dl.categories[k]) || k; },
    validity(v) { return v ? (v.effective || '?') + ' ～ ' + (v.expire || '未定') : ''; }
  },
  template: `
<div v-if="!store.data" class="pageloading">{{ store.error || '載入中⋯' }}</div>
<div v-else-if="!dl" class="pageloading">監控資料沒有下載狀態</div>
<div v-else class="stack">
  <Card title="下載狀態">
    <template #side>{{ dl.root }}</template>
    <div class="dl-sum">
      <div><div class="lbl">項目</div><BigStat :value="dl.summary.count" unit="項" /></div>
      <div><div class="lbl">已存檔</div><BigStat :value="dl.summary.downloaded" :unit="'/ ' + dl.summary.count" /></div>
      <div><div class="lbl">需要更新</div><BigStat :value="dl.summary.outdated" unit="項" /></div>
      <div><div class="lbl">容量</div><BigStat :value="bytes(dl.summary.bytes)" /></div>
    </div>
  </Card>

  <Card title="轉入 SQLite（finder 查詢用）">
    <KvRow k="資料庫"><Chip :tone="sqliteTone">{{ sqliteText }}</Chip></KvRow>
    <KvRow k="最後建置">{{ (fd && fd.sqlite && fd.sqlite.built_at) || '—' }}</KvRow>
    <KvRow k="下載資料">
      <Chip :tone="fd && fd.download && fd.download.complete ? 'ok' : 'bad'">
        {{ fd && fd.download && fd.download.complete ? '完整' : '不完整，不會寫入資料庫' }}
      </Chip>
    </KvRow>
    <ul v-if="fd && fd.download && fd.download.missing && fd.download.missing.length" class="missing">
      <li v-for="m in fd.download.missing" :key="m.path"><span class="mono">{{ m.path }}</span>（{{ m.reason }}）</li>
    </ul>
  </Card>

  <Card title="各系統">
    <div class="dl-groups">
      <div v-for="g in groups" :key="g.key" class="dl-group">
        <span class="name">{{ g.name }}</span>
        <Chip :tone="g.downloaded === g.count ? 'ok' : 'warn'">{{ g.downloaded }} / {{ g.count }}</Chip>
        <span class="num">{{ bytes(g.bytes) }}</span>
      </div>
    </div>
  </Card>

  <Card title="檔案清單">
    <template #side>{{ count }}</template>
    <div class="toolbar">
      <input v-model="q" type="search" placeholder="搜尋路徑或名稱，例如 StationTimeTable、臺鐵" autocomplete="off">
      <select v-model="group" aria-label="系統">
        <option value="">全部系統</option>
        <option v-for="g in groups" :key="g.key" :value="g.key">{{ groupName(g.key) }}</option>
      </select>
      <select v-model="state" aria-label="狀態">
        <option value="">全部狀態</option><option value="ok">正常</option>
        <option value="outdated">需要更新</option><option value="missing">尚未下載</option>
      </select>
    </div>
    <div class="entries">
      <div v-for="i in rows" :key="i.path" class="entry">
        <div class="ttl">{{ i.title }}</div>
        <div class="path mono">{{ i.path }}</div>
        <div class="meta">
          <Chip>{{ groupName(i.group) }}</Chip>
          <Chip>{{ catName(i.category) }}</Chip>
          <Chip :tone="!i.downloaded ? 'bad' : i.outdated ? 'warn' : 'ok'">
            {{ !i.downloaded ? '尚未下載' : i.outdated ? '需要更新' : '正常' }}
          </Chip>
          <span v-if="i.downloaded">{{ bytes(i.size) }}</span>
          <span v-if="i.saved_at">存檔 {{ i.saved_at }}</span>
          <span v-if="i.validity">適用 {{ validity(i.validity) }}</span>
        </div>
      </div>
      <div v-if="!rows.length" class="empty">沒有符合的項目</div>
    </div>
  </Card>
</div>`
};
