/* 設定管理（admin_settings）：檢視所有設定、項目編輯器（寫入權限）、整包下載 custom/（寫入權限）。
   不提供上傳：要放回檔案請用 FTP 或主機的檔案管理工具。 */
import { auth, can, api } from '../../panel/ui/index.js';

const LEVEL = { public: '公開', private: '私人', secret: '金鑰' };

function show(v) { return typeof v === 'string' ? v : JSON.stringify(v); }

export default {
  name: 'PageSettings',
  data: () => ({ auth, d: null, err: '', msg: '', LEVEL, edit: {}, busy: false, dlBusy: false }),
  style: `
.setlist .row { border-top: 1px solid var(--line); padding: 12px 0; }
.setlist .row:first-child, .setlist .grp + .row { border-top: 0; }
.setlist .grp { margin: 18px 0 2px; font-size: 13px; color: var(--accent); letter-spacing: .03em; }
.setlist .grp:first-of-type { margin-top: 4px; }
.setlist .k { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.setlist .k b { overflow-wrap: anywhere; }
.setlist .v { margin-top: 4px; font-size: 14px; overflow-wrap: anywhere; }
.setlist .desc { font-size: 12px; color: var(--muted); margin-top: 4px; white-space: pre-line; }
.setlist .ed { margin-top: 8px; display: grid; gap: 8px; }
.setlist .ed textarea { width: 100%; min-height: 110px; font: 13px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  border: 1px solid var(--line); border-radius: 10px; background: var(--bg); color: var(--text); padding: 8px 10px; }
.setlist .ed input[type=text], .setlist .ed input[type=number], .setlist .ed input[type=password] { width: 100%; }
.setlist .modes { display: flex; gap: 6px; flex-wrap: wrap; }
.setlist .modes button.cur { background: var(--accent); color: #fff; border-color: var(--accent); }
.savebar { position: sticky; bottom: 0; background: var(--bg); padding: 10px 0 calc(10px + env(safe-area-inset-bottom));
  display: flex; gap: 8px; align-items: center; border-top: 1px solid var(--line); z-index: 5; }
.savebar .grow { flex: 1; font-size: 13px; color: var(--muted); }
button.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.okmsg { background: var(--ok-bg); color: var(--ok); border-radius: 12px; padding: 10px 14px; }
`,
  computed: {
    canRead() { return can('admin_settings', 'read'); },
    canWrite() { return can('admin_settings', 'write'); },
    dirty() { return Object.keys(this.edit).length; }
  },
  watch: { 'auth.checked': { immediate: true, handler(v) { if (v && this.canRead) this.load(); } } },
  methods: {
    show,
    async load() {
      this.err = '';
      try { this.d = await api('admin_settings', 'view'); } catch (e) { this.err = e.message; }
    },
    begin(it) {
      if (this.edit[it.name]) { const e = { ...this.edit }; delete e[it.name]; this.edit = e; return; }
      let text = '';
      if (it.type === 'list') text = JSON.stringify(it.value, null, 1);
      else if (it.level !== 'secret') text = it.type === 'bool' ? String(it.value) : String(it.value);
      this.edit = { ...this.edit, [it.name]: { mode: it.custom ? 'custom' : 'default', text, bool: !!it.value } };
    },
    payload() {
      const changes = {};
      for (const it of this.d.items) {
        const e = this.edit[it.name];
        if (!e) continue;
        if (e.mode === 'default') { changes[it.name] = { mode: 'default' }; continue; }
        let value = e.text;
        if (it.type === 'bool') value = e.bool;
        else if (it.type === 'int') value = String(e.text).trim();
        else if (it.type === 'list') {
          try { value = JSON.parse(e.text); } catch (x) { throw new Error(it.name + '：不是合法的 JSON 陣列'); }
        }
        changes[it.name] = { mode: 'custom', value };
      }
      return changes;
    },
    async save() {
      this.err = ''; this.msg = '';
      let changes;
      try { changes = this.payload(); } catch (e) { this.err = e.message; return; }
      if (!confirm('儲存 ' + Object.keys(changes).length + ' 項設定？只改 custom/libs/config.json 裡這幾項，舊版會留在 custom/libs/config_history/。')) return;
      this.busy = true;
      try {
        const r = await api('admin_settings', 'save', { changes });
        this.msg = r.message; this.edit = {};
        await this.load();
      } catch (e) { this.err = e.message; } finally { this.busy = false; }
    },
    async download() {
      if (!confirm('下載整包 custom/？內含金鑰、帳號資料庫與 PAT 紀錄，請妥善保管。')) return;
      this.dlBusy = true; this.err = '';
      try {
        const r = await fetch('/txapi/admin_settings/download', { method: 'POST', credentials: 'same-origin',
          headers: { 'X-CSRF-Token': auth.csrf, 'Content-Type': 'application/json' }, body: '{}' });
        if (!r.ok) {
          let m = 'HTTP ' + r.status;
          try { const j = await r.json(); m = j.errorMsg || j.error || m; } catch (x) { /* 不是 JSON */ }
          throw new Error(m);
        }
        const cd = r.headers.get('content-disposition') || '';
        const name = (cd.match(/filename="([^"]+)"/) || [])[1] || 'custom.zip';
        const url = URL.createObjectURL(await r.blob());
        const a = document.createElement('a');
        a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 5000);
      } catch (e) { this.err = '下載失敗：' + e.message; } finally { this.dlBusy = false; }
    }
  },
  template: `
<div>
  <div v-if="!auth.checked" class="pageloading">載入中⋯</div>
  <div v-else-if="!auth.user" class="pageloading">請先登入</div>
  <div v-else-if="!canRead" class="pageloading">沒有設定管理（admin_settings）的讀取權限</div>
  <div v-else class="stack">
    <div v-if="err" class="err">{{ err }}</div>
    <div v-if="msg" class="okmsg">{{ msg }}</div>
    <template v-if="d">
      <div v-if="d.error" class="err">設定檔讀取失敗，目前全部用預設值：{{ d.error }}</div>
      <div v-if="d.unknownKeys && d.unknownKeys.length" class="err" style="background:var(--warn-bg);color:var(--warn)">
        設定檔有不認得的名稱（拼錯或已不使用，不會生效）：{{ d.unknownKeys.join('、') }}</div>
      <Card title="檔案">
        <KvRow v-for="(ok, f) in d.files" :key="f" :k="f"><Chip :tone="ok ? 'ok' : ''">{{ ok ? '存在' : '沒有' }}</Chip></KvRow>
        <KvRow k="設定檔舊版">{{ d.history.length }} 份</KvRow>
        <div v-if="canWrite" style="margin-top:10px">
          <button type="button" :disabled="dlBusy" @click="download">{{ dlBusy ? '打包中⋯' : '下載整包 custom/（' + d.archive + '）' }}</button>
        </div>
        <p class="small muted" style="margin:8px 0 0">不提供上傳：要放回檔案請用 FTP 或主機的檔案管理工具。</p>
      </Card>
      <Card title="設定值" class="setlist">
        <template v-for="(it, i) in d.items" :key="it.name">
        <h3 v-if="it.group && (i === 0 || it.group !== d.items[i - 1].group)" class="grp">{{ it.group }}</h3>
        <div class="row">
          <div class="k"><b class="mono">{{ it.name }}</b>
            <Chip :tone="it.custom ? 'ok' : ''">{{ it.custom ? 'custom' : '預設' }}</Chip>
            <Chip v-if="it.level !== 'public'" :tone="it.level === 'secret' ? 'warn' : ''">{{ LEVEL[it.level] }}</Chip>
            <button v-if="canWrite && it.editable" type="button" style="margin-left:auto;min-height:32px;padding:4px 10px"
                    @click="begin(it)">{{ edit[it.name] ? '取消' : '編輯' }}</button>
          </div>
          <div class="v mono">{{ show(it.value) }}</div>
          <div v-if="it.custom && it.level !== 'secret'" class="desc">預設：{{ show(it.default) }}</div>
          <div class="desc">{{ it.desc }}</div>
          <div v-if="!it.editable" class="desc">這一項不能在後臺修改（改了所有 PAT 與登入都會失效），要改請直接編輯 custom/libs/config.json。</div>
          <div v-if="edit[it.name]" class="ed">
            <div class="modes">
              <button type="button" :class="{ cur: edit[it.name].mode === 'default' }" @click="edit[it.name].mode = 'default'">用預設值</button>
              <button type="button" :class="{ cur: edit[it.name].mode === 'custom' }" @click="edit[it.name].mode = 'custom'">自訂</button>
            </div>
            <template v-if="edit[it.name].mode === 'custom'">
              <label v-if="it.type === 'bool'"><input type="checkbox" v-model="edit[it.name].bool" style="width:auto;min-height:0"> true（勾選）／false</label>
              <input v-else-if="it.type === 'int'" type="number" step="1" v-model="edit[it.name].text">
              <textarea v-else-if="it.type === 'list'" v-model="edit[it.name].text" spellcheck="false"></textarea>
              <input v-else-if="it.level === 'secret'" type="password" v-model="edit[it.name].text" placeholder="輸入新值（不會顯示目前的值）" autocomplete="new-password">
              <input v-else type="text" v-model="edit[it.name].text" autocapitalize="none" spellcheck="false">
              <div v-if="it.type === 'list'" class="desc">JSON 字串陣列，例如 ["localhost", "127.0.0.1"]</div>
            </template>
          </div>
        </div>
        </template>
      </Card>
      <div v-if="canWrite && dirty" class="savebar">
        <span class="grow">已修改 {{ dirty }} 項</span>
        <button type="button" @click="edit = {}">全部取消</button>
        <button type="button" class="primary" :disabled="busy" @click="save">{{ busy ? '儲存中⋯' : '儲存' }}</button>
      </div>
    </template>
  </div>
</div>`
};
