/**
 * 顯示用字：站名、路線名、營運系統名稱的「臺」顯示成「台」（使用者規則 2026-11，方便閱讀）。
 * 只轉「名稱」：API 回應裡 TAI_NAME_KEYS 這些欄位（含它們底下的多語系物件）在 api/txapi.js 收到時轉換（toTaiNames）；
 * 備註（Note）、說明、錯誤訊息等自由文字維持原文；正式名稱（TDX 運輸資料流通服務平臺）也不轉。
 * 語言檔裡的營運系統名稱（台鐵、台北捷運…）直接寫「台」。
 * 搜尋比對時兩個字視為同一個字（toTai 後再比），使用者打「臺北」也找得到。
 * 前端只讀公開 API、不會把轉換後的資料寫回後端，所以只動顯示，不影響後端資料。
 */
export function toTai(s) {
  return typeof s === 'string' && s.includes('臺') ? s.replace(/臺/g, '台') : s
}

/**
 * 要轉換的名稱欄位：站名、路線名、營運系統名稱、轉乘群組名、行車方向（往臺東），
 * 以及由這些名稱組成的路徑文字（RouteText）、走法類別名（臺鐵區間（西部幹線））、補充路由名（臺鐵區間車接力）、
 * 路線區段名（Name：南迴線(枋寮-臺東)；列車車種的 Name 不含臺字）
 */
export const TAI_NAME_KEYS = new Set([
  'StationName',
  'LineName',
  'OperatorName',
  'OperatorNames',
  'GroupName',
  'HeadSign',
  'RouteText',
  'CategoryName',
  'Supplement',
  'Name',
])

/** 物件、陣列裡名稱欄位（TAI_NAME_KEYS，含底下的多語系物件）的字串把「臺」換成「台」；原地修改並回傳 */
export function toTaiNames(value, inName = false) {
  if (typeof value === 'string') return inName ? toTai(value) : value
  if (Array.isArray(value)) {
    for (let i = 0; i < value.length; i += 1) value[i] = toTaiNames(value[i], inName)
    return value
  }
  if (value && typeof value === 'object') {
    for (const k of Object.keys(value)) value[k] = toTaiNames(value[k], inName || TAI_NAME_KEYS.has(k))
  }
  return value
}
