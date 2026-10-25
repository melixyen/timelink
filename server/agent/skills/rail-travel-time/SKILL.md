---
name: rail-travel-time
description: 本站 軌道起訖時刻與站間行駛時間的資料與算法總表——每個營運公司、每條路線擁有哪些資料（列車時刻表、車站時刻表、路線站間行駛時間、兩站間行駛時間矩陣、營運班距）、取得方法的優先順序（真實 → 真實加推算 → 完全推算）以及計算或推算方法。更新 TDX 資料、新增系統、路線或車站、調整 finder 的 rail_train_time／metro_travel_time／metro_trip_time 時使用。
---

# 軌道起訖時刻與站間行駛時間：各營運公司、各路線的資料與算法

本 skill 存放於 `agent/skills/rail-travel-time/`（進版控）。這個站的部署方式、站址等主機細節見 `custom/agent/` 的私密筆記。
未來更新 TDX 資料、TDX 新增或停止提供某種資料、新增路線時，依本文件逐條確認並照做。

## 原則

1. **火車**有列車（車次）時刻表，起訖時刻直接查表，不推算
2. **捷運、輕軌**沒有車次：出發用車站時刻表，抵達 = 出發 + 站間行駛時間，再對到迄站時刻表
3. **取得方法依優先順序：真實資料 → 真實資料加推算 → 完全推算**（見下一節）。每一站、每一段都由上往下找第一個有資料的方法，
   推算值一律標示來源（`source` 欄位），不混在原始資料裡，也不會蓋過真實資料
4. 站間行駛時間的儲存型態依「能否無損」決定：路線站距表能完全重現原始矩陣才存站距表，否則存原始矩陣
5. 票價不推算（沒有公式就不能推導，價錢不能有任何錯誤）
6. 向 TDX 發請求每分鐘最多 3 次（目前金鑰上限每分鐘 5 次）

## 取得方法的優先順序

分三層，API 回應以 `DataTier` 標示：**`real` 真實資料 → `mixed` 真實資料加推算 → `derived` 完全推算**。
程式常數：`libs/txapi/finderdb.php` 的 `FINDERDB_TRAVEL_RANK`、`FINDERDB_TIMETABLE_RANK`、`FINDERDB_TIERS`
（函式 `finderDbTravelRank()`、`finderDbTravelTier()`、`finderDbTimetableTier()`、`finderDbWorstTier()`）。

**車站時刻表**（每一站 × 每個方向；`metro_group.source`，一站一方向只會有一種來源）：

| 順序 | source | 層級 | 方法 | 算法 |
| --- | --- | --- | --- | --- |
| 1 | `timetable` | real | TDX StationTimeTable | — |
| 2 | `shifted` | mixed | 同營運路線、同方向最近一個有真實時刻表的車站（上游優先，再下游），把會經過本站的班次加減站間行駛時間平移 | G |
| 3 | `simulated` | derived | 首末班車起依時段平均班距排出（FirstLastTimetable＋Frequency） | C |

**站間行駛時間**（路線站距表**逐段**取；`metro_travel.source` 逐站記「本站與前一站之間」，`metro_travel_od.source` 逐對記）：

| 順序 | source | 層級 | 方法 | 算法 |
| --- | --- | --- | --- | --- |
| 1 | `tdx` | real | TDX S2STravelTime 同方向（相鄰站鏈或原始矩陣） | D、E |
| 2 | `reversed` | mixed | S2STravelTime 反方向同一區間倒用（只有相鄰站鏈；矩陣不倒用） | D |
| 3 | `timetable` | mixed | 同方向車站時刻表的離站時間差；整條路線沒有 S2S 時改用串車起訖矩陣 | D、F |
| 4 | `timetable-reversed` | mixed | 反方向車站時刻表的離站時間差（往終點站的區間：終點站沒有往終點方向的發車） | D、F |

**抵達時刻**（`metro_trip_time`）：

| 順序 | ArrivalSource | 層級 | 方法 |
| --- | --- | --- | --- |
| 1 | `timetable` | real | 迄站**真實**車站時刻表中推算抵達之後最近的同一列車 |
| 2 | `estimate` | mixed（出發或站間時間更差時取更差的） | 出發＋站間行駛時間 |

- 整班的 `DataTier` = 出發層級與抵達層級中較差的一個；站距表跨多種來源時 `Source` 以 `+` 連接，層級取最差的
- 同一對兩站有多份站距表／矩陣可用時，`metro_trip_time` 取來源等級最好的；`metro_travel_time` 的 `travel[]` 依等級再依秒數排序
- **新增車站資料不足時不需改程式**：S2STravelTime 沒有新站 → 該站前後兩段自動落到第 3、4 層；車站時刻表沒有新站 → 自動平移（G）；
  建置回應 `rebuilt.filled` 列出這次用到非真實資料補上的路線與車站（`travel[]`、`timetable[]`），每次更新資料後要看一次
- 新的資料來源要加進順序時：在常數表插入並重排數字、更新本節與 finder／download 說明，`FINDERDB_SCHEMA_VERSION` + 1

## 五種資料

| 簡稱 | 資料種類 | TDX API | SQLite 表 |
| --- | --- | --- | --- |
| 列車 | 列車時刻表（每個車次的停靠站與到離站時刻） | 臺鐵 `/v3/Rail/TRA/GeneralTrainTimetable`、高鐵 `/v2/Rail/THSR/GeneralTimetable` | `train`、`train_stop` |
| 車站 | 車站時刻表（每站 × 終點 × 營運日的發車時刻，沒有車次） | `/v2/Rail/Metro/StationTimeTable/{SYS}` | `metro_group`、`metro_dep`（`source=timetable`、`simulated=0`） |
| 站距 | 路線站間行駛時間（相鄰兩站的行駛秒數與停站秒數） | `/v2/Rail/Metro/S2STravelTime/{SYS}`（相鄰站鏈形狀） | `metro_travel` |
| 矩陣 | 兩站間行駛時間矩陣（任兩站秒數） | `/v2/Rail/Metro/S2STravelTime/{SYS}`（起訖矩陣形狀） | `metro_travel_od` |
| 班距 | 營運班距＋首末班車 | `/v2/Rail/Metro/Frequency/{SYS}`、`/v2/Rail/Metro/FirstLastTimetable/{SYS}` | 轉成模擬車站時刻（`source=simulated`、`simulated=1`） |

平移時刻存 `source=shifted`、`simulated=1`（`simulated=1` 表示「非真實」，比對抵達時刻與 `rail_station_time` 預設都不用）。

站序一律取 `StationOfRoute`（捷運）、`StationOfLine`（臺鐵）、`S2SDistance` 累加（高鐵）。
儲存型態與比對結果記在 `metro_travel_set`（`raw_shape`：`chain`／`matrix`／`timetable`；`storage`：`route`／`matrix`）。

## 統一的時刻格式（所有系統）

建置最後一步 `finderDbNormalizeTimes()`：火車 `train_stop`、捷運 `metro_dep` 每一列都有到站 `arr` 與離站 `dep`（`HH:MM`，時為 00～23；
TDX 高雄捷運、輕軌過午夜寫 `24:xx`，改成 `00:xx`，排序與跨午夜一律看 `sort_min`），來源記在 `arr_src`／`dep_src`，
API 輸出為 `ArrivalTimeSource`／`DepartureTimeSource`：

| 來源 | 意義 | 用到的系統 |
| --- | --- | --- |
| `timetable` | TDX 原始值 | 全部（高雄捷運、環狀輕軌、淡海、安坑輕軌的到站與離站相同） |
| `origin` | 起站沒有到站時間，以離站時間填 | 高鐵 |
| `terminal` | 終點站沒有離站時間，以到站時間填 | 高鐵 |
| `estimate` | 到站 ＝ 離站 − 本站停站秒數（離站視為該分鐘中間 +30 秒，減停站後捨去到分鐘；停站取 `metro_travel` 本站同方向等級最好的一筆） | 臺北捷運（真實時刻只有離站）、平移與模擬時刻 |

**有獨立到站時間、看得出停站與待避的**：臺鐵、高鐵、機場捷運、新北捷運（部分站）。例：機捷長庚醫院 A8 週一往機場方向
普通車 08:04 到、08:08 開（停 4 分），直達車 08:06 到、08:07 開 → 直達車在 A8 追越普通車。
`metro_trip_time` 比對迄站時刻時，只有 `arr_src = timetable` 才用到站時間，推算的到站不拿來比對（改用離站時間）。

### 時間的定義（營運日，使用者指定：API 不自動換算）

- 時刻表依**營運日**分：04:00 起到隔天 03:59，午夜後（00:00～03:59）的班次屬於前一個營運日。查詢參數 `week`／`date`／`start`／`end`
  一律以營運日表示，午夜後寫 24 時以後（`24:30`）。使用者以日曆時間查詢時由**前端換算**（星期往前一天、時間加 24 小時），API 不自動換算
- 省略 `week`／`date` 時的「今天」是日曆日，凌晨不會自動改成前一個營運日
- 回應時刻一律 `00`～`23` 時；排序與 `sort_min` 以營運日連續分鐘計（00:00～03:59 為 1440 以後）
- 火車的營運日＝列車從起點發車的那一天
- 完整說明在 finder 說明頁「時間的定義（營運日）」，改規則時兩處一起改

## 總表（2026-10）

✓＝TDX 有且使用；推＝由其他資料推算；—＝沒有；◦＝TDX 有但未下載（可由車站時刻表推導）

| 營運公司 | 系統 | 路線 | 列車 | 車站 | 站距 | 矩陣 | 班距 | 起訖時刻算法 | 站間時間來源 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 臺鐵 | TRA | WL、WL-C、SL、EL、SU、PX、SA、NW、LJ、CZ、JJ、SH | ✓ | 推（列車展開） | — | — | — | A | 不需要 |
| 台灣高鐵 | THSR | HSRL | ✓ | 推（列車展開） | — | — | — | A | 不需要 |
| 臺北捷運 | TRTC | BL、R、G、O | — | ✓ | ✓ 單向（另向 reversed） | — | ✓ 未使用 | B | D |
| 臺北捷運 | TRTC | BR 文湖線 | — | 推（C 模擬） | ✓ 單向（另向 reversed） | — | ✓ | B | D |
| 新北捷運 | NTMC | Y 環狀線、LB 三鶯線 | — | ✓ | ✓ 單向（另向 reversed） | — | ◦ | B | D |
| 新北捷運 | NTDLRT | V 淡海輕軌 | — | ✓ | — | 推（F） | ◦ | B | F |
| 新北捷運 | NTALRT | K 安坑輕軌 | — | ✓ | — | 推（F） | ◦ | B | F |
| 桃園捷運 | TYMC | A 機場捷運（普通、直達） | — | ✓（含停站模式） | — | ✓ 原始矩陣 | ◦ | B | E → 存矩陣 |
| 臺中捷運 | TMRT | G 綠線 | — | 推（C 模擬） | ✓ 雙向 | — | ✓ | B | D |
| 高雄捷運 | KRTC | O 橘線、R 紅線 | — | ✓ | ✓ 雙向 | — | ◦ | B | D |
| 高雄捷運 | KLRT | C 環狀輕軌 | — | ✓ | — | ✓ 原始矩陣 | ◦ | B | E → 存站距表 |

`metro_travel_set` 的實際內容（`system=…` 查詢回應的 `storage[]`）：

| 系統 | 路線 | 車種 | 原始形狀 | 儲存 | 原始筆數 | 比對 | 不一致 |
| --- | --- | --- | --- | --- | --- | --- | --- |
| TRTC | BL／BR／G／O／R | 1 | chain | route | 40／23／30／36／44 | — | — |
| NTMC | LB／Y | 0／1 | chain | route | 11／13 | — | — |
| KRTC | O／R | 0 | chain | route | 26／48 | — | — |
| TMRT | G | 0 | chain | route | 34 | — | — |
| KLRT | C | 0 | matrix | route | 1,482 | 1,482 | 0 |
| TYMC | A | 1 普通 | matrix | matrix | 462 | 462 | 387 |
| TYMC | A | 2 直達 | matrix | matrix | 42 | 42 | 30 |
| NTDLRT | V | 0 | timetable | matrix | 479 班 | 136 組（另 34 組到終點站以反方向推估） | 75 組班次間有 ±1 分差 |
| NTALRT | K | 0 | timetable | matrix | 472 班 | 56 組（另 16 組到終點站以反方向推估） | 0 |

## 各路線注意事項

- **臺鐵**：路線依 `StationOfLine`；列車的 `LineIDs` 依停靠站在路線圖上的最短路徑推得。車站時刻表由列車停靠站展開（`GeneralStationTimetable` 不下載）
- **高鐵**：站序與里程由 `S2SDistance` 累加（`StationOfLine` 的嘉義累計里程有誤，不下載）
- **臺北捷運**：S2STravelTime 更新時間 2020 年、每條只有一個方向（BL23→BL01 等），另一方向用反方向區間倒用（`reversed`）。
  2020 年資料沒有淡水信義線新站 **R01 廣慈/奉天宮**，由 D 的第 3、4 層用站別時刻表補上：R01→R02 離站時間差 162 秒（眾數 3 分 ±1 分內的平均），
  往 R01 方向以反方向推估（`timetable-reversed`）；R-1 全程 R01→R28、R28→R01 都是 56.5 分。站別時刻表上 R-2（北投區間車）也已從 R01 發車
  區間車路線（BL-2、G-2、R-2）被較長的路線包含而略過；O-1（迴龍）、O-2（蘆洲）分開兩條；支線 G-3 小碧潭、R-3 新北投各自一條。
  TDX 站別時刻表的方向編號與 StationOfRoute 不一定一致（G-3 時刻表方向 1＝往小碧潭，站序方向 0＝往小碧潭），判斷列車是否到達迄站時以終點站為準
- **文湖線 BR**：TDX 沒有站別時刻表，用 C 模擬；站距有
- **新北捷運 Y、LB**：站距各只有一個方向；LB 的站別時刻表 RouteID 空白、沒有方向，以終點判斷
- **淡海 V、安坑 K**：TDX 沒有 S2STravelTime（回 400），用 F 由車站時刻表串車推算矩陣；V 有 V-1（紅樹林—崁頂）、V-2（紅樹林—淡水漁人碼頭）兩條，
  K 有 K-1（雙城—十四張）、K-2（K06—十四張區間）。終點站沒有往終點方向的發車時刻，「某站→終點站」的最後一段以反方向時間推估（`timetable-reversed`）
- **機場捷運 A**：原始矩陣值已含中間停站（普通車相鄰加總全程 68 分、矩陣 83 分），相鄰加總無法重現，存原始矩陣；
  站別時刻表的 `TrainTypeCode` 1＝普通、2＝直達，對應 S2STravelTime 的 `TrainType` 1、2；有到站時間
- **臺中捷運 G**：TDX 沒有站別時刻表，用 C 模擬；站距雙向
- **高雄捷運 O、R**：站距雙向，原始值為整分鐘；起站那一筆 `StopTime` 為 300（起站停站，不計入）
- **高雄環狀輕軌 C**：矩陣兩筆紀錄方向混雜、含繞遠路的值；相鄰加總可完全重現（順向一圈 5400 秒、逆向 5340 秒），存站距表。
  `StopTime` 固定 25 不可靠、不使用。**TDX 站距比時刻表長約 1 分**（C1→C5 站距 10 分、時刻表 9 分），起訖時刻預設 `tolerance=2`（`FINDER_TRIP_TOLERANCE`）

## 算法

### A. 火車：列車時刻表直接查（臺鐵、高鐵）

`/txapi/finder/rail_train_time?station=<起站>&dest=<迄站>&week=…&start=…&end=…`：
找同時停靠兩站、且迄站在起站之後的車次，起站離站時刻與迄站到站時刻直接取自 `train_stop`。不需要站間行駛時間。

### B. 捷運起訖時刻（`func=metro_trip_time`）

1. **出發**：起站車站時刻表（`metro_dep`，依優先順序：真實 → G 平移 → C 模擬）在指定星期、時間範圍的每一班
2. **篩選**：這班車要到得了迄站——終點在迄站（含）之後；終點在同路線其他站序上（例如往松山的車不進小碧潭）就排除；
   環狀線（站序頭尾同站）或終點不在任何站序上（例如 TDX 標 R01）改看方向。到不了的計入 `skipped`
3. **站間時間**：路線站距表（D）從起站累加到迄站（迄站 `arr` − 起站 `dep`），或起訖矩陣（E、F）直接查；機捷依車種；
   有多份可用時取來源等級最好的
4. **推算抵達** = 出發 + 站間時間
5. **抵達**：迄站車站時刻表中「推算抵達（捨去秒）−`tolerance` 分」之後最近的同一列車
   （同方向、同終點、同車種；找不到再放寬為同方向、同車種），有到站時間用到站時間。
   **只和真實的車站時刻表比對**：迄站只有推算時刻（G 平移或 C 模擬，例如文湖線、臺中捷運）、列車在迄站終止（迄站時刻表沒有這班車）或找不到時，
   直接用推算抵達（`ArrivalSource: estimate`）
6. `tolerance`：迄站時刻可比推算抵達提早的分鐘數；預設依系統（`libs/txapi/finder.php` 的 `FINDER_TRIP_TOLERANCE`：環狀輕軌 2，其餘 0），
   查詢參數可覆寫
7. 每班輸出 `DepartureSource`／`DepartureTier`、`TravelSource`／`TravelTier`、`ArrivalSource`／`ArrivalTier`、`DataTier`

### C. 模擬車站時刻表（最後一層，derived；文湖線 BR、臺中捷運 G）

`finderDbBuildMetroSim()`；只用在真實時刻表與 G 平移都沒有的站與方向（整條路線都沒有真實時刻表時才會發生），
系統要在 `FINDERDB_SIM_SYSTEMS`（有下載 FirstLastTimetable、Frequency）。比照接軌時刻：

1. 每一站、每個終點取 `FirstLastTimetable` 的首班、末班（04:00 前 +24 時）；該站該方向（或該終點）已有真實或平移時刻就略過
2. 營運路線取 `StationOfRoute`（以起站、終點找），`Frequency` 取同路線、該星期適用的一筆
3. 從首班車起，每班加上「該時刻所在時段」的平均班距 (MinHeadwayMins + MaxHeadwayMins) ÷ 2，四捨五入到分，排到末班車；最後一班固定為末班車
4. 各星期分別排，排出相同的星期合併成一組；存 `metro_group.source = simulated`、`simulated = 1`、`info.Note` 註明模擬
5. `rail_station_time` 預設不列模擬時刻，加 `simulated=1` 才列

### D. 路線站間行駛時間（相鄰站鏈，`raw_shape = chain`）

`finderDbBuildMetroTravel()` → `finderDbTravelLadderChain()`／`finderDbTravelSegment()`；獨立版本 `../metro-travel-time/scripts/s2s_to_offsets.php`（只做第 1、2 層）：

1. 同系統、同 `LineID`、同 `TrainType` 合併；相鄰查找表同一對取最小正值
2. 站序取 `StationOfRoute` 每條營運路線、每個方向的**完整站序**
3. **逐段**（相鄰兩站 a→b）依優先順序取第一個有資料的：
   1. `tdx`：S2STravelTime a→b
   2. `reversed`：S2STravelTime b→a 倒用
   3. `timetable`：同方向車站時刻表，a 站每班之後 b 站同終點（本路線終點）的第一班，離站時間差（15 分內）；
      取眾數 ±1 分鐘內的平均換成秒（時刻表只到分鐘，平均比眾數準：以臺北捷運 S2S 驗證平均誤差 17 秒 → 9 秒）。時間差已含 b 站停站
   4. `timetable-reversed`：反方向車站時刻表 b→a 的離站時間差（同上算法），已含 a 站停站
   5. 都沒有：站序在這裡斷開，前後各成一段（每段至少兩站）
4. 停站（中間站）也依順序：相鄰段是時刻表推算、已含本站停站 → 0；S2S 同方向「本站→下一站」的 `StopTime`；
   S2S 反方向「本站→前一站」的 `StopTime`（TDX 的 `StopTime` 是**出發站**的停站）；都沒有 → 0。起訖站停站 0
5. 累加：抵達 = 前站離站 + 行駛；離站 = 抵達 + 停站
6. 被同方向較長鏈完整包含的站序略過
7. 兩站間時間 = 迄站 `arr` − 起站 `dep`（與接軌時刻 `offset_time` 相減的用法相同，單位秒）
8. `metro_travel.source` 逐站記「本站與前一站之間」那段的來源（起站同第一段）。目前非 `tdx` 的：
   TRTC、NTMC 的反方向（`reversed`），TRTC R01（`timetable`／`timetable-reversed`）

2026-10 修正：舊版反方向倒用時，停站誤取「下一站」的 `StopTime`（等於把資料方向起站的停站算進來、漏掉第一個中間站），
改為取本站在反方向的 `StopTime`。板南線 BL01→BL22 從 2715 秒變 2743 秒，車站時刻表實測 2758 秒（6 條反方向路線與時刻表的誤差平均約 28→16 秒）

### E. 兩站間行駛時間矩陣（TDX 原始，`raw_shape = matrix`）

判斷：不重複的起訖對數 > 2 × 車站數。

1. 用矩陣中相鄰兩站的值依站序累加成路線站距表（站序只留這個車種有資料的車站），**停站一律 0、不補、不倒用**（只用第 1 層）
2. 拿矩陣的每一筆原始值（去重）找站距表上同時含兩站、起站在前的鏈（環狀線同站出現兩次時任一對算數），計算值要完全相同
3. **全部一致、且路線上沒有矩陣缺少的車站 → 存路線站距表**（`metro_travel`）；**有任何一筆不同 → 原樣存矩陣**（`metro_travel_od`，`source = tdx`）
4. **矩陣缺少的車站**（`StationOfRoute` 有、該路線所有車種的矩陣都沒有）：存矩陣，再用 F 串車推算，只補含缺站的起訖對（`source = timetable`／
   `timetable-reversed`，記在停最多站的車種）。不把推算值併進站距表，因為那樣原始矩陣就無法完全重現。
   限制：機捷直達車與普通車混在一起串車，以刪掉 A5 的副本驗證，21 對中 20 對與原始矩陣相差 1 分內

### F. 由車站時刻表串車推算矩陣（`raw_shape = timetable`）

給 S2STravelTime **整條路線都沒有**、但有車站時刻表的路線（淡海、安坑輕軌，或 TDX 系統裡的新路線；有 S2S 的路線不做，真實資料優先），
以及 E 第 4 步的缺站。`finderDbTimetableOD()`、`finderDbBuildTimetableMatrix()`；獨立版本 `php scripts/timetable_matrix.php <StationTimeTable/{SYS}.json> <StationOfRoute/{SYS}.json>`：

1. 每條營運路線、每個營運日（ServiceTag），先算相鄰兩站的發車時間差眾數（A 站每班之後、B 站同終點的第一班）
2. 從起點站每一班出發，逐站找同終點、與「前站時刻 + 相鄰眾數」相差 1 分內、尚未用過的班次，串成一整班車
3. 每兩站的時間 = 同一班車在兩站的時刻相減；同一對取所有班次的眾數（平日、假日眾數相同，合併計算）
4. 存 `metro_travel_od`（`source = timetable`，`samples` 班次數、`agree` 與眾數相同的班次數）；精度為分鐘
5. **到終點站**：終點站沒有往終點方向的發車時刻，「某站→終點站」串不出來。最後一段（前一站→終點站）用反方向（終點站→前一站）
   的時間推估：時間(A→終點) = 時間(A→前一站) + 時間(終點→前一站)；A 就是前一站時只取後者。存 `source = timetable-reversed`
   （目前淡海 34 組、安坑 16 組）。最後一段以離站時刻相減，前一站的停站時間會算兩次，誤差約數十秒
6. **不要**用「迄站下一班發車」直接配對遠距起訖：會把中途站才發車的區間車配進來（安坑 K06 發車的 K-2），短算 10～20 分

### G. 平移車站時刻表（第 2 層，mixed）

`finderDbBuildMetroShifted()`（`finderDbBuildMetroFill()` 先做 G 再做 C）。在站間行駛時間（D、E、F）建好之後執行：

1. 每條營運路線、每個方向、每個非終點站 x，判斷是否已有時刻表：同方向的組，或終點在 x 之後的組
   （TDX 車站時刻表的方向編號不一定與 StationOfRoute 一致，例如三鶯線，所以兩者任一成立即可；環狀線只看方向）
2. 沒有時，候選來源站 y：同站序上游由近到遠，再下游由近到遠；y 要有**真實**時刻表
3. 站間時間取 y→x（上游）或 x→y（下游）的離站差：同路線站距表中來源等級最好的一條，沒有再查起訖矩陣；取不到換下一個 y
4. 複製 y 站「會經過 x」的組：終點在本站序上時必須在 x（上游）或 y（下游）之後；終點不在本站序上或環狀線時看方向
5. 每班時刻 ± 四捨五入到分的站間時間，星期、終點、車種、停站模式照抄；`source = shifted`、`simulated = 1`、
   `info.Note` 記來源站與平移量、`info.ShiftedFromUID`
6. 目前沒有車站用到（所有有車站時刻表的路線每站都有真實資料）。2026-10 以刪掉資料的副本驗證：
   刪環狀輕軌 C10 → 由 C9／C11 平移，326 班全部在 ±1 分內（49% 完全相同）；刪環狀線 Y10 → 由 Y09／Y11 平移，78% 在 ±1 分內
   （環狀線 TDX 車站時刻表本身各站不一致，例如假日 Y08 與 Y09 時刻完全相同）

## 更新流程

1. **看現況**（不消耗 TDX 額度）：
   `/txapi/ratelimit`、`/txapi/download/list?service=tdx`、`/txapi/finder/list`、
   `/txapi/finder/metro_travel_time?system=<SYS>`（看 `storage[]`）
2. **確認 TDX 支援範圍**（需要時）：透過 proxy 每次 `$top=1`，**每分鐘最多 3 次**。
   S2STravelTime 打不支援的代碼（例如 `NTDLRT`）會回 400 並列出全部支援的系統。
   某系統新增或停止提供某種資料時，依上方總表找到對應算法，改程式：
   - `libs/txapi/download.php`：`DOWNLOAD_METRO_APIS` 各 API 的系統陣列
   - `libs/txapi/finderdb.php`：`FINDERDB_S2S_SYSTEMS`（有站間時間）、`FINDERDB_SIM_SYSTEMS`（用班距模擬）、
     `FINDERDB_METRO_SYSTEMS`；有站別時刻表但沒有站間時間的系統自動走 F
   - 結構或規則有變：`FINDERDB_SCHEMA_VERSION` + 1
3. **部署**：先備份再上傳改過的檔案（方式見 `custom/agent/` 的私密筆記）；密碼、PAT 向使用者取得或照筆記的位置讀，不寫進進版控的檔案。
   下載清單多了項目而檔案還沒下載時 finder 會回 503，所以部署後**立刻**同步
4. **同步**：`/txapi/admin_download/sync?service=tdx[&category=traveltime|timetable][&force=1]`，
   依 `next_in` 呼叫 `continue` 網址直到 `done: true`；完成時自動重建 SQLite（或 `/txapi/admin_download_to_sqlite/build`）
5. **驗證**：重新產生上方兩張表並比對；看建置回應的 `rebuilt.filled`（哪些路線、車站用了非真實資料）；
   必要時用 `scripts/` 的獨立程式重算，真實資料的部分應與 API 完全相同
6. **改演算法時的回歸測試**：改之前先把 `metro_travel`、`metro_travel_od`、`metro_group`（含每組班次數）、`metro_travel_set`
   匯出成快照，改完重建後逐列比對；只該有預期內的差異。新增車站的情境用「複製網站到暫存目錄、刪掉某站的 S2S 或車站時刻表、在另一個 port 重建」
   驗證，並和原本的真實資料比較誤差

## 驗證參考值（2026-10）

| 查詢 | 結果 |
| --- | --- |
| `metro_travel_time` TRTC-BL12→BL01 | 26.3 分（route） |
| `metro_travel_time` TYMC-A1→A12 | 直達 36、普通 48 分（matrix, tdx） |
| `metro_travel_time` KLRT-C1→C5 | 順向 10 分（route） |
| `metro_travel_time` NTALRT-K01→K08 | 17 分（matrix, timetable） |
| `metro_travel_time` TRTC-R01→R28／R28→R01 | 56.5／56.5 分（`timetable+reversed`／`tdx+timetable-reversed`，mixed） |
| 全程 | 板南線 47.6、文湖線 44.7 分（兩方向相同，反方向為 `reversed`、mixed）；環狀輕軌一圈 90 分 |
| `metro_trip_time` BL12→BL05 週一 08:06 | 推算 08:21:51，對到迄站 08:22 |
| `metro_trip_time` TYMC A1→A12 週一 08:00 直達 | 推算 08:36，迄站時刻 08:36 |
| `metro_trip_time` NTALRT K01→K08 週一 08:02 | 推算 08:19，迄站時刻 08:19 |
| `metro_trip_time` NTALRT K01→K09 週一 08:02 | 17＋3 分（最後一段反方向推估），08:22（estimate） |
| `metro_trip_time` KLRT C1→C5 週一 08:00 順向 | 推算 08:10，tolerance 2 對到迄站 08:09 |
| `metro_trip_time` TRTC BR10→BR15 週一 08:00 | 模擬出發，推算 08:11:14，直接用推算（estimate），`DataTier: derived` |
| `metro_trip_time` BL12→BL05 週一 08:06 | `DataTier: real`（出發、站間、抵達都是真實資料） |

## 已決定的規則（2026-10，使用者指定）

- 淡海、安坑到終點站的區間：最後一段用反方向時間推估補上（`timetable-reversed`）
- 環狀輕軌起訖時刻允許迄站時刻比推算提早 2 分鐘（預設 `tolerance=2`）
- 比對迄站時刻只在迄站時刻表是真實資料時才做；文湖線、臺中捷運的抵達直接用推算值
- 取得方法一律依優先順序：真實資料 → 真實資料加推算 → 完全推算（例如文湖線車站時刻表由班距推得是最後一層）；
  演算法逐站、逐段照這個順序取值，新增車站資料不足時自動取可用的最佳方法

## 已知問題

- 臺北捷運站距資料為 2020 年版本
- 高雄捷運的站間時間（S2S）比車站時刻表的離站差平均長約 50 秒；依優先順序仍用 S2S（真實），不以時刻表覆蓋
- 平移只處理「某站某方向完全沒有時刻表」；某站只缺部分終點的班次（例如只有區間車）時不補
- 跨路線、跨系統轉乘尚未建立：需要轉乘的兩站回空陣列
