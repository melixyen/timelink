<script setup>
/**
 * 文字連結。to → router-link、href → <a>，都沒有時是 <button>（click 事件）。
 * type：primary（預設）、default、danger；underline：滑過時加底線
 */
import { computed, getCurrentInstance } from 'vue'

const props = defineProps({
  type: { type: String, default: 'primary' },
  to: { type: [String, Object], default: null },
  href: { type: String, default: '' },
  target: { type: String, default: '' },
  underline: { type: Boolean, default: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['click'])
const app = getCurrentInstance()?.appContext

const tag = computed(() => {
  if (props.to && app?.components.RouterLink) return app.components.RouterLink
  if (props.href) return 'a'
  return 'button'
})

const attrs = computed(() => {
  if (tag.value === 'button') return { type: 'button', disabled: props.disabled }
  if (tag.value === 'a') {
    return { href: props.href, target: props.target || undefined, rel: props.target === '_blank' ? 'noopener' : undefined }
  }
  return { to: props.to }
})
</script>

<template>
  <component
    :is="tag"
    v-bind="attrs"
    class="train-link"
    :class="[`train-link--${type}`, { 'is-underline': underline, 'is-disabled': disabled }]"
    @click="!disabled && emit('click', $event)"
  >
    <slot />
  </component>
</template>
