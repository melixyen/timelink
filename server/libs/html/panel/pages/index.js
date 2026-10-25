/* 總覽：服務狀態、TDX 流量、快取用量 */
import { store, svcName, bytes, dur, pct, hitTotals } from '../ui/index.js';

export default {
  name: 'PageOverview',
  data: () => ({ store }),
  computed: {
    svc() { return svcName(); },
    d() { return this.store.data; },
    service() { return (this.d && this.d.service) || {}; },
    cache() { return this.d && this.d.cache; },
    tdx() { return this.d && this.d.ratelimit && this.d.ratelimit.tdx; },
    proxy() { return this.d && this.d.ratelimit && this.d.ratelimit.proxy; },
    minute() {
      const l = this.tdx && this.tdx.limits;
      if (!l) return null;
      return l.minute || l[Object.keys(l)[0]] || null;
    },
    windows() {
      const l = (this.tdx && this.tdx.limits) || {};
      return Object.keys(l).map(w => l[w].limit + '/' + w).join('、') || '—';
    },
    usedRatio() {
      const m = this.minute;
      return m && m.limit && !this.tdx.stale ? (m.used || 0) / m.limit : 0;
    },
    usedTone() {
      if (!this.minute || this.tdx.stale) return '';
      return this.usedRatio >= 1 ? 'bad' : this.usedRatio >= 0.6 ? 'warn' : 'ok';
    },
    totals() {
      let hit = 0, total = 0;
      const modes = (this.cache && this.cache.modes) || {};
      Object.keys(modes).forEach(k => {
        const t = hitTotals(modes[k].stats);
        hit += t.hit; total += t.total;
      });
      return { hit, total };
    }
  },
  methods: { bytes, dur, pct },
  template: `
<div v-if="!d" class="pageloading">{{ store.error || '載入中⋯' }}</div>
<div v-else class="grid three">
  <Card :title="svc + ' 服務狀態'">
    <div class="chips">
      <Chip tone="ok">{{ svc }} 正常</Chip>
      <Chip :tone="service.configured ? 'ok' : 'bad'">{{ service.configured ? '金鑰已設定' : '金鑰未設定' }}</Chip>
      <Chip :tone="cache && cache.enabled === false ? 'warn' : 'ok'">{{ cache && cache.enabled === false ? '快取已停用' : '快取啟用中' }}</Chip>
    </div>
    <div style="margin-top:10px">
      <KvRow k="伺服器時間">{{ d.now }}</KvRow>
      <KvRow k="PHP">{{ service.php || '—' }}</KvRow>
      <KvRow k="允許網域">{{ (service.allowed_origins || []).join('、') }}<template v-if="service.allowed_origins_custom">，以及 custom 內的自訂網域</template></KvRow>
    </div>
  </Card>

  <Card title="TDX 流量（本服務金鑰）">
    <template v-if="minute && minute.limit">
      <BigStat :value="tdx.stale ? '—' : minute.remaining"
               :unit="'/ ' + minute.limit + ' 次 剩餘' + (tdx.stale ? '（紀錄已過期）' : '')" />
      <Meter :ratio="usedRatio" :tone="usedTone" />
    </template>
    <BigStat v-else value="—" unit="尚無紀錄" />
    <template v-if="tdx">
      <KvRow k="上限">{{ windows }}</KvRow>
      <KvRow k="重置倒數">{{ tdx.reset_in == null ? '—' : tdx.reset_in + ' 秒' }}</KvRow>
      <KvRow k="最後觀察">{{ tdx.observed_at }}（{{ dur(tdx.age_seconds) }}前）</KvRow>
      <KvRow k="最後狀態"><Chip v-if="tdx.last_status === 429" tone="bad">429 被限流</Chip><template v-else>{{ tdx.last_status }}</template></KvRow>
    </template>
    <template v-if="proxy">
      <KvRow k="今日呼叫 TDX">{{ proxy.calls_today }} 次</KvRow>
      <KvRow k="今日被限流"><Chip v-if="proxy.throttled_today" tone="bad">{{ proxy.throttled_today }} 次</Chip><template v-else>0 次</template></KvRow>
      <KvRow k="每分鐘最高">{{ proxy.peak_per_minute }} 次</KvRow>
      <KvRow v-if="proxy.yesterday" k="昨日">{{ proxy.yesterday.calls }} 次／限流 {{ proxy.yesterday.throttled }}</KvRow>
    </template>
    <div v-if="!tdx && !proxy" class="empty">尚無流量紀錄</div>
  </Card>

  <Card :title="svc + ' 快取用量'">
    <template v-if="cache">
      <BigStat :value="bytes(cache.bytes)" :unit="cache.entries + ' 筆'" />
      <div style="margin-top:8px">
        <KvRow k="整體命中率">{{ totals.total ? pct(totals.hit / totals.total) : '—' }}</KvRow>
        <KvRow k="命中／總請求">{{ totals.hit }} / {{ totals.total }}</KvRow>
        <KvRow k="狀態"><Chip :tone="cache.enabled === false ? 'warn' : 'ok'">{{ cache.enabled === false ? '已停用' : '啟用中' }}</Chip></KvRow>
      </div>
      <p class="small muted" style="margin:10px 0 0">各模式細節見 <a href="#/cache">快取統計</a>，每一筆見 <a href="#/list">快取清單</a>。</p>
    </template>
    <BigStat v-else value="未載入" />
  </Card>
</div>`
};
