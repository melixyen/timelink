<script setup>
/** 載入中指示。text 或 default slot 為文字；block 時置中佔一整行 */
import TrainIcon from './train_icon.vue'

defineProps({
  text: { type: String, default: '' },
  size: { type: [Number, String], default: '1.15em' },
  block: { type: Boolean, default: false },
})
</script>

<template>
  <span class="train-spinner" :class="{ 'is-block': block }" role="status">
    <TrainIcon name="loading" :size="size" />
    <span v-if="text || $slots.default" class="train-spinner__text"><slot>{{ text }}</slot></span>
  </span>
</template>
