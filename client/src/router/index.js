import { createRouter, createWebHashHistory } from 'vue-router'
import { watch } from 'vue'
import { i18n, t } from '@/i18n'
import { config } from '@/config'

const routes = [
  {
    path: '/',
    name: 'home',
    component: () => import('@/views/HomeView.vue'),
    meta: { titleKey: 'nav.home' },
  },
  {
    path: '/search',
    name: 'search',
    component: () => import('@/views/SearchView.vue'),
    meta: { titleKey: 'nav.search' },
  },
  {
    path: '/stations',
    name: 'stations',
    component: () => import('@/views/StationsView.vue'),
    meta: { titleKey: 'nav.stations' },
  },
  {
    path: '/lines',
    name: 'lines',
    component: () => import('@/views/LinesView.vue'),
    meta: { titleKey: 'nav.lines' },
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

function applyTitle(route) {
  if (typeof document === 'undefined') return
  const key = route.meta?.titleKey
  const label = key ? t(key) : ''
  document.title = label ? `${label} - ${config.appName}` : config.appName
}

const router = createRouter({
  // 佈署在靜態主機（沒有 rewrite 規則）時使用 hash 模式，
  // 進入頁面只載入一次，之後只切換 #hash 路由
  history: createWebHashHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.afterEach((to) => applyTitle(to))

// 切換語言時同步更新網頁標題
watch(
  () => i18n.global.locale.value,
  () => applyTitle(router.currentRoute.value),
)

export default router
