/* finder 可用清單：每個 func 的用途、查詢的 SQLite 資料表是否最新，以及各資料表的來源 download 檔案 */
import { auth, can, api, bytes } from '../../panel/ui/index.js';

export default {
  name: 'PageFinder',
  data: () => ({ auth, fd: null, err: '', open: {} }),
  style: `
.fn + .fn { border-top: 1px solid var(--line); padding-top: 12px; margin-top: 12px; }
.fn h3 { font-size: 16px; margin: 0; display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.fn h3 .mono { font-size: 13px; font-weight: 500; color: var(--muted); }
.fn p { margin: 6px 0; font-size: 14px; }
.fn .row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 6px; font-size: 13px; }
.fn .row .lbl { color: var(--muted); flex: 0 0 auto; }
.fn .ex { display: block; margin-top: 8px; font-size: 12.5px; overflow-wrap: anywhere; }
.fn .why { font-size: 12.5px; color: var(--warn); margin-top: 4px; overflow-wrap: anywhere; }
.tbl + .tbl { border-top: 1px solid var(--line); padding-top: 10px; margin-top: 10px; }
.tbl .head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.tbl .head .name { font-weight: 600; }
.tbl .head .rows { margin-left: auto; color: var(--muted); font-size: 13px; font-variant-numeric: tabular-nums; }
.tbl .desc { font-size: 13px; color: var(--muted); margin-top: 4px; }
.tbl button.link { margin-top: 6px; padding: 2px 0; background: none; border: 0; color: var(--accent); font-size: 13px; cursor: pointer; }
.srcs { margin: 6px 0 0; padding-left: 18px; font-size: 12.5px; }
.srcs li { overflow-wrap: anywhere; }
.srcs .t { color: var(--muted); }
.missing { margin: 8px 0 0; padding-left: 18px; font-size: 13px; }
.missing li { overflow-wrap: anywhere; }
`,
  computed: {
    canRead() { return can('admin_finder', 'read'); },
    funcs() { return (this.fd && this.fd.funcs) || []; },
    tables() { return (this.fd && this.fd.tables) || []; },
    sq() { return (this.fd && this.fd.sqlite) || {}; }
  },
  watch: { 'auth.checked': { immediate: true, handler(v) { if (v && this.canRead) this.load(); } } },
  methods: {
    bytes,
    async load() {
      this.err = '';
      try { this.fd = await api('admin_finder', 'status'); } catch (e) { this.err = e.message; }
    },
    staleList(f) { return Object.entries(f.stale || {}).map(([t, why]) => t + '：' + why); },
    tableFresh(name) {
      const t = this.tables.find(x => x.table === name);
      return t ? t.fresh : false;
    },
    toggle(t) { this.open = { ...this.open, [t]: !this.open[t] }; },
    num(n) { return n == null ? '—' : Number(n).toLocaleString(); }
  },
  template: `
<div v-if="!auth.checked" class="pageloading">載入中⋯</div>
<div v-else-if="!auth.user" class="pageloading">請先登入</div>
<div v-else-if="!canRead" class="pageloading">沒有查詢功能（admin_finder）的讀取權限</div>
<div v-else-if="err" class="pageloading">無法取得 finder 狀態：{{ err }}</div>
<div v-else-if="!fd" class="pageloading">載入中⋯</div>
<div v-else class="stack">
  <Card title="SQLite 資料庫">
    <template #side><span class="mono">sqlite/{{ sq.db }}.sqlite</span></template>
    <KvRow k="狀態">
      <Chip :tone="!sq.exists ? 'bad' : sq.fresh ? 'ok' : 'warn'">
        {{ !sq.exists ? '尚未建立' : sq.fresh ? '已是最新' : '待更新' }}
      </Chip>
    </KvRow>
    <KvRow k="最後建置">{{ sq.built_at || '—' }}</KvRow>
    <KvRow k="容量">{{ sq.size ? bytes(sq.size) : '—' }}</KvRow>
    <KvRow k="下載資料">
      <Chip :tone="fd.download.complete ? 'ok' : 'bad'">{{ fd.download.complete ? '完整' : '不完整，無法建置' }}</Chip>
    </KvRow>
    <ul v-if="fd.download.missing && fd.download.missing.length" class="missing">
      <li v-for="m in fd.download.missing" :key="m.path"><span class="mono">{{ m.path }}</span>（{{ m.reason }}）</li>
    </ul>
  </Card>

  <Card title="可用功能">
    <template #side>{{ funcs.length }} 個</template>
    <div v-for="f in funcs" :key="f.func" class="fn">
      <h3>{{ f.title }} <span class="mono">/txapi/finder/{{ f.func }}</span>
        <Chip :tone="f.fresh ? 'ok' : 'warn'">{{ f.fresh ? '資料最新' : '查詢時會先更新' }}</Chip>
      </h3>
      <p>{{ f.desc }}</p>
      <div class="row"><span class="lbl">參數</span><Chip v-for="p in f.params" :key="p">{{ p }}</Chip></div>
      <div class="row"><span class="lbl">查詢資料表</span>
        <Chip v-for="t in f.tables" :key="t" :tone="tableFresh(t) ? 'ok' : 'warn'"><span class="mono">{{ t }}</span></Chip>
      </div>
      <div v-for="w in staleList(f)" :key="w" class="why">{{ w }}</div>
      <a class="ex mono" :href="f.example" target="_blank" rel="noopener">{{ f.example }}</a>
    </div>
  </Card>

  <Card title="資料表與建置依據">
    <div v-for="t in tables" :key="t.table" class="tbl">
      <div class="head">
        <span class="name mono">{{ t.table }}</span>
        <Chip :tone="t.fresh ? 'ok' : 'warn'">{{ t.fresh ? '最新' : '待更新' }}</Chip>
        <span class="rows">{{ num(t.rows) }} 筆</span>
      </div>
      <div class="desc">{{ t.desc }}</div>
      <div v-if="t.stale" class="why" style="font-size:12.5px;color:var(--warn);margin-top:4px">{{ t.stale }}</div>
      <button type="button" class="link" @click="toggle(t.table)">
        {{ open[t.table] ? '收合' : '來源檔案 ' + t.sources.length + ' 個' }}
      </button>
      <ul v-if="open[t.table]" class="srcs">
        <li v-for="s in t.sources" :key="s.path">
          <span class="mono">{{ s.path }}</span>
          <span class="t"> · {{ s.downloaded ? '存檔 ' + s.saved_at : '尚未下載' }}</span>
        </li>
      </ul>
    </div>
  </Card>
</div>`
};
