/**
 * Train UI 共用小工具（元件內部使用，也可以從 '@/train_ui_frame' 匯入）。
 */
import { onBeforeUnmount, onMounted } from 'vue'

let seed = 0

/** 產生頁面內唯一的 id（label for、aria-labelledby 用） */
export function trainUid(prefix = 'train') {
  seed += 1
  return `${prefix}-${seed}`
}

/** 依背景色決定文字色（深底白字、淺底深字）；不是 #RRGGBB 時回白色 */
export function trainTextOn(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '')
  if (!m) return '#fff'
  const n = parseInt(m[1], 16)
  const lin = (c) => {
    c /= 255
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  }
  const L = 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255)
  return L > 0.45 ? '#1f2a3d' : '#fff'
}

/** 點擊元素外面時呼叫 handler（下拉選單、彈出面板關閉用） */
export function useTrainClickOutside(elRef, handler) {
  function onDown(e) {
    const el = elRef.value
    if (el && !el.contains(e.target)) handler(e)
  }
  onMounted(() => document.addEventListener('pointerdown', onDown))
  onBeforeUnmount(() => document.removeEventListener('pointerdown', onDown))
}

/** 目前開著的 train-modal（後開的在後面；只有最上面的回應 Esc） */
export const trainModalStack = []

/** 鎖定頁面捲動（可重疊呼叫，最後一個解鎖才恢復） */
let lockCount = 0
let savedOverflow = ''
let savedPadding = ''

export function trainLockScroll() {
  if (typeof document === 'undefined') return
  if (lockCount === 0) {
    const body = document.body
    const bar = window.innerWidth - document.documentElement.clientWidth
    savedOverflow = body.style.overflow
    savedPadding = body.style.paddingRight
    body.style.overflow = 'hidden'
    if (bar > 0) body.style.paddingRight = `${bar}px`
  }
  lockCount += 1
}

export function trainUnlockScroll() {
  if (typeof document === 'undefined' || lockCount === 0) return
  lockCount -= 1
  if (lockCount === 0) {
    document.body.style.overflow = savedOverflow
    document.body.style.paddingRight = savedPadding
  }
}
