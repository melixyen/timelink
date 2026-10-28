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

const columns = computed(() => [
  { key: 'name', label: t('stations.col.name'), class: 'strong' },
  { key: 'en', label: t('stations.col.en'), class: 'muted' },
  { key: 'system', label: t('stations.col.system') },
  { key: 'code', label: t('stations.col.code'), class: 'mono' },
  { key: 'lines', label: t('stations.col.lines') },
])

const lineUid = (line) => (typeof line === 'string' ? line : line.LineUID)

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
    <train-card>
      <template #header>
        {{ t('stations.title') }}
        <span class="result-count">{{ t('stations.count', { count: rows.length }) }}</span>
      </template>

      <div class="control-row first">
        <train-input
          v-model="keyword"
          class="search-input"
          type="search"
          prefix-icon="search"
          clearable
          :placeholder="t('stations.search')"
        />
        <train-spinner v-if="stationStore.loading" :text="t('common.loading')" />
        <train-alert v-else-if="stationStore.error" type="error" simple>{{ stationStore.error }}</train-alert>
      </div>

      <train-table :columns="columns" :data="rows" row-key="StationUID" table-class="data-table">
        <template #cell-name="{ row }">{{ nameOf(row.StationName) }}</template>
        <template #cell-en="{ row }">{{ row.StationName?.En }}</template>
        <template #cell-system="{ row }">
          <train-tag>{{ infoStore.systemName(row.System) }}</train-tag>
        </template>
        <template #cell-code="{ row }">{{ row.StationID }}</template>
        <template #cell-lines="{ row }">
          <span class="tag-list">
            <train-tag v-for="line in row.Lines || []" :key="lineUid(line)" :color="lineStore.lineColor(lineUid(line))">
              {{ lineStore.lineName(lineUid(line)) }}
            </train-tag>
          </span>
        </template>
      </train-table>
    </train-card>
  </div>
</template>
