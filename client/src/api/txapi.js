/**
 * TimeLink 自訂 API（/txapi/）前端呼叫層。
 *
 * - 開發模式：vite.config.js 已把 /txapi proxy 到 https://tdx.jiapi.net
 * - 建置後與後端同網域：直接使用同源 /txapi
 * - 其他情境：以 VITE_TDX_BASE_URL 指定完整網址前綴
 */

import { config } from '@/config'

/** API 網址前綴（設定於 .env 的 VITE_TDX_BASE_URL；空字串＝同源 /txapi） */
export const API_BASE = config.apiBaseUrl

export class TxApiError extends Error {
  constructor(message, code = 0, payload = null) {
    super(message)
    this.name = 'TxApiError'
    this.code = code
    this.payload = payload
  }
}

function buildQuery(params = {}) {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value === undefined || value === null || value === '') return
    search.append(key, String(value))
  })
  const q = search.toString()
  return q ? '?' + q : ''
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

/** 同一個 GET 同時只有一個進行中的請求，避免重複打同一支 API */
const inflight = new Map()

async function send(url) {
  const controller = new AbortController()
  const timer = setTimeout(() => controller.abort(), config.apiTimeout)
  let response
  try {
    response = await fetch(url, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    })
  } catch (e) {
    const error = new TxApiError(`無法連線到查詢服務：${e.message}`, 0)
    error.status = 0
    throw error
  } finally {
    clearTimeout(timer)
  }
  const text = await response.text()
  let body = null
  if (text) {
    try {
      body = JSON.parse(text)
    } catch {
      body = null
    }
  }

  if (!response.ok || !body || body.ok === false) {
    const message =
      (body && (body.errorMsg || body.error || body.Message)) || `查詢服務回應錯誤（HTTP ${response.status}）`
    const error = new TxApiError(message, (body && body.errorCode) || response.status, body)
    error.status = response.status
    throw error
  }

  return body
}

async function request(path, params = {}) {
  const url = `${API_BASE}${path}${buildQuery(params)}`
  if (inflight.has(url)) return inflight.get(url)

  const promise = (async () => {
    let lastError = null
    for (let attempt = 0; attempt < 3; attempt += 1) {
      try {
        return await send(url)
      } catch (e) {
        lastError = e
        // 連線被斷或伺服器暫時錯誤時重試
        const retriable = !e.status || e.status >= 500
        if (!retriable || attempt === 2) break
        await sleep(400 * (attempt + 1))
      }
    }
    throw lastError
  })()

  inflight.set(url, promise)
  try {
    return await promise
  } finally {
    inflight.delete(url)
  }
}

export const api = {
  // 系統、營運單位
  systems: () => request('/txapi/public_info/systems'),

  // 車站
  stations: (system) => request('/txapi/public_station/list', system ? { system } : {}),
  stationSearch: (q, limit = 10) => request('/txapi/public_station/search', { q, limit }),
  stationDetail: (station) => request('/txapi/public_station/detail', { station }),

  // 路線
  lines: (system) => request('/txapi/public_line/list', system ? { system } : {}),
  lineDetail: (line) => request('/txapi/public_line/detail', { line }),

  // 一次取得所有營運單位的「路線 → 站序」清單（finder 靜態資料查詢）
  railStations: () => request('/txapi/finder/rail_stations'),

  // 時刻與轉乘
  journeys: (params) => request('/txapi/app_trip/journeys', params),
  routes: (params) => request('/txapi/app_trip/routes', params),
  routeTrains: (params) => request('/txapi/app_trip/route_trains', params),
  direct: (params) => request('/txapi/app_trip/direct', params),
  stationTimetable: (params) => request('/txapi/app_station/timetable', params),
  departures: (params) => request('/txapi/app_station/departures', params),
  trainStops: (params) => request('/txapi/app_train/stops', params),
}

export default api
