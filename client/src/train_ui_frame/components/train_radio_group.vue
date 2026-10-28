<script setup>
/**
 * 單選群組：v-model 是選中的值，裡面放 <train-radio :value>。
 * button：改成分段按鈕的樣式；options：[{ label, value }] 時可以不寫子元件
 */
import { computed, provide } from 'vue'
import TrainRadio from './train_radio.vue'
import { trainUid } from '../utils'

const props = defineProps({
  modelValue: { type: null, default: '' },
  options: { type: Array, default: () => [] },
  button: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  size: { type: String, default: 'default' },
})

const emit = defineEmits(['update:modelValue', 'change'])

provide('trainRadioGroup', {
  name: trainUid('train-radio'),
  current: computed(() => props.modelValue),
  disabled: computed(() => props.disabled),
  button: computed(() => props.button),
  size: computed(() => props.size),
  select(v) {
    emit('update:modelValue', v)
    emit('change', v)
  },
})
</script>

<template>
  <div class="train-radio-group" :class="{ 'is-button': button }" role="radiogroup">
    <slot>
      <TrainRadio v-for="opt in options" :key="String(opt.value)" :value="opt.value" :disabled="opt.disabled">
        {{ opt.label }}
      </TrainRadio>
    </slot>
  </div>
</template>
