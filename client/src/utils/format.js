import { i18n } from '@/i18n'
import { nameRules } from '@/i18n/nameRules'

/**
 * 資料名稱（名稱物件 Zh_tw／En／Ja／Ko）的取用順序由後端 /txapi/public_info/names 提供
 * （server/libs/txapi/names.php：預設「選取的語系 → 英文 → 中文」，日文等特定語言另有規則）。
 * 下面是規則還沒載入時的預設值：泰、越、印尼、西文沒有對應欄位，一律從英文開始。
 * 後端缺的語言已依補充名稱（server/libs/txapi/data/name_supplements.json）補過。
 */
const DEFAULT_FIELDS = { zh: 'Zh_tw', ja: 'Ja', ko: 'Ko' }
const DEFAULT_FALLBACK = ['En', 'Zh_tw', 'Ja', 'Ko']

/** 目前語言的名稱欄位取用順序 */
function nameOrder() {
  const lang = String(i18n.global.locale.value || 'en').slice(0, 2).toLowerCase()
  const rules = nameRules.value
  if (rules) {
    const order = rules.Orders?.[lang] || rules.Default
    if (Array.isArray(order) && order.length) return order
  }
  return [...new Set([DEFAULT_FIELDS[lang] || 'En', ...DEFAULT_FALLBACK])]
}

/** 目前的語言代碼前兩碼（zh／ja／ko／其餘） */
function localeGroup() {
  const locale = String(i18n.global.locale.value || 'en').toLowerCase()
  if (locale.startsWith('zh')) return 'zh'
  if (locale.startsWith('ja')) return 'ja'
  if (locale.startsWith('ko')) return 'ko'
  return 'other'
}

/**
 * 目前語言是否為中文語系（zh-TW／zh-CN）。
 * 後端有些欄位只有中文（列車等級中文名、行車方向的中文 HeadSign），
 * 只有中文語系才顯示，其他語系改用可翻譯的說明。
 */
export function isChineseLocale() {
  return localeGroup() === 'zh'
}

/**
 * 多語系名稱：依目前語言的取用順序（nameOrder）取第一個有值的欄位。
 * @param {object|string} obj StationName、LineName、OperatorNames、TrainType.Name 等多語系物件
 */
export function nameOf(obj) {
  if (!obj) return ''
  if (typeof obj === 'string') return obj
  for (const field of nameOrder()) {
    if (obj[field]) return obj[field]
  }
  return ''
}

/**
 * 分鐘數轉為「1 小時 30 分」形式
 * @param {number} minutes
 * @param {{ minute?: string, hour?: string }} units 由語言包提供的單位字串
 */
export function formatMinute2HM(minutes, units = {}) {
  const minuteUnit = units.minute || '分'
  const hourUnit = units.hour || '小時'
  const m = Math.max(0, Math.round(Number(minutes) || 0))
  if (m < 60) return `${m} ${minuteUnit}`
  const h = Math.floor(m / 60)
  const rest = m % 60
  return rest === 0 ? `${h} ${hourUnit}` : `${h} ${hourUnit} ${rest} ${minuteUnit}`
}

/**
 * 依各區段組出可讀的路徑說明（使用目前語言的名稱）。
 * @param {object} journey /txapi/app_trip/journeys 的單一方案
 * @param {{ t?: Function, lineName?: (uid: string) => string }} helpers
 */
export function buildRouteText(journey, helpers = {}) {
  const legs = (journey && journey.Legs) || []
  if (!legs.length) return (journey && journey.RouteText) || ''
  const t = helpers.t || ((key) => key)
  const lineName = helpers.lineName || ((uid) => uid)

  const parts = legs.map((leg) => {
    const from = nameOf(leg.From?.StationName)
    const to = nameOf(leg.To?.StationName)
    let label = ''
    if (leg.Type === 'walk') {
      label = t('journey.walkShort', { min: leg.Minutes })
    } else {
      const raw =
        (leg.LineName && nameOf(leg.LineName)) ||
        (leg.Path && leg.Path[0] && leg.Path[0].LineName && nameOf(leg.Path[0].LineName)) ||
        lineName(leg.LineUID)
      label = raw
    }
    return `${from} ─${label}→ ${to}`
  })

  return parts.join(' ')
}

/** 依背景色決定文字色 */
export function textOn(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '')
  if (!m) return '#fff'
  const n = parseInt(m[1], 16)
  const lin = (c) => {
    c /= 255
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  }
  const L = 0.2126 * lin(n >> 16) + 0.7152 * lin(n >> 8 & 255) + 0.0722 * lin(n & 255)
  return L + 0.05 >= 1.05 ? '#111' : '#fff'
}
