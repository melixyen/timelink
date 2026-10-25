/* 基礎元件：Card、KvRow、Chip、Meter、BigStat。全域註冊，頁面直接使用。 */

export const Card = {
  name: 'Card',
  props: { title: String },
  style: `
.card { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 14px; min-width: 0; }
.card > h2 { font-size: 13px; font-weight: 600; color: var(--muted); margin: 0 0 10px; letter-spacing: .02em;
  display: flex; align-items: center; gap: 8px; }
.card > h2 .side { margin-left: auto; font-weight: 400; }
`,
  template: `
<section class="card">
  <h2 v-if="title || $slots.side">{{ title }}<span class="side"><slot name="side" /></span></h2>
  <slot />
</section>`
};

export const KvRow = {
  name: 'KvRow',
  props: { k: String },
  style: `
.kv { display: flex; justify-content: space-between; gap: 12px; font-size: 14px; padding: 3px 0; }
.kv > span:first-child { color: var(--muted); flex: 0 0 auto; }
.kv > span:last-child { text-align: right; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; min-width: 0; }
`,
  template: `<div class="kv"><span>{{ k }}</span><span><slot /></span></div>`
};

export const Chip = {
  name: 'Chip',
  props: { tone: String },
  style: `
.chip { display: inline-block; font-size: 12px; padding: 2px 8px; border-radius: 999px; background: var(--chip);
  color: var(--text); white-space: nowrap; }
.chip.ok { background: var(--ok-bg); color: var(--ok); }
.chip.warn { background: var(--warn-bg); color: var(--warn); }
.chip.bad { background: var(--bad-bg); color: var(--bad); }
.chip.rail { color: var(--rail); }
.chip.bus { color: var(--bus); }
.chips { display: flex; flex-wrap: wrap; gap: 6px; }
`,
  template: `<span class="chip" :class="tone"><slot /></span>`
};

export const Meter = {
  name: 'Meter',
  props: { ratio: Number, tone: String },
  style: `
.meter { height: 8px; border-radius: 999px; background: var(--chip); overflow: hidden; margin: 8px 0 4px; }
.meter > i { display: block; height: 100%; background: var(--accent); transition: width .3s; }
.meter.ok > i { background: var(--ok); } .meter.warn > i { background: var(--warn); } .meter.bad > i { background: var(--bad); }
`,
  computed: {
    width() { return Math.max(0, Math.min(100, (this.ratio || 0) * 100)) + '%'; }
  },
  template: `<div class="meter" :class="tone"><i :style="{ width }"></i></div>`
};

export const BigStat = {
  name: 'BigStat',
  props: { value: [String, Number], unit: String },
  style: `
.bigstat { font-size: 28px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
.bigstat small { font-size: 14px; font-weight: 500; color: var(--muted); }
`,
  template: `<div class="bigstat">{{ value }} <small v-if="unit">{{ unit }}</small></div>`
};
