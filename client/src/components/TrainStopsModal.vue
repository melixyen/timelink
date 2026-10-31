<script setup>
/**
 * 列車停靠站與時刻的對話視窗（查詢結果的「列車時刻」「列車資訊」欄點下去開啟）。
 *
 * 資料來源（依序）：
 *   1. 搭乘段本身的 StopTimes（journeys 一起回傳：上車站到下車站，含通過站 Pass＝true）
 *   2. 臺鐵、高鐵：GET /txapi/app_train/stops?train=<RunCode>&week=&pass=1 取整班車的全程時刻表，開啟時才查
 * 捷運、輕軌沒有整列車時刻表：只列搭乘區間，不查 app_train/stops；開頭標明只有上下車站是車站時刻表的時刻，
 * 中間各站是依站間行駛時間比例推算（上車站的時刻表本身也是推算時另外說明）
 * 有整班車時刻表時全部列出：搭乘區間醒目顯示，區間以外的車站降低不透明度；
 * 搭乘區間優先用 StopTimes（與查詢結果的時刻一致）。查不到時只列搭乘區間；
 * 兩者都沒有（舊版後端）時只列上下車站。
 * 有到站、離站兩個時刻時分開顯示，只有一個時只顯示那一個；通過站顯示「通過」。
 *
 * Props:
 *   modelValue  開關（v-model）
 *   leg         查詢結果的搭乘段（Legs 裡 Type＝ride 的那一段）
 *   week        查詢的星期（0～6），帶給 app_train/stops
 */
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/txapi'
import { usePublicLineStore } from '@/stores/publicLine'
import { isLatinLocale, nameOf } from '@/utils/format'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  leg: { type: Object, default: null },
  week: { type: [Number, String], default: null },
})
const emit = defineEmits(['update:modelValue'])

const { t } = useI18n()
const lineStore = usePublicLineStore()

const open = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const loading = ref(false)
const train = ref(null) // app_train/stops 的結果
const failed = ref(false)
let loadedCode = null // 已查過的代碼：重開同一班車不再查

/** 查 app_train/stops 用的代碼：新版後端是 RunCode；舊版後端的 Train 也是同一個代碼 */
const runCode = computed(() => props.leg?.RunCode || props.leg?.Train || '')

/** 捷運、輕軌（臺鐵、高鐵以外）：沒有整列車時刻表，中間站是推算的 */
const isMetro = computed(() => {
  const leg = props.leg
  if (!leg) return false
  const sys = leg.System || String(leg.LineUID || '').split('-')[0]
  return sys !== 'TRA' && sys !== 'THSR'
})

/** 上車站的時刻也是推算的（文湖線、臺中捷運等只有模擬站別時刻表的路線） */
const boardEstimated = computed(() => {
  const first = segmentRows.value[0]
  return isMetro.value && !!first && ESTIMATE_SOURCES.has(first.Source)
})

async function load() {
  train.value = null
  failed.value = false
  loadedCode = runCode.value
  if (isMetro.value) {
    scrollToRide()
    return
  }
  if (!runCode.value) {
    failed.value = true
    return
  }
  loading.value = true
  try {
    const params = { train: runCode.value, pass: 1 }
    if (props.week !== null && props.week !== '') params.week = props.week
    const res = await api.trainStops(params)
    train.value = res.data || null
    if (!train.value || !Array.isArray(train.value.Stops) || !train.value.Stops.length) failed.value = true
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
  scrollToRide()
}

/** 整班車的時刻表很長時（臺鐵），捲到上車站 */
const listEl = ref(null)
function scrollToRide() {
  nextTick(() => {
    const row = listEl.value?.querySelector('.is-board')
    if (row && typeof row.scrollIntoView === 'function') row.scrollIntoView({ block: 'center' })
  })
}

watch(
  () => [props.modelValue, runCode.value],
  ([v, code]) => {
    if (!v) return
    if (code !== loadedCode) load()
    else scrollToRide()
  },
  { immediate: true },
)

/** 推算的時刻來源（timetable、terminal 等其他來源都是時刻表上的時刻） */
const ESTIMATE_SOURCES = new Set(['estimate', 'interpolated', 'simulated', 'derived'])

const fromUid = computed(() => props.leg?.From?.StationUID || '')
const toUid = computed(() => props.leg?.To?.StationUID || '')

/** 搭乘區間（上車站到下車站）：StopTimes，沒有時用上下車站兩列 */
const segmentRows = computed(() => {
  const leg = props.leg
  if (!leg) return []
  if (Array.isArray(leg.StopTimes) && leg.StopTimes.length) return leg.StopTimes
  return [
    { StationUID: fromUid.value, StationName: leg.From?.StationName, ArrivalTime: null, DepartureTime: leg.DepartureTime, Source: leg.DepartureSource },
    { StationUID: toUid.value, StationName: leg.To?.StationName, ArrivalTime: leg.ArrivalTime, DepartureTime: null, Source: leg.ArrivalSource },
  ]
})
const segmentIsFallback = computed(() => !(Array.isArray(props.leg?.StopTimes) && props.leg.StopTimes.length))

/**
 * 顯示的列：{ key, uid, name, arr, dep, pass, inRide, board, alight, estimate }
 * 有整班車時刻表時：上車站以前、下車站以後取整班車時刻表，中間用搭乘區間
 */
const rows = computed(() => {
  const seg = segmentRows.value
  const full = train.value?.Stops || []
  let before = []
  let after = []
  let middle = seg
  if (full.length) {
    const i0 = full.findIndex((s) => !s.Pass && s.StationUID === fromUid.value)
    const i1 = i0 < 0 ? -1 : full.findIndex((s, i) => i > i0 && !s.Pass && s.StationUID === toUid.value)
    if (i0 >= 0 && i1 > i0) {
      before = full.slice(0, i0)
      after = full.slice(i1 + 1)
      // 只有上下車站兩列（舊版後端沒有 StopTimes）時，搭乘區間改用整班車時刻表的那一段
      if (segmentIsFallback.value) middle = full.slice(i0, i1 + 1)
    }
  }
  const out = []
  const leg = props.leg || {}
  const push = (s, inRide, idx, len) => {
    const pass = !!s.Pass
    const board = inRide && idx === 0
    const alight = inRide && idx === len - 1
    // 上車站一定有出發時刻、下車站一定有到達時刻（整班車時刻表只有離站時刻時，用查詢結果的時刻補）
    out.push({
      key: `${out.length}-${s.StationUID}`,
      uid: s.StationUID,
      name: nameOf(s.StationName) || s.StationUID,
      arr: pass ? null : s.ArrivalTime || (alight ? leg.ArrivalTime : null) || null,
      dep: pass ? null : s.DepartureTime || (board ? leg.DepartureTime : null) || null,
      pass,
      inRide,
      board,
      alight,
      estimate: !pass && ESTIMATE_SOURCES.has(s.Source),
    })
  }
  before.forEach((s) => push(s, false))
  middle.forEach((s, i) => push(s, true, i, middle.length))
  after.forEach((s) => push(s, false))
  return out
})

const hasOutside = computed(() => rows.value.some((r) => !r.inRide))
const hasPass = computed(() => rows.value.some((r) => r.pass))
const hasEstimate = computed(() => rows.value.some((r) => r.estimate))

/**
 * 時刻：到站、離站都有且不同時分開顯示；只有一個時只顯示那一個。
 * kind＝arr／dep：寬度夠時到站固定在左欄、離站固定在右欄（同一欄上下對齊），寬度不足才上下換行
 */
function times(r) {
  if (r.pass) return []
  const arr = { kind: 'arr', label: t('stops.arr'), value: r.arr }
  const dep = { kind: 'dep', label: t('stops.dep'), value: r.dep }
  if (r.arr && r.dep && r.arr !== r.dep) return [arr, dep]
  if (r.alight && r.arr) return [arr]
  if (r.dep) return [dep]
  if (r.arr) return [arr]
  return []
}

const lineColor = computed(() => (props.leg ? lineStore.lineColor(props.leg.LineUID) : '#999999'))
const lineName = computed(() => {
  const leg = props.leg
  if (!leg) return ''
  if (leg.LineName) return nameOf(leg.LineName)
  return lineStore.lineName(leg.LineUID)
})

/** 車次：只顯示真實車次（TrainNo），捷運等沒有車次時顯示「無車次資訊」 */
const trainNoText = computed(() => props.leg?.TrainNo || train.value?.TrainNo || t('journey.noTrainNo'))

const trainTypeText = computed(() => {
  const leg = props.leg
  const name = leg?.TrainType ? nameOf(leg.TrainType.Name) : ''
  const short = name ? name.replace(/[（(].*$/, '').trim() || name : ''
  const cls = isLatinLocale() ? '' : leg?.ClassLabel || ''
  if (!short) return cls
  if (!cls || cls === short || cls.includes(short)) return cls || short
  return `${short}（${cls}）`
})

const directionText = computed(() => {
  const leg = props.leg
  if (!leg) return ''
  const dest = leg.Destination || train.value?.Destination
  if (dest) return `${t('journey.direction')} ${nameOf(dest.StationName)}`
  return leg.HeadSign || ''
})

const rideText = computed(() => {
  const leg = props.leg
  if (!leg) return ''
  return `${nameOf(leg.From?.StationName)} ${leg.DepartureTime || ''} → ${nameOf(leg.To?.StationName)} ${leg.ArrivalTime || ''}`
})
</script>

<template>
  <train-modal v-model="open" :close-label="t('common.close')" body-class="train-stops-body" inset="8% 20%">
    <template #header>
      <div class="train-stops-head">
        <train-tag :color="lineColor">{{ lineName }}</train-tag>
        <span class="title">{{ t('stops.title') }}</span>
      </div>
    </template>

    <div v-if="leg" class="train-stops">
      <div class="train-stops-info">
        <div>
          <span class="label">{{ t('journey.trainNo') }}: </span
          ><span :class="{ muted: !leg.TrainNo && !train?.TrainNo }">{{ trainNoText }}</span>
        </div>
        <div v-if="trainTypeText"><span class="label">{{ t('journey.trainClass') }}: </span>{{ trainTypeText }}</div>
        <div v-if="directionText">{{ directionText }}</div>
        <div class="ride">{{ rideText }}</div>
      </div>

      <train-alert v-if="isMetro" type="warning" show-icon class="train-stops-metro">
        <div>{{ t('stops.metroEstimated') }}</div>
        <div v-if="boardEstimated">{{ t('stops.metroSimulated') }}</div>
      </train-alert>

      <train-spinner v-if="loading" block :text="t('stops.loading')" />
      <template v-else>
        <train-alert v-if="failed" type="info" simple show-icon :title="t('stops.segmentOnly')" />
        <div v-else-if="hasOutside" class="train-stops-hint">{{ t('stops.fullLine') }}</div>

        <ol ref="listEl" class="train-stops-list" :style="{ '--line-color': lineColor }">
          <li
            v-for="r in rows"
            :key="r.key"
            class="train-stops-row"
            :class="{
              'is-ride': r.inRide,
              'is-outside': !r.inRide,
              'is-pass': r.pass,
              'is-board': r.board,
              'is-alight': r.alight,
            }"
          >
            <span class="mark" aria-hidden="true"><span class="dot" /></span>
            <span class="name">
              {{ r.name }}
              <train-tag v-if="r.board" size="small" effect="dark">{{ t('stops.board') }}</train-tag>
              <train-tag v-if="r.alight" size="small" effect="dark">{{ t('stops.alight') }}</train-tag>
            </span>
            <span class="times">
              <span v-if="r.pass" class="pass">{{ t('stops.pass') }}</span>
              <span
                v-for="x in times(r)"
                :key="x.kind"
                class="time"
                :class="[`is-${x.kind}`, { estimate: r.estimate }]"
              >
                <span class="time-label">{{ x.label }}</span>
                <span class="time-value">{{ x.value }}</span>
              </span>
            </span>
          </li>
        </ol>

        <div v-if="hasPass || hasEstimate" class="train-stops-legend">
          <span v-if="hasPass">{{ t('stops.passNote') }}</span>
          <span v-if="hasEstimate">{{ t('stops.estimate') }}</span>
        </div>
      </template>
    </div>
  </train-modal>
</template>
