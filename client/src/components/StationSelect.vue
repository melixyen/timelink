<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { usePublicLineStore } from '@/stores/publicLine'
import { config, groupByOperator } from '@/config'
import { nameOf } from '@/utils/format'
import RailwayMapPicker from '@/components/RailwayMapPicker.vue'
import { useIsMobile } from '@/utils/media'
import { trainLockScroll, trainTextOn, trainUnlockScroll } from '@/train_ui_frame/utils'

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
const selectedLine = ref('')
const mapOpen = ref(false)

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

/**
 * 路線按鈕的顏色：有路線顏色（#RRGGBB）的用路線顏色當底色，深色底配白字、淺色底配按鈕原本的深灰字；
 * 沒有路線顏色的維持按鈕預設樣式（回 null）
 */
const CHIP_DARK_TEXT = '#3c4a5e' // 按鈕原本的深灰字（--train-ui-text-color-regular）

function chipStyle(color) {
  if (!/^#[0-9a-f]{6}$/i.test(color || '')) return null
  // 白字與深灰字取對比度高的：深色底白字、淺色底深灰字
  const fg = trainTextOn(color, CHIP_DARK_TEXT) === '#fff' ? '#fff' : 'var(--train-ui-text-color-regular)'
  return {
    '--tb-bg': color,
    '--tb-border': color,
    '--tb-hover-bg': color,
    '--tb-hover-border': color,
    '--tb-color': fg,
    '--tb-hover-color': fg,
  }
}

const norm = (s) => String(s || '').toLowerCase().replace(/\s+/g, '').replace(/臺/g, '台') // 臺、台視為同一字

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

function choose(station) {
  emit('update:modelValue', station.StationUID)
  open.value = false
  keyword.value = ''
}

function clear() {
  emit('update:modelValue', '')
}

/** 開出路網圖選站（先把下拉面板收起來） */
function openMap() {
  open.value = false
  mapOpen.value = true
}

/** 路網圖選好車站：回填給上層（出發站／到達站） */
function chooseFromMap(uid) {
  emit('update:modelValue', uid)
  keyword.value = ''
}

/**
 * 下拉面板的位置與高度（桌面版）：面板要能完整看到搜尋列、路線區與車站清單。
 * 1. 下拉位置到畫面底部放得下（最多 PANEL_MAX 高）：照常往下開
 * 2. 放不下：先縮短高度，最短到「搜尋列＋路線區＋車站清單最少 MIN_LIST_HEIGHT」的最小高度
 * 3. 縮到最小高度還放不下：往上移，讓面板底部貼齊畫面底部
 * 4. 畫面高度連最小高度都不夠：放在畫面頂端（top＝0），不再調整
 * 手機版（useIsMobile）直接全螢幕，不用計算
 */
const PANEL_MAX = 580
const PANEL_GAP = 6
const VIEW_MARGIN = 8
const MIN_LIST_HEIGHT = 150

const isMobile = useIsMobile()
const rootEl = ref(null)
const panelEl = ref(null)
const panelTop = ref(null)
const panelMaxHeight = ref(null)

function measureMinHeight(panel) {
  const cs = getComputedStyle(panel)
  const pad = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom)
  const gap = parseFloat(cs.rowGap) || 0
  const kids = [...panel.children]
  let h = pad + gap * Math.max(0, kids.length - 1)
  for (const el of kids) {
    h += el.classList.contains('option-list') ? MIN_LIST_HEIGHT : el.offsetHeight
  }
  return Math.ceil(h)
}

function measureNaturalHeight(panel) {
  const cs = getComputedStyle(panel)
  const pad = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom)
  const gap = parseFloat(cs.rowGap) || 0
  const kids = [...panel.children]
  let h = pad + gap * Math.max(0, kids.length - 1)
  for (const el of kids) {
    h += el.classList.contains('option-list') ? Math.max(el.scrollHeight, MIN_LIST_HEIGHT) : el.offsetHeight
  }
  return Math.ceil(h)
}

function placePanel() {
  if (!open.value || isMobile.value || !rootEl.value || !panelEl.value) return
  // 面板以下拉元件（train-dropdown，不含上方的欄位標題）為定位基準
  const anchor = rootEl.value.querySelector('.train-dropdown') || rootEl.value
  const rect = anchor.getBoundingClientRect()
  const vh = window.innerHeight
  const minH = measureMinHeight(panelEl.value)
  const want = Math.min(PANEL_MAX, measureNaturalHeight(panelEl.value))
  const below = vh - rect.bottom - PANEL_GAP - VIEW_MARGIN
  let top = rect.height + PANEL_GAP // 相對下拉元件頂端
  let height = want
  if (below < want) {
    if (below >= minH) {
      height = below
    } else if (vh - VIEW_MARGIN * 2 >= minH) {
      height = minH
      top = vh - VIEW_MARGIN - minH - rect.top
    } else {
      height = minH
      top = -rect.top
    }
  }
  panelTop.value = top
  panelMaxHeight.value = height
}

function resetPanel() {
  panelTop.value = null
  panelMaxHeight.value = null
}

const panelStyle = computed(() =>
  isMobile.value || panelMaxHeight.value === null ? null : { maxHeight: `${panelMaxHeight.value}px` },
)

function onViewportChange() {
  placePanel()
}

watch(open, async (v) => {
  if (v) {
    if (isMobile.value) trainLockScroll()
    resetPanel()
    await nextTick()
    placePanel()
    window.addEventListener('resize', onViewportChange)
    window.addEventListener('scroll', onViewportChange, true)
  } else {
    window.removeEventListener('resize', onViewportChange)
    window.removeEventListener('scroll', onViewportChange, true)
    trainUnlockScroll()
  }
})

// 清單內容變了（切換路線、搜尋）：重新計算高度與位置
watch([selectedLine, kw, () => stationStore.loading], async () => {
  if (!open.value) return
  await nextTick()
  placePanel()
})

watch(isMobile, () => {
  if (open.value) placePanel()
})

onBeforeUnmount(() => {
  clearTimeout(timer)
  window.removeEventListener('resize', onViewportChange)
  window.removeEventListener('scroll', onViewportChange, true)
  if (open.value) trainUnlockScroll()
})
</script>

<template>
  <div
    ref="rootEl"
    class="station-select"
    :class="{ 'is-mobile': isMobile }"
    :style="!isMobile && panelTop !== null ? { '--station-panel-top': `${panelTop}px` } : null"
  >
    <div v-if="fieldLabel" class="field-label">{{ fieldLabel }}</div>
    <train-dropdown v-model="open">
      <template #trigger="{ toggle }">
        <button type="button" class="trigger" :class="{ open }" @click="toggle">
          <span :class="{ placeholder: !selected }">{{ selected || t(placeholderKey) }}</span>
          <span v-if="selected" class="uid">{{ modelValue }}</span>
          <train-icon :name="open ? 'caret-up' : 'caret-down'" class="caret" />
        </button>
      </template>

      <div ref="panelEl" class="station-panel" :style="panelStyle">
        <!-- 手機版全螢幕：上方標題列＋關閉鈕 -->
        <div v-if="isMobile" class="panel-head">
          <span class="panel-title">{{ fieldLabel }}</span>
          <train-button
            size="small"
            circle
            icon="close"
            :aria-label="t('common.close')"
            :title="t('common.close')"
            @click="open = false"
          />
        </div>

        <!-- 搜尋列：搜尋框＋路網圖按鈕（同一排，按鈕在最右邊） -->
        <div class="search-row">
          <train-input
            v-model="keyword"
            class="search"
            type="search"
            prefix-icon="search"
            clearable
            :placeholder="t('station.search', { count: stationStore.stations.length })"
          />
          <button type="button" class="map-btn" :title="t('station.map')" @click="openMap">
            <train-icon name="route" />
            <span>{{ t('station.mapShort') }}</span>
          </button>
        </div>

        <!-- 路線切換器：全部 / 指定路線 -->
        <div class="line-switch">
          <div class="line-switch-head">
            <span class="line-switch-title">{{ t('station.byLine') }}</span>
            <train-button
              size="small"
              round
              :type="selectedLine ? 'default' : 'primary'"
              @click="selectedLine = ''"
            >
              {{ t('station.allLines') }}
            </train-button>
          </div>
          <div class="line-switch-body">
            <div v-for="group in lineGroups" :key="group.id" class="line-switch-group">
              <div class="line-switch-label">{{ group.name }}</div>
              <div class="line-switch-chips">
                <train-button
                  v-for="line in group.lines"
                  :key="line.uid"
                  size="small"
                  round
                  class="line-chip"
                  :class="{ active: selectedLine === line.uid, colored: !!chipStyle(line.color) }"
                  :style="chipStyle(line.color)"
                  @click="selectedLine = selectedLine === line.uid ? '' : line.uid"
                >
                  <span v-if="!chipStyle(line.color)" class="bar" :style="{ background: line.color }"></span>
                  {{ line.name }}
                </train-button>
              </div>
            </div>
          </div>
        </div>

        <train-spinner v-if="stationStore.loading" :text="t('station.loading')" />
        <div v-else-if="totalCount === 0" class="hint">{{ t('station.notFound') }}</div>

        <div v-else class="option-list">
          <div v-if="view.mode === 'line'" class="group-title">
            <train-tag :color="lineStore.lineColor(view.line)">
              {{ currentLine ? nameOf(currentLine.LineName) : view.line }}
            </train-tag>
            <span class="count">{{ totalCount }}</span>
            <train-link @click="selectedLine = ''">{{ t('station.backToAll') }}</train-link>
          </div>
          <div v-else-if="view.searching" class="group-title">
            <train-tag>{{ t('station.searchResult') }}</train-tag>
            <span class="count">{{ totalCount }}</span>
          </div>

          <div v-for="group in view.groups" :key="group.id" class="option-group">
            <div v-if="view.mode !== 'line'" class="group-title">
              <train-tag>{{ group.name }}</train-tag>
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
              <train-tag
                v-for="line in s.TransferLines || []"
                :key="line"
                :color="lineStore.lineColor(line)"
                size="small"
              >
                {{ lineStore.lineName(line) }}
              </train-tag>
            </button>
          </div>
        </div>

        <div class="panel-foot">
          <train-link @click="clear">{{ t('station.clear') }}</train-link>
        </div>
      </div>
    </train-dropdown>

    <railway-map-picker v-model="mapOpen" :selected="modelValue" @confirm="chooseFromMap" />
  </div>
</template>
