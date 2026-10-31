import { onBeforeUnmount, onMounted, ref } from 'vue'

/**
 * 媒體查詢（會隨視窗大小變動更新）。
 * @param {string} query 媒體查詢字串，例如 '(max-width: 640px)'
 */
export function useMediaQuery(query) {
  const matches = ref(false)
  let mql = null

  const update = () => {
    matches.value = !!mql && mql.matches
  }

  onMounted(() => {
    if (typeof window === 'undefined' || !window.matchMedia) return
    mql = window.matchMedia(query)
    update()
    mql.addEventListener('change', update)
  })

  onBeforeUnmount(() => {
    if (mql) mql.removeEventListener('change', update)
  })

  return matches
}

/** 手機模式（與 main.css 的 breakpoints 一致） */
export function useIsMobile() {
  return useMediaQuery('(max-width: 640px)')
}
