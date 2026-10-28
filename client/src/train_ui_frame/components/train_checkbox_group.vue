<script setup>
/**
 * 多選群組：v-model 是陣列，裡面的 <train-checkbox :value> 勾選時加入、取消時移除。
 * max：最多可勾幾個（達到後其他未勾的會停用）
 */
import { computed, provide } from 'vue'

const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  disabled: { type: Boolean, default: false },
  max: { type: Number, default: 0 },
  vertical: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

provide('trainCheckboxGroup', {
  has: (v) => props.modelValue.includes(v),
  disabled: computed(() => props.disabled),
  full: computed(() => props.max > 0 && props.modelValue.length >= props.max),
  toggle(v, on) {
    const next = props.modelValue.filter((x) => x !== v)
    if (on) next.push(v)
    emit('update:modelValue', next)
    emit('change', next)
  },
})
</script>

<template>
  <div class="train-checkbox-group" :class="{ 'is-vertical': vertical }" role="group"><slot /></div>
</template>
