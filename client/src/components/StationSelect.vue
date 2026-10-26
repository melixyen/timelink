<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { usePublicLineStore } from '@/stores/publicLine'
import { config, groupByOperator } from '@/config'
import { nameOf } from '@/utils/format'

const props = defineProps({
  modelValue: { type: String, default: '' },
  label: { type: String, default: '' },
  labelKey: { type: String, default: '' },
  placeholderKey: { type: String, default: 'station.placeholder' },
})

const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const stationStore = usePublicStationStore()
const infoStore = usePublicInfoStore()
const lineStore = usePublicLineStore()
const open = ref(false)
const keyword = ref('')
const root = ref(null)
const selectedLine = ref('')

const selected = computed(() => (props.modelValue ? stationStore.stationName(props.modelValue) : ''))
const fieldLabel = computed(() => (props.labelKey ? t(props.labelKey) : props.label))
const kw = computed(() => keyword.value.trim())

/**
 * 路線切換器：依設定（src/config/menu.js）的業者順序與路線順序顯示。
 * 新北捷運的三個系統合併成一組、高雄捷運的兩個系統合併成一組。
 */
const lineGroups = computed(() =>
  lineStore.operatorGroups.map((group) => ({
    id: group.id,
    name: group.name,
    lines: group.lines.map((l) => ({ uid: l.LineUID, name: nameOf(l.LineName), color: l.LineColor })),
  })),
)

const norm = (s) => String(s || '').toLowerCase().replace(/\s+/g, '')

function matchStation(station, q) {
  const key = norm(q)
  if (!key) return true
  return (
    norm(station.StationName?.Zh_tw).includes(key) ||
    norm(station.StationName?.En).includes(key) ||
    norm(station.StationUID).includes(key)
  )
}

/** 依業者分組（順序與合併規則由 src/config/menu.js 決定） */
function groupItems(list) {
  return groupByOperator(list, (id) => infoStore.systemName(id))
}

/** 全站搜尋結果（本地比對＋遠端拼音搜尋） */
const globalMatches = computed(() => {
  if (!kw.value) return []
  const local = stationStore.searchLocal(kw.value, 200)
  const seen = new Set(local.map((s) => s.StationUID))
  const extra = stationStore.remoteResults.filter((s) => !seen.has(s.StationUID))
  return [...local, ...extra]
})

/** 指定路線時的車站（由車站清單的 Lines 欄位取得，不需額外請求） */
const lineStations = computed(() => {
  if (!selectedLine.value) return []
  return stationStore.stationsByLine.get(selectedLine.value) || []
})

/** 站序：路線明細載入後依站序排序並附上可轉乘的路線 */
const orderedStations = computed(() => {
  if (!selectedLine.value) return []
  const detail = lineStore.details[selectedLine.value]
  const list = lineStations.value
  if (!detail?.Stations?.length) {
    return list.map((s, i) => ({ ...s, Sequence: i + 1, TransferLines: [] }))
  }
  const byUid = new Map(list.map((s) => [s.StationUID, s]))
  return detail.Stations.map((st) => {
    const base = byUid.get(st.StationUID)
    return {
      ...(base || {}),
      ...st,
      Sequence: st.Sequence,
      TransferLines: st.TransferLines || [],
      StationName: st.StationName || base?.StationName,
    }
  })
})

const currentLine = computed(() =>
  selectedLine.value ? lineStore.lineMap.get(selectedLine.value) : null,
)

/**
 * 目前要顯示的清單：
 * - 有搜尋字：先在選定的路線內找，找不到才顯示全站搜尋結果
 * - 選了路線：顯示該路線的車站（依站序）
 * - 否則：顯示全部車站（依業者分組）
 */
const view = computed(() => {
  if (kw.value) {
    if (selectedLine.value) {
      const scoped = orderedStations.value.filter((s) => matchStation(s, kw.value))
      if (scoped.length) {
        return {
          mode: 'line',
          line: selectedLine.value,
          groups: [{ id: selectedLine.value, name: lineStore.lineName(selectedLine.value), items: scoped }],
        }
      }
    }
    return { mode: 'all', searching: true, groups: groupItems(globalMatches.value) }
  }
  if (selectedLine.value) {
    return {
      mode: 'line',
      line: selectedLine.value,
      groups: [
        {
          id: selectedLine.value,
          name: lineStore.lineName(selectedLine.value),
          items: orderedStations.value,
        },
      ],
    }
  }
  return { mode: 'all', searching: false, groups: groupItems(stationStore.stations) }
})

const totalCount = computed(() => view.value.groups.reduce((sum, g) => sum + g.items.length, 0))

// 切換路線時載入該線的站序與轉乘資訊（結果會快取，重複選擇不再打 API）
watch(selectedLine, (uid) => {
  if (!uid) return
  if (lineStore.details[uid]) return
  lineStore.loadDetail(uid).catch(() => {})
})

let timer = null
watch(keyword, (val) => {
  clearTimeout(timer)
  const q = val.trim()
  if (!q) {
    stationStore.remoteResults = []
    return
  }
  // 本地找不到時才呼叫遠端搜尋（支援拼音），避免過度消耗查詢額度
  timer = setTimeout(() => {
    if (stationStore.searchLocal(q, 1).length === 0) stationStore.searchRemote(kw.value, config.stationSearchLimit)
  }, 350)
})

function onDocClick(e) {
  if (root.value && !root.value.contains(e.target)) open.value = false
}

function choose(station) {
  emit('update:modelValue', station.StationUID)
  open.value = false
  keyword.value = ''
}

function clear() {
  emit('update:modelValue', '')
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
  clearTimeout(timer)
})
</script>

<template>
  <div ref="root" class="station-select">
    <div v-if="fieldLabel" class="field-label">{{ fieldLabel }}</div>
    <button type="button" class="trigger" @click="open = !open">
      <span :class="{ placeholder: !selected }">{{ selected || t(placeholderKey) }}</span>
      <span v-if="selected" class="uid">{{ modelValue }}</span>
      <span class="caret">{{ open ? '▲' : '▼' }}</span>
    </button>

    <div v-if="open" class="panel">
      <input
        v-model="keyword"
        class="search"
        type="search"
        :placeholder="t('station.search', { count: stationStore.stations.length })"
      />

      <!-- 路線切換器：全部 / 指定路線 -->
      <div class="line-switch">
        <div class="line-switch-head">
          <span class="line-switch-title">{{ t('station.byLine') }}</span>
          <button
            type="button"
            class="line-chip all"
            :class="{ active: !selectedLine }"
            @click="selectedLine = ''"
          >
            {{ t('station.allLines') }}
          </button>
        </div>
        <div class="line-switch-body">
          <div v-for="group in lineGroups" :key="group.id" class="line-switch-group">
            <div class="line-switch-label">{{ group.name }}</div>
            <div class="line-switch-chips">
              <button
                v-for="line in group.lines"
                :key="line.uid"
                type="button"
                class="line-chip"
                :class="{ active: selectedLine === line.uid }"
                :style="selectedLine === line.uid ? { background: line.color, borderColor: line.color } : {}"
                @click="selectedLine = selectedLine === line.uid ? '' : line.uid"
              >
                <span class="bar" :style="{ background: line.color }"></span>
                {{ line.name }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="stationStore.loading" class="hint">{{ t('station.loading') }}</div>
      <div v-else-if="totalCount === 0" class="hint">{{ t('station.notFound') }}</div>

      <div v-else class="option-list">
        <div v-if="view.mode === 'line'" class="group-title">
          <span class="badge" :style="{ background: lineStore.lineColor(view.line), color: '#fff' }">
            {{ currentLine ? nameOf(currentLine.LineName) : view.line }}
          </span>
          <span class="count">{{ totalCount }}</span>
          <button type="button" class="link" @click="selectedLine = ''">{{ t('station.backToAll') }}</button>
        </div>
        <div v-else-if="view.searching" class="group-title">
          <span class="badge">{{ t('station.searchResult') }}</span>
          <span class="count">{{ totalCount }}</span>
        </div>

        <div v-for="group in view.groups" :key="group.id" class="option-group">
          <div v-if="view.mode !== 'line'" class="group-title">
            <span class="badge">{{ group.name }}</span>
            <span class="count">{{ group.items.length }}</span>
          </div>
          <button
            v-for="s in group.items"
            :key="s.StationUID"
            type="button"
            class="option"
            :class="{ active: s.StationUID === modelValue }"
            @click="choose(s)"
          >
            <span v-if="view.mode === 'line'" class="seq">{{ s.Sequence }}</span>
            <span class="name">{{ nameOf(s.StationName) || s.StationUID }}</span>
            <span v-if="s.StationName?.En" class="en">{{ s.StationName.En }}</span>
            <span class="uid">{{ s.StationUID }}</span>
            <span
              v-for="line in s.TransferLines || []"
              :key="line"
              class="line-badge"
              :style="{ background: lineStore.lineColor(line) }"
            >
              {{ lineStore.lineName(line) }}
            </span>
          </button>
        </div>
      </div>

      <div class="panel-foot">
        <button type="button" class="link" @click="clear">{{ t('station.clear') }}</button>
        <router-link class="link" to="/stations" @click="open = false">{{ t('station.all') }}</router-link>
      </div>
    </div>
  </div>
</template>
