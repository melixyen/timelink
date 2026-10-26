<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { SUPPORTED_LOCALES, setLocale } from '@/i18n'

const { t, locale } = useI18n()

const current = computed({
  get: () => locale.value,
  set: (value) => setLocale(value),
})
</script>

<template>
  <label class="locale-switch">
    <span class="locale-label">{{ t('app.lang') }}</span>
    <select v-model="current" :aria-label="t('app.lang')">
      <option v-for="item in SUPPORTED_LOCALES" :key="item.code" :value="item.code">
        {{ t(item.labelKey) }}
      </option>
    </select>
  </label>
</template>
