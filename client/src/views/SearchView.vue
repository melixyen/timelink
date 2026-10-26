<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import StationSelect from '@/components/StationSelect.vue'
import JourneyCard from '@/components/JourneyCard.vue'
import RouteFilter from '@/components/RouteFilter.vue'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'
import { byOptions, sortOptions, useSearchStore, weekNames } from '@/stores/search'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const stationStore = usePublicStationStore()
const lineStore = usePublicLineStore()
const search = useSearchStore()

const form = search.form

const hours = Array.from({ length: 24 }, (_, i) => String(i).padStart(2, '0'))
const minutes = Array.from({ length: 12 }, (_, i) => String(i * 5).padStart(2, '0'))

const startHour = ref(form.start.slice(0, 2))
const startMinute = ref(form.start.slice(3, 5))
const endHour = ref(form.end.slice(0, 2))
const endMinute = ref(form.end.slice(3, 5))

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

function syncTime() {
  form.start = `${startHour.value}:${startMinute.value}`
  form.end = `${endHour.value}:${endMinute.value}`
}

function setNow() {
  const now = new Date()
  const h = now.getHours()
  const m = Math.min(55, Math.ceil(now.getMinutes() / 5) * 5)
  const h2 = (h + 2) % 24
  startHour.value = String(h).padStart(2, '0')
  startMinute.value = String(m).padStart(2, '0')
  endHour.value = String(h2).padStart(2, '0')
  endMinute.value = String(m).padStart(2, '0')
  form.week = now.getDay()
  syncTime()
}

function exchange() {
  const tmp = form.from
  form.from = form.to
  form.to = tmp
}

async function doSearch() {
  syncTime()
  await search.run()
  router.replace({
    path: '/search',
    query: {
      a: form.from,
      b: form.to,
      w: String(form.week),
      startTime: form.start,
      endTime: form.end,
    },
  })
}

const resultCount = computed(() => search.visibleJourneys.length)
const weekList = computed(() => weekNames())
const byList = computed(() => byOptions())
const sortList = computed(() => sortOptions())

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

  // 條件有變才重設表單；條件相同時沿用既有結果，不重複查詢
  const same =
    form.from === from && form.to === to && form.week === week && form.start === start && form.end === end
  if (from && to) {
    if (!same) {
      form.from = from
      form.to = to
      if (week !== null) form.week = week
      if (start) {
        form.start = start
        startHour.value = start.slice(0, 2)
        startMinute.value = start.slice(3, 5)
      }
      if (end) {
        form.end = end
        endHour.value = end.slice(0, 2)
        endMinute.value = end.slice(3, 5)
      }
      doSearch()
    } else if (!search.journeys.length) {
      doSearch()
    }
  }
})
</script>

<template>
  <div class="page search-page">
    <section class="panel control">
      <h2 class="panel-title">{{ t('search.title') }}</h2>

      <div class="control-grid">
        <StationSelect v-model="form.from" label-key="search.from" />
        <StationSelect v-model="form.to" label-key="search.to" />
      </div>

      <div class="control-row">
        <button type="button" class="btn ghost" @click="exchange">{{ t('search.exchange') }}</button>
      </div>

      <div class="control-grid time-grid">
        <div class="field">
          <div class="field-label">{{ t('search.startFrom') }}</div>
          <div class="time-row">
            <select v-model="startHour"><option v-for="h in hours" :key="h" :value="h">{{ h }}</option></select>
            <span>:</span>
            <select v-model="startMinute"><option v-for="m in minutes" :key="m" :value="m">{{ m }}</option></select>
            <button type="button" class="btn ghost small" @click="setNow">{{ t('search.setNow') }}</button>
          </div>
        </div>

        <div class="field">
          <div class="field-label">{{ t('search.endAt') }}</div>
          <div class="time-row">
            <select v-model="endHour"><option v-for="h in hours" :key="h" :value="h">{{ h }}</option></select>
            <span>:</span>
            <select v-model="endMinute"><option v-for="m in minutes" :key="m" :value="m">{{ m }}</option></select>
          </div>
        </div>

        <div class="field">
          <div class="field-label">{{ t('search.week') }}</div>
          <div class="time-row">
            <select v-model.number="form.week">
              <option v-for="(name, i) in weekList" :key="i" :value="i">{{ name }}</option>
            </select>
          </div>
        </div>

        <div class="field">
          <div class="field-label">{{ t('search.by') }}</div>
          <div class="time-row radio-row">
            <label v-for="opt in byList" :key="opt.value" class="radio">
              <input v-model="form.by" type="radio" :value="opt.value" />
              <span>{{ opt.label }}</span>
            </label>
          </div>
        </div>

        <div class="field">
          <div class="field-label">{{ t('search.sort') }}</div>
          <div class="time-row">
            <select v-model="form.sort">
              <option v-for="opt in sortList" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>
          </div>
        </div>

        <div class="field">
          <div class="field-label">{{ t('search.extra') }}</div>
          <div class="time-row checkbox-row">
            <label class="checkbox">
              <input v-model="isNoExpress" type="checkbox" />
              <span>{{ t('search.extra.noExpress') }}</span>
            </label>
            <label class="checkbox">
              <input v-model="isNoHighSpeed" type="checkbox" />
              <span>{{ t('search.extra.noHighSpeed') }}</span>
            </label>
          </div>
        </div>
      </div>

      <div class="control-row">
        <button
          type="button"
          class="btn primary"
          :disabled="search.loading || !search.canSearch"
          @click="doSearch"
        >
          {{ search.loading ? t('search.loading') : t('search.button') }}
        </button>
        <span v-if="search.error" class="error-text">{{ search.error }}</span>
      </div>
    </section>

    <section v-if="search.searched" class="panel result">
      <h2 class="panel-title">
        {{ t('search.result.title') }}
        <span class="result-count">{{ t('search.result.count', { count: resultCount }) }}</span>
      </h2>

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

      <RouteFilter
        v-if="search.routeGroups.length"
        :groups="search.routeGroups"
        :hidden="search.hiddenRoutes"
        @toggle="search.toggleRoute"
        @fastest="search.showFastestRoutes"
      />

      <div v-if="!search.loading && search.visibleJourneys.length === 0" class="empty">
        {{ t('search.result.empty') }}
      </div>

      <JourneyCard
        v-for="(journey, i) in search.visibleJourneys"
        :key="journey.route + '-' + journey.DepartureMinute + '-' + i"
        :journey="journey"
        :index="i"
      />
    </section>
  </div>
</template>
