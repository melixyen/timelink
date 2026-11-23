<script setup>
/**
 * 時刻欄位的排版元件（對應表格 td.time 裡的 time-box）。
 *
 * 所有要顯示的內容都由外部以 props 傳入，本元件只負責排版，
 * 日後要調整版面（加箭頭、改順序、改樣式）只改這裡或傳入不同的 slot。
 *
 * Props:
 *   type          'ride'｜'walk'，決定顯示乘車時間或步行時間
 *   departureTime 出發時間字串
 *   arrivalTime   到達時間字串
 *   duration      乘車時間（已格式化的字串）
 *   walkText      步行時間文字
 *   departureMark 出發時間後的跨日標示（例如「(+1 日)」，空字串不顯示）
 *   arrivalMark   到達時間後的跨日標示
 *
 * Slots（皆為選用，預設走上面的 props）：
 *   start   出發端
 *   middle  中間（乘車時間／步行時間）
 *   end     到達端
 */
const props = defineProps({
  type: { type: String, default: 'ride' },
  departureTime: { type: String, default: '' },
  arrivalTime: { type: String, default: '' },
  duration: { type: String, default: '' },
  walkText: { type: String, default: '' },
  departureMark: { type: String, default: '' },
  arrivalMark: { type: String, default: '' },
})
</script>

<template>
  <div class="time-box" :class="{ walk: props.type === 'walk' }">
    <template v-if="props.type === 'ride'">
      <div class="value start"><slot name="start">{{ props.departureTime }}<span v-if="props.departureMark" class="day-mark">{{ props.departureMark }}</span></slot></div>
      <div class="deep"><div class="minute"><slot name="middle">{{ props.duration }}</slot></div></div>
      <div class="value end"><slot name="end">{{ props.arrivalTime }}<span v-if="props.arrivalMark" class="day-mark">{{ props.arrivalMark }}</span></slot></div>
    </template>
    <template v-else>
      <div class="minute"><slot name="middle">{{ props.walkText }}</slot></div>
    </template>
  </div>
</template>
