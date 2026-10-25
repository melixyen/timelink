/* 初始化檢查清單（後臺首頁）：讀 /txapi/admin_settings/checklist（admin_settings 讀取權限）。
   新主機從空的 repo 上線要完成的步驟，逐項顯示是否完成與下一步；全部完成時收成一行。 */
import { api } from '../../panel/ui/index.js';

const STATUS = {
  ok:   { tone: 'ok',   label: '完成' },
  todo: { tone: 'bad',  label: '待處理' },
  warn: { tone: 'warn', label: '建議' },
  wait: { tone: '',     label: '等前一步' }
};

export const SetupChecklist = {
  name: 'SetupChecklist',
  data: () => ({ d: null, err: '', open: false, copied: '' }),
  style: `
.ck-sum { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.ck-item { display: grid; grid-template-columns: 28px minmax(0, 1fr); gap: 10px; padding: 12px 0; border-top: 1px solid var(--line); }
.ck-item:first-of-type { border-top: 0; }
.ck-no { width: 26px; height: 26px; border-radius: 50%; display: grid; place-items: center; font-size: 13px; font-weight: 700;
  background: var(--chip); color: var(--muted); }
.ck-no.ok { background: var(--ok-bg); color: var(--ok); }
.ck-no.todo { background: var(--bad-bg); color: var(--bad); }
.ck-no.warn { background: var(--warn-bg); color: var(--warn); }
.ck-head { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.ck-head b { font-size: 15px; }
.ck-item.wait .ck-head b { color: var(--muted); }
.ck-detail { font-size: 13px; color: var(--muted); margin-top: 2px; overflow-wrap: anywhere; }
.ck-acts { display: grid; gap: 6px; margin-top: 8px; }
.ck-acts a.btn { justify-self: start; display: inline-flex; align-items: center; min-height: 36px; padding: 6px 12px;
  border: 1px solid var(--accent); border-radius: 10px; color: var(--accent); text-decoration: none; font-size: 14px; font-weight: 600; }
.ck-code { display: flex; gap: 6px; align-items: flex-start; background: var(--chip); border-radius: 10px; padding: 8px 10px; }
.ck-code .k { flex: 0 0 auto; font-size: 11px; color: var(--muted); padding-top: 2px; }
.ck-code code { flex: 1; min-width: 0; font-size: 12px; overflow-wrap: anywhere; }
.ck-code button { flex: 0 0 auto; min-height: 28px; padding: 2px 8px; font-size: 12px; }
`,
  computed: {
    list() { return (this.d && this.d.items) || []; },
    show() { return this.d && (!this.d.done || this.open); }
  },
  created() { this.load(); },
  methods: {
    st(s) { return STATUS[s] || STATUS.wait; },
    async load() {
      this.err = '';
      try { this.d = await api('admin_settings', 'checklist'); } catch (e) { this.err = e.message; }
    },
    async copy(text) {
      try { await navigator.clipboard.writeText(text); this.copied = text; setTimeout(() => { this.copied = ''; }, 1500); } catch (e) { /* 不支援就算了 */ }
    }
  },
  template: `
<Card title="初始化檢查清單">
  <template #side><button type="button" style="min-height:32px;padding:4px 10px" @click="load">重新檢查</button></template>
  <div v-if="err" class="err">{{ err }}</div>
  <div v-else-if="!d" class="empty">檢查中⋯</div>
  <template v-else>
    <div class="ck-sum">
      <Chip tone="ok">完成 {{ d.count.ok }}</Chip>
      <Chip v-if="d.count.todo" tone="bad">待處理 {{ d.count.todo }}</Chip>
      <Chip v-if="d.count.warn" tone="warn">建議 {{ d.count.warn }}</Chip>
      <Chip v-if="d.count.wait">等前一步 {{ d.count.wait }}</Chip>
      <button v-if="d.done" type="button" style="margin-left:auto;min-height:32px;padding:4px 10px" @click="open = !open">{{ open ? '收合' : '展開' }}</button>
    </div>
    <p v-if="d.done && !open" class="small muted" style="margin:8px 0 0">必要的步驟都完成了{{ d.count.warn ? '，還有建議項目可以處理' : '' }}。</p>
    <div v-if="show" style="margin-top:6px">
      <div v-for="(it, i) in list" :key="it.key" class="ck-item" :class="it.status">
        <div class="ck-no" :class="it.status">{{ it.status === 'ok' ? '✓' : i + 1 }}</div>
        <div>
          <div class="ck-head"><b>{{ it.title }}</b><Chip :tone="st(it.status).tone">{{ st(it.status).label }}</Chip></div>
          <div class="ck-detail">{{ it.detail }}</div>
          <div v-if="it.panel || it.api || it.cli" class="ck-acts">
            <a v-if="it.panel" class="btn" :href="it.panel.href">前往{{ it.panel.label }}</a>
            <div v-if="it.api" class="ck-code"><span class="k">API</span><code class="mono">{{ it.api }}</code>
              <button type="button" @click="copy(it.api)">{{ copied === it.api ? '已複製' : '複製' }}</button></div>
            <div v-if="it.cli" class="ck-code"><span class="k">命令列</span><code class="mono">{{ it.cli }}</code>
              <button type="button" @click="copy(it.cli)">{{ copied === it.cli ? '已複製' : '複製' }}</button></div>
          </div>
        </div>
      </div>
      <p v-if="d.note" class="small muted" style="margin:10px 0 0">{{ d.note }}。API 要帶後臺 PAT（Authorization: Bearer）呼叫；命令列的 pipeline.php 會自動重複呼叫直到完成。</p>
    </div>
  </template>
</Card>`
};
