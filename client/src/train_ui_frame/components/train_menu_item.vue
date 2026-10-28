<script setup>
/**
 * 選單項目。to：路由位置（有 vue-router 時渲染 router-link，依路由標示目前項目）；
 * index：沒有 to 時的識別值，與 <train-menu> 的 v-model 比對；icon：train-icon 名稱
 */
import { computed, getCurrentInstance, inject } from 'vue'
import TrainIcon from './train_icon.vue'

const props = defineProps({
  index: { type: [String, Number], default: '' },
  to: { type: [String, Object], default: null },
  icon: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const menu = inject('trainMenu', null)
const app = getCurrentInstance()?.appContext
const RouterLink = computed(() => (props.to ? app?.components.RouterLink : null))

const selfActive = computed(() => !!menu && props.index !== '' && menu.active.value === props.index)

function onClick() {
  if (!props.disabled && menu && props.index !== '') menu.select(props.index)
}
</script>

<template>
  <component :is="RouterLink" v-if="RouterLink" v-slot="{ href, navigate, isActive }" :to="to" custom>
    <a
      :href="href"
      class="train-menu-item"
      :class="{ 'is-active': isActive, 'is-disabled': disabled }"
      :aria-current="isActive ? 'page' : undefined"
      @click="navigate($event), onClick()"
    >
      <TrainIcon v-if="icon" :name="icon" />
      <slot />
    </a>
  </component>
  <button
    v-else
    type="button"
    class="train-menu-item"
    :class="{ 'is-active': selfActive, 'is-disabled': disabled }"
    :disabled="disabled"
    @click="onClick"
  >
    <TrainIcon v-if="icon" :name="icon" />
    <slot />
  </button>
</template>
