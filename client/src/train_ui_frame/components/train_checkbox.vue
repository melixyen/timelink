<script setup>
/**
 * 核取方塊。單獨使用時 v-model 是 boolean；放在 <train-checkbox-group> 裡用 value 指定代表的值。
 * indeterminate：半選（全選鈕用）；border：外框樣式
 */
import { computed, inject } from 'vue'
import TrainIcon from './train_icon.vue'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  value: { type: null, default: undefined },
  label: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  indeterminate: { type: Boolean, default: false },
  border: { type: Boolean, default: false },
  size: { type: String, default: 'default' },
})

const emit = defineEmits(['update:modelValue', 'change'])
const group = inject('trainCheckboxGroup', null)

const checked = computed(() => (group && props.value !== undefined ? group.has(props.value) : props.modelValue))
const isDisabled = computed(
  () => props.disabled || !!group?.disabled.value || (!!group?.full.value && !checked.value),
)

function onChange(e) {
  const on = e.target.checked
  if (group && props.value !== undefined) group.toggle(props.value, on)
  else emit('update:modelValue', on)
  emit('change', on)
}
</script>

<template>
  <label
    class="train-checkbox"
    :class="[
      size !== 'default' && `train-checkbox--${size}`,
      { 'is-checked': checked, 'is-disabled': isDisabled, 'is-indeterminate': indeterminate, 'is-bordered': border },
    ]"
  >
    <input
      class="train-checkbox__original"
      type="checkbox"
      :checked="checked"
      :disabled="isDisabled"
      :aria-checked="indeterminate ? 'mixed' : checked"
      @change="onChange"
    />
    <span class="train-checkbox__box">
      <TrainIcon v-if="indeterminate" name="minus" />
      <TrainIcon v-else-if="checked" name="check" />
    </span>
    <span v-if="label || $slots.default" class="train-checkbox__label"><slot>{{ label }}</slot></span>
  </label>
</template>
