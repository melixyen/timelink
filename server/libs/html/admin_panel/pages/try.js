/* 查詢測試（後臺，admin_finder_try）：輸入參數呼叫 admin_finder（等同 /txapi/finder）的 rail_station_time、rail_train_time，看請求網址、回應時間與結果。
   車站用 StationPicker（營運單位 → 路線 → 站序，可捲動、可快查）。參數都放在網址 #/try?func=…，可分享、上一頁返回。 */
import { StationPicker, loadStations, textOn } from '../../panel/ui/stationpicker.js';
import { auth, can, txapiUrl } from '../../panel/ui/index.js';

const ACTION = 'admin_finder';   // /txapi/admin_finder/<func>
const FUNCS = {
  rail_station_time: { title: '車站時刻', desc: '某站某星期幾的全日時刻（各方向），臺鐵、高鐵、捷運、輕軌統一格式',
                       fields: ['station', 'week', 'date', 'start', 'end', 'direction'] },
  rail_train_time:   { title: '列車時刻', desc: '依車次、星期、起訖站、時間、車種、路線找臺鐵、高鐵列車（含完整停靠站）；捷運回空陣列',
                       fields: ['key', 'system', 'train', 'week', 'date', 'station', 'dest', 'start', 'end', 'type', 'line', 'direction'] }
};
const ALL = ['key', 'system', 'train', 'station', 'dest', 'week', 'date', 'start', 'end', 'direction', 'type', 'line'];
const WEEK = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
const WEEK_KEYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const DIR = { TRA: ['順行', '逆行'], THSR: ['南下', '北上'] };
const PAGE = 100;

const empty = () => Object.fromEntries(ALL.map(k => [k, '']));

export default {
  name: 'PageTry',
  components: { StationPicker },
  data: () => ({
    func: 'rail_station_time', form: empty(), dateMode: 'week',
    res: null, status: 0, ms: 0, url: '', err: '', missing: [], loading: false, seq: 0,
    lines: {}, limit: PAGE, openTrains: {}, copied: false
  }),
  style: `
.try-tabs { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.try-tabs button { padding: 10px 8px; font-weight: 600; }
.try-tabs button.cur { background: var(--accent); border-color: var(--accent); color: #fff; }
.try-desc { font-size: 13px; color: var(--muted); margin: 8px 0 0; }
.try-form { display: grid; gap: 12px; margin-top: 12px; grid-template-columns: minmax(0, 1fr); }
@media (min-width: 640px) { .try-form { grid-template-columns: repeat(2, minmax(0, 1fr)); } .try-form .wide { grid-column: 1 / -1; } }
.try-f { display: grid; gap: 4px; min-width: 0; }
.try-f > label, .try-f > .lbl { font-size: 12.5px; color: var(--muted); display: flex; align-items: center; gap: 6px; }
.try-f .req { color: var(--bad); }
.try-f input, .try-f select { width: 100%; min-width: 0; box-sizing: border-box; }
.try-f .hint { font-size: 11.5px; color: var(--muted); }
.try-2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 6px; }
.try-seg { display: inline-flex; gap: 2px; margin-left: auto; }
.try-seg button { font-size: 11.5px; padding: 2px 8px; min-height: 0; }
.try-seg button.cur { background: var(--accent); border-color: var(--accent); color: #fff; }
.try-actions { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
.try-actions .primary { background: var(--accent); color: #fff; border-color: var(--accent); min-width: 96px; }
.try-url { display: flex; gap: 8px; align-items: flex-start; font-size: 12.5px; background: var(--chip); border-radius: 10px; padding: 8px 10px; }
.try-url a { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.try-url button { flex: 0 0 auto; font-size: 12px; padding: 2px 8px; min-height: 0; }
.try-meta { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 8px; font-size: 13px; }
.try-note { font-size: 12.5px; color: var(--warn); margin-top: 6px; }
.try-st { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.try-st b { font-size: 18px; }
.lc { display: inline-block; font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 5px; line-height: 1.4; }
.lcs { display: inline-flex; gap: 3px; flex-wrap: wrap; }
.tt { display: grid; }
.tt-row { display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; gap: 4px 10px; align-items: center; padding: 9px 0;
  border-bottom: 1px solid var(--line); font-size: 14px; }
.tt-row:last-child { border-bottom: 0; }
.tt-row .tm { font-size: 18px; font-weight: 700; font-variant-numeric: tabular-nums; }
.tt-row .tm small { display: block; font-size: 11px; font-weight: 400; color: var(--muted); }
.tt-row .mid { min-width: 0; }
.tt-row .mid .l1 { display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: center; }
.tt-row .mid .l2 { font-size: 12.5px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
.tt-row .dir { font-size: 12px; color: var(--muted); text-align: right; white-space: nowrap; }
button.lnk { background: none; border: 0; padding: 0; min-height: 0; color: var(--accent); font: inherit; font-weight: 600; cursor: pointer; }
.tr + .tr { margin-top: 8px; }
.tr { border: 1px solid var(--line); border-radius: 12px; padding: 10px 12px; }
.tr .hd { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; }
.tr .hd b { font-size: 16px; }
.tr .rt { font-size: 14px; margin-top: 4px; overflow-wrap: anywhere; }
.tr .rt .t { font-variant-numeric: tabular-nums; font-weight: 600; }
.tr .sub { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; font-size: 12.5px; color: var(--muted); margin-top: 4px; }
.wk { display: inline-flex; gap: 2px; }
.wk i { font-style: normal; font-size: 11px; width: 18px; text-align: center; border-radius: 4px; background: var(--chip); color: var(--muted); }
.wk i.on { background: var(--ok-bg); color: var(--ok); font-weight: 700; }
.stops { width: 100%; border-collapse: collapse; font-size: 13.5px; margin-top: 8px; }
.stops th, .stops td { padding: 5px 6px; border-bottom: 1px solid var(--line); text-align: left; }
.stops th { color: var(--muted); font-weight: 600; font-size: 12px; }
.stops td.t { font-variant-numeric: tabular-nums; white-space: nowrap; }
.stops tr.hit td { background: var(--warn-bg); }
.more { display: block; width: 100%; margin-top: 10px; }
.raw summary { cursor: pointer; font-size: 13px; color: var(--accent); }
.raw pre { font-size: 11.5px; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 60vh; overflow: auto; background: var(--chip);
  border-radius: 10px; padding: 10px; margin: 8px 0 0; }
.missing { margin: 8px 0 0; padding-left: 18px; font-size: 13px; }
.missing li { overflow-wrap: anywhere; }
`,
  computed: {
    authOk() { return auth.checked && can('admin_finder_try', 'read'); },
    authMsg() {
      if (!auth.checked) return '載入中⋯';
      if (!auth.user) return '請先登入（右上「登入」）';
      return '沒有查詢測試（admin_finder_try）的讀取權限';
    },
    def() { return FUNCS[this.func]; },
    has() { return Object.fromEntries(this.def.fields.map(f => [f, true])); },
    funcs() { return FUNCS; },
    weeks() { return WEEK; },
    shownUrl() { try { return decodeURIComponent(this.url); } catch (e) { return this.url; } },
    canRun() { return this.func !== 'rail_station_time' || !!this.form.station; },
    rows() { return this.res ? (this.res.timetable || this.res.trains || []) : []; },
    shown() { return this.rows.slice(0, this.limit); },
    hitUID() { return this.res && this.res.query ? this.res.query.station || '' : ''; },
    rawText() {
      if (!this.res) return '';
      const s = JSON.stringify(this.res, null, 2);
      return s.length > 200000 ? s.slice(0, 200000) + '\n…（超過 200 KB，後面省略，完整內容請開啟請求網址）' : s;
    }
  },
  watch: {
    '$route.query': { handler() { this.sync(); }, deep: true }
  },
  created() {
    loadStations().then(d => { this.lines = d.lines; }).catch(() => {});
    this.sync();
  },
  methods: {
    /* 網址 → 表單；有查詢參數就執行 */
    sync() {
      if (this.$route.path !== '/try') return;
      const q = this.$route.query;
      this.func = FUNCS[q.func] ? q.func : 'rail_station_time';
      const f = empty();
      ALL.forEach(k => { if (typeof q[k] === 'string') f[k] = q[k]; });
      this.form = f;
      this.dateMode = f.date ? 'date' : 'week';
      if (this.def.fields.some(k => f[k] !== '') && this.canRun) this.run();
      else { this.res = null; this.err = ''; this.url = ''; }
    },
    params() {
      const p = { func: this.func };
      this.def.fields.forEach(k => {
        if (k === 'week' && this.dateMode === 'date') return;
        if (k === 'date' && this.dateMode !== 'date') return;
        const v = String(this.form[k] || '').trim();
        if (v !== '') p[k] = v;
      });
      return p;
    },
    submit() {
      if (!this.canRun) return;
      const query = this.params();
      const same = JSON.stringify(query) === JSON.stringify(this.$route.query);
      if (same) this.run(); else this.$router.push({ path: '/try', query });
    },
    reset() { this.$router.push({ path: '/try', query: { func: this.func } }); },
    setFunc(f) {
      if (f === this.func) return;
      const keep = {};
      FUNCS[f].fields.forEach(k => { if (this.form[k]) keep[k] = this.form[k]; });
      this.func = f;
      this.form = { ...empty(), ...keep };
      this.res = null; this.err = ''; this.url = '';
    },
    async run() {
      const my = ++this.seq;
      const { func, ...rest } = this.params();
      this.url = txapiUrl(ACTION, func, rest);
      this.loading = true; this.err = ''; this.missing = [];
      const t0 = performance.now();
      try {
        const r = await fetch(this.url, { cache: 'no-store', credentials: 'same-origin' });
        const text = await r.text();
        if (my !== this.seq) return;
        this.ms = Math.round(performance.now() - t0);
        this.status = r.status;
        let d = null;
        try { d = JSON.parse(text); } catch (e) { /* 不是 JSON */ }
        if (!d) throw new Error('回應不是 JSON（HTTP ' + r.status + '）');
        this.res = d;
        this.limit = PAGE;
        this.openTrains = d.trains && d.trains.length === 1 ? { 0: true } : {};
        if (!r.ok || !d.ok) { this.err = d.error || ('HTTP ' + r.status); this.missing = d.missing || []; }
      } catch (e) {
        if (my !== this.seq) return;
        this.err = e.message; this.res = null;
      } finally {
        if (my === this.seq) this.loading = false;
      }
    },
    async copy() {
      try { await navigator.clipboard.writeText(location.origin + this.url); this.copied = true; setTimeout(() => { this.copied = false; }, 1500); }
      catch (e) { /* 不支援剪貼簿 */ }
    },
    /* 顯示 */
    line(sys, id) { return this.lines[sys + '-' + id] || null; },
    lineStyle(sys, id) {
      const l = this.line(sys, id);
      const c = l ? l.LineColor : '#6b7385';
      return { background: c, color: textOn(c) };
    },
    lineName(sys, id) { const l = this.line(sys, id); return l ? l.LineName.Zh_tw : id; },
    dirText(sys, d) {
      if (d === '' || d === null || d === undefined) return '';
      const t = DIR[sys];
      return t ? t[d] || '方向 ' + d : '方向 ' + d;
    },
    name(n) { return n && n.Zh_tw ? n.Zh_tw : ''; },
    type(r) { return this.name(r.TrainTypeName) || r.TrainTypeCode || ''; },
    weekOn(sd, i) { return sd && Number(sd[WEEK_KEYS[i]]) === 1; },
    first(tr) { const s = tr.StopTimes || []; return s.length ? s[0].DepartureTime || s[0].ArrivalTime : ''; },
    last(tr) { const s = tr.StopTimes || []; return s.length ? s[s.length - 1].ArrivalTime || s[s.length - 1].DepartureTime : ''; },
    toggleTrain(i) { this.openTrains = { ...this.openTrains, [i]: !this.openTrains[i] }; },
    /* 跳轉：車次 → 列車時刻；停靠站 → 車站時刻（保留星期／日期） */
    goTrain(key) {
      const q = { func: 'rail_train_time', key };
      this.$router.push({ path: '/try', query: q });
    },
    goStation(uid) {
      const q = { func: 'rail_station_time', station: uid };
      if (this.dateMode === 'date' && this.form.date) q.date = this.form.date;
      else if (this.form.week !== '') q.week = this.form.week;
      this.$router.push({ path: '/try', query: q });
    }
  },
  template: `
<div v-if="!authOk" class="pageloading">{{ authMsg }}</div>
<div v-else class="stack">
  <Card title="finder API 測試">
    <div class="try-tabs" role="tablist">
      <button v-for="(f, k) in funcs" :key="k" type="button" role="tab" :aria-selected="func === k ? 'true' : 'false'"
              :class="{ cur: func === k }" @click="setFunc(k)">{{ f.title }}<br><small class="mono" style="font-weight:400;opacity:.8">{{ k }}</small></button>
    </div>
    <p class="try-desc">{{ def.desc }}</p>

    <form @submit.prevent="submit">
      <div class="try-form">
        <div v-if="has.key" class="try-f wide">
          <label for="try-key">列車 key</label>
          <input id="try-key" v-model="form.key" type="text" placeholder="例如 TRA-123、THSR-0108（有填時忽略系統與車次）" autocomplete="off">
        </div>
        <div v-if="has.system" class="try-f">
          <label for="try-sys">系統</label>
          <select id="try-sys" v-model="form.system"><option value="">臺鐵＋高鐵</option><option value="TRA">臺鐵 TRA</option><option value="THSR">高鐵 THSR</option></select>
        </div>
        <div v-if="has.train" class="try-f">
          <label for="try-train">車次</label>
          <input id="try-train" v-model="form.train" type="text" inputmode="numeric" placeholder="例如 108（前導 0 可省略）" autocomplete="off">
        </div>
        <div v-if="has.station" class="try-f" :class="{ wide: func === 'rail_station_time' }">
          <span class="lbl">{{ func === 'rail_station_time' ? '車站' : '停靠站（起站）' }}<span v-if="func === 'rail_station_time'" class="req">＊</span></span>
          <StationPicker v-model="form.station" :label="func === 'rail_station_time' ? '選擇車站' : '選擇停靠站'" />
        </div>
        <div v-if="has.dest" class="try-f">
          <span class="lbl">終點站（在起站之後停靠）</span>
          <StationPicker v-model="form.dest" label="選擇終點站" placeholder="不限" />
        </div>
        <div class="try-f">
          <div class="lbl">{{ dateMode === 'date' ? '日期' : '星期' }}
            <span class="try-seg">
              <button type="button" :class="{ cur: dateMode === 'week' }" @click="dateMode = 'week'">星期</button>
              <button type="button" :class="{ cur: dateMode === 'date' }" @click="dateMode = 'date'">日期</button>
            </span>
          </div>
          <select v-if="dateMode === 'week'" v-model="form.week" aria-label="星期">
            <option value="">{{ func === 'rail_station_time' ? '今天' : '不限' }}</option>
            <option v-for="(w, i) in weeks" :key="i" :value="String(i)">{{ w }}（{{ i }}）</option>
          </select>
          <input v-else v-model="form.date" type="date" aria-label="日期">
          <span class="hint">{{ dateMode === 'date' ? '換算成星期查詢；定期時刻表不含該日的加開、停駛' : '0＝星期日' }}</span>
        </div>
        <div class="try-f">
          <span class="lbl">時間範圍（HH:MM，過午夜可寫 24:30）</span>
          <div class="try-2">
            <input v-model="form.start" type="text" inputmode="numeric" placeholder="開始 07:00" maxlength="5" aria-label="開始時間" autocomplete="off">
            <input v-model="form.end" type="text" inputmode="numeric" placeholder="結束 09:00" maxlength="5" aria-label="結束時間" autocomplete="off">
          </div>
        </div>
        <div v-if="has.direction" class="try-f">
          <label for="try-dir">方向</label>
          <select id="try-dir" v-model="form.direction">
            <option value="">全部</option><option value="0">0（臺鐵順行、高鐵南下、捷運去程）</option><option value="1">1（臺鐵逆行、高鐵北上、捷運回程）</option>
          </select>
        </div>
        <div v-if="has.type" class="try-f">
          <label for="try-type">車種</label>
          <input id="try-type" v-model="form.type" type="text" placeholder="TrainTypeCode 或 ID，逗號分隔，例如 1,2" autocomplete="off">
        </div>
        <div v-if="has.line" class="try-f">
          <label for="try-line">行經路線</label>
          <input id="try-line" v-model="form.line" type="text" placeholder="LineID，例如 WL、EL、PX" autocomplete="off">
        </div>
      </div>
      <div class="try-actions">
        <button type="submit" class="primary" :disabled="!canRun || loading">{{ loading ? '查詢中⋯' : '查詢' }}</button>
        <button type="button" @click="reset">重設</button>
      </div>
    </form>
  </Card>

  <Card v-if="url" title="結果">
    <div class="try-url"><a class="mono" :href="url" target="_blank" rel="noopener">GET {{ shownUrl }}</a>
      <button type="button" @click="copy">{{ copied ? '已複製' : '複製' }}</button></div>
    <div class="try-meta">
      <Chip :tone="status >= 200 && status < 300 ? 'ok' : 'bad'">HTTP {{ status || '—' }}</Chip>
      <Chip>{{ ms }} ms</Chip>
      <Chip v-if="res && res.count != null">{{ res.count }} 筆</Chip>
      <Chip v-if="res && res.weekday">{{ res.weekday }}{{ res.date ? '（' + res.date + '）' : '' }}</Chip>
      <Chip v-if="res && res.sqlite" :tone="res.sqlite.rebuilt ? 'warn' : ''">{{ res.sqlite.rebuilt ? '查詢前重建了資料庫' : '資料庫 ' + res.sqlite.built_at }}</Chip>
    </div>
    <div v-if="err" class="err" style="margin-top:10px">{{ err }}
      <ul v-if="missing.length" class="missing"><li v-for="m in missing" :key="m.path"><span class="mono">{{ m.path }}</span>（{{ m.reason }}）</li></ul>
    </div>
    <div v-for="n in [].concat((res && res.note) || [])" :key="n" class="try-note">{{ n }}</div>
  </Card>

  <!-- 車站時刻 -->
  <Card v-if="res && res.ok && res.func === 'rail_station_time'" title="車站時刻表">
    <template #side>{{ rows.length }} 班</template>
    <div class="try-st">
      <b>{{ name(res.station.StationName) }}</b>
      <span class="mono muted small">{{ res.station.StationUID }}</span>
      <span class="lcs"><span v-for="l in res.station.LineIDs" :key="l" class="lc" :style="lineStyle(res.station.System, l)" :title="lineName(res.station.System, l)">{{ l }}</span></span>
    </div>
    <div class="try-meta">
      <Chip v-for="(n, d) in res.directions" :key="d">{{ dirText(res.station.System, d) || '方向不明' }} {{ n }} 班</Chip>
    </div>
    <div v-if="!rows.length" class="empty">沒有班次</div>
    <div class="tt">
      <div v-for="(r, i) in shown" :key="i" class="tt-row">
        <div class="tm">{{ r.DepartureTime || r.ArrivalTime }}<small v-if="r.ArrivalTime && r.DepartureTime && r.ArrivalTime !== r.DepartureTime">到 {{ r.ArrivalTime }}</small><small v-else-if="!r.DepartureTime">到站</small></div>
        <div class="mid">
          <div class="l1">
            <button v-if="r.TrainKey" type="button" class="lnk mono" @click="goTrain(r.TrainKey)">{{ r.TrainNo }}</button>
            <span v-if="type(r)">{{ type(r) }}</span>
            <span class="lcs"><span v-for="(l, j) in r.LineIDs" :key="j" class="lc" :style="lineStyle(r.System, l)">{{ l }}</span></span>
          </div>
          <div class="l2">
            <template v-if="r.StartingStationUID"><button type="button" class="lnk" style="font-weight:400" @click="goStation(r.StartingStationUID)">{{ name(r.StartingStationName) || r.StartingStationUID }}</button> → </template>
            <template v-else>… → </template>
            <button v-if="r.EndingStationUID" type="button" class="lnk" style="font-weight:400" @click="goStation(r.EndingStationUID)">{{ name(r.EndingStationName) || r.EndingStationUID }}</button>
            <span v-if="r.TripHeadSign">．{{ r.TripHeadSign }}</span>
            <span v-if="r.ServiceTag">．{{ r.ServiceTag }}</span>
          </div>
        </div>
        <div class="dir">{{ dirText(r.System, r.Direction) }}</div>
      </div>
    </div>
    <button v-if="rows.length > limit" type="button" class="more" @click="limit += 100">再顯示 100 班（還有 {{ rows.length - limit }} 班）</button>
  </Card>

  <!-- 列車時刻 -->
  <Card v-if="res && res.ok && res.func === 'rail_train_time'" title="列車">
    <template #side>{{ rows.length }} 班</template>
    <div v-if="!rows.length" class="empty">沒有符合的列車</div>
    <div v-for="(tr, i) in shown" :key="tr.TrainKey" class="tr">
      <div class="hd">
        <b class="mono">{{ tr.TrainNo }}</b><span>{{ type(tr) }}</span>
        <span class="lcs"><span v-for="(l, j) in tr.LineIDs" :key="j" class="lc" :style="lineStyle(tr.System, l)" :title="lineName(tr.System, l)">{{ l }}</span></span>
        <span class="muted small">{{ dirText(tr.System, tr.Direction) }}</span>
      </div>
      <div class="rt">
        <button type="button" class="lnk" @click="goStation(tr.StartingStationUID)">{{ name(tr.StartingStationName) }}</button> <span class="t">{{ first(tr) }}</span>
        → <button type="button" class="lnk" @click="goStation(tr.EndingStationUID)">{{ name(tr.EndingStationName) }}</button> <span class="t">{{ last(tr) }}</span>
      </div>
      <div class="sub">
        <span class="wk" aria-label="行駛星期"><i v-for="(w, k) in weeks" :key="k" :class="{ on: weekOn(tr.ServiceDay, k) }">{{ w.slice(2) }}</i></span>
        <span v-if="tr.TripHeadSign">{{ tr.TripHeadSign }}</span>
        <span v-if="tr.Overnight === 1">跨日</span>
        <span v-if="tr.Note">{{ tr.Note }}</span>
      </div>
      <button type="button" class="lnk small" style="margin-top:6px" @click="toggleTrain(i)">{{ openTrains[i] ? '收合停靠站' : '停靠站 ' + (tr.StopTimes || []).length + ' 站' }}</button>
      <table v-if="openTrains[i]" class="stops">
        <thead><tr><th>#</th><th>車站</th><th>到</th><th>開</th></tr></thead>
        <tbody>
          <tr v-for="s in tr.StopTimes" :key="s.StopSequence" :class="{ hit: s.StationUID === hitUID }">
            <td class="muted">{{ s.StopSequence }}</td>
            <td><button type="button" class="lnk" style="font-weight:400" @click="goStation(s.StationUID)">{{ name(s.StationName) }}</button></td>
            <td class="t">{{ s.ArrivalTime }}</td><td class="t">{{ s.DepartureTime }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <button v-if="rows.length > limit" type="button" class="more" @click="limit += 100">再顯示 100 班（還有 {{ rows.length - limit }} 班）</button>
  </Card>

  <Card v-if="res" title="原始回應">
    <details class="raw"><summary>JSON（{{ rawText.length.toLocaleString() }} 字）</summary><pre class="mono">{{ rawText }}</pre></details>
  </Card>
</div>`
};
