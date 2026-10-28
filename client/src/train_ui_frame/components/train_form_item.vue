<script setup>
/**
 * 表單欄位：標籤＋內容。label 或 #label；inline 時標籤在左、內容在右；
 * error 顯示在內容下方的錯誤文字
 */
defineProps({
  label: { type: String, default: '' },
  inline: { type: Boolean, default: false },
  error: { type: String, default: '' },
})
</script>

<template>
  <div class="train-form-item" :class="{ 'is-inline': inline, 'is-error': !!error }">
    <div v-if="label || $slots.label" class="train-form-item__label"><slot name="label">{{ label }}</slot></div>
    <div class="train-form-item__content">
      <slot />
      <div v-if="error" class="train-form-item__error">{{ error }}</div>
    </div>
  </div>
</template>
