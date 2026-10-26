<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicStationStore } from '@/stores/publicStation'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { usePublicLineStore } from '@/stores/publicLine'
import { config } from '@/config'
import { nameOf } from '@/utils/format'

const { t } = useI18n()
const stationStore = usePublicStationStore()
const infoStore = usePublicInfoStore()
const lineStore = usePublicLineStore()
const keyword = ref('')

const local = computed(() =>
  keyword.value.trim() ? stationStore.searchLocal(keyword.value, 500) : stationStore.stations,
)

const remoteExtra = computed(() => {
  const kw = keyword.value.trim()
  if (!kw) return []
  const seen = new Set(local.value.map((s) => s.StationUID))
  return stationStore.remoteResults.filter((s) => !seen.has(s.StationUID))
})

const rows = computed(() => [...local.value, ...remoteExtra.value])

let timer = null
watch(keyword, (val) => {
  clearTimeout(timer)
  const kw = val.trim()
  if (!kw) {
    stationStore.remoteResults = []
    return
  }
  timer = setTimeout(() => {
    if (stationStore.searchLocal(kw, 1).length === 0) stationStore.searchRemote(kw, config.stationSearchLimit)
  }, 350)
})

onMounted(() => {
  stationStore.ensureLoaded().catch(() => {})
  infoStore.ensureLoaded().catch(() => {})
  lineStore.ensureLoaded().catch(() => {})
})
</script>

<template>
  <div class="page">
    <section class="panel">
      <h2 class="panel-title">
        {{ t('stations.title') }}
        <span class="result-count">{{ t('stations.count', { count: rows.length }) }}</span>
      </h2>

      <div class="control-row">
        <input v-model="keyword" class="search-input" type="search" :placeholder="t('stations.search')" />
        <span v-if="stationStore.loading" class="hint">{{ t('common.loading') }}</span>
        <span v-else-if="stationStore.error" class="error-text">{{ stationStore.error }}</span>
      </div>

      <div class="table-scroll">
        <table class="data-table">
          <thead>
            <tr>
              <th>{{ t('stations.col.name') }}</th>
              <th>{{ t('stations.col.en') }}</th>
              <th>{{ t('stations.col.system') }}</th>
              <th>{{ t('stations.col.code') }}</th>
              <th>{{ t('stations.col.lines') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="s in rows" :key="s.StationUID">
              <td class="strong">{{ nameOf(s.StationName) }}</td>              <td class="muted">{{ s.StationName?.En }}</td>
              <td><span class="badge">{{ infoStore.systemName(s.System) }}</span></td>
              <td class="mono">{{ s.StationID }}</td>
              <td>
                <span
                  v-for="line in s.Lines || []"
                  :key="typeof line === 'string' ? line : line.LineUID"
                  class="line-badge"
                  :style="{ background: lineStore.lineColor(typeof line === 'string' ? line : line.LineUID) }"
                  >{{ lineStore.lineName(typeof line === 'string' ? line : line.LineUID) }}</span
                >
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</template>
