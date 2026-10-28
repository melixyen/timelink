<script setup>
/**
 * 「選擇路由」視窗：列出起訖站間所有路由（/txapi/app_trip/routes，不看時刻），
 * 勾選想走的路由後發出 confirm(路由代碼陣列)，由查詢頁以相同條件重新查詢時刻表。
 *
 * Props:
 *   modelValue    視窗開關
 *   picked        目前指定的路由（開啟時預先勾選）
 *   resultRoutes  這次查詢結果中出現的路由（標示「這次結果中有」）
 *   cond          條件摘要文字（起訖站、時段…）
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { config } from '@/config'
import { useSearchStore } from '@/stores/search'
import { usePublicLineStore } from '@/stores/publicLine'
import { formatMinute2HM, nameOf } from '@/utils/format'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  picked: { type: Array, default: () => [] },
  resultRoutes: { type: Array, default: () => [] },
  cond: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'confirm', 'clear'])

const { t, locale } = useI18n()
const search = useSearchStore()
const lineStore = usePublicLineStore()

const routes = ref([])
const loading = ref(false)
const error = ref('')
const selection = ref([])
const keyword = ref('')
const transfers = ref('all')
const max = config.maxPickedRoutes

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const inResult = computed(() => new Set(props.resultRoutes))

const transferOptions = computed(() => {
  const counts = [...new Set(routes.value.map((r) => Math.min(r.TransferCount, 3)))].sort()
  return [
    { value: 'all', label: t('picker.transfers.all') },
    ...counts.map((n) => ({
      value: n,
      label: n === 0 ? t('journey.tag.Direct') : n === 3 ? t('picker.transfers.more', { n }) : t('picker.transfers.n', { n }),
    })),
  ]
})

const norm = (s) => String(s || '').toLowerCase().replace(/\s+/g, '')

function searchText(route) {
  return norm(
    route.Legs.flatMap((leg) => [
      leg.From?.StationName?.Zh_tw,
      leg.From?.StationName?.En,
      leg.To?.StationName?.Zh_tw,
      leg.To?.StationName?.En,
      leg.LineName?.Zh_tw,
      leg.LineName?.En,
      ...(leg.Paths || []).flat().flatMap((p) => [p.LineName?.Zh_tw, p.LineName?.En]),
    ]).join('|'),
  )
}

const visible = computed(() => {
  const kw = norm(keyword.value)
  return routes.value.filter((r) => {
    if (transfers.value !== 'all' && Math.min(r.TransferCount, 3) !== transfers.value) return false
    return !kw || searchText(r).includes(kw)
  })
})

const visibleCodes = computed(() => visible.value.map((r) => r.route))
const visibleOn = computed(() => visibleCodes.value.filter((c) => selection.value.includes(c)).length)
const allVisibleOn = computed(
  () => visibleOn.value > 0 && (visibleOn.value === visibleCodes.value.length || selection.value.length >= max),
)

function setAllVisible(on) {
  if (!on) {
    const drop = new Set(visibleCodes.value)
    selection.value = selection.value.filter((c) => !drop.has(c))
    return
  }
  const next = [...selection.value]
  for (const code of visibleCodes.value) {
    if (next.length >= max) break
    if (!next.includes(code)) next.push(code)
  }
  selection.value = next
}

function pickResultRoutes() {
  selection.value = routes.value
    .map((r) => r.route)
    .filter((c) => inResult.value.has(c))
    .slice(0, max)
}

/** 路線代表色：系統直達段（TRA、THSR）沒有自己的顏色，用經過的第一條路線 */
function legColor(leg) {
  return leg.LineColor || leg.Paths?.[0]?.[0]?.LineColor || lineStore.lineColor(leg.LineUID) || '#6b7a90'
}

function classText(leg) {
  if (!leg.Class) return ''
  if (locale.value === 'zh-TW' && leg.ClassLabel) return leg.ClassLabel
  return t(`picker.class.${leg.Class}`)
}

function legLabel(leg) {
  const name = nameOf(leg.LineName) || lineStore.lineName(leg.LineUID)
  const cls = classText(leg)
  return cls ? `${name}・${cls}` : name
}

/** 系統直達段經過的路線（山線、海線…），放在 title 提示 */
function legPaths(leg) {
  return (leg.Paths || []).map((path) => path.map((p) => nameOf(p.LineName)).join('→')).join(' / ')
}

function duration(minutes) {
  return formatMinute2HM(minutes, { minute: t('time.minuteShort'), hour: t('time.hourShort') })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    routes.value = await search.loadRouteOptions()
    const known = new Set(routes.value.map((r) => r.route))
    selection.value = selection.value.filter((c) => known.has(c))
  } catch (e) {
    error.value = t('common.loadFailed', { msg: e.message || '' })
  } finally {
    loading.value = false
  }
}

watch(
  () => props.modelValue,
  (v) => {
    if (!v) return
    selection.value = props.picked.slice(0, max)
    keyword.value = ''
    transfers.value = 'all'
    load()
  },
  { immediate: true },
)

function confirm() {
  if (!selection.value.length) return
  // 依清單順序送出，結果與代碼順序無關但網址比較穩定
  const order = new Map(routes.value.map((r, i) => [r.route, i]))
  const codes = [...selection.value].sort((a, b) => (order.get(a) ?? 0) - (order.get(b) ?? 0))
  emit('confirm', codes)
  open.value = false
}

function clearPicked() {
  emit('clear')
  open.value = false
}
</script>

<template>
  <train-modal v-model="open" :title="t('picker.title')" :close-label="t('common.close')" body-class="route-picker">
    <p class="route-picker-cond">{{ cond }}</p>
    <p class="route-picker-hint">{{ t('picker.hint') }}</p>

    <div class="route-picker-tools">
      <train-input
        v-model="keyword"
        class="route-picker-search"
        type="search"
        prefix-icon="search"
        clearable
        :placeholder="t('picker.search')"
      />
      <train-radio-group v-model="transfers" :options="transferOptions" button size="small" />
    </div>

    <div class="route-picker-bar">
      <train-checkbox
        :model-value="allVisibleOn"
        :indeterminate="visibleOn > 0 && !allVisibleOn"
        :disabled="!visible.length"
        @update:model-value="setAllVisible"
      >
        {{ t('picker.selectAll') }}
      </train-checkbox>
      <train-button v-if="resultRoutes.length" type="text" size="small" @click="pickResultRoutes">
        {{ t('picker.pickInResult') }}
      </train-button>
      <train-button type="text" size="small" :disabled="!selection.length" @click="selection = []">
        {{ t('picker.clear') }}
      </train-button>
      <span class="route-picker-count">{{ t('picker.selected', { count: selection.length, max }) }}</span>
    </div>

    <train-spinner v-if="loading" block :text="t('common.loading')" />
    <train-alert v-else-if="error" type="error">{{ error }}</train-alert>
    <train-empty v-else-if="!routes.length" :description="t('picker.empty')" />
    <train-empty v-else-if="!visible.length" icon="filter" :description="t('picker.noMatch')" />

    <train-checkbox-group v-else v-model="selection" :max="max" vertical class="route-picker-list">
      <train-checkbox
        v-for="r in visible"
        :key="r.route"
        :value="r.route"
        border
        class="route-picker-item"
        :class="{ 'is-in-result': inResult.has(r.route) }"
      >
        <span class="route-picker-legs">
          <template v-for="(leg, i) in r.Legs" :key="i">
            <span v-if="i === 0" class="station">{{ nameOf(leg.From?.StationName) }}</span>
            <train-tag v-if="leg.Type === 'ride'" :color="legColor(leg)" :title="legPaths(leg)">
              {{ legLabel(leg) }}
            </train-tag>
            <train-tag v-else type="warning">{{ t('journey.walkShort', { min: leg.Minutes }) }}</train-tag>
            <span class="station">{{ nameOf(leg.To?.StationName) }}</span>
          </template>
        </span>
        <span class="route-picker-meta">
          <span>{{ t('picker.meta', { count: r.TransferCount, time: duration(r.EstimatedMinutes) }) }}</span>
          <span v-if="r.DistanceKm">{{ t('picker.km', { km: r.DistanceKm }) }}</span>
          <span v-if="r.Supplement && locale.startsWith('zh')" class="supplement">{{ r.Supplement }}</span>
          <train-tag v-if="inResult.has(r.route)" type="success" size="small">{{ t('picker.inResult') }}</train-tag>
        </span>
      </train-checkbox>
    </train-checkbox-group>

    <template #footer>
      <train-button v-if="picked.length" class="route-picker-showall" @click="clearPicked">
        {{ t('picker.showAll') }}
      </train-button>
      <train-button @click="open = false">{{ t('picker.cancel') }}</train-button>
      <train-button type="primary" :disabled="!selection.length" @click="confirm">
        {{ t('picker.confirm', { count: selection.length }) }}
      </train-button>
    </template>
  </train-modal>
</template>
