<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePublicLineStore } from '@/stores/publicLine'
import { usePublicInfoStore } from '@/stores/publicInfo'
import { nameOf } from '@/utils/format'

const { t } = useI18n()
const lineStore = usePublicLineStore()
const infoStore = usePublicInfoStore()
const current = ref(null)
const detail = ref(null)
const detailLoading = ref(false)
const detailError = ref('')

const grouped = computed(() => lineStore.operatorGroups)

async function showLine(line) {
  current.value = line
  detailLoading.value = true
  detailError.value = ''
  try {
    // 路線站序由 store 快取，切回同一條路線不會再打 API
    detail.value = await lineStore.loadDetail(line.LineUID)
  } catch (e) {
    detailError.value = t('common.loadFailed', { msg: e.message || '' })
  } finally {
    detailLoading.value = false
  }
}

onMounted(() => {
  infoStore.ensureLoaded().catch(() => {})
  lineStore.ensureLoaded().catch(() => {})
})
</script>

<template>
  <div class="page">
    <train-card>
      <template #header>
        {{ t('lines.title') }}
        <span class="result-count">{{ t('lines.count', { count: lineStore.lines.length }) }}</span>
      </template>

      <div v-for="group in grouped" :key="group.id" class="line-group">
        <h3 class="group-title">
          <train-tag>{{ group.name }}</train-tag>
          <span class="count">{{ group.lines.length }}</span>
        </h3>
        <ul class="line-list">
          <li v-for="line in group.lines" :key="line.LineUID">
            <button
              type="button"
              class="line-item"
              :class="{ active: current && current.LineUID === line.LineUID }"
              @click="showLine(line)"
            >
              <span class="bar" :style="{ background: line.LineColor }"></span>
              <span class="name">{{ nameOf(line.LineName) }}</span>
              <span class="count">{{ t('lines.stationCount', { count: line.StationCount }) }}</span>
            </button>
          </li>
        </ul>
      </div>
    </train-card>

    <train-card v-if="current">
      <template #header>
        {{ nameOf(current.LineName) }}
        <span class="result-count">{{ current.LineUID }}</span>
      </template>

      <train-spinner v-if="detailLoading" :text="t('common.loading')" />
      <train-alert v-else-if="detailError" type="error" simple>{{ detailError }}</train-alert>
      <ol v-else-if="detail" class="station-order">
        <li v-for="s in detail.Stations" :key="s.StationUID">
          <span class="seq">{{ s.Sequence }}</span>
          <span class="name">{{ nameOf(s.StationName) }}</span>
          <train-tag v-if="s.TrainChange" effect="dark" size="small">{{ t('lines.canChange') }}</train-tag>
          <train-tag v-for="transfer in s.TransferLines || []" :key="transfer" :color="lineStore.lineColor(transfer)">
            {{ lineStore.lineName(transfer) }}
          </train-tag>
        </li>
      </ol>
    </train-card>
  </div>
</template>
