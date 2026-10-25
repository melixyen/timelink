/* 車站快查選單：先選營運單位，再從依路線、站序排列的清單捲動挑車站（可輸入站名或站碼快查）。
   資料來自 /txapi/finder/rail_stations（整份載入一次、全頁共用）。
   每個區段（臺鐵依 railtime 分段，其他系統為整條路線）的標題與車站列以代表色為底，字色依底色深淺自動取黑或白。 */

const API = '/txapi/finder/rail_stations';
let loading = null;

/** 載入車站清單（只打一次 API） */
export function loadStations() {
  if (!loading) {
    loading = fetch(API, { cache: 'no-store' })
      .then(r => r.json().then(d => { if (!r.ok || !d.ok) throw new Error(d.error || 'HTTP ' + r.status); return d; }))
      .then(d => {
        const lines = {};
        const stations = {};
        d.operators.forEach(o => o.Lines.forEach(l => {
          lines[l.System + '-' + l.LineID] = l;
          l.Stations.forEach(s => {
            if (stations[s.StationUID]) return;
            const sec = sectionsOf(l).find(x => s.Sequence >= x.FromSequence && s.Sequence <= x.ToSequence);
            stations[s.StationUID] = { ...s, System: l.System, Operator: o.Operator, line: l,
                                       color: sec ? sec.Color : l.LineColor, badge: sec ? sec.Short : l.LineID };
          });
        }));
        return { operators: d.operators, lines, stations };
      })
      .catch(e => { loading = null; throw e; });
  }
  return loading;
}

/** 底色上可讀的字色：依 WCAG 相對亮度，取與底色對比較高的黑或白 */
export function textOn(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return '#fff';
  const n = parseInt(m[1], 16);
  const lin = c => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
  const L = 0.2126 * lin(n >> 16 & 255) + 0.7152 * lin(n >> 8 & 255) + 0.0722 * lin(n & 255);
  return (L + 0.05) / 0.05 >= 1.05 / (L + 0.05) ? '#111' : '#fff';
}

/** 路線的顯示區段；舊資料沒有 Sections 時整條一段 */
function sectionsOf(l) {
  return l.Sections && l.Sections.length ? l.Sections
    : [{ Name: l.LineName.Zh_tw, Short: l.LineID, Color: l.LineColor, FromSequence: 1, ToSequence: l.Stations.length }];
}

const norm = s => String(s || '').toLowerCase().replace(/臺/g, '台').replace(/\s+/g, '');

export const StationPicker = {
  name: 'StationPicker',
  props: {
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '選擇車站' },
    label: { type: String, default: '車站' }
  },
  emits: ['update:modelValue'],
  data: () => ({ data: null, err: '', open: false, op: '', q: '' }),
  style: `
.stp { position: relative; min-width: 0; }
.stp-trigger { width: 100%; display: flex; align-items: center; gap: 8px; text-align: left; min-height: 42px; box-sizing: border-box; }
.stp-trigger .ph { color: var(--muted); flex: 1; }
.stp-trigger .nm { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.stp-trigger .uid { font-size: 12px; color: var(--muted); }
.stp-trigger .caret { color: var(--muted); font-size: 12px; }
.stp-sw { flex: 0 0 auto; font-size: 11px; font-weight: 700; padding: 2px 6px; border-radius: 6px; line-height: 1.3; }
.stp-clear { background: none; border: 0; color: var(--muted); padding: 0 4px; font-size: 16px; cursor: pointer; }
.stp-mask { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 90; }
.stp-pop { position: fixed; left: 0; right: 0; bottom: 0; z-index: 91; background: var(--card); border-radius: 16px 16px 0 0;
  max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 -8px 30px rgba(0,0,0,.25);
  padding-bottom: env(safe-area-inset-bottom); }
@media (min-width: 640px) {
  .stp-mask { background: transparent; }
  .stp-pop { position: absolute; left: 0; right: auto; bottom: auto; top: calc(100% + 6px); width: 420px; max-width: calc(100vw - 32px);
    max-height: 520px; border-radius: 14px; border: 1px solid var(--line); box-shadow: 0 12px 30px rgba(0,0,0,.18); padding-bottom: 0; }
}
.stp-head { padding: 12px 12px 8px; border-bottom: 1px solid var(--line); display: grid; gap: 8px; }
.stp-head .ttl { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
.stp-head .ttl span { flex: 1; }
.stp-ops { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
.stp-ops::-webkit-scrollbar { display: none; }
.stp-ops button { flex: 0 0 auto; font-size: 13px; padding: 6px 10px; border-radius: 999px; }
.stp-ops button.cur { background: var(--accent); border-color: var(--accent); color: #fff; }
.stp-q { width: 100%; box-sizing: border-box; }
.stp-jump { display: flex; gap: 4px; flex-wrap: wrap; }
.stp-jump button { font-size: 11px; font-weight: 700; padding: 3px 7px; border-radius: 6px; border: 0; min-height: 0; }
.stp-list { overflow-y: auto; position: relative; flex: 1; min-height: 120px; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
.stp-line > h4 { position: sticky; top: 0; z-index: 1; margin: 0; padding: 7px 12px; font-size: 13px; display: flex; gap: 8px; align-items: center;
  box-shadow: 0 1px 0 rgba(0,0,0,.25); }
.stp-line > h4 small { margin-left: auto; font-weight: 500; opacity: .85; }
.stp-st { display: flex; align-items: center; gap: 10px; width: 100%; border: 0; border-radius: 0; text-align: left; padding: 9px 12px;
  font-size: 15px; border-top: 1px solid rgba(127,127,127,.28); min-height: 44px; cursor: pointer; }
.stp-st:hover, .stp-st:focus-visible { filter: brightness(.92); outline: none; }
.stp-st .seq { width: 22px; font-size: 11px; opacity: .75; text-align: right; flex: 0 0 auto; }
.stp-st .n { flex: 1; min-width: 0; }
.stp-st .n small { display: block; font-size: 11.5px; opacity: .8; }
.stp-st .id { font-size: 12px; opacity: .85; flex: 0 0 auto; }
.stp-st.sel { box-shadow: inset 5px 0 0 currentColor; font-weight: 700; }
.stp-st.sel .id::before { content: '✓ '; }
.stp-msg { padding: 24px 12px; text-align: center; color: var(--muted); font-size: 14px; }
`,
  computed: {
    sel() { return this.data && this.modelValue ? this.data.stations[this.modelValue] || null : null; },
    ops() { return this.data ? this.data.operators : []; },
    curOp() { return this.ops.find(o => o.Operator === this.op) || this.ops[0] || null; },
    lines() {
      if (!this.curOp) return [];
      const q = norm(this.q);
      // 每條路線依顯示區段拆成群組（臺鐵西部幹線拆成基隆-竹南、山線…），底色用區段代表色
      const groups = [];
      this.curOp.Lines.forEach(l => sectionsOf(l).forEach((x, i) => {
        const all = l.Stations.filter(s => s.Sequence >= x.FromSequence && s.Sequence <= x.ToSequence);
        groups.push({
          key: l.System + '-' + l.LineID + '-' + i, LineID: l.LineID, name: x.Name, short: x.Short, bg: x.Color, fg: textOn(x.Color),
          list: q ? all.filter(s => norm(s.StationName.Zh_tw + ' ' + s.StationName.En + ' ' + s.StationID + ' ' + s.StationUID).includes(q)) : all
        });
      }));
      return groups.filter(g => g.list.length);
    }
  },
  created() {
    loadStations().then(d => { this.data = d; }).catch(e => { this.err = e.message; });
    this._key = e => { if (e.key === 'Escape' && this.open) this.close(); };
    window.addEventListener('keydown', this._key);
  },
  unmounted() {
    window.removeEventListener('keydown', this._key);
    this.lockScroll(false);
  },
  methods: {
    textOn,
    toggle() { this.open ? this.close() : this.show(); },
    show() {
      this.op = this.sel ? this.sel.Operator : (this.op || (this.ops[0] && this.ops[0].Operator) || '');
      this.q = '';
      this.open = true;
      this.lockScroll(true);
      this.$nextTick(() => {
        const list = this.$refs.list;
        const el = list && list.querySelector('.stp-st.sel');
        if (el) list.scrollTop = el.offsetTop - list.clientHeight / 3;
      });
    },
    close() { this.open = false; this.lockScroll(false); },
    /* 手機版是底部面板，開啟時鎖住背景捲動 */
    lockScroll(on) {
      if (window.matchMedia('(min-width: 640px)').matches) return;
      document.body.style.overflow = on ? 'hidden' : '';
    },
    setOp(id) {
      this.op = id;
      this.$nextTick(() => { if (this.$refs.list) this.$refs.list.scrollTop = 0; });
    },
    jump(l) {
      const list = this.$refs.list;
      const el = list && list.querySelector('[data-line="' + l.key + '"]');
      if (el) list.scrollTop = el.offsetTop;
    },
    pick(s) { this.$emit('update:modelValue', s.StationUID); this.close(); },
    clear() { this.$emit('update:modelValue', ''); }
  },
  template: `
<div class="stp">
  <button type="button" class="stp-trigger" :aria-expanded="open ? 'true' : 'false'" :aria-label="label" @click="toggle">
    <template v-if="sel">
      <span class="stp-sw" :style="{ background: sel.color, color: textOn(sel.color) }">{{ sel.badge }}</span>
      <span class="nm">{{ sel.StationName.Zh_tw }} <span class="uid mono">{{ sel.StationUID }}</span></span>
    </template>
    <span v-else-if="modelValue" class="nm mono">{{ modelValue }}</span>
    <span v-else class="ph">{{ err ? '車站清單載入失敗' : placeholder }}</span>
    <span class="caret">▼</span>
  </button>
  <template v-if="open">
    <div class="stp-mask" @click="close"></div>
    <div class="stp-pop" role="dialog" :aria-label="label">
      <div class="stp-head">
        <div class="ttl"><span>{{ label }}</span>
          <button v-if="modelValue" type="button" @click="clear(); close()">清除</button>
          <button type="button" @click="close">關閉</button>
        </div>
        <div class="stp-ops" role="tablist" aria-label="營運單位">
          <button v-for="o in ops" :key="o.Operator" type="button" role="tab" :aria-selected="curOp === o ? 'true' : 'false'"
                  :class="{ cur: curOp === o }" @click="setOp(o.Operator)">{{ o.OperatorName }}</button>
        </div>
        <input v-model="q" class="stp-q" type="search" placeholder="快查：站名、英文或站碼" autocomplete="off" aria-label="快查車站">
        <div v-if="lines.length > 1" class="stp-jump" aria-label="跳到路線">
          <button v-for="l in lines" :key="l.key" type="button" :style="{ background: l.bg, color: l.fg }"
                  :title="l.name" @click="jump(l)">{{ l.short }}</button>
        </div>
      </div>
      <div ref="list" class="stp-list">
        <div v-if="err" class="stp-msg">{{ err }}</div>
        <div v-else-if="!data" class="stp-msg">載入車站清單⋯</div>
        <div v-else-if="!lines.length" class="stp-msg">沒有符合的車站</div>
        <section v-for="l in lines" :key="l.key" class="stp-line" :data-line="l.key">
          <h4 :style="{ background: l.bg, color: l.fg }">
            <span class="mono">{{ l.LineID }}</span>{{ l.name }}<small>{{ l.list.length }} 站</small>
          </h4>
          <button v-for="s in l.list" :key="s.StationUID" type="button" class="stp-st"
                  :class="{ sel: s.StationUID === modelValue }" :style="{ background: l.bg, color: l.fg }" @click="pick(s)">
            <span class="seq">{{ s.Sequence }}</span>
            <span class="n">{{ s.StationName.Zh_tw }}<small>{{ s.StationName.En }}</small></span>
            <span class="id mono">{{ s.StationID }}</span>
          </button>
        </section>
      </div>
    </div>
  </template>
</div>`
};
