import { createI18n } from 'vue-i18n'
import zhTW from '../../language/zh-TW.json'
import zhCN from '../../language/zh-CN.json'
import en from '../../language/en.json'
import ja from '../../language/ja.json'

export const SUPPORTED_LOCALES = [
  { code: 'zh-TW', labelKey: 'app.lang.zh-TW' },
  { code: 'zh-CN', labelKey: 'app.lang.zh-CN' },
  { code: 'en', labelKey: 'app.lang.en' },
  { code: 'ja', labelKey: 'app.lang.ja' },
]

const STORAGE_KEY = 'timelink.locale'

const messages = {
  'zh-TW': zhTW,
  'zh-CN': zhCN,
  en,
  ja,
}

/** 依瀏覽器標頭（navigator.languages / navigator.language）判斷語言 */
export function detectLocale() {
  if (typeof window === 'undefined') return 'en'

  try {
    // 網址參數優先（?lang=ja），方便測試與分享；hash 模式也一併檢查
    const hashQuery = window.location.hash.includes('?')
      ? window.location.hash.slice(window.location.hash.indexOf('?') + 1)
      : ''
    const forced =
      new URLSearchParams(window.location.search).get('lang') ||
      new URLSearchParams(hashQuery).get('lang')
    if (forced && messages[forced]) return forced
  } catch {
    /* 無法解析網址參數時忽略 */
  }

  try {
    const saved = window.localStorage.getItem(STORAGE_KEY)
    if (saved && messages[saved]) return saved
  } catch {
    /* localStorage 不可用時忽略 */
  }

  const list = navigator.languages && navigator.languages.length ? navigator.languages : [navigator.language]
  for (const raw of list) {
    const tag = String(raw || '').toLowerCase().replace(/_/g, '-')
    if (!tag) continue
    if (tag.startsWith('zh')) {
      if (/-(hant|tw|hk|mo)\b/.test(tag) || /\b(hant)\b/.test(tag)) return 'zh-TW'
      if (/-(hans|cn|sg)\b/.test(tag) || /\b(hans)\b/.test(tag)) return 'zh-CN'
      return 'zh-TW'
    }
    if (tag.startsWith('ja')) return 'ja'
    if (tag.startsWith('en')) return 'en'
  }

  // 判斷不到時預設英文
  return 'en'
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: detectLocale(),
  fallbackLocale: 'en',
  messages,
})

if (typeof document !== 'undefined') {
  document.documentElement.lang = i18n.global.locale.value
  i18n.global.locale.value && document.documentElement.setAttribute('lang', i18n.global.locale.value)
}

/** 切換語言（並記住使用者的選擇） */
export function setLocale(locale) {
  if (!messages[locale]) return i18n.global.locale.value
  i18n.global.locale.value = locale
  if (typeof document !== 'undefined') document.documentElement.setAttribute('lang', locale)
  try {
    window.localStorage.setItem(STORAGE_KEY, locale)
  } catch {
    /* localStorage 不可用時忽略 */
  }
  return locale
}

/** 讓 Pinia store 也能取用翻譯字串 */
export function t(key, params) {
  return i18n.global.t(key, params || {})
}

export { i18n }
export default i18n
