/**
 * 選單與顯示設定（純前端設定，不需環境變數）。
 *
 * operators：業者（營運單位）的分組與顯示順序
 *   id     業者代號（例如 TRA、TRTC）
 *   subIDs 要合併進這一組的業者／系統代號（例如新北捷運的三個系統合併成一組）
 *   lines  該業者底下路線的顯示順序（LineUID）；未列出的路線接在後面，依原有順序
 *
 * 渲染規則：
 *   1. 業者代號（System 或 Operator）出現在 operators 裡的，就依此順序與分組渲染
 *   2. 沒有出現在設定裡的業者，最後依後端回傳的原有順序渲染
 */
export const menuConfig = {
  operators: [
    {
      id: 'TRA',
      subIDs: [],
      lines: [
        'TRA-WL', // 西部幹線
        'TRA-WL-C', // 西部幹線 (海線)
        'TRA-SL', // 南迴線
        'TRA-EL', // 東部幹線
        'TRA-LJ', // 六家線
        'TRA-NW', // 內灣線
        'TRA-JJ', // 集集線
        'TRA-CZ', // 成追線
        'TRA-PX', // 平溪線
        'TRA-SA', // 深澳線
        'TRA-SU', // 蘇澳新-蘇澳
        'TRA-SH', // 沙崙線
      ],
    },
    {
      id: 'THSR',
      subIDs: [],
      lines: ['THSR-HSRL'], // 台灣高鐵線
    },
    {
      id: 'TRTC',
      subIDs: [],
      lines: [
        'TRTC-BL', // 板南線
        'TRTC-R', // 淡水信義線
        'TRTC-G', // 松山新店線
        'TRTC-O', // 中和新蘆線
        'TRTC-BR', // 文湖線
      ],
    },
    {
      id: 'NTMC',
      subIDs: ['NTDLRT', 'NTALRT'], // 新北捷運：三鶯線、環狀線、淡海輕軌、安坑輕軌
      lines: ['NTMC-LB', 'NTMC-Y', 'NTDLRT-V', 'NTALRT-K'],
    },
    {
      id: 'TYMC',
      subIDs: [],
      lines: ['TYMC-A'], // 桃園機場捷運線
    },
    {
      id: 'TMRT',
      subIDs: [],
      lines: ['TMRT-G'], // 烏日文心北屯線
    },
    {
      id: 'KRTC',
      subIDs: ['KLRT'], // 高雄捷運：橘線、紅線、環狀輕軌
      lines: ['KRTC-O', 'KRTC-R', 'KLRT-C'],
    },
  ],
}

/** 某個項目（車站或路線）應該歸到哪一個設定業者 */
export function resolveOperatorId(item, operatorConfig = menuConfig.operators) {
  for (const entry of operatorConfig) {
    const ids = [entry.id, ...(entry.subIDs || [])]
    if (item?.System && ids.includes(item.System)) return entry.id
    if (item?.Operator && item.Operator === entry.id) return entry.id
  }
  return null
}

/**
 * 依設定的業者順序分組。
 * @param {Array} items 有 System（與選填 Operator）欄位的項目
 * @param {(id: string) => string} nameOf 顯示名稱的取用函式
 * @param {Array} [operatorConfig]
 * @returns {Array<{ id: string, systems: string[], name: string, items: Array }>}
 */
export function groupByOperator(items, nameOf, operatorConfig = menuConfig.operators) {
  const groups = []
  const taken = new Set()

  for (const entry of operatorConfig) {
    const members = items.filter((item) => {
      if (taken.has(item)) return false
      return resolveOperatorId(item, operatorConfig) === entry.id
    })
    if (!members.length) continue
    members.forEach((m) => taken.add(m))
    groups.push({
      id: entry.id,
      systems: [entry.id, ...(entry.subIDs || [])],
      name: nameOf(entry.id),
      items: members,
    })
  }

  // 沒有出現在設定裡的業者：依後端回傳的原有順序排在最後
  const rest = items.filter((item) => !taken.has(item))
  const restMap = new Map()
  rest.forEach((item) => {
    const key = item.System || item.Operator || 'other'
    if (!restMap.has(key)) restMap.set(key, [])
    restMap.get(key).push(item)
  })
  restMap.forEach((members, key) => {
    groups.push({ id: key, systems: [key], name: nameOf(key), items: members })
  })

  return groups
}

/**
 * 依設定的業者順序分組路線，並套用各業者的路線順序。
 * @param {Array} lines public_line/list 的路線陣列
 * @param {(id: string) => string} nameOf
 * @param {Array} [operatorConfig]
 * @returns {Array<{ id: string, systems: string[], name: string, lines: Array }>}
 */
export function groupLinesByOperator(lines, nameOf, operatorConfig = menuConfig.operators) {
  const groups = groupByOperator(lines, nameOf, operatorConfig)
  return groups.map((group) => {
    const entry = operatorConfig.find((op) => op.id === group.id)
    const order = entry?.lines || []
    const index = new Map(order.map((uid, i) => [uid, i]))
    const sorted = group.items
      .slice()
      .sort((a, b) => {
        const ai = index.has(a.LineUID) ? index.get(a.LineUID) : Number.MAX_SAFE_INTEGER
        const bi = index.has(b.LineUID) ? index.get(b.LineUID) : Number.MAX_SAFE_INTEGER
        return ai - bi
      })
    return { ...group, lines: sorted }
  })
}
