import { getCurrentScope, onScopeDispose, readonly, ref } from 'vue'

/** 同一個媒體查詢字串共用一個 MediaQueryList 與監聽（依使用數量決定何時移除） */
const shared = new Map()

function listen(mql, fn) {
  if (mql.addEventListener) mql.addEventListener('change', fn)
  else mql.addListener(fn) // Safari 13 以前
}

function unlisten(mql, fn) {
  if (mql.removeEventListener) mql.removeEventListener('change', fn)
  else mql.removeListener(fn)
}

/**
 * 媒體查詢（會隨視窗大小變動更新）。
 * 建立時就同步讀取目前結果，第一次渲染即正確，不會先以桌面版畫一次再切換。
 * @param {string} query 媒體查詢字串，例如 '(max-width: 640px)'
 * @returns {Readonly<import('vue').Ref<boolean>>}
 */
export function useMediaQuery(query) {
  if (typeof window === 'undefined' || !window.matchMedia) return readonly(ref(false))

  let entry = shared.get(query)
  if (!entry) {
    const mql = window.matchMedia(query)
    const matches = ref(mql.matches)
    const update = (e) => {
      matches.value = e.matches
    }
    listen(mql, update)
    entry = { mql, matches: readonly(matches), update, users: 0 }
    shared.set(query, entry)
  }
  entry.users++

  if (getCurrentScope()) {
    onScopeDispose(() => {
      entry.users--
      if (entry.users === 0) {
        unlisten(entry.mql, entry.update)
        shared.delete(query)
      }
    })
  }

  return entry.matches
}

/** 手機模式（與 main.css 的 breakpoints 一致） */
export function useIsMobile() {
  return useMediaQuery('(max-width: 640px)')
}
