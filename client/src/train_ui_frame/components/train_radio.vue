<script setup>
/**
 * 單選鈕。放在 <train-radio-group> 裡使用；單獨使用時 v-model 與 value 相同即為選中。
 */
import { computed, inject } from 'vue'

const props = defineProps({
  modelValue: { type: null, default: undefined },
  value: { type: null, required: true },
  label: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])
const group = inject('trainRadioGroup', null)

const checked = computed(() => (group ? group.current.value : props.modelValue) === props.value)
const isDisabled = computed(() => props.disabled || !!group?.disabled.value)
const isButton = computed(() => !!group?.button.value)
const size = computed(() => group?.size.value || 'default')

function onChange() {
  if (group) group.select(props.value)
  else emit('update:modelValue', props.value)
  emit('change', props.value)
}
</script>

<template>
  <label
    :class="[
      isButton ? 'train-radio-button' : 'train-radio',
      size !== 'default' && `${isButton ? 'train-radio-button' : 'train-radio'}--${size}`,
      { 'is-checked': checked, 'is-disabled': isDisabled },
    ]"
  >
    <input
      class="train-radio__original"
      type="radio"
      :name="group?.name"
      :checked="checked"
      :disabled="isDisabled"
      @change="onChange"
    />
    <span v-if="!isButton" class="train-radio__dot"></span>
    <span class="train-radio__label"><slot>{{ label }}</slot></span>
  </label>
</template>
