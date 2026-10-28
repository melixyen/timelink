<script setup>
/**
 * 提示訊息。type：info、success、warning、error；title 或 default slot 為內容。
 * simple：只有文字與圖示、沒有底色（表單旁的錯誤文字）；closable 顯示關閉鈕（close 事件）
 */
import TrainIcon from './train_icon.vue'

defineProps({
  type: { type: String, default: 'info' },
  title: { type: String, default: '' },
  simple: { type: Boolean, default: false },
  showIcon: { type: Boolean, default: true },
  closable: { type: Boolean, default: false },
})

const emit = defineEmits(['close'])
</script>

<template>
  <div class="train-alert" :class="[`train-alert--${type}`, { 'is-simple': simple }]" role="alert">
    <TrainIcon v-if="showIcon" :name="type" class="train-alert__icon" />
    <div class="train-alert__content">
      <div v-if="title" class="train-alert__title">{{ title }}</div>
      <div v-if="$slots.default" class="train-alert__desc"><slot /></div>
    </div>
    <button v-if="closable" type="button" class="train-alert__close" aria-label="close" @click="emit('close')">
      <TrainIcon name="close" />
    </button>
  </div>
</template>
