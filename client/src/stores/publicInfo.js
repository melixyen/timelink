import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { api } from '@/api/txapi'
import { t } from '@/i18n'

/**
 * 系統 / 營運單位等公共資訊（/txapi/public_info/）。
 * 第一次呼叫時載入，之後共用，切路由不再重新載入。
 */
export const usePublicInfoStore = defineStore('publicInfo', () => {
  const systems = ref([])
  const loaded = ref(false)
  const loading = ref(false)
  const error = ref('')
  let pending = null

  const systemMap = computed(() => {
    const map = new Map()
    systems.value.forEach((s) => map.set(s.System, s))
    return map
  })

  function systemName(uid) {
    const s = systemMap.value.get(uid)
    return s ? s.OperatorName || s.System : uid
  }

  /** 載入一次即可；重複呼叫會共用同一個進行中的請求 */
  async function ensureLoaded(options = {}) {
    if (loaded.value && !options.force) return systems.value
    if (pending && !options.force) return pending

    pending = (async () => {
      loading.value = true
      error.value = ''
      try {
        const res = await api.systems()
        systems.value = res.data?.systems || []
        loaded.value = true
        return systems.value
      } catch (e) {
        error.value = t('common.loadFailed', { msg: e.message || '' })
        throw e
      } finally {
        loading.value = false
        pending = null
      }
    })()

    return pending
  }

  return { systems, loaded, loading, error, systemMap, systemName, ensureLoaded }
})
