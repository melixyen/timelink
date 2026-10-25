/* 快取統計：各模式容量與命中、快取時間設定 */
import { store, svcName, bytes, dur, pct, tone, MODE_NAME, CAT_NAME } from '../ui/index.js';

export default {
  name: 'PageCache',
  data: () => ({ store }),
  style: `
.mode + .mode { border-top: 1px solid var(--line); padding-top: 10px; margin-top: 10px; }
.mode h3 { font-size: 15px; margin: 0 0 6px; display: flex; align-items: center; gap: 8px; }
.mode h3 .muted { font-weight: 400; font-size: 13px; }
.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.policy { width: 100%; border-collapse: collapse; font-size: 14px; }
.policy th, .policy td { text-align: left; padding: 7px 4px; border-bottom: 1px solid var(--line); vertical-align: top; }
.policy th { color: var(--muted); font-weight: 500; font-size: 12px; }
.policy td.num { white-space: nowrap; font-variant-numeric: tabular-nums; }
`,
  computed: {
    svc() { return svcName(); },
    cache() { return this.store.data && this.store.data.cache; },
    modes() {
      const m = (this.cache && this.cache.modes) || {};
      return Object.keys(m).map(k => ({ key: k, ...m[k] }));
    },
    policyRows() {
      const rows = [];
      this.modes.forEach(m => {
        const p = m.policy || {};
        ['static', 'timetable'].forEach(cat => {
          if (p[cat]) rows.push({ mode: m.key, label: CAT_NAME[cat], fresh: dur(p[cat].fresh), max: dur(p[cat].max) });
        });
        if (p.daily) {
          rows.push({ mode: m.key, label: 'Today 時刻表', span: p.daily.today });
          rows.push({ mode: m.key, label: '今天與未來日期', span: dur(p.daily.future) });
          rows.push({ mode: m.key, label: '過去日期', span: dur(p.daily.past) });
        }
      });
      return rows;
    }
  },
  methods: { bytes, pct, tone, modeName: k => MODE_NAME[k] || k, catName: c => CAT_NAME[c] || c },
  template: `
<div v-if="!cache" class="pageloading">{{ store.error || '載入中⋯' }}</div>
<div v-else class="grid">
  <Card :title="svc + ' 各模式快取'">
    <div v-for="m in modes" :key="m.key" class="mode">
      <h3><span class="dot" :style="{ background: 'var(--' + m.key + ')' }"></span>{{ modeName(m.key) }}
        <span class="muted">{{ m.entries }} 筆．{{ bytes(m.bytes) }}</span></h3>
      <Meter :ratio="m.stats.hit_rate || 0" :tone="tone(m.stats.hit_rate)" />
      <KvRow k="命中率">{{ pct(m.stats.hit_rate) }}</KvRow>
      <KvRow k="HIT／確認未變／舊資料">{{ m.stats.hit || 0 }}／{{ m.stats.revalidated || 0 }}／{{ m.stats.stale || 0 }}</KvRow>
      <KvRow k="MISS／錯誤">{{ m.stats.miss || 0 }}／{{ m.stats.error || 0 }}</KvRow>
      <KvRow k="統計起始">{{ m.stats.since || '—' }}</KvRow>
      <div v-if="Object.keys(m.by_category || {}).length" class="chips" style="margin-top:8px">
        <Chip v-for="(n, c) in m.by_category" :key="c">{{ catName(c) }} {{ n }}</Chip>
      </div>
    </div>
    <div v-if="!modes.length" class="empty">沒有資料</div>
  </Card>

  <Card :title="svc + ' 快取時間設定'">
    <table class="policy">
      <thead><tr><th>模式／分類</th><th>新鮮期</th><th>最長保存</th></tr></thead>
      <tbody>
        <tr v-for="(r, i) in policyRows" :key="i">
          <td><Chip :tone="r.mode">{{ modeName(r.mode) }}</Chip> {{ r.label }}</td>
          <td v-if="r.span" class="num" colspan="2">{{ r.span }}</td>
          <template v-else><td class="num">{{ r.fresh }}</td><td class="num">{{ r.max }}</td></template>
        </tr>
      </tbody>
    </table>
    <p class="small muted" style="margin:8px 0 0">新鮮期過後向 TDX 確認（304 不重新下載）；超過最長保存就整份重抓。預設值在 libs/config.json，覆蓋值在 custom/libs/config.json（後臺「設定管理」可改）。</p>
  </Card>
</div>`
};
