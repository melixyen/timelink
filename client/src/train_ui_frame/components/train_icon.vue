<script setup>
/**
 * 線條圖示（24×24 viewBox，跟著文字顏色）。
 * name：close、check、minus、plus、caret-down、caret-up、arrow-right、search、swap、clock、
 *       route、filter、info、success、warning、error、loading
 */
import { computed } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: '1em' },
})

const PATHS = {
  close: 'M6 6l12 12M18 6L6 18',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  minus: 'M6 12h12',
  plus: 'M12 6v12M6 12h12',
  'caret-down': 'M7 10l5 5 5-5',
  'caret-up': 'M7 14l5-5 5 5',
  'arrow-right': 'M5 12h14M13 6l6 6-6 6',
  search: 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM20 20l-4-4',
  swap: 'M7 4v15M3.5 7.5L7 4l3.5 3.5M17 20V5M13.5 16.5L17 20l3.5-3.5',
  clock: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 7.5V12l3 2',
  route: 'M6 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM18 15.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM8.5 6H15a3 3 0 0 1 0 6H9a3 3 0 0 0 0 6h6.5',
  filter: 'M4 5h16l-6 7.5V19l-4 1.5v-8z',
  info: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 11v5.5M12 7.5v.5',
  success: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM8 12.5l2.8 2.8L16.5 9.5',
  warning: 'M12 3.5L2.5 20h19zM12 10v4.5M12 17v.5',
  error: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM9 9l6 6M15 9l-6 6',
  loading: 'M12 3a9 9 0 1 0 9 9',
}

const d = computed(() => PATHS[props.name] || '')
const dim = computed(() => (typeof props.size === 'number' ? `${props.size}px` : props.size))
</script>

<template>
  <svg
    class="train-icon"
    :class="{ 'is-spin': name === 'loading' }"
    :width="dim"
    :height="dim"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    aria-hidden="true"
  >
    <path :d="d" />
  </svg>
</template>
