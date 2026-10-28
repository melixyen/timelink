<script setup>
/**
 * 文字輸入框。v-model 字串；prefix-icon / suffix-icon 或 #prefix / #suffix slot；clearable 顯示清除鈕。
 * 事件：enter（按 Enter）、clear；expose focus()、blur()
 */
import { computed, ref, useAttrs } from 'vue'
import TrainIcon from './train_icon.vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  modelValue: { type: [String, Number], default: '' },
  type: { type: String, default: 'text' },
  size: { type: String, default: 'default' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  clearable: { type: Boolean, default: false },
  prefixIcon: { type: String, default: '' },
  suffixIcon: { type: String, default: '' },
})

const emit = defineEmits(['update:modelValue', 'enter', 'clear'])
const attrs = useAttrs()
const input = ref(null)

const rootAttrs = computed(() => ({ class: attrs.class, style: attrs.style }))
const inputAttrs = computed(() => {
  const { class: _c, style: _s, ...rest } = attrs
  return rest
})
const showClear = computed(() => props.clearable && !props.disabled && String(props.modelValue ?? '') !== '')

function onInput(e) {
  emit('update:modelValue', e.target.value)
}

function clear() {
  emit('update:modelValue', '')
  emit('clear')
  input.value?.focus()
}

defineExpose({
  focus: () => input.value?.focus(),
  blur: () => input.value?.blur(),
})
</script>

<template>
  <div
    v-bind="rootAttrs"
    class="train-input"
    :class="[size !== 'default' && `train-input--${size}`, { 'is-disabled': disabled }]"
  >
    <span v-if="prefixIcon || $slots.prefix" class="train-input__prefix">
      <slot name="prefix"><TrainIcon :name="prefixIcon" /></slot>
    </span>
    <input
      ref="input"
      v-bind="inputAttrs"
      class="train-input__inner"
      :type="type"
      :value="modelValue"
      :placeholder="placeholder"
      :disabled="disabled"
      @input="onInput"
      @keydown.enter="emit('enter', $event)"
    />
    <button v-if="showClear" type="button" class="train-input__clear" tabindex="-1" aria-label="clear" @click="clear">
      <TrainIcon name="close" />
    </button>
    <span v-if="suffixIcon || $slots.suffix" class="train-input__suffix">
      <slot name="suffix"><TrainIcon :name="suffixIcon" /></slot>
    </span>
  </div>
</template>
