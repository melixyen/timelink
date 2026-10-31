<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMinute2HM } from '@/utils/format'

const props = defineProps({
  groups: { type: Array, default: () => [] },
  hidden: { type: Object, default: () => new Set() },
})

const emit = defineEmits(['toggle', 'fastest'])
const { t } = useI18n()

/**
 * 手機模式：清單不另外捲動（巢狀捲動會吃掉捲動手勢），改成先列前幾條、按「顯示全部」再展開。
 * 收合只由 CSS 在手機斷點生效，桌面版仍是原本固定高度的清單。
 */
const CLAMP = 3
const expanded = ref(false)
const clampable = computed(() => props.groups.length > CLAMP)
// 換一批查詢結果時回到收合狀態
watch(
  () => props.groups.map((g) => g.key).join('|'),
  () => {
    expanded.value = false
  },
)

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
      <div v-if="groups.length > 4" class="quick">
        <train-button-group>
          <train-button v-for="n in 3" :key="n" size="small" @click="emit('fastest', n)">{{ n }}</train-button>
        </train-button-group>
        <span class="quick-hint">{{ t('route.quickHint') }}</span>
      </div>
      <slot name="extra" />
    </div>
    <ul id="route-list" class="route-list" :class="{ 'is-clamped': clampable && !expanded }">
      <li v-for="g in groups" :key="g.key" class="route-item" :class="{ hidden: hidden.has(g.key) }">
        <train-checkbox :model-value="!hidden.has(g.key)" class="route-check" @update:model-value="emit('toggle', g.key)">
          <span class="route-text">{{ g.text || g.RouteText }}</span>
        </train-checkbox>
        <span class="route-meta">
          {{ t('route.meta', { count: g.minTransferCount, time: formatDuration(g.minTravelMinutes) }) }}
        </span>
      </li>
    </ul>
    <button
      v-if="clampable"
      type="button"
      class="route-more"
      aria-controls="route-list"
      :aria-expanded="expanded ? 'true' : 'false'"
      @click="expanded = !expanded"
    >
      {{ expanded ? t('route.showLess') : t('route.showAll', { count: groups.length }) }}
    </button>
  </div>
</template>
