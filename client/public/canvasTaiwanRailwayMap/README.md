# TimeLink 軌道路網圖（canvasTaiwanRailwayMap）

以 canvas 畫出台灣的軌道路網，可以點選車站。**一個 HTML、一個 JS**：
程式與路網資料都在 `canvasTaiwanRailwayMap.js` 裡，不需要後端 API，也不依賴其他套件。

版面（座標、站級、線條轉折點）參考
[melixyen/railtime](https://github.com/melixyen/railtime/blob/gh-pages/canvasTaiwanRailwayMap.js)；
路線、配色、車站代碼與名稱來自 TimeLink 後端的離線資料。

## 檔案

| 檔案 | 說明 |
| --- | --- |
| `canvasTaiwanRailwayMap.js` | library ＋ 路網資料（資料區塊在 `// <railway-data>` 與 `// </railway-data>` 之間，由工具產生） |
| `index.html` | 示範頁（樣式與程式都寫在裡面） |
| `tools/build.mjs` | 產生資料區塊，寫回 `canvasTaiwanRailwayMap.js` |
| `tools/layout-overrides.mjs` | 手調版面：轉乘站收攏、站外轉乘、線條轉折（**路網有變化時主要改這個檔**） |
| `tools/reference-layout.json` | 參考檔的版面（已匯入，平常不用動） |
| `tools/import-reference.mjs` | 參考檔改版時重新匯入 `reference-layout.json` |
| `tools/check.mjs` | 檢查資料、排版、點擊判定（不需要瀏覽器） |

工具一律是 Node.js（18 以上），沒有額外的套件。

## 用法

```html
<div id="map" style="height: 70vh; overflow: auto"></div>
<script src="./canvasTaiwanRailwayMap.js"></script>
<script>
  // 載入後只會掛上 window.canvasTaiwanRailwayMap，不會自動畫圖
  var map = window.canvasTaiwanRailwayMap.setup(document.getElementById('map'), {
    regions: ['taipei', 'taoyuan'],
    onClick: function (e) {
      if (e.station) { console.log(e.station.StationUID, e.station.name); }
    }
  });
</script>
```

`setup(容器, 選項)` 的容器可以是 DOM 元素、CSS 選擇器、Vue 的 ref（`{ value: el }`）或元件實例（`$el`）。
canvas 由 library 建立，大小由 library 決定（剛好容納要畫的路網，四邊留一個字的空白）；
圖比容器大時由容器捲動。

### 選項

| 選項 | 預設 | 說明 |
| --- | --- | --- |
| `regions` | `'all'` | `'all'`＝全台灣；`'taipei'`；`['taipei', 'taoyuan', 'kaohsiung']`。多個生活圈時，有路線相連的畫在一起（北北基＋桃園），不相連的由上往下分塊 |
| `scale` | `'auto'` | 數字＝固定比例；`'auto'`＝配合容器寬度，但不小於 `minScale`（字才看得清楚）；`'fit'`＝一律縮到容器寬度 |
| `minScale` / `maxScale` | 自動 / `1` | `'auto'` 的範圍。`minScale` 沒指定時：滑鼠裝置 0.8、觸控裝置（手機）1 |
| `selected` | `null` | 一開始標示的 StationUID（會捲到那一站） |
| `selectOnClick` | `true` | 點到車站就標示、點空白就取消。要自己控制（例如 v-model）就設 `false`，改呼叫 `map.select()` |
| `onClick` | `null` | 點擊的回呼（見下） |
| `fontScale` | `1` | 站名字級的倍率（1＝基礎大小）。路網維持緊湊、字偏大，所以站名之間會靠得很近甚至稍微重疊；調大會更擠 |
| `showEnglish` / `showRegionNames` | `true` | 英文小字、生活圈名稱浮水印 |
| `maxPixels` | `12000000` | canvas 實際像素上限（手機記憶體）；超過會自動降低解析度倍率 |
| `scroll` | `true` | 容器沒設 overflow 時自動設成 `auto` |

生活圈代碼：`taipei taoyuan hsinchu miaoli taichung changhua yunlin chiayi tainan kaohsiung pingtung yilan hualien taitung`
（`canvasTaiwanRailwayMap.regions()` 會回傳清單）。

### 點擊事件

```js
{
  type: 'click',
  x, y,            // 畫布座標（CSS px，左上角是原點）
  mapX, mapY,      // 版面座標（跟縮放、範圍無關）
  target,          // 'station'（站點的圓圈）| 'label'（站名）| 'group'（轉乘站的大圈）| null（空白）
  station,         // 點到的車站；點在空白是 undefined
  group,           // 車站所屬的 TransferGroup；沒有就是 undefined
  candidates,      // 點站名時：共用這個站名的 StationUID（依優先順序）；其他情況就是 [station.StationUID] 或 []
  ring,            // 跟點到的車站圈在同一個大圈（共構）的 StationUID（依優先順序）；不是轉乘站就是 [station.StationUID]
  originalEvent
}
// station：{ StationUID, StationName: { Zh_tw, En }, name, System, SystemName, Lines, Region, TransferGroupID, x, y }
// group：  { TransferGroupID, GroupName: { Zh_tw, En }, name, primary, StationUIDs, Stations: [station…] }
```

- 點在**站點的圓圈**上 → 那一站自己的 StationUID（轉乘站裡每個系統各有一個圓圈）。
  **站點優先於站名**：站名蓋到或緊貼站點時，點在站點附近（滑鼠 5px、手指 10px 內）一律算站點。
- 點在**站名**或轉乘站的大圈上 → 依優先順序（高鐵 > 台鐵 > 捷運 > 輕軌 > 其他）的第一順位。
  站名不同的成員自己的小字（例：新烏日）點下去是它自己。
- **好幾個系統共用同一個站名**（板橋＝高鐵、台鐵、板南線、環狀線）：點站名一律選優先順序第一位。
  2.1.0 起不再「再點一次同一個站名換下一個」；要讓使用者在轉乘站的各系統之間切換，用 `e.ring`（或 `canvasTaiwanRailwayMap.ring(uid)`）
  自己做介面（TimeLink 的選站小卡是左右滑動）。

### 方法

`map.select(uid, { scroll: true })`、`map.getSelected()`、`map.fontSize(cls)`（站名字級，CSS px；`cls`＝站級，0 最大，例：台北、板橋）、`map.setRegions(...)`、`map.setScale(...)`、
`map.update({...})`、`map.scrollTo(uid)`、`map.locate(uid)`、`map.hitTest(x, y)`、`map.getView()`、
`map.on('click', fn)` / `map.off('click', fn)`、`map.toDataURL()`、`map.redraw()`、`map.destroy()`。

靜態：`canvasTaiwanRailwayMap.regions()`、`.lines()`、`.station(uid)`、`.group(id)`、`.ring(uid)`（同一個大圈的車站）、
`.draw(canvas, options)`（只畫到指定的 canvas，不建立 DOM）。

### 接 Vue 的車站選擇器

```vue
<script setup>
import { onMounted, onBeforeUnmount, ref, watch } from 'vue'
const props = defineProps({ modelValue: String, regions: { type: [String, Array], default: 'all' } })
const emit = defineEmits(['update:modelValue'])
const box = ref(null)
let map = null

onMounted(() => {
  // canvasTaiwanRailwayMap.js 放在 public/，用 <script> 載入（或用到時再動態插入 script）
  map = window.canvasTaiwanRailwayMap.setup(box, {
    regions: props.regions,
    selected: props.modelValue,
    selectOnClick: false,
    onClick: (e) => { if (e.station) emit('update:modelValue', e.station.StationUID) },
  })
})
watch(() => props.modelValue, (uid) => map && map.select(uid))
watch(() => props.regions, (r) => map && map.setRegions(r))
onBeforeUnmount(() => map && map.destroy())   // 會釋放 canvas 的記憶體
</script>
<template><div ref="box" class="rail-map" /></template>
<style scoped>.rail-map { max-height: 70vh; overflow: auto; }</style>
```

手機：`'auto'` 不會把圖縮到字看不清楚，改由容器捲動；排版約數十毫秒；選站只移動一個疊在上面的圓圈，不重畫 canvas。

## 顯示規則

| 規則 | 做法 |
| --- | --- |
| 轉乘站 | TransferGroup 的每個車站各畫一個圓圈（可分別點選），**共構**的用一個大圈圈起來 |
| 站外轉乘 | 可以轉乘但不共構的（`layout-overrides.mjs` 的 `detached`）自己一個點、自己的小字站名，用虛線連過去（例：龍山寺 ⇢ 萬華；機場捷運台北車站圈在台北的大圈裡，另用虛線連到北門） |
| 站名 | 主要站名取優先順序第一位（高鐵 > 台鐵 > 捷運 > 輕軌）；臺→台；結尾的「站」去掉（「車站」保留）；高鐵與台鐵同名但不同站時高鐵加前綴 |
| 成員站名不同 | 用自己的中英文小字標在自己的圓圈旁（例：高鐵台中｜新烏日｜高鐵台中(捷運)；台北＝台鐵高鐵共用「台北 Taipei」，捷運是「台北車站 Taipei Main Station」）。放不下時只留中文 |
| 線條 | 台鐵、高鐵畫成鐵路圖例（路線色與白色相間）；捷運、輕軌畫實線 |
| 生活圈 | 跨生活圈的路段是連接軌道（白色部分改成 `#efefef`）；通往沒畫出來的生活圈只留一小段 |

## 路網有變化時怎麼更新

1. 先更新後端的離線資料（`server/download/tdx/v2/Rail/**`、`transfer_groups.json`、`transfer_regions.json`）。
2. `node client/public/canvasTaiwanRailwayMap/tools/build.mjs`（加 `--verbose` 看細節）。它會印出：
   - **沒有座標的車站**、**用經緯度估位置的端點站** → 到 `layout-overrides.mjs` 的 `stations` 補座標
     （線中間的新站會自動依經緯度排在前後站之間，通常不用補）。
   - **分不開的轉乘群組**、**圈起來範圍太大的節點** → 調整 `stations` 的座標，或把不共構的車站列進 `detached`。
   - 新路線：在 `build.mjs` 的 `LINE_MAP` 對應參考線；參考檔沒有的線，在 `stations` 給頭尾與轉折站座標、`legs` 給轉折點。
3. `node client/public/canvasTaiwanRailwayMap/tools/check.mjs`，全部通過後開 `index.html` 目視檢查。

`layout-overrides.mjs` 的寫法：兩條線在轉乘站**交叉**就填同一個座標（工具會自動把各站沿自己的路線錯開）；
**平行**就相鄰擺放（間距約 20）；每一筆都有註解說明原因。
站名平常是自動找位置：橫向的線優先放正上方／正下方，直向的線優先放左右。
路網要維持緊湊，所以規則是「盡量不蓋到站點（點選的地方）＞ 站名之間少重疊 ＞ 不貼著別站的站點 ＞ 不壓線」，
放不下中英文兩行時只放中文，站名不會被省略。
自動排得不理想時可以在 `labels` 指定：節點 id（TransferGroupID 或單站的 StationUID）或轉乘站裡某個成員的 StationUID（它自己的小字站名），
值是 `[x, y, 對齊, 'zh']`（y 是整塊文字的垂直中心；第四個值 `'zh'` 表示只放中文）。例：台北放在高鐵與板南線之間、淡海輕軌藍海線三站。
想讓交叉點留給某一條線、另一條線的站點畫在旁邊，就直接在 `stations` 給那一站座標（例：民權西路的中和新蘆線在左邊）；
同一級的系統裡誰當主要車站（點站名先選到誰）用 `prefer` 指定。

資料來源：TDX `Station.json`（站名、英文、經緯度）、`StationOfRoute`（捷運主線／支線／環線的站序）、
`transfer_groups.json`（路線、配色、轉乘群組）、`transfer_regions.json`（生活圈）。
