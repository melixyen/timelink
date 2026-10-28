<script setup>
/**
 * 頁籤。items：[{ name, label, disabled? }]；v-model 是目前的 name。
 * 各頁內容放在與 name 同名的 slot（#<name>），或用 default slot（slot props：{ active }）自己切換。
 * type：line（底線，預設）、card（膠囊）
 */
defineProps({
  modelValue: { type: [String, Number], default: '' },
  items: { type: Array, default: () => [] },
  type: { type: String, default: 'line' },
})

const emit = defineEmits(['update:modelValue', 'change'])

function pick(item) {
  if (item.disabled) return
  emit('update:modelValue', item.name)
  emit('change', item.name)
}
</script>

<template>
  <div class="train-tabs" :class="`train-tabs--${type}`">
    <div class="train-tabs__nav" role="tablist">
      <button
        v-for="item in items"
        :key="item.name"
        type="button"
        role="tab"
        class="train-tabs__item"
        :class="{ 'is-active': item.name === modelValue, 'is-disabled': item.disabled }"
        :aria-selected="item.name === modelValue"
        :disabled="item.disabled"
        @click="pick(item)"
      >
        {{ item.label }}
      </button>
    </div>
    <div class="train-tabs__content">
      <slot :name="String(modelValue)" />
      <slot :active="modelValue" />
    </div>
  </div>
</template>
