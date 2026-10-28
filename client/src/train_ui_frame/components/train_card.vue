<script setup>
/**
 * 卡片（區塊容器）。title 或 #header 為標題列；#extra 放在標題右側；#footer 底部。
 * shadow：always、hover、never；bodyClass 加在內容區
 */
defineProps({
  title: { type: String, default: '' },
  shadow: { type: String, default: 'always' },
  bodyClass: { type: [String, Array, Object], default: '' },
})
</script>

<template>
  <section class="train-card" :class="`is-shadow-${shadow}`">
    <header v-if="title || $slots.header || $slots.extra" class="train-card__header">
      <div class="train-card__title"><slot name="header">{{ title }}</slot></div>
      <div v-if="$slots.extra" class="train-card__extra"><slot name="extra" /></div>
    </header>
    <div class="train-card__body" :class="bodyClass"><slot /></div>
    <footer v-if="$slots.footer" class="train-card__footer"><slot name="footer" /></footer>
  </section>
</template>
