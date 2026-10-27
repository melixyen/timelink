import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/txapi'
import { t } from '@/i18n'
import { groupLinesByOperator } from '@/config'
import { nameOf } from '@/utils/format'
import { usePublicInfoStore } from '@/stores/publicInfo'

/**
 * 路線資料（/txapi/public_line/）。
 * 路線需依系統分別取得，第一次載入後一直共用；路線站序（detail）依路線代碼快取。
 */
export const usePublicLineStore = defineStore('publicLine', () => {
  const infoStore = usePublicInfoStore()
  const lines = ref([])
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref('')
  const details = ref({})
  const stationIndex = ref({})
  const indexLoaded = ref(false)
  const indexLoading = ref(false)
  let pending = null
  let indexPromise = null

  const lineMap = computed(() => {
    const map = new Map()
    lines.value.forEach((l) => map.set(l.LineUID, l))
    return map
  })

  /**
   * 依系統分組的路線。
   * 回傳一般陣列（[system, lines] 配對的陣列），不要回傳 Map：
   * Vue 的 v-for 走訪 Map 時取到的是 [key, value] 配對與數字索引，容易誤用。
   */
  const linesBySystem = computed(() => {
    const groups = new Map()
    lines.value.forEach((l) => {
      if (!groups.has(l.System)) groups.set(l.System, [])
      groups.get(l.System).push(l)
    })
    return Array.from(groups.entries())
  })

  /**
   * 依設定（src/config/menu.js）的業者順序分組，
   * 新北捷運的三個系統合併成一組、高雄捷運的兩個系統合併成一組。
   */
  const operatorGroups = computed(() =>
    groupLinesByOperator(lines.value, (id) => infoStore.systemName(id)),
  )

  function lineName(uid) {
    const l = lineMap.value.get(uid)
    return l ? nameOf(l.LineName) || uid : uid
  }

  function lineColor(uid) {
    const l = lineMap.value.get(uid)
    return l?.LineColor || '#888888'
  }

  /** 路線站序與轉乘路線（已載入過的直接回快取） */
  async function loadDetail(lineUid, options = {}) {
    if (!lineUid) return null
    if (!options.force && details.value[lineUid]) return details.value[lineUid]
    const res = await api.lineDetail(lineUid)
    const data = res.data || null
    if (data) details.value = { ...details.value, [lineUid]: data }
    return data
  }

  /**
   * 一次取得所有營運單位的「路線 → 站序」清單（/txapi/finder/rail_stations）。
   * 資料量不大（約 90 KB），可用來一次建立全部路線的站序索引；
   * 失敗時不影響其他功能，會退回逐條路線查詢。
   */
  async function loadStationIndex(options = {}) {
    if (indexLoaded.value && !options.force) return stationIndex.value
    if (indexPromise && !options.force) return indexPromise

    indexPromise = (async () => {
      try {
        const res = await api.railStations()
        const map = {}
        ;(res.operators || []).forEach((op) => {
          ;(op.Lines || []).forEach((line) => {
            const uid = `${line.System}-${line.LineID}`
            map[uid] = (line.Stations || []).map((st) => ({ ...st, LineUID: uid, System: line.System }))
          })
        })
        stationIndex.value = map
        indexLoaded.value = true
        return map
      } catch {
        stationIndex.value = {}
        return {}
      } finally {
        indexLoading.value = false
        indexPromise = null
      }
    })()

    return indexPromise
  }

  /** 取得某條路線的站序清單（索引優先，沒有就用 public_line/detail 補） */
  async function stationsOfLine(lineUid) {
    if (!lineUid) return []
    if (stationIndex.value[lineUid]) return stationIndex.value[lineUid]
    try {
      const detail = await loadDetail(lineUid)
      if (detail?.Stations?.length) {
        return detail.Stations.map((st) => ({ ...st, LineUID: lineUid, System: detail.System }))
      }
    } catch {
      /* 忽略：呼叫端有 fallback */
    }
    return stationIndex.value[lineUid] || []
  }

  /** 載入一次即可；重複呼叫會共用同一個進行中的請求 */
  async function ensureLoaded(options = {}) {
    if (loaded.value && !options.force) return lines.value
    if (pending && !options.force) return pending

    pending = (async () => {
      loading.value = true
      error.value = ''
      try {
        // public_line/list 不帶 system 時一次回傳所有營運單位的路線
        const res = await api.lines()
        const all = res?.data?.lines || []
        if (all.length) {
          lines.value = all
          loaded.value = true
        } else {
          error.value = t('common.loadFailed', { msg: 'lines' })
        }
        return lines.value
      } catch (e) {
        error.value = t('common.loadFailed', { msg: e.message || '' })
        throw e
      } finally {
        loading.value = false
        pending = null
      }
    })()

    return pending
  }

  return {
    lines,
    loaded,
    loading,
    error,
    details,
    stationIndex,
    indexLoaded,
    indexLoading,
    lineMap,
    linesBySystem,
    operatorGroups,
    lineName,
    lineColor,
    loadDetail,
    loadStationIndex,
    stationsOfLine,
    ensureLoaded,
  }
})
