<script setup>
/**
 * 選單（導覽列）。mode：horizontal（預設）、vertical；裡面放 <train-menu-item>。
 * 項目有 to 時依路由判斷目前項目；沒有 to 時用 v-model（目前項目的 index），點擊發出 select
 */
import { computed, provide } from 'vue'

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  mode: { type: String, default: 'horizontal' },
})

const emit = defineEmits(['update:modelValue', 'select'])

provide('trainMenu', {
  active: computed(() => props.modelValue),
  select(index) {
    emit('update:modelValue', index)
    emit('select', index)
  },
})
</script>

<template>
  <nav class="train-menu" :class="`train-menu--${mode}`">
    <slot />
  </nav>
</template>
