<script setup>
/**
 * 按鈕。給 to 時渲染成 router-link、給 href 時渲染成 <a>，其餘是 <button>。
 * type：default、primary、success、warning、danger、info、text
 */
import { computed, getCurrentInstance } from 'vue'
import TrainIcon from './train_icon.vue'

const props = defineProps({
  type: { type: String, default: 'default' },
  size: { type: String, default: 'default' },
  plain: { type: Boolean, default: false },
  round: { type: Boolean, default: false },
  circle: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  active: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
  icon: { type: String, default: '' },
  nativeType: { type: String, default: 'button' },
  to: { type: [String, Object], default: null },
  href: { type: String, default: '' },
})

const emit = defineEmits(['click'])
const app = getCurrentInstance()?.appContext

const tag = computed(() => {
  if (props.to && app?.components.RouterLink) return app.components.RouterLink
  if (props.href || props.to) return 'a'
  return 'button'
})

const attrs = computed(() => {
  if (tag.value === 'button') return { type: props.nativeType, disabled: props.disabled || props.loading }
  if (tag.value === 'a') return { href: props.href || (typeof props.to === 'string' ? props.to : '#') }
  return { to: props.to }
})

const classes = computed(() => [
  'train-button',
  `train-button--${props.type}`,
  props.size !== 'default' && `train-button--${props.size}`,
  {
    'is-plain': props.plain,
    'is-round': props.round,
    'is-circle': props.circle,
    'is-block': props.block,
    'is-active': props.active,
    'is-disabled': props.disabled,
    'is-loading': props.loading,
  },
])

function onClick(e) {
  if (props.disabled || props.loading) {
    e.preventDefault()
    return
  }
  emit('click', e)
}
</script>

<template>
  <component :is="tag" :class="classes" v-bind="attrs" :aria-disabled="disabled || undefined" @click="onClick">
    <TrainIcon v-if="loading" name="loading" class="train-button__icon" />
    <TrainIcon v-else-if="icon" :name="icon" class="train-button__icon" />
    <span v-if="$slots.default" class="train-button__text"><slot /></span>
  </component>
</template>
