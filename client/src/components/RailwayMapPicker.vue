<script setup>
/**
 * 路網圖選站：滿版的 canvasTaiwanRailwayMap。
 *
 * - 右上角的 X：關閉，不選任何車站。
 * - 最下面一排操作列：左邊是地區（下拉選單），右邊是選到的車站與「確定」。
 * - 圖在 div 範圍內捲動：桌機用捲軸／滑鼠滾輪，手機用拖曳。
 * - 點了車站或站名：在那一站旁邊空白最多的一側顯示選站小卡（站名＋路線＋確定）。
 *   轉乘站（大圈圈起來的車站）可以在小卡上左右滑動切換，左右露出鄰站約 1/5 的殘影，兩側有雙箭頭按鈕；
 *   按小卡的確定就設定為出發站／到達站並關閉。站名不再「再點一次換下一站」（路網圖 library 2.1.0）。
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { resolveOperatorId } from '@/config'
import { nameOf, textOn } from '@/utils/format'
import { trainTextOn } from '@/train_ui_frame/utils'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  selected: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'confirm'])

const { t, locale } = useI18n()
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
/** 路網圖 library 載入了沒有：window 上的 library 不是 reactive，用到它的 computed 要靠這個在載入後重算 */
const libReady = ref(false)

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
  if (!picked.value || !libReady.value) return null
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
    libReady.value = true
    await nextTick()
    if (!box.value) return
    map = lib.setup(box.value, {
      regions: currentRegions.value,
      selected: props.selected || null,
      selectOnClick: true,
      onClick: (e) => {
        picked.value = e.station ? e.station.StationUID : ''
        if (e.station) openCard(e.ring && e.ring.length ? e.ring : [e.station.StationUID], e.station.StationUID)
        else closeCard()
      },
    })
    if (box.value) box.value.addEventListener('scroll', onBoxScroll, { passive: true })
    loading.value = false
  } catch (err) {
    failed.value = err?.message || String(err)
    loading.value = false
  }
}

function changeArea() {
  closeCard()
  if (map) map.setRegions(currentRegions.value)
}

function destroyMap() {
  closeCard()
  if (box.value) box.value.removeEventListener('scroll', onBoxScroll)
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

// ---------------------------------------------------------------- 選站小卡

const wrapEl = ref(null)
const cardEl = ref(null)
const viewEl = ref(null)
/** 小卡：ring＝可以左右切換的車站（同一個大圈，依優先順序），index＝目前這一張 */
const card = ref({ show: false, ring: [], index: 0, left: 0, top: 0, fs: 26 })
let cardScrollTimer = null
let boxScrollFrame = 0

const cardIsGroup = computed(() => card.value.ring.length > 1)

/** 車站的路線（名稱與代表色）：路網圖資料裡的路線 */
function stationLines(uid) {
  const lib = typeof window !== 'undefined' ? window.canvasTaiwanRailwayMap : null
  const raw = lib?.data?.stations?.[uid]
  const all = lib?.data?.lines || []
  return (raw?.l || []).map((lu) => {
    const line = all.find((l) => l.uid === lu)
    const name = line ? (localeIsZh() ? line.name : line.nameEn || line.name) : lu
    const color = line?.color || ''
    return { uid: lu, name: String(name).replace(/臺/g, '台'), color, fg: color ? trainTextOn(color) : '' }
  })
}

/** 中文、日文用路線的中文名稱，其他語言用英文名稱 */
function localeIsZh() {
  return /^(zh|ja)/i.test(String(locale.value || ''))
}

/** 小卡每一張的內容：站名、營運系統、路線 */
const cardSlides = computed(() =>
  (libReady.value ? card.value.ring : []).map((uid) => {
    const lib = typeof window !== 'undefined' ? window.canvasTaiwanRailwayMap : null
    const info = lib?.station(uid) || null
    const st = stationStore.stationMap.get(uid)
    // 站名牌：大字一律中文、小字英文（不隨介面語言）
    const zh = String(st?.StationName?.Zh_tw || info?.name || stationNameOf(uid, info)).replace(/臺/g, '台')
    const en = st?.StationName?.En || info?.StationName?.En || ''
    return { uid, name: zh, en, system: systemNameOf(uid, info), lines: stationLines(uid) }
  }),
)

/** 營運系統名稱（與底部操作列同一套規則） */
function systemNameOf(uid, info) {
  const lib = typeof window !== 'undefined' ? window.canvasTaiwanRailwayMap : null
  const raw = lib?.data?.stations?.[uid] || null
  const system = info?.System || raw?.s || ''
  const operatorId = resolveOperatorId({ System: system }) || system
  const fromStore = operatorId ? infoStore.systemName(operatorId) : ''
  return fromStore && fromStore !== operatorId ? fromStore : SYSTEM_NAMES[operatorId] || info?.SystemName || operatorId
}

async function openCard(ring, uid) {
  const index = Math.max(0, ring.indexOf(uid))
  card.value = { ...card.value, show: true, ring, index, fs: map ? map.fontSize(0) : 26 }
  await nextTick()
  scrollCardTo(index, false)
  placeCard()
}

function closeCard() {
  clearTimeout(cardScrollTimer)
  card.value = { ...card.value, show: false, ring: [], index: 0 }
}

/**
 * 小卡放在車站（整個大圈）上下左右空白最多的一側：以目前看得到的路網圖範圍計算，
 * 放不下時往畫面內收，蓋住車站也不要超出畫面
 */
function placeCard() {
  if (!card.value.show || !map || !wrapEl.value || !cardEl.value) return
  const wrapRect = wrapEl.value.getBoundingClientRect()
  const canvasRect = map.canvas.getBoundingClientRect()
  let x1 = Infinity
  let y1 = Infinity
  let x2 = -Infinity
  let y2 = -Infinity
  for (const u of card.value.ring) {
    const p = map.locate(u)
    if (!p) continue
    const cx = canvasRect.left - wrapRect.left + p.x
    const cy = canvasRect.top - wrapRect.top + p.y
    x1 = Math.min(x1, cx - p.r - 6)
    x2 = Math.max(x2, cx + p.r + 6)
    y1 = Math.min(y1, cy - p.r - 6)
    y2 = Math.max(y2, cy + p.r + 6)
  }
  if (x1 === Infinity) return
  const W = wrapRect.width
  const H = wrapRect.height
  const cw = cardEl.value.offsetWidth
  const ch = cardEl.value.offsetHeight
  const M = 10
  const space = { left: x1, right: W - x2, top: y1, bottom: H - y2 }
  const fits = {
    left: space.left >= cw + M * 2,
    right: space.right >= cw + M * 2,
    top: space.top >= ch + M * 2,
    bottom: space.bottom >= ch + M * 2,
  }
  // 放得下的一側裡空白最多的；都放不下就取空白最多的
  const sides = Object.keys(space).sort((a, b) => space[b] - space[a])
  const side = sides.find((k) => fits[k]) || sides[0]
  const midX = (x1 + x2) / 2
  const midY = (y1 + y2) / 2
  let left
  let top
  if (side === 'left') {
    left = x1 - M - cw
    top = midY - ch / 2
  } else if (side === 'right') {
    left = x2 + M
    top = midY - ch / 2
  } else if (side === 'top') {
    left = midX - cw / 2
    top = y1 - M - ch
  } else {
    left = midX - cw / 2
    top = y2 + M
  }
  left = Math.min(Math.max(M, left), Math.max(M, W - cw - M))
  top = Math.min(Math.max(M, top), Math.max(M, H - ch - M))
  card.value = { ...card.value, left, top }
}

/** 捲動路網圖時小卡跟著車站移動 */
function onBoxScroll() {
  if (!card.value.show || boxScrollFrame) return
  boxScrollFrame = requestAnimationFrame(() => {
    boxScrollFrame = 0
    placeCard()
  })
}

/** 捲到第 i 張（smooth＝滑動） */
function scrollCardTo(i, smooth = true) {
  const view = viewEl.value
  const slide = view?.querySelectorAll('.railway-map__slide')[i]
  if (!view || !slide) return
  const left = slide.offsetLeft - (view.clientWidth - slide.offsetWidth) / 2
  view.scrollTo({ left, behavior: smooth ? 'smooth' : 'auto' })
}

function setCardIndex(i) {
  if (i < 0 || i >= card.value.ring.length) return
  card.value = { ...card.value, index: i }
  picked.value = card.value.ring[i]
  if (map) map.select(picked.value)
}

function goCard(i) {
  if (i < 0 || i >= card.value.ring.length) return
  setCardIndex(i)
  scrollCardTo(i, true)
}

/** 滑動停下來：看哪一張在中間 */
function onCardScroll() {
  clearTimeout(cardScrollTimer)
  cardScrollTimer = setTimeout(() => {
    const view = viewEl.value
    if (!view) return
    const center = view.scrollLeft + view.clientWidth / 2
    let best = 0
    let dist = Infinity
    view.querySelectorAll('.railway-map__slide').forEach((el, i) => {
      const d = Math.abs(el.offsetLeft + el.offsetWidth / 2 - center)
      if (d < dist) {
        dist = d
        best = i
      }
    })
    if (best !== card.value.index) setCardIndex(best)
  }, 90)
}

function confirmCard() {
  const uid = card.value.ring[card.value.index]
  if (!uid) return
  picked.value = uid
  confirm()
}

onBeforeUnmount(() => {
  clearTimeout(cardScrollTimer)
  if (boxScrollFrame) cancelAnimationFrame(boxScrollFrame)
})

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
    <div ref="wrapEl" class="railway-map__wrap">
      <div
        ref="box"
        class="railway-map__box"
        @touchstart.passive="onTouchStart"
        @touchmove.passive="onTouchMove"
        @touchend.passive="onTouchEnd"
        @click.capture="onClickCapture"
      ></div>
      <!-- 選站小卡：站名＋路線，轉乘站可以左右滑動切換；下面是確定 -->
      <div
        v-if="card.show && cardSlides.length"
        ref="cardEl"
        class="railway-map__card"
        :class="{ 'is-group': cardIsGroup }"
        :style="{ left: `${card.left}px`, top: `${card.top}px`, '--rm-fs': `${card.fs}px` }"
      >
        <div class="railway-map__card-box">
          <div ref="viewEl" class="railway-map__slides" @scroll.passive="onCardScroll">
            <span v-if="cardIsGroup" class="railway-map__spacer" aria-hidden="true"></span>
            <button
              v-for="(sl, i) in cardSlides"
              :key="sl.uid"
              type="button"
              class="railway-map__slide"
              :class="{ 'is-active': i === card.index }"
              :aria-current="i === card.index ? 'true' : null"
              @click="goCard(i)"
            >
              <!-- 站名牌：左上角營運單位（沒有 logo，用小字）、中文站名大字、英文小字、最下面是路線底色吃滿全寬的路線帶 -->
              <span class="railway-map__board">
                <span v-if="sl.system" class="railway-map__board-op">{{ sl.system }}</span>
                <span class="railway-map__board-name">{{ sl.name }}</span>
                <span v-if="sl.en" class="railway-map__board-en">{{ sl.en }}</span>
                <span class="railway-map__board-bands">
                  <span
                    v-for="ln in sl.lines"
                    :key="ln.uid"
                    class="railway-map__board-band"
                    :style="ln.color ? { background: ln.color, color: ln.fg } : null"
                    >{{ ln.name }}</span
                  >
                </span>
              </span>
            </button>
            <span v-if="cardIsGroup" class="railway-map__spacer" aria-hidden="true"></span>
          </div>
          <button
            v-if="cardIsGroup && card.index > 0"
            type="button"
            class="railway-map__arrow is-prev"
            :aria-label="t('station.map.prev')"
            @click="goCard(card.index - 1)"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M13 6l-6 6 6 6M19 6l-6 6 6 6" /></svg>
          </button>
          <button
            v-if="cardIsGroup && card.index < card.ring.length - 1"
            type="button"
            class="railway-map__arrow is-next"
            :aria-label="t('station.map.next')"
            @click="goCard(card.index + 1)"
          >
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M11 6l6 6-6 6M5 6l6 6-6 6" /></svg>
          </button>
        </div>
        <button type="button" class="railway-map__card-ok" @click="confirmCard">
          {{ t('station.map.confirm') }}
        </button>
      </div>

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

/* 選站小卡：字級跟路網圖的大站站名（台北、板橋）一樣（--rm-fs） */
.railway-map__card {
  position: absolute;
  z-index: 5;
  width: calc(var(--rm-fs) * 6.6);
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: calc(var(--rm-fs) * 0.3);
  padding: calc(var(--rm-fs) * 0.3);
  background: #fff;
  border: 1px solid #d3d8e0;
  border-radius: 10px;
  box-shadow: 0 6px 22px rgba(20, 30, 50, 0.22);
}

.railway-map__card.is-group {
  width: calc(var(--rm-fs) * 10.5);
}

.railway-map__card-box {
  position: relative;
}

/* 左右滑動：一張佔 60%，左右各露出約 1/5 的鄰站殘影 */
.railway-map__slides {
  display: flex;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  scrollbar-width: none;
  overscroll-behavior-x: contain;
}

.railway-map__slides::-webkit-scrollbar {
  display: none;
}

.railway-map__spacer {
  flex: 0 0 20%;
}

.railway-map__slide {
  flex: 0 0 100%;
  scroll-snap-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  margin: 0;
  padding: 0 3px;
  border: 0;
  background: none;
  font: inherit;
  color: #111;
  cursor: pointer;
  transition: opacity 0.25s;
}

.railway-map__card.is-group .railway-map__slide {
  flex-basis: 60%;
  opacity: 0.3;
}

.railway-map__card.is-group .railway-map__slide.is-active {
  opacity: 1;
}

/* 站名牌（類似車站的站名牌）：深色邊框、白底，最下面是路線帶 */
.railway-map__board {
  flex: 1 1 auto;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  box-sizing: border-box;
  border: 3px solid #4a5160;
  border-radius: 6px;
  background: #fff;
  overflow: hidden;
  box-shadow: inset 0 0 0 1px #d7dbe2;
}

/* 左上角營運單位：沒有 logo，用小字做成標章 */
.railway-map__board-op {
  align-self: flex-start;
  margin: 4px 0 0 5px;
  padding: 0 4px;
  border: 1px solid #8a92a3;
  border-radius: 3px;
  color: #2f3645;
  font-size: max(10px, calc(var(--rm-fs) * 0.4));
  font-weight: 700;
  line-height: 1.35;
  white-space: nowrap;
}

.railway-map__board-name {
  padding: 0 4px;
  font-size: var(--rm-fs);
  font-weight: 800;
  line-height: 1.15;
  letter-spacing: 0.06em;
  text-align: center;
  overflow-wrap: anywhere;
}

.railway-map__board-en {
  padding: 0 4px;
  font-size: max(10px, calc(var(--rm-fs) * 0.45));
  font-weight: 700;
  line-height: 1.2;
  color: #222;
  text-align: center;
  overflow-wrap: anywhere;
}

/* 路線帶：路線底色吃滿左右全寬（好幾條路線就疊好幾條） */
.railway-map__board-bands {
  align-self: stretch;
  margin-top: auto;
  padding-top: calc(var(--rm-fs) * 0.3);
  display: flex;
  flex-direction: column;
}

.railway-map__board-band {
  display: block;
  padding: calc(var(--rm-fs) * 0.08) 6px;
  background: #6b7280;
  color: #fff;
  font-size: max(11px, calc(var(--rm-fs) * 0.5));
  font-weight: 700;
  line-height: 1.3;
  text-align: center;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* 左右的雙箭頭浮動按鈕 */
.railway-map__arrow {
  position: absolute;
  top: 50%;
  transform: translateY(-50%);
  width: calc(var(--rm-fs) * 1.25);
  height: calc(var(--rm-fs) * 1.25);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: 1px solid #c9ced8;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.95);
  color: #334;
  cursor: pointer;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
}

.railway-map__arrow svg {
  width: 62%;
  height: 62%;
  fill: none;
  stroke: currentColor;
  stroke-width: 2.4;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.railway-map__arrow.is-prev {
  left: -4px;
}

.railway-map__arrow.is-next {
  right: -4px;
}

/* 確定：翠綠底、黃字（與車站選單的路網圖按鈕同一套），字級比站名大 20% */
.railway-map__card-ok {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: calc(var(--rm-fs) * 0.15) 12px;
  border: 1px solid #046c4e;
  border-bottom-width: 3px;
  border-radius: 6px;
  background: linear-gradient(#12a173, #0b8a61);
  color: #ffd83d;
  font-family: inherit;
  font-size: calc(var(--rm-fs) * 1.2);
  font-weight: 700;
  line-height: 1.25;
  cursor: pointer;
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28), 0 1px 2px rgba(0, 0, 0, 0.18);
}

.railway-map__card-ok:hover {
  filter: brightness(1.08);
}

.railway-map__card-ok:active {
  transform: translateY(1px);
  border-bottom-width: 2px;
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
