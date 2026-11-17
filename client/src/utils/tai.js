/**
 * 顯示用字：「臺」一律顯示成「台」（使用者規則 2026-11，方便閱讀）。
 * API 回應的字串值在 api/txapi.js 收到時統一轉換（toTaiDeep），語言檔與設定的文字直接寫「台」；
 * 搜尋比對時兩個字視為同一個字（toTai 後再比），使用者打「臺北」也找得到。
 * 前端只讀公開 API、不會把轉換後的資料寫回後端，所以只動顯示，不影響後端資料。
 */
export function toTai(s) {
  return typeof s === 'string' && s.includes('臺') ? s.replace(/臺/g, '台') : s
}

/** 把物件、陣列裡所有字串值（不含鍵名）的「臺」換成「台」；原地修改並回傳 */
export function toTaiDeep(value) {
  if (typeof value === 'string') return toTai(value)
  if (Array.isArray(value)) {
    for (let i = 0; i < value.length; i += 1) value[i] = toTaiDeep(value[i])
    return value
  }
  if (value && typeof value === 'object') {
    for (const k of Object.keys(value)) value[k] = toTaiDeep(value[k])
  }
  return value
}
