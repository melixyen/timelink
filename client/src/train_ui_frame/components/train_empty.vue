<script setup>
/** 空狀態。description 或 default slot 為說明；#image 換掉預設圖示；#actions 放按鈕 */
import TrainIcon from './train_icon.vue'

defineProps({
  description: { type: String, default: '' },
  icon: { type: String, default: 'route' },
})
</script>

<template>
  <div class="train-empty">
    <div class="train-empty__image">
      <slot name="image"><TrainIcon :name="icon" size="44" /></slot>
    </div>
    <div class="train-empty__desc"><slot>{{ description }}</slot></div>
    <div v-if="$slots.actions" class="train-empty__actions"><slot name="actions" /></div>
  </div>
</template>
