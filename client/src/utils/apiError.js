import { i18n } from '@/i18n'

/**
 * API 錯誤的顯示文字（使用者規則 2026-11）：
 * - 後端有獨立錯誤代碼（errorCode）的：用語言檔 error.code.<代碼> 組訊息（代入後端的 errorVars）。
 *   中文（zh-TW、zh-CN）的訊息帶後端的說明（{detail}，只有中文）；其他語言省略說明。找不到的「{what}」（車站、路線、班次）另外翻譯
 * - 前端自己產生的（連不上、不是 JSON 的錯誤回應）：error.network、error.http
 * - 其他沒有代碼的：後端訊息是英文就直接顯示；只有中文的換成 error.unknown
 */
const WHAT = { 車站: 'station', 路線: 'line' }

export function apiErrorText(e) {
  const g = i18n.global
  if (!e) return g.t('error.unknown')
  if (e.kind === 'network') return g.t('error.network')
  if (e.kind === 'http') return g.t('error.http', { status: e.status || e.code || '' })
  const code = e.payload && e.payload.errorCode
  if (code && g.te(`error.code.${code}`)) {
    const vars = { ...(e.vars || {}) }
    if (vars.what !== undefined) {
      const key = String(vars.what).startsWith('班次') ? 'train' : WHAT[vars.what]
      if (key) vars.what = g.t(`error.what.${key}`)
    }
    return g.t(`error.code.${code}`, vars)
  }
  const msg = String((e && e.message) || '')
  if (!msg || /[㐀-鿿]/.test(msg)) return g.t('error.unknown')
  return msg
}
