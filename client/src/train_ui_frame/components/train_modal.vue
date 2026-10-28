<script setup>
/**
 * 對話視窗（modal）。開啟時面板從畫面中央往四周展開；桌面版距離四邊 --train-ui-modal-inset（預設 10%），
 * 手機（寬度 ≤ 768px）佔滿整個畫面。
 *
 * v-model 控制開關；title 或 #header 為標題列；default slot 為內容（內容區自己捲動）；#footer 底部按鈕列。
 * inset：只改這個視窗的桌面版邊距（例如 '6%'、'40px 15%'）；fullscreen：桌面版也佔滿。
 * closeOnBackdrop、closeOnEsc、showClose 控制關閉方式。事件：open、opened、close、closed。
 * 關閉時內容會卸載（下次開啟重新建立）。
 */
import { computed, nextTick, onBeforeUnmount, ref, useSlots, watch } from 'vue'
import TrainIcon from './train_icon.vue'
import { trainLockScroll, trainModalStack, trainUid, trainUnlockScroll } from '../utils'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  title: { type: String, default: '' },
  inset: { type: String, default: '' },
  fullscreen: { type: Boolean, default: false },
  closeOnBackdrop: { type: Boolean, default: true },
  closeOnEsc: { type: Boolean, default: true },
  showClose: { type: Boolean, default: true },
  closeLabel: { type: String, default: 'Close' },
  bodyClass: { type: [String, Array, Object], default: '' },
})

const emit = defineEmits(['update:modelValue', 'open', 'opened', 'close', 'closed'])
const slots = useSlots()
const panel = ref(null)
const titleId = trainUid('train-modal-title')
const hasHeader = computed(() => !!(props.title || slots.header || props.showClose))
const panelStyle = computed(() => (props.inset ? { '--train-ui-modal-inset': props.inset } : null))

// 疊在最上面的視窗才回應 Esc
const stack = trainModalStack
const self = Symbol('train-modal')
let locked = false
let lastFocus = null
let downOnBackdrop = false

function close() {
  emit('update:modelValue', false)
}

function onKeydown(e) {
  if (e.key === 'Escape' && props.closeOnEsc && stack[stack.length - 1] === self) {
    e.stopPropagation()
    close()
  }
}

function onBackdropDown(e) {
  downOnBackdrop = e.target === e.currentTarget
}

function onBackdropUp(e) {
  if (downOnBackdrop && e.target === e.currentTarget && props.closeOnBackdrop) close()
  downOnBackdrop = false
}

function activate() {
  lastFocus = document.activeElement
  stack.push(self)
  document.addEventListener('keydown', onKeydown)
  if (!locked) {
    trainLockScroll()
    locked = true
  }
  emit('open')
  nextTick(() => panel.value?.focus({ preventScroll: true }))
}

function deactivate() {
  const i = stack.indexOf(self)
  if (i >= 0) stack.splice(i, 1)
  document.removeEventListener('keydown', onKeydown)
  if (locked) {
    trainUnlockScroll()
    locked = false
  }
}

watch(
  () => props.modelValue,
  (v) => {
    if (v) activate()
    else {
      deactivate()
      emit('close')
    }
  },
  { immediate: true },
)

function onAfterLeave() {
  if (lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus({ preventScroll: true })
  lastFocus = null
  emit('closed')
}

onBeforeUnmount(deactivate)
</script>

<template>
  <Teleport to="body">
    <Transition name="train-modal" @after-enter="emit('opened')" @after-leave="onAfterLeave">
      <div
        v-if="modelValue"
        class="train-modal"
        @pointerdown="onBackdropDown"
        @pointerup="onBackdropUp"
      >
        <div
          ref="panel"
          class="train-modal__panel"
          :class="{ 'is-fullscreen': fullscreen }"
          :style="panelStyle"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="title || $slots.header ? titleId : undefined"
          tabindex="-1"
        >
          <header v-if="hasHeader" class="train-modal__header">
            <div :id="titleId" class="train-modal__title"><slot name="header">{{ title }}</slot></div>
            <button
              v-if="showClose"
              type="button"
              class="train-modal__close"
              :aria-label="closeLabel"
              :title="closeLabel"
              @click="close"
            >
              <TrainIcon name="close" />
            </button>
          </header>
          <div class="train-modal__body" :class="bodyClass"><slot /></div>
          <footer v-if="$slots.footer" class="train-modal__footer"><slot name="footer" /></footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
