/**
 * 環境變數設定（讀自 client/.env）。
 * 所有部署環境會不同的項目都放在這裡，缺值時使用預設值。
 */

const env = import.meta.env

const str = (key, fallback = '') => {
  const value = env[key]
  return value === undefined || value === '' ? fallback : String(value)
}

const num = (key, fallback) => {
  const value = Number(env[key])
  return Number.isFinite(value) && value > 0 ? value : fallback
}

/** 星期幾：auto＝今天，或 0（星期日）～6（星期六） */
function parseWeek(value) {
  const v = String(value ?? 'auto').trim().toLowerCase()
  if (v === 'auto' || v === '') return new Date().getDay()
  const n = Number(v)
  return Number.isInteger(n) && n >= 0 && n <= 6 ? n : new Date().getDay()
}

/**
 * API 網址前綴：
 * - 未設定 VITE_TDX_BASE_URL 時使用預設的查詢服務網址
 * - 設成空字串代表使用同源的 /txapi（前端與後端同網域部署時）
 */
const apiBaseUrl =
  env.VITE_TDX_BASE_URL === undefined
    ? 'https://tdx.jiapi.net'
    : String(env.VITE_TDX_BASE_URL).replace(/\/+$/, '')

export const envConfig = {
  // API
  apiBaseUrl,
  apiTimeout: num('VITE_API_TIMEOUT', 30000),

  // 產品與組織
  appName: str('VITE_APP_NAME', '接軌時刻'),
  appSubtitle: str('VITE_APP_SUBTITLE', 'TimeLink'),
  orgName: str('VITE_ORG_NAME', ''),
  appVersion: str('VITE_APP_VERSION', '0.0.0'),
  homepageUrl: str('VITE_HOMEPAGE_URL', ''),
  contactEmail: str('VITE_CONTACT_EMAIL', ''),

  // 資料來源與授權
  dataSourceName: str('VITE_DATA_SOURCE_NAME', 'TDX 運輸資料流通服務平臺'),
  dataSourceUrl: str('VITE_DATA_SOURCE_URL', 'https://tdx.transportdata.tw/'),
  licenseName: str('VITE_LICENSE_NAME', '政府資料開放授權條款'),
  licenseUrl: str('VITE_LICENSE_URL', 'https://data.gov.tw/license'),

  // 預設查詢條件
  defaultFromStation: str('VITE_DEFAULT_FROM_STATION', ''),
  defaultToStation: str('VITE_DEFAULT_TO_STATION', ''),
  defaultStartTime: str('VITE_DEFAULT_START_TIME', '07:00'),
  defaultEndTime: str('VITE_DEFAULT_END_TIME', '09:00'),
  defaultWeek: parseWeek(env.VITE_DEFAULT_WEEK),
  defaultResultLimit: num('VITE_DEFAULT_RESULT_LIMIT', 10),
  // 指定路由查詢：路由清單最多列幾條（後端上限 APP_ROUTES_LIMIT_MAX）、一次最多勾幾條（後端上限 APP_JOURNEY_ROUTES）、
  // 回傳幾個方案（後端上限 APP_JOURNEYS_LIMIT_MAX）
  routeListLimit: num('VITE_ROUTE_LIST_LIMIT', 50),
  maxPickedRoutes: num('VITE_MAX_PICKED_ROUTES', 10),
  pickedResultLimit: num('VITE_PICKED_RESULT_LIMIT', 50),

  // 限制
  maxRangeMinutes: num('VITE_MAX_RANGE_MINUTES', 180),
  maxRangeHours: Math.round(num('VITE_MAX_RANGE_MINUTES', 180) / 60),
  stationSearchLimit: num('VITE_STATION_SEARCH_LIMIT', 10),

  // 開發用
  devPort: num('VITE_DEV_PORT', 5173),
  devProxyTarget: str('VITE_DEV_PROXY_TARGET', 'https://tdx.jiapi.net'),

  // 選用
  gaId: str('VITE_GA_ID', ''),
}

export default envConfig
