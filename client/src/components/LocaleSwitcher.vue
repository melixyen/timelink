<script setup>
/**
 * 語言切換：前面用單色地球圖示代替「語言 | Language」文字（看不懂目前語言的人也認得）。
 * 圖示顏色：CSS 變數 --locale-icon-color（預設 --train-ui-icon-color，系統圖示主色），
 * 或用 iconColor 屬性指定（任何 CSS 顏色值）。
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, setLocale } from '@/i18n'

const props = defineProps({
  iconColor: { type: String, default: '' },
})

const { t, locale } = useI18n()

const current = computed({
  get: () => locale.value,
  set: (value) => setLocale(value),
})

const options = computed(() => SUPPORTED_LOCALES.map((item) => ({ value: item.code, label: t(item.labelKey) })))
const style = computed(() => (props.iconColor ? { '--locale-icon-color': props.iconColor } : null))
</script>

<template>
  <label class="locale-switch" :style="style">
    <train-icon name="globe" :size="20" class="locale-icon" />
    <train-select v-model="current" :options="options" size="small" :aria-label="t('app.lang')" />
  </label>
</template>
