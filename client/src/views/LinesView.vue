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
    <section class="panel">
      <h2 class="panel-title">
        {{ t('lines.title') }}
        <span class="result-count">{{ t('lines.count', { count: lineStore.lines.length }) }}</span>
      </h2>

      <div v-for="group in grouped" :key="group.id" class="line-group">
        <h3 class="group-title">
          <span class="badge">{{ group.name }}</span>
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
    </section>

    <section v-if="current" class="panel">
      <h2 class="panel-title">
        {{ nameOf(current.LineName) }}
        <span class="result-count">{{ current.LineUID }}</span>
      </h2>

      <div v-if="detailLoading" class="hint">{{ t('common.loading') }}</div>
      <div v-else-if="detailError" class="error-text">{{ detailError }}</div>
      <ol v-else-if="detail" class="station-order">
        <li v-for="s in detail.Stations" :key="s.StationUID">
          <span class="seq">{{ s.Sequence }}</span>
          <span class="name">{{ nameOf(s.StationName) }}</span>
          <span v-if="s.TrainChange" class="tag">{{ t('lines.canChange') }}</span>
          <span
            v-for="transfer in s.TransferLines || []"
            :key="transfer"
            class="line-badge"
            :style="{ background: lineStore.lineColor(transfer) }"
          >
            {{ lineStore.lineName(transfer) }}
          </span>
        </li>
      </ol>
    </section>
  </div>
</template>
