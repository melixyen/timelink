<script setup>
/**
 * 表格。columns：[{ key, label, width?, align?, class? }]；data：列資料陣列。
 * 儲存格預設顯示 row[key]，要自訂時用 #cell-<key> slot（slot props：{ row, index, column }），
 * 表頭用 #header-<key>。rowKey：列的 key 欄位名稱或函式；stripe 斑馬紋；border 直線框；
 * emptyText 或 #empty：沒有資料時的內容。外層有水平捲動容器。
 */
defineProps({
  data: { type: Array, default: () => [] },
  columns: { type: Array, default: () => [] },
  rowKey: { type: [String, Function], default: '' },
  stripe: { type: Boolean, default: false },
  border: { type: Boolean, default: false },
  emptyText: { type: String, default: '' },
  tableClass: { type: [String, Array, Object], default: '' },
})

function keyOf(row, index, rowKey) {
  if (typeof rowKey === 'function') return rowKey(row, index)
  if (rowKey) return row[rowKey]
  return index
}
</script>

<template>
  <div class="train-table-wrap">
    <table class="train-table" :class="[tableClass, { 'is-stripe': stripe, 'is-border': border }]">
      <colgroup>
        <col v-for="col in columns" :key="col.key" :style="col.width ? { width: col.width } : null" />
      </colgroup>
      <thead>
        <tr>
          <th
            v-for="col in columns"
            :key="col.key"
            :class="[col.class, col.align && `is-${col.align}`]"
          >
            <slot :name="`header-${col.key}`" :column="col">{{ col.label }}</slot>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in data" :key="keyOf(row, index, rowKey)">
          <td v-for="col in columns" :key="col.key" :class="[col.class, col.align && `is-${col.align}`]">
            <slot :name="`cell-${col.key}`" :row="row" :index="index" :column="col">{{ row[col.key] }}</slot>
          </td>
        </tr>
        <tr v-if="!data.length && (emptyText || $slots.empty)" class="train-table__empty">
          <td :colspan="columns.length">
            <slot name="empty">{{ emptyText }}</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
