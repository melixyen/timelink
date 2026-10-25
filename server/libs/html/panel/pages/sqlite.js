/* 資料庫：唯讀瀏覽 sqlite/ 內的資料庫。資料表清單 → 表格檢視（結構、篩選、排序、後端分頁、關聯跳轉）。
   資料來自 /txapi/sqlite（只接受 GET、不接受 SQL；表格、欄位、運算子由後端白名單驗證，值一律參數綁定）。
   狀態全部放在網址 #/sqlite?db=&t=&f=欄位:運算子:值&q=&sort=&dir=&page=&per=，可直接分享或上一頁返回。 */
import { bytes, txapiUrl } from '../ui/index.js';

const ACTION = 'sqlite';   // /txapi/sqlite/<func>
const PER = [10, 50, 100, 500];
const DEF_PER = 100;

const asList = v => (v == null ? [] : Array.isArray(v) ? v : [v]);

function parseFilter(s) {
  const a = String(s).split(':');
  return { column: a[0] || '', op: a[1] || 'eq', value: a.slice(2).join(':') };
}

async function getJson(params) {
  const u = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => asList(v).forEach(x => u.append(k, x)));
  const func = u.get('func') || '';
  u.delete('func');
  const res = await fetch(txapiUrl(ACTION, func, u), { cache: 'no-store' });
  let data = null;
  try { data = await res.json(); } catch (e) { /* 不是 JSON */ }
  if (!res.ok || !data || !data.ok) throw new Error((data && data.error) || ('HTTP ' + res.status));
  return data;
}

export default {
  name: 'PageSqlite',
  data: () => ({
    dbs: null, dbErr: '',
    res: null, err: '', loading: false, seq: 0,
    draft: [], kw: '',
    showSchema: false, detail: null
  }),
  style: `
.sq-db + .sq-db { margin-top: 12px; }
.sq-tables { display: grid; gap: 8px; }
.sq-tbl { display: block; border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; color: var(--text); text-decoration: none; }
.sq-tbl:hover { border-color: var(--accent); }
.sq-tbl .top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sq-tbl .name { font-weight: 600; }
.sq-tbl .n { margin-left: auto; color: var(--muted); font-size: 13px; font-variant-numeric: tabular-nums; }
.sq-tbl .desc { font-size: 13px; color: var(--muted); margin-top: 4px; }
.sq-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sq-head .crumb { font-size: 14px; }
.sq-head select { flex: 1 1 160px; min-width: 0; }
.sq-desc { font-size: 13.5px; margin-top: 8px; }
.sq-ro { font-size: 12px; color: var(--muted); margin-top: 6px; }
button.sq-link { background: none; border: 0; padding: 2px 0; color: var(--accent); font-size: 13px; cursor: pointer; }
.sq-schema { width: 100%; border-collapse: collapse; font-size: 13px; }
.sq-schema th, .sq-schema td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
.sq-schema th { color: var(--muted); font-weight: 600; white-space: nowrap; }
.sq-schema td.d { min-width: 180px; }
.sq-sql { white-space: pre-wrap; font-size: 12px; background: var(--chip); border-radius: 10px; padding: 10px; margin: 8px 0 0; overflow-wrap: anywhere; }
.sq-sub { font-size: 13px; font-weight: 600; color: var(--muted); margin: 12px 0 4px; }
.sq-filters { display: grid; gap: 8px; }
.sq-f { display: grid; grid-template-columns: 1fr auto; gap: 6px; align-items: center; }
.sq-f .cond { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 6px; }
.sq-f .cond input { grid-column: 1 / -1; }
@media (min-width: 640px) { .sq-f .cond { grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 2fr); } .sq-f .cond input { grid-column: auto; } }
.sq-f select, .sq-f input, .sq-kw { width: 100%; min-width: 0; box-sizing: border-box; }
.sq-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.sq-actions .primary { background: var(--accent); color: #fff; border-color: var(--accent); }
.sq-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 8px; font-size: 13px; }
.sq-bar .info { color: var(--muted); font-variant-numeric: tabular-nums; }
.sq-bar .per { margin-left: auto; display: flex; align-items: center; gap: 6px; color: var(--muted); }
.sq-wrap { overflow: auto; max-height: 70vh; border: 1px solid var(--line); border-radius: 10px; -webkit-overflow-scrolling: touch; }
.sq-wrap.busy { opacity: .55; }
.sq-grid { border-collapse: separate; border-spacing: 0; font-size: 13px; min-width: 100%; }
.sq-grid th, .sq-grid td { padding: 6px 10px; border-bottom: 1px solid var(--line); text-align: left; white-space: nowrap; }
.sq-grid thead th { position: sticky; top: 0; background: var(--card); z-index: 1; vertical-align: bottom; box-shadow: 0 1px 0 var(--line); }
.sq-grid th .cn { display: flex; align-items: center; gap: 4px; font-weight: 600; cursor: pointer; user-select: none; }
.sq-grid th .cn .arr { color: var(--accent); font-size: 11px; }
.sq-grid th .ct { font-size: 11px; color: var(--muted); font-weight: 400; }
.sq-grid th .cd { font-size: 11px; color: var(--muted); font-weight: 400; max-width: 220px; overflow: hidden; text-overflow: ellipsis; }
.sq-grid th .pk { font-size: 10px; background: var(--warn-bg); color: var(--warn); border-radius: 4px; padding: 0 4px; }
.sq-grid td { max-width: 280px; overflow: hidden; text-overflow: ellipsis; font-variant-numeric: tabular-nums; }
.sq-grid td.no { color: var(--muted); position: sticky; left: 0; background: var(--card); padding-right: 6px; }
.sq-grid td.no button { background: none; border: 0; padding: 0; color: var(--accent); font-size: 12px; cursor: pointer; }
.sq-grid tbody tr:hover td { background: var(--chip); }
.sq-grid a { text-decoration: none; }
.sq-grid .rb { margin-left: 6px; font-size: 11px; }
.sq-null { color: var(--muted); font-style: italic; }
.sq-pager { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 6px; margin-top: 10px; font-size: 13px; }
.sq-pager input { width: 64px; text-align: center; }
.sq-modal { position: fixed; inset: 0; z-index: 100; background: rgba(0,0,0,.45); display: flex; align-items: flex-end; justify-content: center; }
@media (min-width: 640px) { .sq-modal { align-items: center; } }
.sq-sheet { background: var(--card); width: 100%; max-width: 720px; max-height: 88vh; overflow: auto; border-radius: 16px 16px 0 0;
  padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); box-sizing: border-box; }
@media (min-width: 640px) { .sq-sheet { border-radius: 16px; } }
.sq-sheet .hd { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.sq-sheet .hd b { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.sq-field { padding: 8px 0; border-bottom: 1px solid var(--line); }
.sq-field .k { font-size: 12px; color: var(--muted); }
.sq-field .v { font-size: 14px; overflow-wrap: anywhere; margin-top: 2px; }
.sq-field pre { margin: 4px 0 0; font-size: 12px; white-space: pre-wrap; overflow-wrap: anywhere; background: var(--chip); border-radius: 8px; padding: 8px; }
.sq-field .links { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 4px; font-size: 12.5px; }
`,
  computed: {
    q() { return this.$route.query; },
    db() { return typeof this.q.db === 'string' ? this.q.db : ''; },
    table() { return typeof this.q.t === 'string' ? this.q.t : ''; },
    per() { const n = parseInt(this.q.per, 10); return PER.includes(n) ? n : DEF_PER; },
    page() { return Math.max(1, parseInt(this.q.page, 10) || 1); },
    filters() { return asList(this.q.f).map(parseFilter); },
    schema() { return this.res && this.res.schema; },
    cols() { return (this.schema && this.schema.columns) || []; },
    colMap() { return Object.fromEntries(this.cols.map(c => [c.name, c])); },
    ops() { return (this.schema && this.schema.ops) || {}; },
    curDb() { return (this.dbs || []).find(d => d.name === this.db) || null; },
    tableList() { return this.curDb ? this.curDb.tables : []; },
    perOptions() { return PER; },
    apiUrl() {
      if (!this.table) return txapiUrl(ACTION, 'tables');
      const u = new URLSearchParams({ db: this.db, table: this.table });
      this.filters.forEach(f => u.append('f[]', f.column + ':' + f.op + ':' + f.value));
      ['q', 'sort', 'dir'].forEach(k => { if (this.q[k]) u.set(k, this.q[k]); });
      u.set('page', this.page); u.set('per', this.per);
      return txapiUrl(ACTION, 'rows', u);
    }
  },
  watch: {
    '$route.query': { handler() { this.sync(); }, deep: true }
  },
  created() {
    this.loadDbs();
    this.sync();
    this._esc = e => { if (e.key === 'Escape') this.detail = null; };
    window.addEventListener('keydown', this._esc);
  },
  unmounted() { window.removeEventListener('keydown', this._esc); },
  methods: {
    bytes,
    num(n) { return n == null ? '—' : Number(n).toLocaleString(); },
    async loadDbs() {
      try { this.dbs = (await getJson({ func: 'tables' })).dbs; this.dbErr = ''; }
      catch (e) { this.dbErr = e.message; }
    },
    /* 網址變了：重設篩選草稿並向後端取資料 */
    sync() {
      if (this.$route.path !== '/sqlite') return;
      this.draft = this.filters.map(f => ({ ...f }));
      this.kw = typeof this.q.q === 'string' ? this.q.q : '';
      this.detail = null;
      if (!this.table) { this.res = null; this.err = ''; return; }
      if (this.res && this.res.table !== this.table) this.res = null;
      this.fetchRows();
    },
    async fetchRows() {
      const my = ++this.seq;
      this.loading = true;
      const params = { func: 'rows', db: this.db, table: this.table, page: this.page, per: this.per,
                       'f[]': this.filters.map(f => f.column + ':' + f.op + ':' + f.value) };
      ['q', 'sort', 'dir'].forEach(k => { if (typeof this.q[k] === 'string' && this.q[k]) params[k] = this.q[k]; });
      try {
        const data = await getJson(params);
        if (my !== this.seq) return;
        const key = [data.table, data.page, data.per, data.sort, data.dir, data.q, JSON.stringify(data.filters)].join('|');
        const moved = this.lastKey && this.lastKey !== key;
        const otherTable = this.lastKey && this.lastKey.split('|')[0] !== data.table;
        this.lastKey = key;
        this.res = data; this.err = '';
        if (!this.draft.length) this.addDraft(false);
        if (otherTable) window.scrollTo({ top: 0 });
        else if (moved) this.$nextTick(() => this.toTop());
      } catch (e) {
        if (my !== this.seq) return;
        this.err = e.message; this.res = null;
      } finally {
        if (my === this.seq) this.loading = false;
      }
    },
    /* 換頁、排序、篩選後：表格內捲軸回到第一列，頁面捲到「資料」卡片頂端（在它上方時才捲） */
    toTop() {
      const wrap = this.$refs.grid;
      if (wrap) wrap.scrollTop = 0;
      const card = this.$refs.data;
      if (!card) return;
      const nav = document.querySelector('.appnav');
      const offset = (nav ? nav.offsetHeight : 0) + 8;
      const y = card.getBoundingClientRect().top + window.scrollY - offset;
      if (window.scrollY > y) window.scrollTo({ top: y });
    },
    go(patch, replace = false) {
      const query = { ...this.q, ...patch };
      Object.keys(query).forEach(k => {
        if (query[k] === '' || query[k] == null || (Array.isArray(query[k]) && !query[k].length)) delete query[k];
      });
      if (String(query.per) === String(DEF_PER)) delete query.per;
      if (String(query.page) === '1') delete query.page;
      this.$router[replace ? 'replace' : 'push']({ path: '/sqlite', query });
    },
    /* 開啟資料表（可帶一個篩選條件），其餘狀態重設、保留每頁筆數 */
    open(table, filter) {
      const query = { db: this.db || (this.dbs && this.dbs[0] && this.dbs[0].name), t: table };
      if (filter) query.f = Array.isArray(filter) ? filter : [filter];
      if (this.q.per) query.per = this.q.per;
      this.$router.push({ path: '/sqlite', query });
    },
    tableHref(db, t) { return '#/sqlite?db=' + encodeURIComponent(db) + '&t=' + encodeURIComponent(t); },
    switchTable(e) { this.open(e.target.value); },
    addDraft(focus = true) {
      const c = this.cols[0];
      this.draft.push({ column: c ? c.name : '', op: 'contains', value: '' });
      if (focus) this.$nextTick(() => { const el = this.$el.querySelector('.sq-f:last-child input'); if (el) el.focus(); });
    },
    removeDraft(i) { this.draft.splice(i, 1); },
    needsValue(op) { return !this.ops[op] || this.ops[op].needs_value; },
    apply() {
      const f = this.draft
        .filter(d => d.column && (d.value !== '' || !this.needsValue(d.op)))
        .map(d => d.column + ':' + d.op + ':' + (this.needsValue(d.op) ? d.value : ''));
      this.go({ f, q: this.kw.trim(), page: 1 });
    },
    clear() { this.go({ f: [], q: '', page: 1 }); },
    sortBy(c) {
      const cur = this.q.sort === c ? (this.q.dir === 'desc' ? 'desc' : 'asc') : '';
      if (!cur) this.go({ sort: c, dir: 'asc', page: 1 });
      else if (cur === 'asc') this.go({ sort: c, dir: 'desc', page: 1 });
      else this.go({ sort: '', dir: '', page: 1 });
    },
    sortArrow(c) { return this.q.sort === c ? (this.q.dir === 'desc' ? '▼' : '▲') : ''; },
    setPer(e) { this.go({ per: e.target.value, page: 1 }); },
    toPage(p) {
      if (!this.res) return;
      const n = Math.min(Math.max(1, parseInt(p, 10) || 1), this.res.pages);
      if (n !== this.res.page) this.go({ page: n });
    },
    /* 顯示與關聯 */
    isJson(v) { return typeof v === 'string' && /^[[{]/.test(v); },
    pretty(v) { try { return JSON.stringify(JSON.parse(v), null, 2); } catch (e) { return v; } },
    text(v) { return v === null ? 'NULL' : String(v); },
    /* 關聯的篩選條件：本欄位的值，加上複合鍵的其他欄位（with＝{對方欄位: 本表欄位}，值取自同一列） */
    relFilter(column, v, withMap, row) {
      const f = [column + ':eq:' + v];
      Object.entries(withMap || {}).forEach(([to, from]) => {
        const k = this.res.columns.indexOf(from);
        if (k >= 0 && row && row[k] !== null) f.push(to + ':eq:' + row[k]);
      });
      return f;
    },
    refLink(col, v, row) {
      const c = this.colMap[col];
      if (!c || !c.ref || v === null || v === '') return null;
      if (c.ref.column === null) return { table: String(v), filter: null };
      return { table: c.ref.table, filter: this.relFilter(c.ref.column, v, c.ref.with, row) };
    },
    refbyLinks(col, v, row) {
      const c = this.colMap[col];
      if (!c || !c.refby.length || v === null || v === '') return [];
      return c.refby.map(r => ({ table: r.table, column: r.column, filter: this.relFilter(r.column, v, r.with, row) }));
    },
    jsonLinks(col, v) {
      const c = this.colMap[col];
      if (!c || !c.json || !this.isJson(v)) return [];
      let o; try { o = JSON.parse(v); } catch (e) { return []; }
      return Object.entries(c.json)
        .filter(([k]) => o && o[k] !== undefined && o[k] !== null && o[k] !== '')
        .map(([k, to]) => ({ key: k, value: o[k], table: to.table, filter: to.column + ':eq:' + o[k] }));
    },
    follow(l) { this.detail = null; this.open(l.table, l.filter); },
    showRow(i) {
      const r = this.res.rows[i];
      this.detail = { no: this.res.from + i, row: r, cells: this.res.columns.map((c, k) => ({ col: c, v: r[k] })) };
    },
    refTitle(c) {
      if (!c.ref) return '';
      return c.ref.column === null ? '值是資料表名稱' : '→ ' + c.ref.table + '.' + c.ref.column;
    }
  },
  template: `
<div class="stack">
  <!-- 資料表清單 -->
  <template v-if="!table">
    <div v-if="dbErr" class="err">無法取得資料庫清單：{{ dbErr }}</div>
    <div v-else-if="!dbs" class="pageloading">載入中⋯</div>
    <div v-else-if="!dbs.length" class="pageloading">sqlite/ 內還沒有資料庫</div>
    <Card v-for="d in dbs" :key="d.name" :title="'資料庫 ' + d.name" class="sq-db">
      <template #side>{{ bytes(d.size) }}</template>
      <KvRow k="檔案"><span class="mono">sqlite/{{ d.name }}.sqlite</span></KvRow>
      <KvRow k="最後建置">{{ d.built_at || d.modified }}</KvRow>
      <KvRow k="資料表">{{ d.tables.length }} 個</KvRow>
      <div class="sq-ro">唯讀瀏覽：只能查詢，不能寫入。</div>
      <div class="sq-sub">資料表</div>
      <div class="sq-tables">
        <a v-for="t in d.tables" :key="t.name" class="sq-tbl" :href="tableHref(d.name, t.name)">
          <div class="top"><span class="name mono">{{ t.name }}</span><Chip>{{ t.columns }} 欄</Chip><span class="n">{{ num(t.rows) }} 筆</span></div>
          <div v-if="t.desc" class="desc">{{ t.desc }}</div>
        </a>
      </div>
    </Card>
  </template>

  <!-- 表格檢視 -->
  <template v-else>
    <Card>
      <div class="sq-head">
        <a class="crumb" href="#/sqlite">資料庫</a><span class="muted">›</span>
        <span class="crumb mono">{{ db }}</span><span class="muted">›</span>
        <select :value="table" aria-label="資料表" @change="switchTable">
          <option v-if="!tableList.some(t => t.name === table)" :value="table">{{ table }}</option>
          <option v-for="t in tableList" :key="t.name" :value="t.name">{{ t.name }}（{{ num(t.rows) }}）</option>
        </select>
      </div>
      <div v-if="schema && schema.desc" class="sq-desc">{{ schema.desc }}</div>
      <div v-if="schema" class="sq-ro">共 {{ num(schema.rows) }} 筆．{{ cols.length }} 欄．主鍵 <span class="mono">{{ schema.pk.join(', ') || 'rowid' }}</span>．唯讀</div>
      <button v-if="schema" type="button" class="sq-link" @click="showSchema = !showSchema">{{ showSchema ? '收合結構' : '查看結構（欄位、索引、關聯）' }}</button>
      <template v-if="schema && showSchema">
        <div class="sq-wrap" style="max-height:none;margin-top:8px">
          <table class="sq-schema">
            <thead><tr><th>欄位</th><th>型別</th><th>鍵</th><th>說明</th><th>關聯</th></tr></thead>
            <tbody>
              <tr v-for="c in cols" :key="c.name">
                <td class="mono">{{ c.name }}</td>
                <td class="mono">{{ c.type || '—' }}{{ c.notnull ? ' NOT NULL' : '' }}</td>
                <td><span v-if="c.pk" class="chip warn">PK{{ schema.pk.length > 1 ? c.pk : '' }}</span></td>
                <td class="d">{{ c.desc || '—' }}</td>
                <td>
                  <div v-if="c.ref" class="mono">{{ refTitle(c) }}</div>
                  <div v-for="(to, k) in (c.json || {})" :key="k" class="mono">{{ k }} → {{ to.table }}.{{ to.column }}</div>
                  <div v-for="r in c.refby" :key="r.table + r.column" class="mono">← {{ r.table }}.{{ r.column }}</div>
                  <span v-if="!c.ref && !c.json && !c.refby.length" class="muted">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="sq-sub">索引</div>
        <div v-if="!schema.indexes.length" class="muted small">沒有索引</div>
        <div v-for="ix in schema.indexes" :key="ix.name" class="small">
          <span class="mono">{{ ix.name }}</span>（<span class="mono">{{ ix.columns.join(', ') }}</span>）
          <span class="muted">{{ ix.origin === 'pk' ? '主鍵' : ix.unique ? 'UNIQUE' : '' }}</span>
        </div>
        <div class="sq-sub">CREATE 語法</div>
        <pre class="sq-sql mono">{{ schema.sql }}</pre>
      </template>
    </Card>

    <Card title="篩選">
      <form @submit.prevent="apply">
        <input v-model="kw" class="sq-kw" type="search" placeholder="關鍵字：任一欄位包含" autocomplete="off" maxlength="200" aria-label="關鍵字">
        <div class="sq-filters" style="margin-top:8px">
          <div v-for="(d, i) in draft" :key="i" class="sq-f">
            <div class="cond">
              <select v-model="d.column" aria-label="欄位">
                <option v-for="c in cols" :key="c.name" :value="c.name">{{ c.name }}</option>
                <option v-if="d.column && !colMap[d.column]" :value="d.column">{{ d.column }}</option>
              </select>
              <select v-model="d.op" aria-label="運算子">
                <option v-for="(o, k) in ops" :key="k" :value="k">{{ o.label }}</option>
              </select>
              <input v-if="needsValue(d.op)" v-model="d.value" type="text" placeholder="值" maxlength="200" autocomplete="off" aria-label="值">
            </div>
            <button type="button" aria-label="移除條件" @click="removeDraft(i)">✕</button>
          </div>
        </div>
        <div class="sq-actions">
          <button type="submit" class="primary">套用</button>
          <button type="button" @click="addDraft()">＋ 條件</button>
          <button type="button" :disabled="!filters.length && !q.q" @click="clear">清除</button>
        </div>
      </form>
    </Card>

    <div ref="data"><Card title="資料">
      <template #side><a class="small" :href="apiUrl" target="_blank" rel="noopener">JSON</a></template>
      <div v-if="err" class="err">{{ err }}</div>
      <div v-else-if="!res" class="pageloading" style="padding:30px 0">載入中⋯</div>
      <template v-else>
        <div class="sq-bar">
          <span class="info">{{ res.total ? '第 ' + num(res.from) + '–' + num(res.to) + ' 筆，共 ' + num(res.total) + ' 筆' : '沒有符合的資料' }}</span>
          <Chip v-for="f in res.filters" :key="f.column + f.op + f.value">{{ f.column }} {{ ops[f.op] ? ops[f.op].label : f.op }} {{ f.value }}</Chip>
          <Chip v-if="res.q">關鍵字 {{ res.q }}</Chip>
          <label class="per">每頁
            <select :value="res.per" aria-label="每頁筆數" @change="setPer">
              <option v-for="n in perOptions" :key="n" :value="n">{{ n }}</option>
            </select>
          </label>
        </div>
        <div v-if="res.rows.length" ref="grid" class="sq-wrap" :class="{ busy: loading }">
          <table class="sq-grid">
            <thead>
              <tr>
                <th>#</th>
                <th v-for="c in cols" :key="c.name" :title="(c.desc ? c.desc + '\\n' : '') + (c.type || '') + (c.ref ? '\\n' + refTitle(c) : '')">
                  <div class="cn" role="button" tabindex="0" @click="sortBy(c.name)" @keydown.enter="sortBy(c.name)">
                    <span class="mono">{{ c.name }}</span><span v-if="c.pk" class="pk">PK</span><span class="arr">{{ sortArrow(c.name) }}</span>
                  </div>
                  <div class="ct mono">{{ c.type || 'ANY' }}</div>
                  <div v-if="c.desc" class="cd">{{ c.desc }}</div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, i) in res.rows" :key="res.from + i">
                <td class="no"><button type="button" :aria-label="'查看第 ' + (res.from + i) + ' 筆'" @click="showRow(i)">{{ res.from + i }}</button></td>
                <td v-for="(v, k) in r" :key="k" :title="text(v)">
                  <span v-if="v === null" class="sq-null">NULL</span>
                  <a v-else-if="refLink(res.columns[k], v, r)" href="" class="mono" @click.prevent="follow(refLink(res.columns[k], v, r))">{{ v }}</a>
                  <a v-else-if="isJson(v)" href="" class="mono" style="color:inherit" @click.prevent="showRow(i)">{{ v }}</a>
                  <span v-else>{{ v }}</span>
                  <a v-for="l in refbyLinks(res.columns[k], v, r)" :key="l.table + l.column" href="" class="rb"
                     @click.prevent="follow(l)">↳{{ l.table }}</a>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="res.pages > 1" class="sq-pager">
          <button type="button" :disabled="res.page <= 1" aria-label="第一頁" @click="toPage(1)">«</button>
          <button type="button" :disabled="res.page <= 1" aria-label="上一頁" @click="toPage(res.page - 1)">‹</button>
          <span>第 <input type="number" min="1" :max="res.pages" :value="res.page" aria-label="頁碼" @change="toPage($event.target.value)"> / {{ num(res.pages) }} 頁</span>
          <button type="button" :disabled="res.page >= res.pages" aria-label="下一頁" @click="toPage(res.page + 1)">›</button>
          <button type="button" :disabled="res.page >= res.pages" aria-label="最後一頁" @click="toPage(res.pages)">»</button>
        </div>
      </template>
    </Card></div>
  </template>

  <!-- 單筆明細 -->
  <div v-if="detail" class="sq-modal" @click.self="detail = null">
    <div class="sq-sheet" role="dialog" aria-modal="true">
      <div class="hd"><b>{{ table }} 第 {{ detail.no }} 筆</b><button type="button" @click="detail = null">關閉</button></div>
      <div v-for="c in detail.cells" :key="c.col" class="sq-field">
        <div class="k"><span class="mono">{{ c.col }}</span><span v-if="colMap[c.col] && colMap[c.col].desc"> · {{ colMap[c.col].desc }}</span></div>
        <div class="v">
          <span v-if="c.v === null" class="sq-null">NULL</span>
          <a v-else-if="refLink(c.col, c.v, detail.row)" href="" class="mono" @click.prevent="follow(refLink(c.col, c.v, detail.row))">{{ c.v }}</a>
          <pre v-else-if="isJson(c.v)" class="mono">{{ pretty(c.v) }}</pre>
          <span v-else class="mono">{{ c.v === '' ? '（空字串）' : c.v }}</span>
        </div>
        <div v-if="refbyLinks(c.col, c.v, detail.row).length || jsonLinks(c.col, c.v).length" class="links">
          <a v-for="l in refbyLinks(c.col, c.v, detail.row)" :key="'r' + l.table + l.column" href="" @click.prevent="follow(l)">↳ {{ l.table }}.{{ l.column }}</a>
          <a v-for="l in jsonLinks(c.col, c.v)" :key="'j' + l.key" href="" @click.prevent="follow(l)">{{ l.key }} {{ l.value }} → {{ l.table }}</a>
        </div>
      </div>
    </div>
  </div>
</div>`
};
