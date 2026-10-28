<script setup>
import { onMounted } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useI18n } from 'vue-i18n'
import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
import { config } from '@/config'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'

const { t } = useI18n()
const publicInfo = usePublicInfoStore()
const publicStation = usePublicStationStore()
const publicLine = usePublicLineStore()

const navItems = [
  { to: '/', labelKey: 'nav.home' },
  { to: '/search', labelKey: 'nav.search' },
  { to: '/stations', labelKey: 'nav.stations' },
  { to: '/lines', labelKey: 'nav.lines' },
]

// 公共資料只載入一次，之後切路由不再重新請求
onMounted(() => {
  publicInfo.ensureLoaded().catch(() => {})
  publicStation.ensureLoaded().catch(() => {})
  publicLine.ensureLoaded().catch(() => {})
})
</script>

<template>
  <div class="app">
    <header class="app-header">
      <div class="brand">
        <router-link to="/" class="brand-link">
          {{ config.appName }} <span class="sub">{{ config.appSubtitle }}</span>
        </router-link>
      </div>
      <train-menu class="app-nav">
        <train-menu-item v-for="item in navItems" :key="item.to" :to="item.to">
          {{ t(item.labelKey) }}
        </train-menu-item>
      </train-menu>
      <div class="header-right">
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
