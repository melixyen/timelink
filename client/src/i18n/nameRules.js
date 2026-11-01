import { ref } from 'vue'
import { api } from '@/api/txapi'

/**
 * 多語系名稱的取用規則：GET /txapi/public_info/names
 * （表在後端 server/libs/txapi/names.php 的 TXAPI_NAME_FIELDS、TXAPI_NAME_FALLBACK，以 git 維護，前端不另外寫死特殊規則）。
 *   { Fields: { zh: 'Zh_tw', ja: 'Ja', ko: 'Ko', '*': 'En' }, Default: [...], Orders: { ja: ['Ja', 'Zh_tw', 'En', 'Ko'], ... } }
 * 載入前或載入失敗時，nameOf() 用預設規則：選取語系的欄位 → En → Zh_tw → Ja → Ko。
 * 規則是 ref，載入完成後用到 nameOf() 的畫面會自動重新渲染。
 */
export const nameRules = ref(null)

let pending = null

/** 載入一次；重複呼叫共用同一個請求，失敗時下次呼叫再試 */
export function loadNameRules() {
  if (nameRules.value) return Promise.resolve(nameRules.value)
  if (pending) return pending
  pending = api
    .nameRules()
    .then((res) => {
      nameRules.value = res.data || null
      return nameRules.value
    })
    .catch(() => null)
    .finally(() => {
      pending = null
    })
  return pending
}
