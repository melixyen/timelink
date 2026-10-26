<script setup>
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { config } from '@/config'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicLineStore } from '@/stores/publicLine'

const { t } = useI18n()
const stationStore = usePublicStationStore()
const lineStore = usePublicLineStore()

onMounted(() => {
  stationStore.ensureLoaded().catch(() => {})
  lineStore.ensureLoaded().catch(() => {})
})
</script>

<template>
  <div class="page home-page">
    <section class="panel">
      <h2 class="panel-title">{{ t('home.title') }}</h2>
      <p>{{ t('home.intro') }}</p>
      <ul class="help-list">
        <li>
          <b>{{ t('home.help.station.title') }}</b>{{ t('home.help.station.body') }}
        </li>
        <li>
          <b>{{ t('home.help.time.title') }}</b>{{ t('home.help.time.body', { hours: config.maxRangeHours }) }}
        </li>
        <li>
          <b>{{ t('home.help.week.title') }}</b>{{ t('home.help.week.body') }}
        </li>
        <li>
          <b>{{ t('home.help.by.title') }}</b>{{ t('home.help.by.body') }}
        </li>
        <li>
          <b>{{ t('home.help.result.title') }}</b>{{ t('home.help.result.body') }}
        </li>
      </ul>
    </section>

    <section class="panel">
      <h2 class="panel-title">{{ t('home.service.title') }}</h2>
      <ul class="help-list">
        <li>{{ t('home.service.stations', { count: stationStore.stations.length }) }}</li>
        <li>{{ t('home.service.lines', { count: lineStore.lines.length }) }}</li>
        <li>{{ t('home.service.timetable') }}</li>
      </ul>
      <div class="quick-links">
        <router-link class="btn primary" to="/search">{{ t('home.quick.search') }}</router-link>
        <router-link class="btn ghost" to="/stations">{{ t('home.quick.stations') }}</router-link>
        <router-link class="btn ghost" to="/lines">{{ t('home.quick.lines') }}</router-link>
      </div>
    </section>

    <section class="panel">
      <h2 class="panel-title">{{ t('home.source.title') }}</h2>
      <i18n-t keypath="home.source.body" tag="p">
        <template #tdx>
          <a :href="config.dataSourceUrl" target="_blank" rel="noopener">{{ config.dataSourceName }}</a>
        </template>
        <template #license>
          <a :href="config.licenseUrl" target="_blank" rel="noopener">{{ config.licenseName }}</a>
        </template>
      </i18n-t>
      <p class="api-line">
        {{ t('home.api.label') }}
        <code>{{ config.apiBaseUrl }}/txapi/public_station/list</code>、<code
          >{{ config.apiBaseUrl }}/txapi/public_line/list</code
        >、<code>{{ config.apiBaseUrl }}/txapi/app_trip/journeys</code>
      </p>
    </section>
  </div>
</template>
