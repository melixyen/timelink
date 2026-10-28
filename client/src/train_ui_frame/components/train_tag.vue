<script setup>
/**
 * 標籤。type：primary、success、warning、danger、info；effect：light（淺底）、dark（實心）、plain（白底框線）。
 * color：自訂底色（路線色），文字色自動依底色深淺決定。closable 顯示關閉鈕（close 事件）；
 * clickable 時是可聚焦的按鈕（click 事件）。
 */
import { computed } from 'vue'
import TrainIcon from './train_icon.vue'
import { trainTextOn } from '../utils'

const props = defineProps({
  type: { type: String, default: 'primary' },
  effect: { type: String, default: 'light' },
  size: { type: String, default: 'default' },
  color: { type: String, default: '' },
  round: { type: Boolean, default: true },
  closable: { type: Boolean, default: false },
  clickable: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'click'])

const style = computed(() =>
  props.color ? { background: props.color, borderColor: props.color, color: trainTextOn(props.color) } : null,
)
</script>

<template>
  <component
    :is="clickable ? 'button' : 'span'"
    :type="clickable ? 'button' : undefined"
    class="train-tag"
    :class="[
      `train-tag--${type}`,
      `train-tag--${effect}`,
      size !== 'default' && `train-tag--${size}`,
      { 'is-round': round, 'is-clickable': clickable, 'is-custom': !!color },
    ]"
    :style="style"
    @click="emit('click', $event)"
  >
    <slot />
    <button v-if="closable" type="button" class="train-tag__close" aria-label="close" @click.stop="emit('close', $event)">
      <TrainIcon name="close" />
    </button>
  </component>
</template>
