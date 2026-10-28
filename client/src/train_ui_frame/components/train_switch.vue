<script setup>
/** 開關。v-model 是 boolean；activeText / inactiveText 顯示在開關右側 */
const props = defineProps({
  modelValue: { type: Boolean, default: false },
  activeText: { type: String, default: '' },
  inactiveText: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

function toggle() {
  if (props.disabled) return
  emit('update:modelValue', !props.modelValue)
  emit('change', !props.modelValue)
}
</script>

<template>
  <button
    type="button"
    role="switch"
    class="train-switch"
    :class="{ 'is-checked': modelValue, 'is-disabled': disabled }"
    :aria-checked="modelValue"
    :disabled="disabled"
    @click="toggle"
  >
    <span class="train-switch__core"><span class="train-switch__action"></span></span>
    <span v-if="modelValue ? activeText : inactiveText" class="train-switch__label">
      {{ modelValue ? activeText : inactiveText }}
    </span>
  </button>
</template>
