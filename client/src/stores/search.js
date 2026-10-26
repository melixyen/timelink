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

  const canSearch = computed(() => !!form.from && !!form.to)

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
    groups.forEach((g) => {
      g.journeys.sort((a, b) => (a.ArrivalMinute || 0) - (b.ArrivalMinute || 0))
      // 以目前語言重組路徑說明
      g.text = buildRouteText(g.journeys[0], {
        t,
        lineName: (uid) => publicLine.lineName(uid),
      })
    })
    groups.sort((a, b) => a.minArrivalMinute - b.minArrivalMinute)
    return groups
  })

  /** 依路由勾選過濾後要顯示的方案 */
  const visibleJourneys = computed(() =>
    journeys.value.filter((j) => !hiddenRoutes.value.has(j.route || j.RouteText)),
  )

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
  }

  async function run() {
    if (!canSearch.value) {
      error.value = t('search.error.noStation')
      return null
    }
    loading.value = true
    error.value = ''
    hiddenRoutes.value = new Set()
    try {
      const res = await api.journeys({
        from: form.from,
        to: form.to,
        week: form.week,
        start: form.start,
        end: form.end,
        by: form.by,
        sort: form.sort,
        extra: form.extra,
        limit: form.limit,
      })
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
    canSearch,
    routeGroups,
    visibleJourneys,
    showFastestRoutes,
    toggleRoute,
    reset,
    run,
  }
})
