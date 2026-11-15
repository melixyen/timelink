<script setup>
/**
 * 路網圖選站：滿版的 canvasTaiwanRailwayMap。
 *
 * - 右上角的 X：關閉，不選任何車站。
 * - 最下面一排操作列：左邊是地區（下拉選單），右邊是選到的車站與「確定」。
 * - 圖在 div 範圍內捲動：桌機用捲軸／滑鼠滾輪，手機用拖曳。
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { resolveOperatorId } from '@/config'
import { nameOf, textOn } from '@/utils/format'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  selected: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'confirm'])

const { t } = useI18n()
const stationStore = usePublicStationStore()
const infoStore = usePublicInfoStore()

/** 地區組合：value 是選項值，regions 是路網圖的生活圈代碼（沒有資料的生活圈會自動略過） */
const AREAS = [
  { value: 'all', labelKey: 'station.map.all', regions: 'all' },
  { value: 'r1', labelKey: 'station.map.r1', regions: ['taipei', 'taoyuan', 'yilan'] },
  { value: 'r2', labelKey: 'station.map.r2', regions: ['taoyuan', 'hsinchu', 'miaoli'] },
  { value: 'r3', labelKey: 'station.map.r3', regions: ['miaoli', 'taichung', 'changhua', 'nantou'] },
  { value: 'r4', labelKey: 'station.map.r4', regions: ['yunlin', 'chiayi', 'tainan'] },
  { value: 'r5', labelKey: 'station.map.r5', regions: ['kaohsiung', 'pingtung'] },
  { value: 'r6', labelKey: 'station.map.r6', regions: ['hualien', 'taitung'] },
]
const DEFAULT_AREA = 'r1'

const area = ref(DEFAULT_AREA)
const picked = ref('')
const box = ref(null)
const loading = ref(true)
const failed = ref('')

const areaOptions = computed(() => AREAS.map((a) => ({ label: t(a.labelKey), value: a.value })))
const currentRegions = computed(() => (AREAS.find((a) => a.value === area.value) || AREAS[0]).regions)

/** 營運系統名稱（後端還沒載入時先用預設） */
const SYSTEM_NAMES = {
  TRA: '台鐵',
  THSR: '高鐵',
  TRTC: '台北捷運',
  NTMC: '新北捷運',
  TYMC: '桃園捷運',
  TMRT: '台中捷運',
  KRTC: '高雄捷運',
}

/** 選到的車站：營運系統名稱（台鐵／高鐵／台北捷運…）與該路線的代表色 */
const pickedInfo = computed(() => {
  if (!picked.value) return null
  const lib = typeof window !== 'undefined' ? window.canvasTaiwanRailwayMap : null
  const raw = lib?.data?.stations?.[picked.value] || null
  const info = lib?.station(picked.value) || null
  const system = info?.System || raw?.s || ''
  // 系統代碼 → 營運單位（輕軌併到高雄捷運、淡海輕軌併到新北捷運…）
  const operatorId = resolveOperatorId({ System: system }) || system
  const fromStore = operatorId ? infoStore.systemName(operatorId) : ''
  const name =
    fromStore && fromStore !== operatorId
      ? fromStore
      : SYSTEM_NAMES[operatorId] || info?.SystemName || operatorId
  // 路線代表色：車站本身的顏色，沒有就用第一條線的顏色
  let color = raw?.c || ''
  if (!color) {
    const lines = info?.Lines || raw?.l || []
    const line = (lib?.data?.lines || []).find((l) => l.uid === lines[0])
    color = line?.color || ''
  }
  return { name: name || system, color, station: stationNameOf(picked.value, info) }
})

/** 站名：先找後端的資料（有多語系名稱），沒有再用路網圖裡的名稱 */
function stationNameOf(uid, info) {
  const fromStore = stationStore.stationName(uid)
  if (fromStore && fromStore !== uid) return fromStore
  return (info && nameOf(info.StationName)) || uid
}

// ---------------------------------------------------------------- 路網圖 library

let libPromise = null

/** 載入 public 下的路網圖 library（載入過就不再重複載入） */
function loadLibrary() {
  if (typeof window !== 'undefined' && window.canvasTaiwanRailwayMap) {
    return Promise.resolve(window.canvasTaiwanRailwayMap)
  }
  if (libPromise) return libPromise
  libPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = new URL('canvasTaiwanRailwayMap/canvasTaiwanRailwayMap.js', document.baseURI).href
    script.async = true
    script.onload = () => {
      if (window.canvasTaiwanRailwayMap) resolve(window.canvasTaiwanRailwayMap)
      else reject(new Error('canvasTaiwanRailwayMap is not defined'))
    }
    script.onerror = () => reject(new Error('canvasTaiwanRailwayMap.js'))
    document.head.appendChild(script)
  }).catch((err) => {
    libPromise = null
    throw err
  })
  return libPromise
}

let map = null

async function openMap() {
  loading.value = true
  failed.value = ''
  picked.value = props.selected || ''
  try {
    const lib = await loadLibrary()
    await nextTick()
    if (!box.value) return
    map = lib.setup(box.value, {
      regions: currentRegions.value,
      selected: props.selected || null,
      selectOnClick: true,
      onClick: (e) => {
        picked.value = e.station ? e.station.StationUID : ''
      },
    })
    loading.value = false
  } catch (err) {
    failed.value = err?.message || String(err)
    loading.value = false
  }
}

function changeArea() {
  if (map) map.setRegions(currentRegions.value)
}

function destroyMap() {
  if (map) {
    map.destroy()
    map = null
  }
}

function close() {
  emit('update:modelValue', false)
}

function confirm() {
  if (!picked.value) return
  emit('confirm', picked.value)
  close()
}

watch(
  () => props.modelValue,
  (v) => {
    if (v) {
      area.value = DEFAULT_AREA
      openMap()
    } else {
      destroyMap()
    }
  },
)

onBeforeUnmount(destroyMap)

// ---------------------------------------------------------------- 手機拖曳時不要誤觸

let touchStart = null
let dragged = false

function onTouchStart(e) {
  dragged = false
  const t = e.touches && e.touches[0]
  touchStart = t ? [t.clientX, t.clientY] : null
}

function onTouchMove(e) {
  if (!touchStart) return
  const t = e.touches && e.touches[0]
  if (!t) return
  if (Math.abs(t.clientX - touchStart[0]) > 6 || Math.abs(t.clientY - touchStart[1]) > 6) dragged = true
}

function onTouchEnd() {
  touchStart = null
}

/** 拖曳移動後的第一個 click 不要送到圖（避免拖曳完順手選到車站） */
function onClickCapture(e) {
  if (!dragged) return
  dragged = false
  e.stopPropagation()
  e.preventDefault()
}
</script>

<template>
  <train-modal
    :model-value="modelValue"
    fullscreen
    :title="t('station.map')"
    :close-label="t('common.close')"
    body-class="railway-map__body"
    @update:model-value="close"
  >
    <div class="railway-map__wrap">
      <div
        ref="box"
        class="railway-map__box"
        @touchstart.passive="onTouchStart"
        @touchmove.passive="onTouchMove"
        @touchend.passive="onTouchEnd"
        @click.capture="onClickCapture"
      ></div>
      <div v-if="loading" class="railway-map__overlay">
        <train-spinner :text="t('common.loading')" />
      </div>
      <div v-else-if="failed" class="railway-map__overlay">
        <div class="railway-map__error">{{ t('common.loadFailed', { msg: failed }) }}</div>
      </div>
    </div>

    <template #footer>
      <div class="railway-map__bar">
        <div class="railway-map__area">
          <span class="railway-map__area-label">{{ t('station.map.area') }}</span>
          <train-select v-model="area" :options="areaOptions" @change="changeArea" />
        </div>
        <div class="railway-map__result">
          <span
            v-if="pickedInfo"
            class="railway-map__system"
            :style="pickedInfo.color ? { background: pickedInfo.color, color: textOn(pickedInfo.color) } : null"
          >
            {{ pickedInfo.name }}
          </span>
          <span v-if="pickedInfo" class="railway-map__station">{{ pickedInfo.station }}</span>
          <train-button type="primary" :disabled="!picked" @click="confirm">
            {{ t('station.map.confirm') }}
          </train-button>
        </div>
      </div>
    </template>
  </train-modal>
</template>

<style>
/* 這支元件的樣式沒有 scoped：train-modal 會把內容 Teleport 到 body，
   而且樣式要套在 train-modal 自己的元素上（body-class），所以不能加 scoped。 */
.railway-map__body {
  padding: 0 !important;
  overflow: hidden !important;
}

.railway-map__wrap {
  position: relative;
  width: 100%;
  height: 100%;
  background: #fff;
}

/* 圖在這個 div 裡捲動：桌機用捲軸／滾輪，手機用拖曳 */
.railway-map__box {
  position: absolute;
  inset: 0;
  overflow: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
}

.railway-map__overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.9);
}

.railway-map__error {
  color: var(--train-ui-text-color-secondary);
}

.railway-map__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
  flex-wrap: wrap;
}

.railway-map__area {
  display: flex;
  align-items: center;
  gap: 8px;
}

.railway-map__area-label {
  color: var(--train-ui-text-color-secondary);
  font-size: var(--train-ui-font-size-sm);
  white-space: nowrap;
}

.railway-map__result {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

/* 選到的營運系統：底色是該路線的代表色 */
.railway-map__system {
  display: inline-flex;
  align-items: center;
  height: 24px;
  padding: 0 10px;
  border-radius: var(--train-ui-radius-round);
  background: var(--train-ui-color-primary);
  color: #fff;
  font-size: var(--train-ui-font-size-sm);
  white-space: nowrap;
}

/* 選到的站名 */
.railway-map__station {
  color: var(--train-ui-text-color-primary);
  font-size: var(--train-ui-font-size-base);
  font-weight: var(--train-ui-font-weight-bold);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 34vw;
}

@media (max-width: 768px) {
  .railway-map__bar {
    gap: 8px;
  }

  .railway-map__picked {
    max-width: 40vw;
  }
}
</style>
