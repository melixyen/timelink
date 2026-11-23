<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import StationSelect from '@/components/StationSelect.vue'
import JourneyCard from '@/components/JourneyCard.vue'
import RouteFilter from '@/components/RouteFilter.vue'
import RoutePicker from '@/components/RoutePicker.vue'
import QuickSort from '@/components/QuickSort.vue'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'
import { byOptions, sortOptions, useSearchStore, weekNames } from '@/stores/search'
import { serviceDays } from '@/utils/format'
import { useIsMobile } from '@/utils/media'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const stationStore = usePublicStationStore()
const lineStore = usePublicLineStore()
const search = useSearchStore()

const form = search.form
const pickerOpen = ref(false)

/**
 * 手機模式：查詢結果出來後，查詢面板收成一行（起訖站）＋左上角的展開鈕；
 * 點展開鈕才把整塊面板滑出來。
 */
const isMobile = useIsMobile()
const panelOpen = ref(true)
// 查詢失敗時不收合：錯誤訊息在查詢面板裡，收起來就看不到了
const panelCollapsed = computed(
  () => isMobile.value && search.searched && !panelOpen.value && !search.error,
)
const fromName = computed(() => stationStore.stationName(form.from) || form.from)
const toName = computed(() => stationStore.stationName(form.to) || form.to)

const isNoExpress = computed({
  get: () => (form.extra & 1) === 1,
  set: (v) => {
    form.extra = v ? form.extra | 1 : form.extra & ~1
  },
})

const isNoHighSpeed = computed({
  get: () => (form.extra & 2) === 2,
  set: (v) => {
    form.extra = v ? form.extra | 2 : form.extra & ~2
  },
})

function setNow() {
  const now = new Date()
  const h = now.getHours()
  const m = Math.min(55, Math.ceil(now.getMinutes() / 5) * 5)
  const pad = (n) => String(n).padStart(2, '0')
  form.time = `${pad(h)}:${pad(m)}`
  form.start = `${pad(h)}:${pad(m)}`
  form.end = `${pad((h + 2) % 24)}:${pad(m)}`
  form.week = now.getDay()
}

/** 旅客模式（預設，只給一個時刻）／研究模式（給時段） */
const modeList = computed(() => [
  { value: 'traveler', label: t('search.mode.traveler') },
  { value: 'research', label: t('search.mode.research') },
])
const isTraveler = computed(() => search.isTraveler)

// 查詢框裡的條件（含不搭的列車 extra）調整好後按「尋找」才查詢，勾選時不自動重新查詢（使用者規則 2026-11）；
// 結果上方切換排序（最早到達、最少轉乘、最短行車時間）只在前端重排，不必重新查詢

function exchange() {
  const tmp = form.from
  form.from = form.to
  form.to = tmp
}

function syncUrl() {
  const traveler = isTraveler.value
  router.replace({
    path: '/search',
    query: {
      m: traveler ? undefined : 'research',
      a: form.from,
      b: form.to,
      w: String(form.week),
      time: traveler ? form.time : undefined,
      by: form.by !== 'dep' ? form.by : undefined,
      startTime: traveler ? undefined : form.start,
      endTime: traveler ? undefined : form.end,
      r: !traveler && search.pickedRoutes.length ? search.pickedRoutes.join(',') : undefined,
    },
  })
}

async function doSearch() {
  await search.run()
  syncUrl()
  collapsePanelOnMobile()
}

/** 以「選擇路由」視窗勾選的路由重新查詢（空陣列＝回到全部路由） */
async function searchRoutes(codes) {
  await search.searchWithRoutes(codes)
  syncUrl()
  collapsePanelOnMobile()
}

/** 手機模式：查出結果後把查詢面板收起來，只留一行起訖站（查詢失敗時保持展開以顯示錯誤） */
function collapsePanelOnMobile() {
  if (isMobile.value && !search.error) panelOpen.value = false
}

/**
 * 快速重新查詢（比照 ekitan、jorudan）：把時刻往前或往後移 N 分鐘，直接重新查詢。
 * 旅客模式移查詢時刻；研究模式把時段的頭尾一起移。時刻是標準時間：跨過午夜時繞回 00～23 時，星期跟著換到前一天或後一天
 * （查詢時刻、研究模式以時段起點為準）。哪個營運日的班次由後端換算
 */
const shiftSteps = [10, 30, 60]
function shiftHM(hm, delta) {
  const [h, m] = String(hm || '00:00').split(':').map(Number)
  const raw = h * 60 + m + delta
  const v = ((raw % 1440) + 1440) % 1440
  const pad = (n) => String(n).padStart(2, '0')
  return { hm: `${pad(Math.floor(v / 60))}:${pad(v % 60)}`, days: Math.floor(raw / 1440) }
}
function shiftTime(delta) {
  if (search.loading) return
  let days
  if (isTraveler.value) {
    ;({ hm: form.time, days } = shiftHM(form.time, delta))
  } else {
    ;({ hm: form.start, days } = shiftHM(form.start, delta))
    form.end = shiftHM(form.end, delta).hm
  }
  if (days) form.week = (((Number(form.week) + days) % 7) + 7) % 7
  if (!isTraveler.value && search.pickedRoutes.length) searchRoutes(search.pickedRoutes)
  else doSearch()
}

/**
 * 查詢時刻沒有可搭的班次時，後端往後找到首班車（出發時刻模式）或往前找到末班車（抵達時刻模式）為起點；
 * 首末班車不在查詢的那一天（例如星期三 02:00 抵達＝星期二深夜的末班車）時，時刻前面加星期
 */
const baseNote = computed(() => {
  const m = search.meta
  if (!m?.base || !m.base_time) return ''
  const other = m.base_week !== undefined && m.base_week !== null && Number(m.base_week) !== Number(m.week ?? form.week)
  return t(m.base === 'last' ? 'search.result.baseLast' : 'search.result.baseFirst', {
    time: m.search_time,
    base: other ? `${weekList.value[m.base_week]} ${m.base_time}` : m.base_time,
  })
})

/** 查詢結果的時刻所在營運日比查詢那一天差幾天（各時刻再依 *Minute 判斷是否跨到前一天、隔天） */
const dayBase = computed(() => serviceDays(search.meta))
const resultCount = computed(() => search.visibleJourneys.length)
const weekList = computed(() => weekNames())
const weekOptions = computed(() => weekList.value.map((label, value) => ({ label, value })))
const byList = computed(() => byOptions())
const sortList = computed(() => sortOptions())
const resultRoutes = computed(() => search.routeGroups.map((g) => g.key))

/** 視窗上方的條件摘要：讓使用者知道重新查詢會沿用哪些條件 */
const pickerCond = computed(() => {
  const extras = [isNoExpress.value && t('search.extra.noExpress'), isNoHighSpeed.value && t('search.extra.noHighSpeed')]
  return t('picker.cond', {
    from: stationStore.stationName(form.from) || form.from,
    to: stationStore.stationName(form.to) || form.to,
    week: weekList.value[form.week],
    start: form.start,
    end: form.end,
    by: form.by === 'dep' ? t('search.result.byDep') : t('search.result.byArr'),
    extra: extras.filter(Boolean).join('、') || t('picker.noExtra'),
  })
})

onMounted(async () => {
  // 公共資料只載入一次，之後切路由不再重新請求
  await stationStore.ensureLoaded().catch(() => {})
  lineStore.ensureLoaded().catch(() => {})

  const q = route.query
  // 舊網址只有 startTime／endTime（時段）：當研究模式開
  const mode = q.m === 'research' || (!q.time && (q.startTime || q.endTime)) ? 'research' : 'traveler'
  const from = q.a ? String(q.a) : ''
  const to = q.b ? String(q.b) : ''
  const week = q.w !== undefined ? Number(q.w) : null
  const time = q.time ? String(q.time) : ''
  const by = q.by === 'arr' ? 'arr' : 'dep'
  const start = q.startTime ? String(q.startTime) : ''
  const end = q.endTime ? String(q.endTime) : ''
  const routes = mode === 'research' && q.r ? String(q.r).split(',').filter(Boolean) : []

  // 條件有變才重設表單；條件相同時沿用既有結果，不重複查詢
  const same =
    form.mode === mode &&
    form.from === from &&
    form.to === to &&
    form.week === week &&
    form.by === by &&
    (mode === 'traveler' ? form.time === time : form.start === start && form.end === end) &&
    search.pickedRoutes.join(',') === routes.join(',')
  if (!from || !to) return
  if (same && search.journeys.length) return
  form.mode = mode
  form.from = from
  form.to = to
  form.by = by
  if (week !== null) form.week = week
  if (time) form.time = time
  if (start) form.start = start
  if (end) form.end = end
  if (routes.length) searchRoutes(routes)
  else doSearch()
})
</script>

<template>
  <div class="page search-page">
    <!-- 手機模式：查出結果後查詢面板收成一列（起訖站）＋左上角展開鈕 -->
    <div class="query-panel" :class="{ 'is-collapsed': panelCollapsed }">
      <transition name="panel-slide">
        <train-card v-if="!panelCollapsed" class="control">
          <!-- 手機：標題最左邊只放箭頭鈕收合（不顯示文字，說明放在 aria-label／title） -->
          <template #header>
            <train-button
              v-if="isMobile"
              size="small"
              circle
              icon="caret-up"
              class="panel-toggle"
              :aria-label="t('search.collapse')"
              :title="t('search.collapse')"
              @click="panelOpen = false"
            />
            {{ t('search.title') }}
          </template>
          <!-- 起訖站：交換鈕只留圖示，放在兩站中間（桌面左中右、手機上中下） -->
          <div class="station-pair">
            <StationSelect v-model="form.from" label-key="search.from" />
            <train-button
              circle
              icon="swap"
              class="swap-btn"
              :aria-label="t('search.exchange')"
              :title="t('search.exchange')"
              @click="exchange"
            />
            <StationSelect v-model="form.to" label-key="search.to" />
          </div>

          <!-- 旅客模式（預設）只給一個時刻；研究模式給時段 -->
          <train-form-item :label="t('search.mode')" class="mode-item">
            <train-radio-group v-model="form.mode" :options="modeList" button size="small" />
            <span class="mode-hint">{{ isTraveler ? t('search.mode.travelerHint') : t('search.mode.researchHint') }}</span>
          </train-form-item>

          <div class="control-grid time-grid">
            <train-form-item v-if="isTraveler" :label="t('search.time')">
              <train-time-select v-model="form.time" />
              <train-button size="small" round icon="clock" @click="setNow">{{ t('search.setNow') }}</train-button>
            </train-form-item>

            <template v-else>
              <train-form-item :label="t('search.startFrom')">
                <train-time-select v-model="form.start" />
                <train-button size="small" round icon="clock" @click="setNow">{{ t('search.setNow') }}</train-button>
              </train-form-item>

              <train-form-item :label="t('search.endAt')">
                <train-time-select v-model="form.end" />
              </train-form-item>
            </template>

            <train-form-item :label="t('search.week')">
              <train-select v-model="form.week" :options="weekOptions" />
            </train-form-item>

            <train-form-item :label="t('search.by')">
              <train-radio-group v-model="form.by" :options="byList" />
            </train-form-item>

            <train-form-item v-if="!isTraveler" :label="t('search.sort')">
              <train-select v-model="form.sort" :options="sortList" />
            </train-form-item>

            <train-form-item :label="t('search.extra')">
              <train-checkbox v-model="isNoExpress">{{ t('search.extra.noExpress') }}</train-checkbox>
              <train-checkbox v-model="isNoHighSpeed">{{ t('search.extra.noHighSpeed') }}</train-checkbox>
            </train-form-item>
          </div>

          <div class="control-row">
            <train-button
              type="primary"
              size="large"
              round
              icon="search"
              :loading="search.loading"
              :disabled="!search.canSearch"
              @click="doSearch"
            >
              {{ search.loading ? t('search.loading') : t('search.button') }}
            </train-button>
            <train-alert v-if="search.error" type="error" simple>{{ search.error }}</train-alert>
          </div>
        </train-card>
      </transition>

      <!-- 收合狀態：左上角展開鈕＋一行起訖站 -->
      <div v-if="panelCollapsed" class="query-collapsed">
        <train-button
          size="small"
          circle
          icon="caret-down"
          class="collapsed-toggle"
          :aria-label="t('search.expand')"
          :title="t('search.expand')"
          @click="panelOpen = true"
        />
        <span class="collapsed-route">{{ fromName }} → {{ toName }}</span>
      </div>
    </div>

    <train-card v-if="search.searched" class="result">
      <template #header>
        {{ t('search.result.title') }}
        <span class="result-count">{{ t('search.result.count', { count: resultCount }) }}</span>
      </template>
      <!-- 指定路由查詢屬於研究模式（旅客模式只給一個時刻） -->
      <template v-if="!isTraveler" #extra>
        <train-button type="primary" plain round icon="route" :disabled="search.loading" @click="pickerOpen = true">
          {{ t('picker.open') }}
        </train-button>
      </template>

      <p v-if="search.meta" class="meta-line">
        {{
          search.meta.search_time
            ? t('search.result.condAt', {
                week: weekList[form.week],
                time: search.meta.search_time,
                by: search.meta.by === 'arr' ? t('by.arr') : t('by.dep'),
                updated: search.meta.dataUpdatedAt || '－',
              })
            : t('search.result.cond', {
                week: weekList[form.week],
                start: form.start,
                end: form.end,
                by: form.by === 'dep' ? t('search.result.byDep') : t('search.result.byArr'),
                updated: search.meta.dataUpdatedAt || '－',
              })
        }}
      </p>

      <nav class="time-shift" :aria-label="t('search.shift.title')" :title="t('search.shift.title')">
        <span v-for="n in shiftSteps" :key="n" class="time-shift-pair">
          <train-link type="primary" :disabled="search.loading" @click="shiftTime(-n)">
            {{ t('search.shift.before', { min: n }) }}
          </train-link>
          <span class="time-shift-sep">|</span>
          <train-link type="primary" :disabled="search.loading" @click="shiftTime(n)">
            {{ t('search.shift.after', { min: n }) }}
          </train-link>
        </span>
      </nav>

      <train-alert v-if="baseNote" type="warning" simple class="base-note">{{ baseNote }}</train-alert>

      <train-alert v-if="!isTraveler && search.pickedRoutes.length" type="info" class="picked-banner">
        <span>{{ t('search.picked.banner', { count: search.pickedRoutes.length }) }}</span>
        <span class="picked-actions">
          <train-button size="small" type="primary" plain @click="pickerOpen = true">
            {{ t('search.picked.change') }}
          </train-button>
          <train-button size="small" :disabled="search.loading" @click="searchRoutes([])">
            {{ t('picker.showAll') }}
          </train-button>
        </span>
      </train-alert>

      <QuickSort
        v-if="search.journeys.length"
        v-model="search.quickSort"
        class="quick-sort-bar"
        :disabled="search.loading"
        :by="search.meta?.by === 'arr' ? 'arr' : 'dep'"
      />

      <RouteFilter
        v-if="search.routeGroups.length"
        :groups="search.routeGroups"
        :hidden="search.hiddenRoutes"
        @toggle="search.toggleRoute"
        @fastest="search.showFastestRoutes"
      />

      <train-spinner v-if="search.loading" block :text="t('search.loading')" />
      <!-- 查詢失敗（後端回錯誤、連不上）：錯誤提示「出現異常，請稍後再試」，下面是原因；不再同時出現「找不到這個時間內的列車」 -->
      <train-alert v-else-if="search.failure" type="error" class="search-failure" :title="t('search.result.failed')">
        {{ search.failure }}
      </train-alert>
      <train-empty
        v-else-if="search.visibleJourneys.length === 0"
        icon="clock"
        :description="t('search.result.empty')"
      />

      <JourneyCard
        v-for="(journey, i) in search.visibleJourneys"
        :key="journey.route + '-' + journey.DepartureMinute + '-' + i"
        :journey="journey"
        :index="i"
        :week="journey.Week ?? search.meta?.service_week ?? search.meta?.week ?? search.form.week"
        :day-base="dayBase"
        :latest-first="!!search.meta?.search_time && search.meta?.by === 'arr'"
      />
    </train-card>

    <RoutePicker
      v-model="pickerOpen"
      :picked="search.pickedRoutes"
      :result-routes="resultRoutes"
      :cond="pickerCond"
      @confirm="searchRoutes"
      @clear="searchRoutes([])"
    />
  </div>
</template>
