<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { config } from '@/config'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'

const { t } = useI18n()
const route = useRoute()
const publicInfo = usePublicInfoStore()
const publicStation = usePublicStationStore()
const publicLine = usePublicLineStore()

const navItems = [
  { to: '/', labelKey: 'nav.home' },
  { to: '/search', labelKey: 'nav.search' },
  { to: '/stations', labelKey: 'nav.stations' },
  { to: '/lines', labelKey: 'nav.lines' },
]

// 手機模式：導覽列與語言切換預設收合，點了才展開（切換路由、按 Esc、點選單外面都會收合）
const navOpen = ref(false)
const headerEl = ref(null)
const navToggleEl = ref(null)

watch(
  () => route.path,
  () => {
    navOpen.value = false
  },
)

function onKeydown(e) {
  if (e.key !== 'Escape' || !navOpen.value) return
  navOpen.value = false
  navToggleEl.value?.$el?.focus?.()
}

function onPointerDown(e) {
  if (navOpen.value && headerEl.value && !headerEl.value.contains(e.target)) navOpen.value = false
}

// 標題列實際高度寫到 CSS 變數 --app-header-height，讓下方 sticky 元素（例如收合的查詢列）貼齊，
// 選單展開後標題列變高也會跟著更新
let headerObserver = null
function syncHeaderHeight() {
  if (!headerEl.value) return
  document.documentElement.style.setProperty('--app-header-height', `${headerEl.value.offsetHeight}px`)
}

onMounted(() => {
  document.addEventListener('keydown', onKeydown)
  document.addEventListener('pointerdown', onPointerDown)
  syncHeaderHeight()
  if (typeof ResizeObserver !== 'undefined') {
    headerObserver = new ResizeObserver(syncHeaderHeight)
    headerObserver.observe(headerEl.value)
  }
})

onBeforeUnmount(() => {
  document.removeEventListener('keydown', onKeydown)
  document.removeEventListener('pointerdown', onPointerDown)
  headerObserver?.disconnect()
})

// 公共資料只載入一次，之後切路由不再重新請求
onMounted(() => {
  publicInfo.ensureLoaded().catch(() => {})
  publicStation.ensureLoaded().catch(() => {})
  publicLine.ensureLoaded().catch(() => {})
})
</script>

<template>
  <div class="app">
    <header ref="headerEl" class="app-header" :class="{ 'is-open': navOpen }">
      <div class="brand">
        <router-link to="/" class="brand-link">
          {{ config.appName }} <span class="sub">{{ config.appSubtitle }}</span>
        </router-link>
      </div>
      <train-button
        ref="navToggleEl"
        class="nav-toggle"
        size="small"
        round
        icon="menu"
        aria-controls="app-nav app-header-right"
        :aria-expanded="navOpen ? 'true' : 'false'"
        @click="navOpen = !navOpen"
      >
        {{ navOpen ? t('nav.close') : t('nav.menu') }}
      </train-button>
      <train-menu id="app-nav" class="app-nav">
        <train-menu-item v-for="item in navItems" :key="item.to" :to="item.to">
          {{ t(item.labelKey) }}
        </train-menu-item>
      </train-menu>
      <div id="app-header-right" class="header-right">
        <LocaleSwitcher />
      </div>
    </header>

    <main class="app-main">
      <RouterView />
    </main>

    <footer class="app-footer">
      <i18n-t keypath="footer.notice" tag="p">
        <template #tdx>
          <train-link :href="config.dataSourceUrl" target="_blank">{{ config.dataSourceName }}</train-link>
        </template>
        <template #license>
          <train-link :href="config.licenseUrl" target="_blank">{{ config.licenseName }}</train-link>
        </template>
      </i18n-t>
      <p class="meta">
        <train-link v-if="config.homepageUrl" :href="config.homepageUrl" target="_blank">{{ config.appName }}</train-link>
        <span v-else>{{ config.appName }}</span>
        <span v-if="config.orgName">・{{ config.orgName }}</span>
        <span>・{{ config.appSubtitle }}</span>
        <span>・v{{ config.appVersion }}</span>
        <span v-if="config.contactEmail">
          ・<train-link :href="`mailto:${config.contactEmail}`">{{ config.contactEmail }}</train-link>
        </span>
      </p>
    </footer>
  </div>
</template>
