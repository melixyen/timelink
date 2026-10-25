---
name: metro-travel-time
description: 取得或更新捷運站間行駛時間（TDX S2STravelTime），依能否無損轉換存成路線站距表或原始起訖矩陣，供 本站 的 finder metro_travel_time 與接軌時刻（railtime）使用。遇到「捷運站間行駛時間、兩站要坐多久、offset_time、S2STravelTime 更新或新增系統」時使用。
---

# 捷運站間行駛時間（S2STravelTime → 路線站距表或原始起訖矩陣）

在 本站 上，用 TDX 的 `S2STravelTime` 取得捷運站間行駛時間，依「能否無損轉換」存成兩種型態之一，
由 `/txapi/finder/metro_travel_time` 提供：

- **路線站距表**（`route`）：每條營運路線 × 方向 × 車種一份，從起站累加經過的車站到迄站。用法與接軌時刻 `ttlib/data.js` 的
  `offset_time` 相同：**兩站間時間 = 迄站抵達偏移 − 起站離站偏移**（秒）
- **起訖矩陣**（`matrix`）：原樣存 TDX 的任兩站秒數，A→B 直接查

**原則：真實資料優先，逐段依優先順序取值**：`tdx`（S2STravelTime 同方向，real）→ `reversed`（反方向倒用）→ `timetable`（同方向車站時刻表離站差）
→ `timetable-reversed`（反方向車站時刻表離站差）；後三者為 mixed。完整的優先順序（含車站時刻表的平移與班距模擬）見 `agent/skills/rail-travel-time`。
起訖矩陣只有路線站距表能**完全重現**時才存路線站距表，否則存原始矩陣；推算值不併進站距表去蓋過或改變原始值。

本 skill 存放於 `agent/skills/metro-travel-time/`（進版控）。這個站的部署方式、站址等主機細節見 `custom/agent/` 的私密筆記。

## 前提

- 寫檔、備份、還原的方式與站址：見 `custom/agent/` 的私密筆記。密碼、PAT **不要寫進進版控的檔案**
- 網站程式：`libs/txapi/download.php`（下載清單、同步、每分鐘呼叫上限）、`libs/txapi/finderdb.php`（轉換成 SQLite）、
  `libs/txapi/finder.php`（`metro_travel_time` 查詢）
- 目前金鑰 TDX 上限每分鐘 5 次。**向 TDX 發請求一律每分鐘最多 3 次**（下載功能已內建 `DOWNLOAD_MAX_PER_MINUTE = 3`；
  手動透過 proxy 試打時自己控制：打 3 次就 `sleep 60`）
- 開始前先看額度：`GET https://<本站>/txapi/ratelimit`（不消耗 TDX 額度）

## 目前狀態（2026-10）

| 系統 | TDX 有無 | 原始形狀 | 儲存 | 備註 |
| --- | --- | --- | --- | --- |
| TRTC 臺北捷運 | 有 | 相鄰站鏈 | route | 只有一個方向（另一方向以 reversed 補）；TDX 更新時間 2020 年 |
| NTMC 新北捷運（環狀線 Y、三鶯線 LB） | 有 | 相鄰站鏈 | route | 各只有一個方向 |
| KRTC 高雄捷運 | 有 | 相鄰站鏈 | route | 雙向；起站 `StopTime` 為 300（起站停站，不計入） |
| KLRT 高雄環狀輕軌 | 有 | 起訖矩陣 | route（1,482 筆全部一致） | 兩筆紀錄、方向混雜、含繞遠路的值；`StopTime` 固定 25 不可靠 |
| TYMC 桃園機場捷運 | 有 | 起訖矩陣 | matrix（普通 387／462、直達 30／42 筆不一致） | `TrainType` 1 普通車（22 站）、2 直達車（7 站）；值為分鐘的倍數 |
| TMRT 臺中捷運 | 有 | 相鄰站鏈 | route | 雙向 |
| NTDLRT 淡海輕軌、NTALRT 安坑輕軌 | **沒有** | — | — | TDX 回 400：`RailSystem: 'X' is not accepted but TRTC, KRTC, TYMC, KLRT, TMRT, NTMC` |

TDX 對不支援的系統回 400，訊息會列出支援的全部系統，所以**打一個不支援的代碼就能知道最新清單**（只花 1 次額度）。

## 操作步驟

### 1. 看現況（不消耗 TDX 額度）

```
GET https://<本站>/txapi/download/list?service=tdx&category=traveltime
GET https://<本站>/txapi/finder/list
GET https://<本站>/txapi/finder/metro_travel_time?system=TRTC
```

### 2. （需要時）試打 TDX，確認支援的系統

透過 proxy、每次只取 1 筆，**每分鐘最多 3 次**：

```
GET https://<本站>/api/basic/v2/Rail/Metro/S2STravelTime/{SYS}?$top=1&$format=JSON
```

先打一個確定不支援的代碼（例如 `NTDLRT`）讀 400 訊息裡的清單，再只試清單內新增的系統。

### 3. 清單有變時改程式並部署

1. `libs/txapi/download.php`：`DOWNLOAD_METRO_APIS['S2STravelTime']` 的系統陣列（第 4 項）
2. `libs/txapi/finderdb.php`：`FINDERDB_S2S_SYSTEMS`；轉換規則或資料表結構有變時 `FINDERDB_SCHEMA_VERSION` + 1
3. 先備份，再上傳改過的檔案（方式見 `custom/agent/` 的私密筆記）

**注意**：下載清單多了項目而檔案還沒下載時，finder 會判定資料不完整（回 503），所以部署後要**立刻**做第 4 步。

### 4. 同步（自動遵守每分鐘 3 次）

```
GET https://<本站>/txapi/admin_download/sync?service=tdx&category=traveltime&force=1
Authorization: Bearer <後臺 PAT>
```

- 回應 `stopped: download budget reached (3 TDX calls per minute)` 時，等 `next_in` 秒後呼叫回應裡的 `continue` 網址
  （force 模式要帶同一個 `since`），直到 `done: true`
- `done: true` 時會自動重建 SQLite（回應的 `sqlite.ok`）；也可手動 `/txapi/admin_download_to_sqlite/build`

### 5. 驗證

```
GET https://<本站>/txapi/finder/metro_travel_time?from=TRTC-BL12&to=TRTC-BL01
GET https://<本站>/txapi/finder/metro_travel_time?from=TYMC-A1&to=TYMC-A12
GET https://<本站>/txapi/finder/metro_travel_time?from=KLRT-C1&to=KLRT-C5
```

- 參考值（2026-10）：臺北車站→頂埔 26.3 分；機捷 A1→A12 直達 36 分、普通 48 分；環狀輕軌 C1→C5 順向 10 分；
  各營運路線全程：板南線 47.6、文湖線 44.7、紅線 69.5（高捷）、環狀輕軌一圈 90 分
- 看 `system=…` 回應的 `storage[]`：起訖矩陣的組 `Mismatch` 為 0 才會是 `route`；機捷目前是 `matrix`
- 機捷矩陣值已含中間停站：只把相鄰 `RunTime` 相加會比起訖值少（普通車全程 68 分 vs 起訖 83 分），差額是停站；
  環狀輕軌不含停站（相鄰加總 = 起訖值：順向一圈 5400 秒、逆向 5340 秒）。同一對有多個值時要分方向看，不能只取全域最小值做比對
- 也可在本機用 `php scripts/s2s_to_offsets.php <S2STravelTime/{SYS}.json> <StationOfRoute/{SYS}.json>` 獨立算一次，儲存型態、比對筆數與路線站距表都應與 API 完全相同

## 儲存型態的判斷與轉換

實作：`libs/txapi/finderdb.php` 的 `finderDbBuildMetroTravel()`；獨立版本：`scripts/s2s_to_offsets.php`（PHP 命令列，不載入 libs，用來交叉比對）
（輸入 S2STravelTime 與 StationOfRoute 的 JSON，輸出與 API 相同的判斷與資料）。結果記在 SQLite 表 `metro_travel_set`。

1. **分組**：同系統、同 `LineID`、同 `TrainType` 為一組
2. **判斷原始形狀**：不重複的「起站→迄站」對數 > 2 × 車站數 → 起訖矩陣；否則為相鄰站鏈
3. **組路線站距表**：
   - 相鄰查找表：同一對取最小正值（環狀線的矩陣同一對有順向與繞遠路兩個值，最小值才是相鄰）
   - 站序取 `StationOfRoute` 每條營運路線、每個方向，只留這組有資料的車站（直達車只停部分站）
   - 依站序累加：抵達 = 前站離站 + 行駛；離站 = 抵達 + 停站
   - 相鄰站鏈用**完整站序逐段**取值：同方向 → 反方向倒用（`reversed`）→ 車站時刻表離站差（`timetable`／`timetable-reversed`，
     眾數 ±1 分內平均換成秒，已含下一站停站）；某段完全沒有資料時從那裡斷開
   - 停站：相鄰站鏈用 TDX 的 `StopTime`（在**出發站**的停站秒數）：同方向取「本站→下一站」那筆，倒用時取反方向「本站→前一站」那筆；
     相鄰段是時刻表推算時記 0。起訖矩陣**一律為 0，不補、不倒用**，站序只留該車種有資料的車站
4. **決定儲存**：
   - 相鄰站鏈 → 存路線站距表（`metro_travel`）
   - 起訖矩陣 → 拿**每一筆**原始值（去重）找路線站距表上同時含兩站、起站在前的鏈（環狀線同站出現兩次時任一對算數），
     計算值要完全相同。**全部一致、且路線上沒有矩陣缺少的車站才存路線站距表；有任何一筆不同就原樣存矩陣**（`metro_travel_od`）；
     矩陣缺少的車站由車站時刻表串車推算，只補含該站的起訖對（`source = timetable`）
5. **去重**：路線站距表中被同方向較長鏈完整包含的站序（區間車，例如 BL-2 ⊂ BL-1）略過

為什麼機捷存矩陣：它的矩陣值含中間停站（普通車相鄰加總全程 68 分、矩陣 83 分），不補停站就無法重現，而補停站是推算值。
環狀輕軌的矩陣值不含停站（相鄰加總 = 起訖值），所以可以無損存成路線站距表。檔案大小：機捷原始矩陣 165 KB（gzip 6 KB），
只留相鄰兩站約 19 KB（gzip 2 KB），壓縮後差距不大，以正確為先。

## API 輸出

- `func=metro_travel_time&from=…&to=…` → `travel[]`：`Storage`、`TrainType`／`TrainTypeName`、`Source`、`Seconds`、`Minutes`、
  `Stops`（路線站距表才有），依秒數排序（機捷同時列出直達與普通）
- `func=metro_travel_time&system=…[&line=…]` → `storage[]`（每組的 `RawShape`、`Storage`、`Checked`、`Mismatch`）、
  `routes[]`（路線站距表，`Stations[]` 有 `RunTime`、`StopTime`、`ArrivalOffset`、`DepartureOffset`）、
  `matrices[]`（起訖矩陣，`Pairs[]` 為 `FromStationUID`、`ToStationUID`、`Seconds`）
- 接軌時刻要換成 `offset_time` 的分鐘表時：只適用路線站距表；以起站為 0，各站取 `DepartureOffset / 60`（終站取 `ArrivalOffset / 60`）。
  起訖矩陣的系統要直接查表

## 應用：捷運起訖時刻（func=metro_trip_time）

站間行駛時間的主要用途。出發取起站站別時刻表（文湖線、臺中捷運用模擬時刻表：首班車起依時段平均班距排到末班車）；
推算抵達 = 出發 + 站間行駛時間；抵達 = 迄站時刻表中推算抵達（含，取到分鐘）之後最近的同一列車，列車在迄站終止時用推算抵達。
TDX 站間時間比時刻表長時會對到下一班（例如環狀輕軌 C1→C5），可加 `tolerance=1`。詳見 `/txapi/finder` 說明。

## 注意

- 機捷不會出現在 `routes[]`，只在 `matrices[]`；機捷與高捷的原始值是整分鐘
- 不要用站別時刻表的發車時間差推導站間時間來**取代** TDX 資料：精度只到分鐘，且約 6～8% 的區間對不上；只在 TDX 沒有那一段時才用（第 3、4 層）
- 每次同步後看建置回應的 `rebuilt.filled.travel`：列出哪些路線用了 `tdx` 以外的來源
- 需要轉乘（跨路線、跨系統）時 API 回空陣列，要分段查詢；跨系統轉乘資料尚未建立
- 淡海、安坑輕軌 TDX 沒有 S2STravelTime，改由車站時刻表串車推算起訖矩陣（`timetable`，mixed；見 rail-travel-time 的 F）
