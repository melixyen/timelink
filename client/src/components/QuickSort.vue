<script setup>
/**
 * 查詢結果的快速排序切換：只在本次查詢結果前端重新排序，不再打 API。
 *
 * 依後端給的三個代表方案（Fastest／ShortestTime／FewestTransfers）切換；
 * 再按同一個按鈕取消排序，回到原本的順序。
 */
import { useI18n } from 'vue-i18n'

const props = defineProps({
  modelValue: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const { t } = useI18n()

const modes = [
  { value: 'Fastest', labelKey: 'journey.tag.Fastest' },
  { value: 'ShortestTime', labelKey: 'journey.tag.ShortestTime' },
  { value: 'FewestTransfers', labelKey: 'journey.tag.FewestTransfers' },
]

function pick(value) {
  emit('update:modelValue', props.modelValue === value ? '' : value)
}
</script>

<template>
  <div class="quick-sort">
    <div class="quick-sort-head">
      <span class="quick-sort-title">{{ t('search.quickSort.title') }}</span>
      <span class="quick-hint">{{ t('search.quickSort.hint') }}</span>
    </div>
    <div class="quick-sort-scroll">
      <train-button-group>
        <train-button
          v-for="m in modes"
          :key="m.value"
          size="small"
          :type="props.modelValue === m.value ? 'primary' : 'default'"
          :active="props.modelValue === m.value"
          :disabled="props.disabled"
          @click="pick(m.value)"
        >
          {{ t(m.labelKey) }}
        </train-button>
      </train-button-group>
    </div>
  </div>
</template>
