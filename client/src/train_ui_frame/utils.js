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

/** 相對亮度（WCAG 2.x）；不是 #RRGGBB 回 null */
export function trainLuminance(hex) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '')
  if (!m) return null
  const n = parseInt(m[1], 16)
  const lin = (c) => {
    c /= 255
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
  }
  return 0.2126 * lin(n >> 16) + 0.7152 * lin((n >> 8) & 255) + 0.0722 * lin(n & 255)
}

/**
 * 依背景色決定文字色：白字與深灰字（判斷基準固定用 #3c4a5e，各處判斷一致）取對比度（WCAG）高的——深底白字、淺底回 $dark。
 * 不是 #RRGGBB 時回白色。中間色調（高鐵橘紅、文湖線棕色）是白字
 */
const TEXT_ON_DARK_REF = '#3c4a5e'

export function trainTextOn(hex, dark = '#1f2a3d') {
  const L = trainLuminance(hex)
  if (L === null) return '#fff'
  const white = 1.05 / (L + 0.05)
  const darkContrast = (L + 0.05) / (trainLuminance(TEXT_ON_DARK_REF) + 0.05)
  return white >= darkContrast ? '#fff' : dark
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
