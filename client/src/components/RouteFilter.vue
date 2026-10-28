<script setup>
import { useI18n } from 'vue-i18n'
import { formatMinute2HM } from '@/utils/format'

defineProps({
  groups: { type: Array, default: () => [] },
  hidden: { type: Object, default: () => new Set() },
})

const emit = defineEmits(['toggle', 'fastest'])
const { t } = useI18n()

function formatDuration(minutes) {
  return formatMinute2HM(minutes, {
    minute: t('time.minuteShort'),
    hour: t('time.hourShort'),
  })
}
</script>

<template>
  <div class="route-filter">
    <div class="route-filter-head">
      <span class="title">{{ t('route.title') }}</span>
      <div class="quick">
        <train-button-group>
          <train-button v-for="n in 3" :key="n" size="small" @click="emit('fastest', n)">{{ n }}</train-button>
        </train-button-group>
        <span class="quick-hint">{{ t('route.quickHint') }}</span>
      </div>
      <slot name="extra" />
    </div>
    <ul class="route-list">
      <li v-for="g in groups" :key="g.key" class="route-item" :class="{ hidden: hidden.has(g.key) }">
        <train-checkbox :model-value="!hidden.has(g.key)" class="route-check" @update:model-value="emit('toggle', g.key)">
          <span class="route-text">{{ g.text || g.RouteText }}</span>
        </train-checkbox>
        <span class="route-meta">
          {{ t('route.meta', { count: g.minTransferCount, time: formatDuration(g.minTravelMinutes) }) }}
        </span>
      </li>
    </ul>
  </div>
</template>
