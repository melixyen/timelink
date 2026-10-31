<script setup>
import { computed, onMounted, ref, watch } from 'vue'
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
const panelCollapsed = computed(() => isMobile.value && search.searched && !panelOpen.value)
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
  form.start = `${pad(h)}:${pad(m)}`
  form.end = `${pad((h + 2) % 24)}:${pad(m)}`
  form.week = now.getDay()
}

function exchange() {
  const tmp = form.from
  form.from = form.to
  form.to = tmp
}

function syncUrl() {
  router.replace({
    path: '/search',
    query: {
      a: form.from,
      b: form.to,
      w: String(form.week),
      startTime: form.start,
      endTime: form.end,
      r: search.pickedRoutes.length ? search.pickedRoutes.join(',') : undefined,
    },
  })
}

async function doSearch() {
  await search.run()
  syncUrl()
  // 手機模式：查出結果後把查詢面板收起來，只留一行起訖站
  if (isMobile.value) panelOpen.value = false
}

/** 以「選擇路由」視窗勾選的路由重新查詢（空陣列＝回到全部路由） */
async function searchRoutes(codes) {
  await search.searchWithRoutes(codes)
  syncUrl()
}

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
  const from = q.a ? String(q.a) : ''
  const to = q.b ? String(q.b) : ''
  const week = q.w !== undefined ? Number(q.w) : null
  const start = q.startTime ? String(q.startTime) : ''
  const end = q.endTime ? String(q.endTime) : ''
  const routes = q.r ? String(q.r).split(',').filter(Boolean) : []

  // 條件有變才重設表單；條件相同時沿用既有結果，不重複查詢
  const same =
    form.from === from &&
    form.to === to &&
    form.week === week &&
    form.start === start &&
    form.end === end &&
    search.pickedRoutes.join(',') === routes.join(',')
  if (!from || !to) return
  if (same && search.journeys.length) return
  form.from = from
  form.to = to
  if (week !== null) form.week = week
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
        <train-card v-if="!panelCollapsed" :title="t('search.title')" class="control">
          <template #extra>
            <train-button v-if="isMobile" size="small" round icon="caret-up" @click="panelOpen = false">
              {{ t('search.collapse') }}
            </train-button>
          </template>
          <div class="control-grid">
            <StationSelect v-model="form.from" label-key="search.from" />
            <StationSelect v-model="form.to" label-key="search.to" />
          </div>

          <div class="control-row">
            <train-button round icon="swap" @click="exchange">{{ t('search.exchange') }}</train-button>
          </div>

          <div class="control-grid time-grid">
            <train-form-item :label="t('search.startFrom')">
              <train-time-select v-model="form.start" />
              <train-button size="small" round icon="clock" @click="setNow">{{ t('search.setNow') }}</train-button>
            </train-form-item>

            <train-form-item :label="t('search.endAt')">
              <train-time-select v-model="form.end" />
            </train-form-item>

            <train-form-item :label="t('search.week')">
              <train-select v-model="form.week" :options="weekOptions" />
            </train-form-item>

            <train-form-item :label="t('search.by')">
              <train-radio-group v-model="form.by" :options="byList" />
            </train-form-item>

            <train-form-item :label="t('search.sort')">
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
          round
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
      <template #extra>
        <train-button type="primary" plain round icon="route" :disabled="search.loading" @click="pickerOpen = true">
          {{ t('picker.open') }}
        </train-button>
      </template>

      <p v-if="search.meta" class="meta-line">
        {{
          t('search.result.cond', {
            week: weekList[form.week],
            start: form.start,
            end: form.end,
            by: form.by === 'dep' ? t('search.result.byDep') : t('search.result.byArr'),
            updated: search.meta.dataUpdatedAt || '－',
          })
        }}
      </p>

      <train-alert v-if="search.pickedRoutes.length" type="info" class="picked-banner">
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
        v-model="search.quickSort"
        class="quick-sort-bar"
        :disabled="search.loading || !search.journeys.length"
      />

      <RouteFilter
        v-if="search.routeGroups.length"
        :groups="search.routeGroups"
        :hidden="search.hiddenRoutes"
        @toggle="search.toggleRoute"
        @fastest="search.showFastestRoutes"
      />

      <train-spinner v-if="search.loading" block :text="t('search.loading')" />
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
