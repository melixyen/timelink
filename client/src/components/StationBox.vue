<script setup>
/**
 * 車站欄位的排版元件（對應表格 td.station 裡的 station-box）。
 *
 * 所有要顯示的內容都由外部以 props 傳入，本元件只負責排版，
 * 日後要調整版面（加箭頭、改站名樣式、改線條長度）只改這裡或傳入不同的 slot。
 *
 * Props:
 *   fromName  出發站名稱
 *   toName    到達站名稱
 *   color     路線代表色（線條顏色）
 *   rail      是否為鐵路系統（台鐵、高鐵）：線條中央加一段白色缺口，
 *             模仿地圖圖例中黑白相間的軌道樣式
 *
 * Slots（皆為選用，預設走上面的 props）：
 *   from    出發站
 *   middle  中間線條
 *   to      到達站
 */
const props = defineProps({
  fromName: { type: String, default: '' },
  toName: { type: String, default: '' },
  color: { type: String, default: '#999999' },
  rail: { type: Boolean, default: false },
})
</script>

<template>
  <div class="station-box">
    <div class="value from"><slot name="from">{{ props.fromName }}</slot></div>
    <div class="deep">
      <div class="line-color" :class="{ rail: props.rail }" :style="{ background: props.color }">
        <slot name="middle" />
      </div>
    </div>
    <div class="value to"><slot name="to">{{ props.toName }}</slot></div>
  </div>
</template>
