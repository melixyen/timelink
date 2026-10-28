<script setup>
/**
 * 下拉選擇（原生 <select>，手機上使用系統選單）。
 * options：[{ label, value, disabled? }]，或在 default slot 自己放 <option :value>。
 * v-model 保留選項值的型別（數字就是數字）。
 */
import { computed, useAttrs } from 'vue'
import TrainIcon from './train_icon.vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: null, default: '' },
  options: { type: Array, default: () => [] },
  size: { type: String, default: 'default' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])
const attrs = useAttrs()

const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }))
const selectAttrs = computed(() => {
  const { class: _c, style: _s, ...rest } = attrs
  return rest
})

const inner = computed({
  get: () => props.modelValue,
  set: (v) => {
    emit('update:modelValue', v)
    emit('change', v)
  },
})
</script>

<template>
  <div
    v-bind="rootAttrs"
    class="train-select"
    :class="[size !== 'default' && `train-select--${size}`, { 'is-disabled': disabled }]"
  >
    <select v-bind="selectAttrs" v-model="inner" class="train-select__inner" :disabled="disabled">
      <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
      <slot>
        <option v-for="opt in options" :key="String(opt.value)" :value="opt.value" :disabled="opt.disabled">
          {{ opt.label }}
        </option>
      </slot>
    </select>
    <TrainIcon name="caret-down" class="train-select__caret" />
  </div>
</template>
