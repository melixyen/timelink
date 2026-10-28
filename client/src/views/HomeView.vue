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
    <train-card :title="t('home.title')">
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
    </train-card>

    <train-card :title="t('home.service.title')">
      <ul class="help-list">
        <li>{{ t('home.service.stations', { count: stationStore.stations.length }) }}</li>
        <li>{{ t('home.service.lines', { count: lineStore.lines.length }) }}</li>
        <li>{{ t('home.service.timetable') }}</li>
      </ul>
      <div class="quick-links">
        <train-button type="primary" round to="/search">{{ t('home.quick.search') }}</train-button>
        <train-button round to="/stations">{{ t('home.quick.stations') }}</train-button>
        <train-button round to="/lines">{{ t('home.quick.lines') }}</train-button>
      </div>
    </train-card>

    <train-card :title="t('home.source.title')">
      <i18n-t keypath="home.source.body" tag="p">
        <template #tdx>
          <train-link :href="config.dataSourceUrl" target="_blank">{{ config.dataSourceName }}</train-link>
        </template>
        <template #license>
          <train-link :href="config.licenseUrl" target="_blank">{{ config.licenseName }}</train-link>
        </template>
      </i18n-t>
      <p class="api-line">
        {{ t('home.api.label') }}
        <code>{{ config.apiBaseUrl }}/txapi/public_station/list</code>、<code
          >{{ config.apiBaseUrl }}/txapi/public_line/list</code
        >、<code>{{ config.apiBaseUrl }}/txapi/app_trip/journeys</code>
      </p>
    </train-card>
  </div>
</template>
