<script setup>
/**
 * 彈出面板：#trigger 是觸發區（slot props：{ open, toggle, visible }），default slot 是面板內容
 * （slot props：{ close }）。v-model 控制開關（也可以不綁，元件自己管）。
 * 點面板外面或按 Esc 關閉。width：'trigger'＝與觸發區同寬（預設）、'auto'，或任意 CSS 寬度。
 * placement：bottom-start（預設）、bottom-end
 */
import { computed, ref, watch } from 'vue'
import { useTrainClickOutside } from '../utils'

const props = defineProps({
  modelValue: { type: Boolean, default: undefined },
  width: { type: String, default: 'trigger' },
  placement: { type: String, default: 'bottom-start' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'open', 'close'])
const root = ref(null)
const local = ref(false)

const visible = computed(() => (props.modelValue === undefined ? local.value : props.modelValue))

function set(v) {
  if (props.disabled && v) return
  local.value = v
  emit('update:modelValue', v)
}

const open = () => set(true)
const close = () => set(false)
const toggle = () => set(!visible.value)

watch(visible, (v) => emit(v ? 'open' : 'close'))

useTrainClickOutside(root, () => {
  if (visible.value) close()
})

const panelStyle = computed(() => {
  if (props.width === 'trigger') return { left: 0, right: 0 }
  if (props.width === 'auto') return null
  return { width: props.width }
})

defineExpose({ open, close, toggle })
</script>

<template>
  <div ref="root" class="train-dropdown" :class="{ 'is-open': visible }" @keydown.esc="close">
    <slot name="trigger" :open="open" :toggle="toggle" :visible="visible" />
    <Transition name="train-fade-down">
      <div v-if="visible" class="train-dropdown__panel" :class="`is-${placement}`" :style="panelStyle">
        <slot :close="close" />
      </div>
    </Transition>
  </div>
</template>
