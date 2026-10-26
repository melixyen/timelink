import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/txapi'
import { t } from '@/i18n'
import { groupByOperator } from '@/config'
import { nameOf } from '@/utils/format'
import { usePublicInfoStore } from '@/stores/publicInfo'

const norm = (s) => String(s || '').toLowerCase().replace(/\s+/g, '')

/**
 * 車站資料（/txapi/public_station/）。
 * 車站清單第一次載入後一直共用；站名搜尋結果依關鍵字快取，避免重複打 API。
 */
export const usePublicStationStore = defineStore('publicStation', () => {
  const infoStore = usePublicInfoStore()
  const stations = ref([])
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref('')
  const remoteResults = ref([])
  const searchCache = new Map()
  let pending = null

  const stationMap = computed(() => {
    const map = new Map()
    stations.value.forEach((s) => map.set(s.StationUID, s))
    return map
  })

  /**
   * 依系統分組的車站。
   * 回傳一般陣列（[system, stations] 配對的陣列），不要回傳 Map：
   * Vue 的 v-for 走訪 Map 時取到的是 [key, value] 配對與數字索引，容易誤用。
   */
  const stationsBySystem = computed(() => {
    const groups = new Map()
    stations.value.forEach((s) => {
      if (!groups.has(s.System)) groups.set(s.System, [])
      groups.get(s.System).push(s)
    })
    return Array.from(groups.entries())
  })

  /**
   * 依設定（src/config/menu.js）的業者順序分組的車站。
   * 新北捷運的三個系統合併成一組、高雄捷運的兩個系統合併成一組。
   */
  const operatorGroups = computed(() =>
    groupByOperator(stations.value, (id) => infoStore.systemName(id)),
  )

  /**
   * 依路線分組的車站（由車站清單的 Lines 欄位建立，不需要額外請求）。
   * 這是 Map，只供程式取用；要走訪請先轉成陣列。
   */
  const stationsByLine = computed(() => {
    const map = new Map()
    stations.value.forEach((s) => {
      ;(s.Lines || []).forEach((uid) => {
        if (!map.has(uid)) map.set(uid, [])
        map.get(uid).push(s)
      })
    })
    return map
  })

  function stationName(uid) {
    const s = stationMap.value.get(uid)
    return s ? nameOf(s.StationName) || uid : uid
  }

  /** 本地搜尋：站名、英文名、站碼、系統代碼 */
  function searchLocal(keyword, limit = 20) {
    const q = norm(keyword)
    if (!q) return []
    const result = []
    for (const s of stations.value) {
      const zh = norm(s.StationName?.Zh_tw)
      const en = norm(s.StationName?.En)
      const uid = norm(s.StationUID)
      const id = norm(s.StationID)
      let score = -1
      if (zh === q) score = 0
      else if (zh.startsWith(q)) score = 1
      else if (en === q) score = 2
      else if (en.startsWith(q)) score = 3
      else if (id === q) score = 4
      else if (uid.includes(q)) score = 5
      else if (zh.includes(q) || en.includes(q)) score = 6
      if (score >= 0) {
        result.push({ ...s, score })
        if (result.length >= limit * 4) break
      }
    }
    result.sort((a, b) => a.score - b.score)
    return result.slice(0, limit)
  }

  /**
   * 遠端搜尋（支援拼音、站碼）：/txapi/public_station/search
   * 同一個關鍵字只打一次 API，結果會快取。
   */
  async function searchRemote(keyword, limit = 10) {
    const q = String(keyword || '').trim()
    if (!q) {
      remoteResults.value = []
      return []
    }
    const cacheKey = `${q}::${limit}`
    if (searchCache.has(cacheKey)) {
      remoteResults.value = searchCache.get(cacheKey)
      return remoteResults.value
    }
    try {
      const res = await api.stationSearch(q, limit)
      const list = res.data?.stations || []
      searchCache.set(cacheKey, list)
      remoteResults.value = list
      return list
    } catch {
      remoteResults.value = []
      return []
    }
  }

  /** 載入一次即可；重複呼叫會共用同一個進行中的請求 */
  async function ensureLoaded(options = {}) {
    if (loaded.value && !options.force) return stations.value
    if (pending && !options.force) return pending

    pending = (async () => {
      loading.value = true
      error.value = ''
      try {
        const res = await api.stations()
        stations.value = res.data?.stations || []
        loaded.value = true
        return stations.value
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
    stations,
    loaded,
    loading,
    error,
    remoteResults,
    stationMap,
    stationsBySystem,
    stationsByLine,
    operatorGroups,
    stationName,
    searchLocal,
    searchRemote,
    ensureLoaded,
  }
})
