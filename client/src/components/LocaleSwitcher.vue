<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, setLocale } from '@/i18n'

const { t, locale } = useI18n()

const current = computed({
  get: () => locale.value,
  set: (value) => setLocale(value),
})

const options = computed(() => SUPPORTED_LOCALES.map((item) => ({ value: item.code, label: t(item.labelKey) })))
</script>

<template>
  <label class="locale-switch">
    <span class="locale-label">{{ t('app.lang') }}</span>
    <train-select v-model="current" :options="options" size="small" :aria-label="t('app.lang')" />
  </label>
</template>
