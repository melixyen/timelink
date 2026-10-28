---
name: train-ui-frame
description: 接軌時刻前端（client/）的共用 UI 元件庫 Train UI Frame 的使用方法。在 client/src 底下新增或修改畫面、按鈕、表單、清單、表格、彈出視窗時先讀這份，用 train-* 元件組畫面、用 --train-ui-* CSS 變數調整樣式，不要再手寫原生 <button>、<select>、<input>、<table> 的樣式。
---

# Train UI Frame

接軌時刻前端（Vue 3 + Vite）的共用 UI 元件庫，Element UI 風格、藍白色系。
位置：`client/src/train_ui_frame/`

```
train_ui_frame/
  index.js        install 外掛：自動全域註冊 components/train_*.vue，並載入 train_ui.css
  train_ui.css    元件的基礎樣式＋所有 --train-ui-* CSS 變數
  utils.js        trainUid、trainTextOn、useTrainClickOutside、trainLockScroll／trainUnlockScroll
  components/     train_xxx.vue，一個元件一個檔
  skill.md        本文件
```

## 載入

`client/src/main.js` 已經 `app.use(TrainUI)`，所有元件全域可用，**頁面與元件不必 import**。
檔名 `train_button_group.vue` 註冊成 `TrainButtonGroup`，樣板裡寫 `<train-button-group>`（建議，與 HTML 一致）或 `<TrainButtonGroup>` 都可以。

在元件庫內部（components/ 之間）互相使用時要 import 相對路徑（`import TrainIcon from './train_icon.vue'`），不要依賴全域註冊。

## 規則

1. 畫面上的按鈕、輸入框、下拉、核取、單選、標籤、卡片、表格、對話視窗一律用 train-* 元件；不要寫原生控制項再自己加樣式。
2. 元件的「基礎」樣式只寫在 `train_ui.css`；網站上個別頁面要調整時，在網站的 CSS（`src/assets/main.css` 或元件的樣式）加 class 覆蓋，不要改 train_ui.css 去配合單一頁面。
3. 顏色、字體、大小、圓角都用 `var(--train-ui-*)`，不要寫死色碼（路線代表色這種資料來的顏色例外）。
4. 新增元件：在 `components/` 加 `train_<名稱>.vue`（小寫、底線分隔），class 用 `train-<名稱>`、子元素 `train-<名稱>__<部位>`、修飾 `train-<名稱>--<變化>`、狀態 `is-<狀態>`；樣式加進 `train_ui.css`；然後在本文件的元件清單補一段。自動註冊不必改 index.js。
5. 元件庫不依賴網站的 store、i18n、config；要顯示的文字都由 props 或 slot 傳入（例如 modal 的 `close-label`）。
6. 共用 props 慣例：`size`＝`small`｜`default`｜`large`；`type`＝`primary`｜`success`｜`warning`｜`danger`｜`info`（按鈕另有 `default`、`text`）；`disabled`；雙向綁定一律 `v-model`（`modelValue` / `update:modelValue`）。

## CSS 變數（全部定義在 train_ui.css 的 `:root`）

| 類別 | 變數 |
| --- | --- |
| 主色 | `--train-ui-color-primary`、`-primary-hover`、`-primary-active`、`-primary-light-3`、`-light-5`、`-light-8`、`-light-9` |
| 狀態色 | `--train-ui-color-success`／`-success-light`、`warning`、`danger`、`info`（各有 `-light`）、`--train-ui-color-white` |
| 文字 | `--train-ui-text-color-primary`、`-regular`、`-secondary`、`-placeholder`、`-disabled` |
| 邊框／底色 | `--train-ui-border-color`、`-light`、`-lighter`；`--train-ui-fill-color`、`-fill-color-light`；`--train-ui-bg-color`、`-bg-color-page`、`-bg-color-overlay` |
| 字體 | `--train-ui-font-family`、`-font-family-mono`、`--train-ui-font-size-xs`／`sm`／`base`／`md`／`lg`／`xl`、`--train-ui-font-weight-bold`、`--train-ui-line-height` |
| 圓角 | `--train-ui-radius-scale`（**0＝全部直角**、1 預設、1.5 更圓）；`--train-ui-radius-small`、`-base`、`-large`、`-round` 由它算出 |
| 尺寸 | `--train-ui-height-small`／`default`／`large`、`--train-ui-padding-x`、`--train-ui-gap` |
| 陰影／動畫／層級 | `--train-ui-shadow-light`、`-shadow`、`-shadow-dark`、`--train-ui-focus-ring`、`--train-ui-transition-duration`、`-transition-ease`、`--train-ui-z-index-dropdown`、`-z-index-modal` |
| Modal | `--train-ui-modal-inset`（桌面版離四邊，預設 `10%`）、`--train-ui-modal-inset-mobile`（≤ 768px，預設 `0`＝全滿）、`--train-ui-modal-duration` |

覆蓋範例：

```css
:root { --train-ui-color-primary: #0a59ae; --train-ui-radius-scale: 0; }   /* 全站 */
.route-picker { --train-ui-modal-inset: 6%; }                                 /* 只影響這個區塊裡的元件 */
```

手機斷點固定 768px（CSS 變數不能用在 `@media`）。

## 元件清單

### train-button / train-button-group
`type`（default、primary、success、warning、danger、info、text）、`size`、`plain`、`round`、`circle`、`block`（滿版寬）、`active`（保持 hover 狀態，做切換鈕）、`disabled`、`loading`、`icon`（train-icon 名稱）、`native-type`、`to`（router-link）、`href`（`<a>`）。事件 `click`。

```vue
<train-button type="primary" :loading="busy" @click="run">查詢</train-button>
<train-button size="small" round icon="swap" @click="exchange">交換</train-button>
<train-button to="/stations" plain type="primary">車站列表</train-button>
<train-button-group>
  <train-button v-for="n in 3" :key="n" size="small" @click="pick(n)">{{ n }}</train-button>
</train-button-group>
```

### train-input
`v-model`、`type`（text、search…）、`size`、`placeholder`、`clearable`、`prefix-icon`、`suffix-icon`（或 `#prefix`、`#suffix`）、`disabled`。事件 `enter`、`clear`；ref 可呼叫 `focus()`、`blur()`。其他屬性（`autocomplete`、`aria-label`）會傳到 `<input>`，`class`、`style` 留在外框。

```vue
<train-input v-model="keyword" type="search" prefix-icon="search" clearable placeholder="搜尋車站" />
```

### train-select
原生 `<select>`（手機用系統選單）。`v-model`（保留值的型別）、`options`＝`[{ label, value, disabled? }]` 或在 default slot 放 `<option :value>`、`size`、`placeholder`、`disabled`。事件 `change`。

```vue
<train-select v-model.number="form.week" :options="weekList.map((n, i) => ({ label: n, value: i }))" />
```

### train-time-select
`v-model`＝`"HH:MM"`、`step`（分鐘間隔，預設 5）、`size`、`disabled`。目前值不在間隔上時也會列進選項。

### train-checkbox / train-checkbox-group
單獨用：`v-model` boolean。群組：`<train-checkbox-group v-model="arr" :max="10">` 裡放 `<train-checkbox :value="x">`；`max` 達到時其他未勾的停用；`vertical` 直排。checkbox 另有 `label`（或 slot）、`indeterminate`（半選）、`border`、`size`、`disabled`。

```vue
<train-checkbox :model-value="allOn" :indeterminate="someOn" @update:model-value="setAll">全選</train-checkbox>
<train-checkbox-group v-model="picked" vertical>
  <train-checkbox v-for="r in routes" :key="r.route" :value="r.route">{{ r.text }}</train-checkbox>
</train-checkbox-group>
```

### train-radio / train-radio-group
`<train-radio-group v-model="by" :options="[{ label, value }]" />` 或在裡面放 `<train-radio :value>`；`button` 改成分段按鈕；`size`、`disabled`。

### train-switch
`v-model` boolean、`active-text`、`inactive-text`、`disabled`。

### train-tag
`type`、`effect`（light 淺底、dark 實心、plain 白底框線）、`size`、`round`（預設 true）、`color`（自訂底色，文字色自動依深淺）、`closable`（`close` 事件）、`clickable`（變成按鈕，`click` 事件）。

```vue
<train-tag :color="lineStore.lineColor(uid)">{{ lineStore.lineName(uid) }}</train-tag>
<train-tag effect="dark">最早到達</train-tag>
<train-tag type="info">{{ group.name }}</train-tag>
```

### train-card
頁面區塊。`title` 或 `#header`、`#extra`（標題右側）、`#footer`、`shadow`（always、hover、never）、`body-class`。

### train-form-item
欄位：`label`（或 `#label`）＋內容；`inline` 標籤在左；`error` 錯誤文字。內容區是會換行的 flex 列。

### train-link
文字連結：`to`（router-link）、`href`＋`target`（`<a>`），都沒有時是 `<button>`（`click`）。`type`（primary、default、danger）、`underline`、`disabled`。

### train-alert
`type`（info、success、warning、error）、`title` 或 default slot、`simple`（沒有底色，表單旁的錯誤文字）、`show-icon`、`closable`（`close` 事件）。

### train-empty / train-spinner
`<train-empty description="找不到列車" icon="route">`，`#image`、`#actions`。
`<train-spinner text="查詢中…" block />`：`block` 置中佔一行。

### train-icon
`<train-icon name="search" size="18" />`，跟著文字顏色。名稱：close、check、minus、plus、caret-down、caret-up、arrow-right、search、swap、clock、route、filter、info、success、warning、error、loading（會旋轉）。要新圖示就在 `train_icon.vue` 的 `PATHS` 加一條 24×24 的線條路徑。

### train-dropdown
彈出面板（選單、自訂選擇器）。`#trigger`（slot props `{ open, toggle, visible }`）是觸發區，default slot（`{ close }`）是面板；`v-model` 可選；點外面或 Esc 關閉；`width`：`trigger`（同觸發區寬，預設）、`auto` 或 CSS 寬度；`placement`：bottom-start、bottom-end。面板 class 是 `train-dropdown__panel`，內距與高度由使用端決定。

```vue
<train-dropdown v-model="open">
  <template #trigger="{ toggle }"><train-button @click="toggle">選擇</train-button></template>
  <template #default="{ close }"><div class="my-panel">…<train-button @click="close">完成</train-button></div></template>
</train-dropdown>
```

### train-menu / train-menu-item
導覽列。`<train-menu mode="horizontal">`（或 vertical）；項目有 `to` 時依目前路由標示（router-link），沒有 `to` 時用 `index` 搭配 menu 的 `v-model`（`select` 事件）。`icon`、`disabled`。

```vue
<train-menu>
  <train-menu-item v-for="item in navItems" :key="item.to" :to="item.to">{{ t(item.labelKey) }}</train-menu-item>
</train-menu>
```

### train-table
`columns`＝`[{ key, label, width?, align?（center、right）, class? }]`、`data`、`row-key`（欄位名或函式）、`stripe`、`border`、`empty-text`（或 `#empty`）、`table-class`。儲存格預設 `row[key]`，自訂用 `#cell-<key>="{ row, index, column }"`，表頭 `#header-<key>`。column 的 `class` 會加在該欄的 th 與 td，網站 CSS 用它調欄寬或排版。

```vue
<train-table :columns="cols" :data="rows" row-key="StationUID">
  <template #cell-name="{ row }"><b>{{ nameOf(row.StationName) }}</b></template>
</train-table>
```

### train-tabs
`items`＝`[{ name, label, disabled? }]`、`v-model`（目前的 name）、`type`（line、card）。內容放在與 name 同名的 slot，或用 default slot（`{ active }`）。

### train-modal
對話視窗：開啟時面板從畫面中央往四邊展開；桌面版距離四邊 `--train-ui-modal-inset`（10%），手機（≤ 768px）全滿。
`v-model`、`title` 或 `#header`、default slot（內容區自己捲動）、`#footer`（靠右的按鈕列）、`inset`（只改這個視窗的桌面版邊距）、`fullscreen`、`close-on-backdrop`、`close-on-esc`、`show-close`、`close-label`（關閉鈕的無障礙文字，傳入 i18n 字串）、`body-class`。事件 `open`、`opened`、`close`、`closed`。
開啟時鎖住頁面捲動、焦點移到視窗、關閉後還原焦點；多個視窗疊開時只有最上面的回應 Esc；關閉時內容卸載，開啟時要的資料在 `open` 事件或 watch v-model 時載入。

```vue
<train-button icon="filter" @click="show = true">選擇路由</train-button>
<train-modal v-model="show" :title="t('picker.title')" :close-label="t('common.close')">
  <p>內容…</p>
  <template #footer>
    <train-button @click="show = false">{{ t('common.cancel') }}</train-button>
    <train-button type="primary" @click="confirm">{{ t('common.confirm') }}</train-button>
  </template>
</train-modal>
```

## 工具（`import { … } from '@/train_ui_frame'`）

- `trainUid(prefix)`：頁面內唯一 id
- `trainTextOn(hex)`：依底色回傳適合的文字色
- `useTrainClickOutside(elRef, handler)`：在 setup 裡呼叫，點元素外面時執行
- `trainLockScroll()`／`trainUnlockScroll()`：鎖定頁面捲動（可重疊呼叫）

## 這個專案的用法參考

- 頁首導覽：`src/App.vue`（train-menu）
- 查詢表單、結果、路由選擇視窗：`src/views/SearchView.vue`、`src/components/RoutePicker.vue`（train-modal＋train-checkbox-group）
- 自訂選擇器：`src/components/StationSelect.vue`（train-dropdown＋train-input）
- 表格：`src/views/StationsView.vue`、`src/components/JourneyCard.vue`（train-table＋cell slot）
