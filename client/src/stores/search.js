import { computed, reactive, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/txapi'
import { t } from '@/i18n'
import { config } from '@/config'
import { buildRouteText } from '@/utils/format'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'

/** 星期幾（依目前語言） */
export function weekNames() {
  return Array.from({ length: 7 }, (_, i) => t(`week.${i}`))
}

/** 排列順序選項（依目前語言） */
export function sortOptions() {
  return [
    { value: 'arrival', label: t('sort.arrival') },
    { value: 'transfers', label: t('sort.transfers') },
    { value: 'duration', label: t('sort.duration') },
  ]
}

/** 出發／到達列車選項（依目前語言） */
export function byOptions() {
  return [
    { value: 'dep', label: t('by.dep') },
    { value: 'arr', label: t('by.arr') },
  ]
}

/**
 * 時刻表 / 轉乘查詢。
 * 資料來源：/txapi/app_trip/journeys（路由與時刻表組合由後端計算，前端只負責呈現）
 */
export const useSearchStore = defineStore('search', () => {
  const publicStation = usePublicStationStore()
  const publicLine = usePublicLineStore()
  const form = reactive({
    from: config.defaultFromStation,
    to: config.defaultToStation,
    week: config.defaultWeek,
    start: config.defaultStartTime,
    end: config.defaultEndTime,
    by: 'dep',
    sort: 'arrival',
    extra: 0,
    limit: config.defaultResultLimit,
  })

  const journeys = ref([])
  const picks = ref({})
  const tags = ref({})
  const meta = ref(null)
  const loading = ref(false)
  const error = ref('')
  const searched = ref(false)
  const hiddenRoutes = ref(new Set())
  /**
   * 快速切換排序（只作用於本次查詢結果，前端重新排序，不再打 API）：
   * ''（不排序）／Fastest（最早到達）／ShortestTime（最短行車時間）／FewestTransfers（最少轉乘）
   */
  const quickSort = ref('')
  /** 指定路由查詢：目前結果是只用這幾條路由查出來的（空陣列＝一般查詢） */
  const pickedRoutes = ref([])
  /** 路由清單快取：routeKey() → app_trip/routes 的 routes */
  const routeOptionCache = new Map()
  /** pickedRoutes 是在哪一組起訖站與 extra 下選的 */
  let pickedFor = ''
  const routeKey = () => `${form.from}|${form.to}|${form.extra}`

  const canSearch = computed(() => !!form.from && !!form.to)

  /**
   * 快速排序的比較器（與後端 journey.php 的排序一致）：
   * Fastest         → [_slow, ArrivalMinute, TransferCount, -DepartureMinute]
   * ShortestTime    → [_slow, TravelMinutes, TransferCount, DepartureMinute]
   * FewestTransfers → [TransferCount, ArrivalMinute]（不看慢不慢：慢的路線也可能是轉乘最少的）
   */
  function quickCompare(mode, slow) {
    if (mode === 'Fastest') {
      return (a, b) =>
        (slow.get(a.route || a.RouteText) ? 1 : 0) - (slow.get(b.route || b.RouteText) ? 1 : 0) ||
        a.ArrivalMinute - b.ArrivalMinute ||
        a.TransferCount - b.TransferCount ||
        b.DepartureMinute - a.DepartureMinute
    }
    if (mode === 'ShortestTime') {
      return (a, b) =>
        (slow.get(a.route || a.RouteText) ? 1 : 0) - (slow.get(b.route || b.RouteText) ? 1 : 0) ||
        a.TravelMinutes - b.TravelMinutes ||
        a.TransferCount - b.TransferCount ||
        a.DepartureMinute - b.DepartureMinute
    }
    if (mode === 'FewestTransfers') {
      return (a, b) => a.TransferCount - b.TransferCount || a.ArrivalMinute - b.ArrivalMinute
    }
    return null
  }

  /** 依「路由」分組，同一條走法的多班車集合在一起 */
  const routeGroups = computed(() => {
    const map = new Map()
    journeys.value.forEach((j) => {
      const key = j.route || j.RouteText
      if (!map.has(key)) {
        map.set(key, {
          key,
          route: j.route,
          RouteText: j.RouteText,
          journeys: [],
          minTravelMinutes: Infinity,
          minArrivalMinute: Infinity,
          minTransferCount: Infinity,
        })
      }
      const g = map.get(key)
      g.journeys.push(j)
      if (typeof j.TravelMinutes === 'number') {
        g.minTravelMinutes = Math.min(g.minTravelMinutes, j.TravelMinutes)
      }
      if (typeof j.ArrivalMinute === 'number') {
        g.minArrivalMinute = Math.min(g.minArrivalMinute, j.ArrivalMinute)
      }
      if (typeof j.TransferCount === 'number') {
        g.minTransferCount = Math.min(g.minTransferCount, j.TransferCount)
      }
    })
    const groups = Array.from(map.values())
    // 慢的路由（與後端相同：最短行車時間超過全部最短的 1.5 倍再多 30 分，例如高鐵為主時的臺鐵直達）排在後面
    const shortest = Math.min(...groups.map((g) => g.minTravelMinutes))
    groups.forEach((g) => {
      g.journeys.sort((a, b) => (a.ArrivalMinute || 0) - (b.ArrivalMinute || 0))
      g.slow = g.minTravelMinutes > shortest * 1.5 + 30
      // 以目前語言重組路徑說明
      g.text = buildRouteText(g.journeys[0], {
        t,
        lineName: (uid) => publicLine.lineName(uid),
      })
    })
    groups.sort((a, b) => a.slow - b.slow || a.minArrivalMinute - b.minArrivalMinute)
    return groups
  })

  /**
   * 依路由勾選過濾後要顯示的方案。
   * quickSort 有值時再依該準則前端重新排序（不重新查詢、只作用於本次結果）。
   */
  const visibleJourneys = computed(() => {
    const list = journeys.value.filter((j) => !hiddenRoutes.value.has(j.route || j.RouteText))
    const mode = quickSort.value
    if (!mode) return list
    const cmp = quickCompare(mode, new Map(routeGroups.value.map((g) => [g.key, g.slow])))
    if (!cmp) return list
    // 一律只排序、不過濾：主要準則都一樣時（例如全部直達）仍依次要準則排；
    // 完全並列時，後端標記為該準則代表的方案排在最前面
    const tagRank = (j) => ((j.Tags || []).includes(mode) ? 0 : 1)
    return list.slice().sort((a, b) => cmp(a, b) || tagRank(a) - tagRank(b))
  })

  /** 只看最快的前 N 條路由（對應接軌時刻的 1 / 2 / 3 快速切換） */
  function showFastestRoutes(n) {
    const top = routeGroups.value.slice(0, n).map((g) => g.key)
    const next = new Set()
    routeGroups.value.forEach((g) => {
      if (!top.includes(g.key)) next.add(g.key)
    })
    hiddenRoutes.value = next
  }

  function toggleRoute(key) {
    const next = new Set(hiddenRoutes.value)
    if (next.has(key)) next.delete(key)
    else next.add(key)
    hiddenRoutes.value = next
  }

  function reset() {
    journeys.value = []
    picks.value = {}
    tags.value = {}
    meta.value = null
    error.value = ''
    searched.value = false
    hiddenRoutes.value = new Set()
    pickedRoutes.value = []
    quickSort.value = ''
  }

  /**
   * 起訖站間所有路由（不看時刻，供「選擇路由」視窗勾選）。同一組起訖站與 extra 只查一次
   */
  async function loadRouteOptions() {
    const key = routeKey()
    if (routeOptionCache.has(key)) return routeOptionCache.get(key)
    const res = await api.routes({ from: form.from, to: form.to, extra: form.extra, limit: config.routeListLimit })
    const list = res.data?.routes || []
    routeOptionCache.set(key, list)
    return list
  }

  /** 與後端 journeys 相同的排序 */
  const journeyCompare = {
    arrival: (a, b) =>
      a.ArrivalMinute - b.ArrivalMinute || a.TransferCount - b.TransferCount || b.DepartureMinute - a.DepartureMinute,
    transfers: (a, b) => a.TransferCount - b.TransferCount || a.ArrivalMinute - b.ArrivalMinute,
    duration: (a, b) =>
      a.TravelMinutes - b.TravelMinutes || a.TransferCount - b.TransferCount || a.DepartureMinute - b.DepartureMinute,
  }

  /**
   * 後端還不支援 journeys 的 routes 參數時（meta 沒有 fixed）：每條路由各查 route_trains 再合併排序
   */
  async function fetchByRouteTrains(params) {
    const list = await Promise.all(
      pickedRoutes.value.map((route) =>
        api.routeTrains({
          route,
          week: params.week,
          start: params.start,
          end: params.end,
          by: params.by,
          extra: params.extra,
        }),
      ),
    )
    const merged = list.flatMap((res) => res.data?.journeys || [])
    merged.sort(journeyCompare[params.sort] || journeyCompare.arrival)
    return {
      data: { journeys: merged.slice(0, params.limit).map((j) => ({ ...j, Tags: [] })), picks: {}, tags: {} },
      meta: { ...(list[0]?.meta || {}), fixed: true },
    }
  }

  async function run() {
    if (!canSearch.value) {
      error.value = t('search.error.noStation')
      return null
    }
    // 起訖站或不搭的列車改了，原本指定的路由就不適用，回到一般查詢
    if (pickedRoutes.value.length && pickedFor !== routeKey()) pickedRoutes.value = []
    loading.value = true
    error.value = ''
    hiddenRoutes.value = new Set()
    quickSort.value = ''
    const routed = pickedRoutes.value.length > 0
    const params = {
      from: form.from,
      to: form.to,
      week: form.week,
      start: form.start,
      end: form.end,
      by: form.by,
      sort: form.sort,
      extra: form.extra,
      limit: routed ? config.pickedResultLimit : form.limit,
      routes: routed ? pickedRoutes.value.join(',') : undefined,
    }
    try {
      let res = await api.journeys(params)
      if (routed && !res.meta?.fixed) res = await fetchByRouteTrains(params)
      journeys.value = res.data?.journeys || []
      picks.value = res.data?.picks || {}
      tags.value = res.data?.tags || {}
      meta.value = res.meta || null
      searched.value = true
      return journeys.value
    } catch (e) {
      error.value = t('search.error.failed', { msg: e.message || '' })
      journeys.value = []
      searched.value = true
      return null
    } finally {
      loading.value = false
    }
  }

  /** 以指定的路由重新查詢（時段、星期、起訖站、不搭的列車沿用目前條件）；空陣列＝回到一般查詢 */
  function searchWithRoutes(codes) {
    pickedRoutes.value = [...codes]
    pickedFor = routeKey()
    return run()
  }

  return {
    form,
    journeys,
    picks,
    tags,
    meta,
    loading,
    error,
    searched,
    hiddenRoutes,
    pickedRoutes,
    quickSort,
    canSearch,
    routeGroups,
    visibleJourneys,
    showFastestRoutes,
    toggleRoute,
    reset,
    run,
    loadRouteOptions,
    searchWithRoutes,
  }
})
