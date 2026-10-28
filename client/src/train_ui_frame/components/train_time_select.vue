<script setup>
/**
 * 時刻選擇（時、分兩個下拉）。v-model 為 "HH:MM"；step 是分鐘間隔，
 * 目前值不在間隔上時也會列進選項，不會被改掉。
 */
import { computed } from 'vue'
import TrainSelect from './train_select.vue'

const props = defineProps({
  modelValue: { type: String, default: '00:00' },
  step: { type: Number, default: 5 },
  size: { type: String, default: 'default' },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'change'])

const pad = (n) => String(n).padStart(2, '0')
const hour = computed(() => (props.modelValue || '00:00').slice(0, 2))
const minute = computed(() => (props.modelValue || '00:00').slice(3, 5))

const hourOptions = Array.from({ length: 24 }, (_, i) => ({ label: pad(i), value: pad(i) }))
const minuteOptions = computed(() => {
  const list = []
  for (let m = 0; m < 60; m += Math.max(1, props.step)) list.push(pad(m))
  if (!list.includes(minute.value)) list.push(minute.value)
  return list.sort().map((m) => ({ label: m, value: m }))
})

function update(h, m) {
  const v = `${h}:${m}`
  emit('update:modelValue', v)
  emit('change', v)
}
</script>

<template>
  <div class="train-time-select">
    <TrainSelect
      :model-value="hour"
      :options="hourOptions"
      :size="size"
      :disabled="disabled"
      @update:model-value="update($event, minute)"
    />
    <span class="train-time-select__sep">:</span>
    <TrainSelect
      :model-value="minute"
      :options="minuteOptions"
      :size="size"
      :disabled="disabled"
      @update:model-value="update(hour, $event)"
    />
  </div>
</template>
