---
name: rail-transfer
description: 本站 軌道轉乘（/txapi/transfer）的資料與演算法——轉乘群組 TransferGroup（TRANSGRP_ 代碼、StationUID 成員、步行分鐘來源優先順序）的產生與更新、路線連結圖的路網結構搜尋、RAPTOR 時刻表轉乘查詢（直通解、最短解、最快解、最佳解、最少轉乘）、固定路由組合的前後班次（func=follow）、車站搜尋（func=stations），以及 railtime（tratrtc.html）與 rocptx（router.v2.js）轉乘演算法的對照。新增車站或系統、TDX 轉乘資料更新、調整轉乘查詢時使用。
---

# 軌道轉乘：轉乘群組、路網結構搜尋、時刻表轉乘

本 skill 存放於 `agent/skills/rail-transfer/`（進版控）。程式在 `libs/txapi/transfer/`，人工維護的輸入在 `libs/txapi/transfer/data/`，用離線資料算出的產生檔在主機的 `custom/libs/txapi/transfer/data/`（不進版控，舊版在 `history/`）：

| 檔案 | 內容 |
| --- | --- |
| `transfer.php` | `/txapi/transfer` 入口與說明文件（func=help／groups／lines／network／route／follow／stations／accel／build_groups／learn） |
| `groups.php` | 轉乘群組產生器 `transferBuildGroups()`、讀取 `transferLoadGroups()` |
| `network.php` | 路網結構搜尋 `transferNetworkSearch()`（路線連結圖） |
| `router.php` | 時刻表轉乘 `transferRoute()`／`transferRaptor()`；方案的路由組合 `transferPatternFromSteps()` |
| `follow.php` | 固定路由組合的前後班次 `transferFollow()`（func=follow） |
| `search.php` | 車站搜尋 `transferSearchStations()`（func=stations） |
| `data/hanzi_pinyin.json` | 車站搜尋的漢字字典（Big5 漢字拼音、異體與簡繁歸一），由 `scripts/build_hanzi.php` 從 Unicode Unihan 產生 |
| `data/transfer_seed.json` | 人工補充：TDX 沒有的跨系統群組、railtime／rocptx 步行分鐘、同站換車分鐘、群組代碼的漢語拼音、設定 |
| `custom/libs/txapi/transfer/data/transfer_groups.json` | 產生結果（給其他專案用的完整陣列，`/txapi/transfer/groups?raw=1` 下載） |

查詢只讀 `sqlite/finder.sqlite` 與 `transfer_groups.json`，不呼叫 TDX。

## 使用者指定的規則（2026-10）

- 車站一律用 TDX **StationUID**（北捷各線各站不同碼，例如臺北車站 `TRTC-BL12`、`TRTC-R10`）
- 路線用「**系統-LineID**」（`TRA-WL`、`TRTC-BL`、`KRTC-R`、`THSR-HSRL`）＝ finder `line` 表主鍵；TDX 沒有 LineUID
- 群組代碼 `TRANSGRP_` ＋ 漢語拼音：代表車站依 **臺鐵 ＞ 高鐵 ＞ 捷運 ＞ 輕軌**（`Settings.SystemPriority`），取英文站名小寫、去掉 station／main station；
  例：臺北車站 `TRANSGRP_taipei`；高鐵臺中＋新烏日＋臺中捷運高鐵臺中站 `TRANSGRP_xinwuri`。英文站名是意譯時（Museum of Fine Arts）
  在 seed 的 `IdNames` 指定拼音（`meishuguan`）。名稱重複才依同一順序加 `1`、`2`（`taoyuan1` 臺鐵、`taoyuan2` 高鐵）
- **同站換車也建成單站群組**（`Type: single`），例如 `TRANSGRP_qidu`、機捷 A8 `TRANSGRP_changgengyiyuan`
- 步行分鐘盡量用 TDX，沒有才用 railtime／rocptx，最後才用座標推算
- 轉乘群組存成 JSON 檔；轉乘演算法的 PHP 收在 `libs/txapi/transfer/`

## 轉乘群組的產生（groups.php）

使用者指定（2026-10）：成員「自動先做兩種」＋「特定的人工輸入」；每次自動更新只比對有沒有新車站可以加入，**不整批換掉**，要移除由人工刪除；
臺鐵同站換車沿用原本的設計（人工大站＋TDX 交會站＋時刻表自動選站），不要用人工清單取代（2026-10 曾改成只用人工 44 站，使用者要求改回：原本的設計比較好，人工大站清單只用於加速）。

1. 車站名稱、路線取 finder 的 `station`、`line_station`；座標取 download 的 `Station`（臺鐵 v2、高鐵、各捷運）
2. 自動偵測的連結（每次 build_groups 都偵測一次）：
   - TDX 捷運 `LineTransfer`（跨線；From＝To 是支線分岔站七張 G03、大橋頭 O12、北投 R22 → 同站換車）、TDX 臺鐵 v3 `LineTransfer`（不同站碼時）、
     TDX 捷運 `TransferStations`（同一實體車站的各線代碼）→ `Kind=group`
   - TDX 捷運 `StationTransfer`（TRTC、NTMC、TYMC）→ `Kind=pair`（兩站不在同一群組就另成兩站群組，例如機捷 A1↔北捷北門 G13）
   - 座標距離（seed `Settings.AutoLink`）：不同系統兩站相距 ≤ 300 公尺（`auto:distance`），或同名（`transferNormName`：臺／台、去掉「車站、站」）
     相距 ≤ 450 公尺（`auto:samename`）→ `Kind=group`
3. 連結紀錄檔 `custom/libs/txapi/transfer/data/transfer_links.json`（只增不減）：新偵測到的加入（`AddedAt`）；已有的這次沒偵測到也保留，標 `Detected=false` 給人工檢查；
   seed `ExcludePairs` 列的會被刪掉且不再加入。2026-10 初次建立 87 筆（auto:distance 44、tdx:LineTransfer 20、tdx:StationTransfer 19、auto:samename 4），
   與原本的群組相比只多了萬華（臺鐵）↔龍山寺（BL10，213 公尺）
4. 合併：紀錄檔的 group 連結＋seed `Groups`（人工群組）→ union-find 群組；紀錄檔的 pair 連結＋seed `Pairs`（人工兩站，北門↔機捷 A1）→ 不在同一群組時另成兩站群組
4b. 排除記號（seed `Bypass`，2026-10 使用者要求）：群組與步行資料保留，`Transfers[]` 標 `Bypass`、`BypassAllowAtEnds`、`BypassNote`，群組內全部標記時群組 `Bypass=true`；
   查詢時不在這裡**中途**轉乘（`transferWalk($m, $u, $v, $atEnd)`：起站出發步行、走到迄站傳 `$atEnd=true`，`AllowAtEnds=true` 時仍可用；中途轉乘、反向搜尋的中途一律不用；時間下界照算）。
   目前：萬華（TRA-1010）↔龍山寺（TRTC-BL10），前後已有臺北、板橋可轉。例：樹林→西門改在板橋轉；萬華出發到西門仍可走到龍山寺搭板南線。
   與 `ExcludePairs` 的差別：ExcludePairs 直接刪掉連結（群組也消失），Bypass 只是不在這裡中途轉
   **查詢時直接讀** seed 的 `Bypass`（`transferSeedBypass()`，network.php 建模型時套用），改了馬上生效、不必跑 build_groups；群組檔裡的 `Bypass` 標記是給其他專案看的，下次 build_groups 才更新。
   排除記號也算進加速清單的輸入雜湊（`transferInputsHash()`），改了之後加速清單自動停用，直到重跑 learn
5. 步行分鐘（有向）優先順序：TDX `LineTransfer` → TDX `StationTransfer`（只給單向時反向沿用，`Source` 加 `(反向)`）→ seed `Walks`（railtime／rocptx）
   → 座標推算 `round(4 + 公尺 ÷ 70)`，限 3～15 分（`estimate`）
6. 同站換車（`TrainChange`）優先順序：TDX 臺鐵／捷運 LineTransfer（交會站、支線分岔）→ seed `TrainChange`（railtime 臺鐵大站 1～4 分、南港、二水、
   rocptx A8 2 分、淡海 V09）→ 預設（臺鐵、高鐵 3、捷運 2）。臺鐵另依時刻表自動選站：全星期合計快車（`TrainTypeCode` 1、2、3、4、11）停靠 ≥ 10 班
   或列車起訖 ≥ 10 班；高鐵每站都可換車。2026-10：臺鐵換車站 83 站
7. 在多站群組內的車站，換車資訊放在該成員的 `TrainChange`；不在任何多站群組才建單站群組
8. 輸出 `Lines`（站序、依站序經過的群組）、`LineLinks`（兩條路線可在哪些群組轉乘）

### 更新流程

1. 自動：資料更新流程的 learn 階段會先跑 `func=build_groups`（見 [data-pipeline](../data-pipeline/SKILL.md)），回應的 `links.added`、`links.notDetected` 是要人工看的地方
2. 人工增加：兩站轉乘加到 seed `Pairs`、多站群組加到 seed `Groups`、同站換車站或分鐘加到 seed `TrainChange`、英文意譯站名補 `IdNames`
3. 人工移除：把兩站加到 seed `ExcludePairs`（下次 build_groups 會從 `transfer_links.json` 刪掉並不再加入）；人工群組直接從 seed 刪；
   只是「不建議在這裡轉乘」但要保留資料時，用 seed `Bypass` 排除記號
4. 改 seed 後部署 seed，再跑 `pipeline.php learn`（或 `func=build_groups` 後 `func=learn`）
   **自動重建（2026-11 使用者要求）**：部署 seed 後不必手動 build_groups——查詢讀群組時（transferLoadGroups → transferEnsureGroups）比對 seed 的 sha1 與
   `custom/…/transfer_groups_stamp.json` 記錄的不同，就自動跑一次（transferRebuildGroups，與 func=build_groups 共用；檔案鎖同時只有一個請求重建，
   有舊群組時其他請求不等；失敗用舊群組、同一份 seed 600 秒內不重試）。加速清單不因群組更新而過期（見 rail-transfer-learn）
5. 驗證：`func=groups&station=<UID>`、`func=network`、`func=route` 幾組代表路線（見下方）

## 路網結構搜尋（func=network）

1. 節點＝路線，`LineLinks` 為邊（臺鐵路線交會站的單站群組也會連起 WL↔EL 等）
2. 起點：起站的路線，以及起站所在群組其他成員的路線（先步行）；終點同理
3. BFS 找換線最少的路線串，再多 `extra`（預設 1）次；不重複經過同一路線，最多 6 條路線
4. 展開每對相鄰路線的轉乘群組與上下車站組合（每段最多 400 種），同一路線上下車不可同站
5. 每段估算：捷運 `metro_travel` 站距表 → `metro_travel_od` 矩陣 → 臺鐵、高鐵最快一班（`train_stop` 的 sort_min 差）→ 站數 × 2；
   加上步行或同站換車分鐘；依轉乘次數、估算分鐘排序
6. 基隆→東湖：WL 基隆→南港 →（nangang）→ BL 南港→南港展覽館 →（nangangzhanlanguan）→ BR，約 42 分

## 時刻表轉乘（func=route，RAPTOR）

- 第 k 輪＝搭第 k 班車（最多 6 班），出發後 12 小時內
- 列車（臺鐵、高鐵）：`train`、`train_stop` 依星期篩選，每班一條 trip；到站秒＝離站 sort_min×60 −（離站−到站）
- 捷運、輕軌：營運路線＝`metro_travel` 的站距表（route／direction／train_type），機捷、淡海、安坑用 `metro_travel_od`
  以 `StationOfRoute` 站序、首站到各站秒數為偏移。在 j 站上車＝j 站 `metro_dep` 下一班（同路線、終點在本營運路線 j 站之後；
  終點在同路線其他營運路線就不是這條的車；環狀線或終點不明看方向；機捷依 `train_type_code` 1 普通／2 直達），到站＝上車＋偏移差
- 掃描：列車從最早可上車的停靠站往後更新；捷運沿站序掃，遇到可上車且等同首站離站更早的發車就改搭
- 轉乘：同群組步行（`Transfers` 分鐘）；同站換車只在有 `TrainChange` 的車站；起站可先步行到同群組車站，迄站可由同群組車站步行抵達
- 每輪迄站變早就記一個方案；從 start 多次搜尋（下一次從上一批最早發車＋1 分），留下互不支配的方案（較晚出發、較早到達、轉乘較少）
- 沒帶 `end` 時最多 12 次搜尋（約涵蓋 start 後 1～3 小時）；帶 `end` 時一路搜尋到 end（全日 `start=04:00&end=27:59`，汐科→關山約 80 次、1 秒），上限 600 次／15 秒（`TRANSFER_ROUTE_BUDGET`），回應 `stop` 說明停在哪裡（end／none 為完整，runs／budget 為截斷）
- 上車站的選擇：同一班車有多個停靠站都趕得上時，選「最早就能在那一站候車」的站（到達不變，前段少坐、候車餘裕多）。例：汐科→南港上 448 次，而不是往回坐到松山；422 次在八堵上，不會把區間車多坐到瑞芳。快車沒停南港時（402、408、426）才會回頭到松山或臺北上車
- 測試：汐科（TRA-0970）→關山（TRA-6050）週一全日 16 個方案，沒有直達車；多數為「區間車到南港／松山／臺北／七堵／八堵轉自強或普悠瑪」，另有轉 2 次的（408 到臺東折返坐莒光 653、426 到玉里轉區間快、228 到花蓮轉 324）
- 分類：Direct 不轉乘最早到達、Shortest 行程最短、Fastest 最早到達、Best（到達 − start ＋ 轉乘 × 8 分 ＋ 步行 × 0.5 額外權重）、FewestTransfers
- `DataTier`：列車段 real；捷運段至少 mixed（到站推算），班距模擬的出發為 derived

### 方案標籤與里程（2026-10 使用者要求）

- 每個方案有 `Tags`（{標籤: bool}）、`TagBits`（bit 相加整數）、`Labels`（true 的標籤名）；回應有 `tags`（定義）與 `picks`（每個標籤一個代表方案的索引）
- 標籤與 bit：Direct 1（直達，屬性）、Fastest 2（最早到達）、ShortestTime 4（全程時間最短）、ShortestDistance 8（軌道里程最短）、FewestTransfers 16（轉乘最少）、Best 32（推薦，只有一個）。並列都標，Best 例外。定義在 router.php `TRANSFER_TAGS`
- 里程（distance.php，資料檔 `custom/libs/txapi/transfer/data/transfer_distance.json`，build_groups 時一起產生；沒有檔案時當場從 download 算）：臺鐵 StationOfLine 累計里程（西部幹線竹南—彰化標 WL-M 山線，海線 WL-C，依列車 TripLine 1／2 排除另一條）、捷運輕軌 StationOfRoute 累計里程（為 0 時用座標直線，estimate）、高鐵沒有里程資料，用相鄰站座標直線（estimate，比實際短約 6%）。搭乘段＝每兩個相鄰停靠站的路網最短里程加總；捷運只走同一路線的邊。TRA-1001 視同 TRA-1000
- 參考值：臺北→高雄 371.4 km、花蓮→臺東 150.9 km、竹南→彰化 山線 85.6／海線 90.3 km、頂埔→南港展覽館 26.7 km、A1→A13 35.9 km
- 互不支配只看出發、到達、轉乘（時間與轉乘都一樣才比里程）。曾經把里程也列入，但會留下「同樣到達、較早出發、只差幾公里」的雜訊方案，而且和反向跳過不相容（跳過只保證時間上的不支配），已拿掉
- 多轉乘的方案要每多轉一次至少早到 3 分鐘（`TRANSFER_MIN_GAIN`）才留下，否則被轉乘較少、出發不早於它的方案淘汰（例：花蓮→蘆洲不再出現在大橋頭多換一次蘆洲支線、只早 1 分鐘的方案）

### 搜尋次數與剪枝（2026-10）

- 為什麼要搜很多次：func=route 的全日查詢是「時刻表輪廓」（profile）——從 start 搜一次得到最早到達，再從那個方案出發後 1 分鐘再搜，直到 end。每次只往後推到下一個出發，所以起站接駁車很密的時候（高雄有區間車＋高雄捷運到新左營），會一班一班接駁車重搜、都接同一班高鐵同時到達。高雄→東湖全日原本 126 次，其中 81 次只是換到更晚的接駁車
- 下界剪枝（`transferLowerBounds`）：每站到迄站最短行駛時間（相鄰停靠站間全部班次的最小值＋步行，反向 Dijkstra），標籤時刻＋下界 ≥ 上界就不展開（A*）。只有在知道上界後才有用
- 沿用上界：前幾次找到、出發 ≥ 這次 t 的方案依「最多搭 k 班」取最早到達當第 k 輪上界。下一次 t 不能超過這些方案的出發
- 反向跳過（`transferLatestDeparture`）：跟前兩次到達時刻＋班數都相同的方案（只重複一次就算常白算，第一次反向搜尋要載入沿線捷運時刻表約 0.1 秒），反向 RAPTOR（下車期限往前推；比 floor 早、或扣掉「起站到該站最短時間」後不比 floor 晚的剪掉）算最晚出發 L，下一次 t = min(L)。在 [出發, L] 出發的方案不會更早到、轉乘不會更少，所以只對（出發、到達、轉乘）的互不支配是精確的
- 驗證：42 組（含科工館、麟洛、山里、漢本往返捷運）四種組合（都關、只跳、只上界、都開）方案集合完全相同；搜尋次數 3363→2407、時間少約 18%。高雄→東湖 126→87 次、汐科→關山 83→48 次；捷運站往小站（例如東湖→山里 124→23 次）省最多，小站出發幾乎不用跳（班次少，本來就沒有重複）
- 長等候改晚出發（`transferShiftLongWait`）：方案中轉乘等候超過 30 分鐘（`TRANSFER_SHIFT_WAIT`），對最後一次長等候之前的部分反向搜尋最晚出發，再正向搜一次替換（同樣到達、班數不變）。例：東湖→漢本 08–12 查詢原本列「11:07 東湖出發、南港等 8 小時坐區間車 4046」，改成 18:49 出發。所以 end 查詢的少數方案出發會晚於 end
- 捷運時刻表每站只查一次並依時刻表群組（終點＋方向）分好（`stationDep`），每條營運路線只判斷群組、不逐班判斷

### 資料與演算法分離、加速清單（2026-10 使用者要求）

- 使用者要求：純資料（靜態資料）一律存成 JSON，PHP 演算法讀檔，格式要讓 PHP 與 Node.js 都能解析（物件、陣列、字串、數字；不用正規表示式）
- `libs/txapi/transfer/settings.php` 讀 `data/transfer_settings.json`，以 define() 產生 TRANSFER_MAX_TRIPS、TRANSFER_HORIZON、TRANSFER_ROUTE_BUDGET、TRANSFER_TRANSFER_PENALTY、TRANSFER_WALK_WEIGHT、TRANSFER_SHIFT_WAIT、TRANSFER_MIN_GAIN、TRANSFER_TAGS；`transferSettings('Router.MaxTrips')` 讀任一欄位；`transferDataFile('Accel')` 取檔案路徑（Files 對照表）
- settings 另含 Distance（StationAliases、SplitSegments 山線區段、TripLineAvoidTags、THSRLineTag）與 GroupSources（捷運 LineTransfer／StationTransfer／TransferStations 讀哪些系統、OperatorAliases、SharedStationIdOperators、ZeroPadStationId）。重構後 build_groups 與里程產出和之前逐位元組相同
- （2026-10 後續）加速清單改由離線再演算產生：`/txapi/admin_transfer/learn`／`learn.php` 算出 `custom/libs/txapi/transfer/data/transfer_accel_learned.json`（推薦換車站、實際使用的走廊），未過期時查詢預設套用；流程見 [rail-transfer-learn](../rail-transfer-learn/SKILL.md)。以下為最初的手寫版說明
- 加速清單 `data/transfer_accel.json`（`accel.php`，`func=route&accel=1`，預設關）：
  - Regions：N 北部、NE 宜蘭與北迴、C 中部、S 南部、SL 南迴、E 花東；Lines 以 LineUID＋From／To（依 line_station 站序，含兩端）展開，Systems、Stations 另列；同轉乘群組的車站互相繼承地區
  - Corridors：地區組合（不分方向）→ Allow 地區或 "*"。被擋的車站在正向與反向搜尋都不展開
  - TransferHubs：臺鐵同站換車只在這些站，或起訖站附近（Settings.LocalMinutes＝40，以時間下界判斷）；RestrictChangeSystems＝["TRA"]
- 驗證：73 組（大站、小站、捷運，全日與 08–12）accel 開關方案完全相同，伺服器時間少約 9%；197 組隨機起訖 5 組不同（奇岩→高鐵彰化經嘉義折返、銅鑼→龍埔在北湖換車、凱旋→苑裡經新竹折返、斗六→北埔經新左營南迴、東澳→凱旋武昌經花東南迴），時間少約 16%。之後把 NE↔S 改成不限制，剩 4 組不同

### 驗證參考值（週一，2026-10）

| 查詢 | 結果 |
| --- | --- |
| 基隆→東湖 08:00 | 115 自強 08:09→南港 08:44，步行→BL 南港→南港展覽館，步行→BR 到東湖 09:00（轉 2，derived：文湖線為模擬時刻） |
| 花蓮→臺中 08:00 | 最快：209 自強到南港轉高鐵 1311 到台中、走到新烏日轉區間車 12:14；直通：10:19 普悠瑪 271 到 14:36 |
| 南港→高雄 08:00 | 最快：高鐵 0207 到左營、新左營轉 161 自強 10:28；直通：109 自強 08:01→13:05 |
| 臺北→機場第二航廈 08:00 | 步行 7 分到 A1，08:15→08:54 |
| 新北投→小碧潭 | R-3 到北投換 R-1、中正紀念堂轉 G-1、七張換 G-3（轉 3） |
| 崁頂→淡水漁人碼頭 | V-1 到濱海沙崙換 V-2（轉 1） |
| 汐科→苑裡 | 區間快到南港轉高鐵到台中、新烏日轉區間車；或松山轉普悠瑪 |
| 150 組隨機起訖（週三 09:00） | 全部 0.1～0.3 秒；沒有方案的只有沒有班次的車站（新馬 TRA-7140） |

## 前端查詢需求（2026-10 使用者要求：做成台灣版 ekitan／jorudan 的核心轉乘查詢，只分星期幾與幾點出發）

| 需求 | API |
| --- | --- |
| 任意兩站轉乘（臺鐵、高鐵、捷運；輕軌資料足以轉乘，保留；林業鐵道不在資料內） | `func=route` |
| 篩選最快抵達、最少轉乘 | `func=route` 的 `Tags`／`TagBits`／`picks` |
| 每一站的時刻表 | `/txapi/finder/rail_station_time` |
| 同一路由組合提早或延後任意分鐘的前後班次 | `func=follow`（把 route 方案的 `Pattern` 送回去） |
| 車站輸入快篩 | `func=stations`（模糊比對前 10 筆） |

### 路由組合 Pattern 與 func=follow（follow.php）

- 使用者規則：前端把路由組合（路線、轉乘站）送到後端，後端只在這個組合裡找前後班車；重複的要篩掉——例如市政府→汐止，板南線班次多、臺鐵少，
  好幾班板南線接同一班火車時只留一個；**每一班車只出現一次，而且是串起來旅程時間最短的那一次**
- Pattern：依序 `{"Type":"Origin","StationUID"}`、`{"Type":"Line","LineUID"}`、`{"Type":"Transfer","From","To","Minutes"}`、…、`{"Type":"Destination","StationUID"}`。
  轉乘的 `Minutes` 可手動設定（保留使用者調整步行速度的可能），null 用資料的轉乘分鐘；`walk_factor` 參數把資料的步行分鐘整體乘上倍數（手動設定的不乘）。
  route 的 `Pattern` 另帶 `Kind`（walk／train_change）、`DefaultMinutes`（資料的分鐘）
- Line 的比對：捷運、輕軌＝同一路線（各營運路線、車種都算）經過上下車站的車；臺鐵、高鐵＝該系統先停上車站再停下車站的任何一班（臺鐵 route 給的 LineUID 是列車的第一條路線，只是標示）
- 演算法：第一段的每一班車各試一次，之後每段搭「趕得上、在下車站最早到」的一班（後綴最小值＋二分搜尋），同站換車不接同一班車；
  由晚到早出發掃描，留下比所有較晚出發者更早到的旅程（互不支配）；最後依全程時間由短到長收，車輛已出現在別的旅程就不收（保證每班車只出現一次）
- 驗證（2026-10）：23 個路由組合（2～4 段，含高鐵、捷運、輕軌、步行起訖）與窮舉全部組合後取互不支配的結果逐筆相同；
  唯一差異是同站換車接到同一班車的組合（已排除）與「同一班車出現在兩個旅程」時只留全程較短的一個（使用者規則）。每次 0.05～0.1 秒
- 選取：`time` 為基準；早於 time 的一側有 `before`（分鐘）用範圍、否則取 `prev` 筆；不早於 time 的一側有 `after` 用範圍、否則取 `next` 筆（都沒給時 next=5）；
  `start`＋`end` 等同 time＋after；`all=1` 全天。回應 `focus`（第一個不早於 time 的方案索引）、`earlier`／`later`（範圍外還有幾個）、`prevTime`／`nextTime`

### 途中各站時刻 StopTimes 與站間行駛時間（2026-10 使用者要求）

- **使用者規則（2026-10-31 改）：捷運沒有整列車時刻表，StopTimes 只有上車站、下車站用車站時刻表的時刻，中間各站不參考各站時刻表，
  依站間行駛時間比例內插（`transferRideStopsSpread`，Source＝interpolated），前端開頭明確標註推算。**
  起因：TDX 新北環狀線站別時刻表有相鄰站同一分鐘的錯誤（景安＝中和、大坪林＝十四張＝秀朗橋），逐站對時刻表會對到錯的班次。
  舊規則（逐站推算這班車對到各站時刻表的哪一班、串成途中各站時刻）已停用；2026-11 起 runs.php 的整列車也不用於查詢（見「捷運整列車 runs」）。
  台高鐵給車次、捷運沒有車次給路線編號（`DisplayNo`／`DisplayNoType`）
- 使用者規則（站間行駛時間）：**以兩站間直接的資料為主，沒有才用該路線一站一站加起來推算**（時刻表與推算的差異可能是有沒有算停站時間）。
  `transferPatternTravel()`：起訖矩陣系統（機捷、淡海、安坑）用 metro_travel_od 的直接秒數，其他（S2STravelTime 只有相鄰兩站）用 metro_travel 累加。
  用在 RAPTOR 的到站、反向搜尋、下界（直接資料也加成邊，下界不高估）、follow、StopTimes。改了之後機捷部分方案早到 1 分（例如環北→新北產業園區、機場第二航廈→高鐵桃園），
  其他系統不變；剪枝與不剪枝結果相同（8 組含機捷、輕軌）
- StopTimes 實作 `router.php`：`transferRideStops()`（`_par` 來源指標留在搭乘段，最後選出的方案才算，`transferAddStops()`；`$arrSec` 下車站時刻）、`transferRideStopsSpread()`（捷運中間站內插）。
  整列車串車（runs.php，決定下車站時刻）：逐站「前一站離站＋兩站間直接行駛時間（＋停站）」，±1 分鐘內對時刻表（至少比前一站晚「行駛時間 × 0.6 − 59 秒」，transferRunMinDep：TDX 新北環狀線往新北產業園區的景安與中和、大坪林與十四張、秀朗橋站別時刻表同一分鐘，同分鐘不採用、改內插；不採用的那一班由 transferRunConsumeEarly 標成已用掉，否則第二輪會從它串出不存在的車，例如從十四張出發、其實是大坪林那班的重複）；有真實到站時間（機捷、高捷、新北捷運、輕軌）比到站——機捷 A8 普通車停站 4 分鐘等直達車，比離站會對錯
- 曾改用「時刻表相鄰兩站發車時間差的眾數」當標準行車時間（對到率較高），使用者指定改回站間行駛時間為主
- 試過「先定兩端再填中間」（使用者提議，2026-10）：先用點對點時長對到下車站時刻表，再把中途站按行駛時間比例分配後對時刻表。比逐站接著推差：
  上車站→倒數第二站，下車站對到率北捷 BL 78%→56%、高捷紅線 100%→25%、環狀線 94%→20%；中途站對到率也下降。原因是點對點時長與時刻表的差會隨距離累積
  （高捷紅線小港→岡山 TDX S2S 69.5 分、時刻表約 50 分，差 19 分；北捷 BL 全線差 1～3 分），超過班距一半就對到別班車；逐站推每段誤差不到 1 分，對到後歸零，所以不累積。
  兩種方法都對到下車站時，北捷 87～96% 相同、高捷紅線 0% 相同（點對點對到的是別班車）。維持逐站接著推
- 高捷 S2STravelTime 的 RunTime 都是 60 秒的倍數（120、180），整體比時刻表長很多；轉乘查詢的高捷到站（用 S2S）因此偏晚，屬資料問題
- 驗證（週一、全部營運路線每 7 班抽 1 班、整條線、不含終點站）：中途站對到時刻表的比例，北捷 BL 96%、R 96%、G 98%、O 100%，高捷紅線 100%、橘線 67%
  （O12 之後 S2S 與時刻表差 2 分以上），新北捷運環狀線 90%、安坑 100%、淡海 99%、高雄輕軌 99%、機捷 80%、新北 LB 71%，文湖線、臺中捷運 0%（只有模擬時刻）；沒有時間倒退

### 中長途分解：生活圈端＋幹線（2026-11 使用者設計，`app/trunk.php`）

- 起訖生活圈不相鄰（appRegionRule 套用）時，appCoreRoutes 不再整段搜尋（appTrunkBaseRoutes；組不出路由才退回整段）：
  1. 幹線站＝起點（終點）生活圈與相鄰生活圈的高鐵站、TraHubs 大站；起站（終站）本身是臺鐵站或高鐵站也算（直接上幹線：汐止直接搭對號、全程區間車接力）
  2. 生活圈端：起站→每個幹線站與一般查詢共用同一套找路（appCoreRoutes，參數同 journeys：前 APP_JOURNEY_ROUTES 條基本路由、帶補充路由，只多不搭高鐵；
     noTrunk）；不設排除／不搭高鐵／不搭對號三次搜尋共用同一次找路，不搭對號時濾掉補充路由切出的對號段。每站取原本演算法排序（轉乘次數 → 速度等級 → 里程 → 估計分鐘）的前
     AccessPerStation＝3 名（使用者規則 2026-11；本機比對 N＝2 會掉「高鐵台南轉沙崙線回嘉義」這類方案，N＝4、5 因 MaxCombos 上限反而擠掉好組合）。終點端對稱
     - 候選臺鐵大站依停靠屬性（TraHubs 的 e／w／s）挑選：只挑兩端有共同幹線的大站（臺北往高雄只用 w，南港、樹林、八堵只標 e 不列）；沒有共同幹線時不篩，由跨線交會站換車；
       起站是別條幹線的大站時照樣找其他大站（宜蘭只標 e，往臺中找北北基標 w 的大站）；往回坐一站的走法（汐止→南港、五堵→七堵）保留（使用者 2026-11）
     - 2026-11 本機驗證 88 組：第一頁依最早到達排序、過慢的排在後面，最早到達／最短行車時間／最少轉乘標的都是完整清單裡最好的，保底方案都在第一頁，各模式最快都不晚於 RAPTOR
  3. 每端依「生活圈端＋幹線估計（appTrunkQuick：同系統直達最短分鐘；東部往來西部經 Gateways 換車）＋另一端最好」每個系統留前 Keep 個幹線站
  4. 幹線（appTrunkLegs）：appNetworkRoutes 只用臺鐵／高鐵（systems 參數），同系統只取直達一段，東部往來西部可多一次轉乘
  5. 組合後過高鐵站經過規則（appTrunkHsrOk，與 appPrefixExtend 同判斷），並排除折返經過大站（appTrunkBackHub：生活圈端搭臺鐵經過的大站、幹線又經過，例如東湖→南港→臺鐵往回到七堵、內壢往北到板橋再搭對號南下；五堵→七堵這種往反方向一站不經過大站的照留）；
     2026-11 本機比對 16 組起訖：這兩項讓臺鐵小站出發的中長途路由少 10%～60%、時間快 0.03～0.07 秒，第一頁只少了往回坐的走法、多了比較快的終點端走法；「同路線只差轉乘站只留一條」沒有效果（空出的名額被補上），不採用；依估計取前 MaxCombos 條（臺鐵、高鐵幹線各至少一半）交回一般流程。段落帶 `_part`（access／trunk／egress），補充資料只改寫幹線段（生活圈端已在短程找路套用過），TRA.HubTransfer 在分解時不用；幹線段沒有直達車的（全程區間車）用補充路由的估計排序
- 參數在 transfer_regions.json 的 `Trunk`（Enabled 可關掉回到整段搜尋）。routes、journeys 的 meta.trunk 列出兩端留下的幹線站、候選數、組合數、耗時
- 區間車接力（TRA.LocalRelay）：區間車能直達、而且不是中長途的段不切（汐止→板橋不在臺北切開）
- 臺鐵跨幹線換車（使用者規則，transfer_regions.json 的 TraJunctions）：中長途臺鐵幹線段兩端的 TraTrunks 沒有共同的（例如花蓮 e、臺中 w），
  appTrunkLegs 多一個在交會站換車的版本（appTrunkJunctions）：東↔西在臺北、西↔南迴在高雄、南迴↔東在臺東；這三站的臺鐵換臺鐵不受生活圈轉乘限制（appRegionTransferOk）。
  花蓮→臺中不搭高鐵由 15:18 變 12:23 到（臺北換對號）
- 全程區間車（使用者規則）：不論起訖站都不必先到大站——分解的幹線段前後接臺鐵（先搭到大站、大站下車再搭區間車）時不產生區間車接力；
  改由 appTrunkMergeTra 把幹線前後相連的臺鐵段合成一段（五堵→松山＋松山→高雄＝五堵→高雄、臺北→豐原＋豐原→松竹＝臺北→松竹，標 _relayOnly、只套用區間車接力）。
  起站（終站）本身是臺鐵站時一定保留當幹線站（五堵往南沒有直達車也要能直接接力）。五堵→愛河之心不搭高鐵的區間車接力由 15 條降為 3 條
- 生活圈端：起訖站與幹線站在同一個轉乘群組（台北車站→臺鐵臺北、臺鐵臺北→高鐵台北）時直接步行（一般找路至少要搭一段車，找不到只步行的走法；
  修正前臺北→高雄要先坐臺鐵到板橋才上高鐵）

### 生活圈幹線規則（2026-11 使用者要求）

- 使用者規則：起訖站的生活圈不同而且不相鄰（跨越兩個生活圈以上）時用幹線判斷——高鐵或臺鐵對號的轉乘只能在起點、終點的生活圈或與它們
  相鄰的生活圈（起訖兩側都一律允許相鄰，所以臺南仍可當高雄目的地的換車點）；西部不列中途換幹線（新竹、苗栗、臺中換臺鐵或高鐵）；
  東部往來西部另外只能在北北基或高雄轉乘。只管「有一方是高鐵或可能搭對號的臺鐵（Class 不是 local）」的轉乘，區間車接力、捷運之間不受限制
- 生活圈以縣市為集合：北北基、新竹縣市、嘉義縣市、彰化與南投（南投只有從二水延伸的集集線）各一區，其他縣市各自一區；key 是代表地名的小寫拼音。
  車站表 `libs/txapi/transfer/data/transfer_regions.json`（Regions、Adjacent 雙向、East、Gateways）；臺鐵依 TDX 車站地址、高鐵依 LocationCity、
  捷運輕軌依系統產生（機捷 A1～A6 在北北基，其餘含林口 A9 在桃園，林口是使用者指定）。新車站不在表裡時不檢查，要人工補
- 實作 `app/routing.php`：appRegionRule（套用時回傳允許的生活圈）、appPrefixExtend 逐段檢查（找路組合時就剪掉，省下後面的組合與串接；
  同一鐵路系統在交會站換線不算轉乘）、appRouteRegionOk（補充資料切開後的路由再檢查一次）
- **相鄰不能直接排除**（使用者指示）：高雄、臺南相鄰的車站可能同時透過高鐵台南或左營轉乘
- **高鐵站經過規則**（使用者規則，以生活圈判斷：**同一生活圈內不檢查**，汐止從南港、台北、板橋上高鐵都要列；上車、下車兩端對稱）。
  **只對 HsrPassCheck 的上下車站檢查**（transfer_regions.json：桃園、新竹、苗栗、彰化、雲林、嘉義、台南；南港、台北、板橋、台中、左營停靠的直達車多，不檢查，所以桃園、中壢經由板橋上高鐵可以；appHsrPassChecked）：
  - 下車後的轉乘路線不能再經過另一個生活圈的高鐵站（含它轉乘群組的車站，appHsrStationOf）：appHsrRideOk。例如高鐵台南下車轉沙崙線，再經新左營或紅線左營站往高雄
  - 上車前不能先經過另一個生活圈的高鐵站不搭（appHsrPassed 記下上車前經過的高鐵站）：appHsrBoardOk
  - 高鐵中途會經過起點（終點）生活圈「有接其他鐵路」的高鐵站時，要從那一站上車（下車）：appHsrBoardOk。例如中壢不搭臺鐵到板橋上高鐵再經過桃園。
    彰化、雲林、嘉義高鐵站轉乘群組沒有其他車站（appHsrLinked＝false），不算
  - 高鐵站的轉乘群組只含直接相連的車站（左營：新左營、高捷左營），不會誤殺
  - 已知資料缺口：桃園、內壢、中壢的臺鐵站沒有轉乘群組（沒接機捷老街溪 A22、環北 A21），路網上到不了高鐵桃園站
- 起因：效能（城際多樣性保底之後 journeys 約 2.3 倍）與大量中途無意義轉乘。前 50 條路由（正式站舊版）套兩條規則的刪減：
  汐止→愛河之心 33（保留 17）、五堵→愛河之心 30、臺北→高雄 41、新竹→高雄 33、基隆→屏東 43、臺北→臺中 21、花蓮→臺中 20、台北車站→南屯 14

### 城際多樣性保底（2026-11 使用者要求）

- **2026-11 改用 RAPTOR 補充**（使用者規則）：路由會經過臺鐵或高鐵時，journeys 用 RAPTOR（transfer/router.php 的 transferRaptor）找三種模式各一班：
  使用者的排除條件本身、再加「不搭高鐵」（FastestNoHSR）、再加「只搭區間車」（不搭高鐵也不搭臺鐵對號，FastestLocal，取代原本的「不搭臺鐵對號最快」FastestNoExpress）。
  原本為了保底另外用各位元呼叫 appCoreRoutes 的做法拿掉。
  - **2026-11 改為 RAPTOR 只跑使用者目前的模式一次**（使用者規則）：沒排除＝全部最快、排除高鐵＝不含高鐵最快、排除對號＝不含對號最快、都排除＝都不含最快；
    兩種保底模式只從原本的演算法串出的方案挑（不另外找路由、不跑 RAPTOR）。用戶主動勾選該模式時 RAPTOR 就會找出該模式最快的一班。
    本機 20 組起訖 × 4 種排除條件：計算時間 −12%（RAPTOR −39%），方案數與列車數不變；333 個標籤 27 個變晚（18 個晚 1 分，
    其餘 12～64 分：內壢、桃園→愛河之心不搭高鐵經新竹接對號、中壢→林邊、臺北→臺中與往南屯只搭區間），勾選該模式後全部找得回來。
    另外對保底模式各找一次路由（appCoreRoutes）的做法比對過：路由數 1.8 倍、時間與 dev 相同，多半仍追不回來，不採用
  - 只找使用者設定的出發時刻之後最早到達、或抵達時刻之前最晚出發的一班（不查整個時段）
  - 出發時刻模式：用原本的演算法在該模式已找到的最早到達當上限剪枝（2026-11 起同一分鐘到也放行：同時到但轉乘較少、出發較晚的可以補進來，由 appJourneyCovered 判斷；找不到就表示原本的已是最佳）；找到時用**固定路由回查法**倒回
    （使用者規則 2026-11，appRaptorFixedLater）：路由固定，以抵達分鐘當期限做反向串接（appChainRouteBack，逐段改搭最晚趕得上的班次），用 appJourneyOut 轉成方案。
    不換路由：換上車站才能更晚出發的（例如永春→高雄只搭區間車，換到臺北上 2013 次可晚 1 小時 41 分），原本的演算法多半已有那個走法，最後一起排序篩選；
    RAPTOR 只是補充。板南線 390 次比對：與反向 RAPTOR＋重算相同 64%，其餘中位數早 6 分出發；每次 1 毫秒對 33 毫秒。抵達時刻、末班模式的反向搜尋是主搜尋，不變
  - RAPTOR 補充不超出出發時段（2026-11）：往後延（appRaptorFixedLater）不超過時段結束，RAPTOR 的班次本身在時段結束之後出發的不列。
    全台隨機 400 組（10 個系統、隨機出發時刻與排除條件）對照只用 RAPTOR：最早到達從未被 RAPTOR 比下去；放行同時到之後，合併版一定等於或優於只用 RAPTOR
    （同時到時轉乘較少、出發較晚 21 組優於；原本 4 組被上限剪掉的同時到、轉乘較少方案補上）
  - 抵達時刻模式（前端「到達的列車」）的對稱（2026-11 本機驗證 14 組起訖：出發模式每個方案以抵達時刻改查、抵達模式每個方案以出發時刻改查，237＋219 筆都找得到同樣好或更好的）：
    RAPTOR 反向搜尋以原本演算法已有的最晚出發當下限（各搭車班數依序找，下限提高到目前找到的；只找不早於它的），比對 56 組結果相同、RAPTOR 少 49%；
    保底標籤與「整體代表方案」比對時，抵達時刻模式用最晚出發的那筆（與保底同一種挑法），修正前淡水→新店這類純捷運會多標不搭高鐵最快、只搭區間車最快
  - 原本的演算法已有同樣好（同時或較早到、同時或較晚出發、轉乘不多）或同一組班次的方案時用原本的（appJourneyCovered），不重複列；
    使用者條件本身的那班沒被涵蓋就併入方案一起做互不支配，其他兩種模式當保底代表（appRaptorJourneyOut 轉成方案格式，_sig 與原本同一種班次代碼）
  - 各模式排除的臺鐵、高鐵班次（appRaptorBan）設在路網資料的 tripBan，transferRaptor／transferLatestDeparture 逐站取班次時直接略過（不複製路網資料）；下界用完整路網算，各模式共用
  - 倒回後的重算用剛找到的抵達時刻當上限剪枝（抵達時刻模式用期限）；2026-11 本機比對 74 組起訖＋24 組抵達時刻、首班、末班查詢輸出完全相同，RAPTOR 部分少約 17%
  - 倒回檢查的效益（2026-11 本機量測，板南線永春、忠孝復興往東部、南部、雲嘉 390 次）：87% 能晚出發（中位數 22 分，最多 210 分），途中最長候車中位數 30→9.5 分；
    journeys 裡拿掉倒回時 72 組有 26 組第一頁不同（代表方案提早 2～39 分出發同時到），只省 7%，所以保留
  - 2026-11 本機比對：16 組起訖 07:00 起第一班車，RAPTOR 單次約 0.08 秒；原本演算法落後的（內壢經新竹接 107 次、花蓮經臺北換車、
    新竹高鐵台中換直達車、區間車在橋頭轉紅線）由補充補上；其他 RAPTOR 佔優勢的邏輯不整合回原本的演算法（使用者指示）
- 原規則（2026-11 早期）：沒勾任何排除車種（extra＝0）、路由會經過臺鐵或高鐵時，journeys 一定要有「不搭高鐵最快」與「不搭臺鐵對號最快」的方案，讓選擇多元。
  **不可以直接刪除沒效率的走法**（使用者指示，路由清單的繞遠／重複走法先不處理）
- 實作 `app/journey.php`：`APP_JOURNEY_DIVERSITY`（標籤 → extra 位元，要加保底就加一行＋前端語言檔加 `journey.tag.<標籤>`）；
  appJourneysCompute 另外用每個位元呼叫 appCoreRoutes（併進候選、用該位元取班次），從全部方案（互不支配與繞遠篩選之前）挑符合該位元的最快方案
  （appJourneyAllowed：臺鐵依實際列車等級、高鐵整段），一定列入並標籤（與 Fastest 同一方案時不另標）
- 起因：一般找路展開前依「估計分鐘＋轉乘×10」只取 NetworkLimit 條，高鐵很快時臺鐵直達會被擠掉（汐止→愛河之心 50 條全是高鐵）；
  營運業者保底的 main:TRA 又被「高鐵到台中再轉臺鐵」這種臺鐵里程較多的混搭路由滿足，臺鐵直達補不回來。保底多找一次路由才串得到
- 回歸測試影響：保底方案也參與最少轉乘標籤的比較，轉乘較少的臺鐵直達可能接手 FewestTransfers

### 查詢限制與錯誤代碼（2026-10 使用者要求）

- 同一 IP 每分鐘 60 次、每小時 1000 次（`libs/txapi/querylimit.php`，`/txapi/transfer`、`/txapi/finder` 合計，func=help／list 與帶管理密碼不計），來源網域不限；
  IP 用 REMOTE_ADDR（主機前面的 CDN 需已換成真實 IP），計數存 `download/tdx_proxy_files/cache/querylimit.sqlite`（只存雜湊）
- 出發時間範圍最長 3 小時（使用者：回歸 railtime 原本「間隔不要超過三小時」）：route 的 end − start，沒給 end 時最多搜到 start＋3 小時（`searchUntil`）；follow 的 before＋after，不提供 all=1，用班數時回傳跨度也限 3 小時
- 上限在 `custom/libs/config.json`（沒寫的用 `libs/config.json` 預設值）（QUERY_RATE_LIMIT_PER_MINUTE、QUERY_RATE_LIMIT_PER_HOUR、QUERY_MAX_RANGE_MINUTES）；錯誤代碼表 `libs/txapi/errors.php` 的 `APP_ERRORS`（40001～40003、42901、42902），`/txapi/errors` 查詢
- 驗證腳本（verify_accel.php）要帶管理密碼，才不受次數與範圍限制

### 捷運整列車 runs（2026-10 使用者要求：起訖站時間越接近正確值越好，依路線資料不同用不同演算法）

- **2026-11 使用者規則：查詢不用整列車（`TRANSFER_USE_RUNS`＝false）。** 捷運查 A 站到 B 站時，班次一律取 A 站官方公告的車站時刻表
  （營運路線 pattern＝發車＋站間行駛時間，下車站推算），除非是沒有公告各站時刻表的路線（文湖線、臺中捷運，車站時刻表本身是模擬的）。
  理由：捷運的車可能從中間各站發車（首班車），不像火車都從起站出發；各站時刻表也有錯（環狀線景安＝中和），串車會串出不存在的車。
  不要再提議用複本時刻表辨識或上下車站兩表配對修串車（使用者否決）。runs.php 保留但不用；app_line/timetable 的捷運因此改用營運路線首站發車推算（`app/line.php`）
- 營運路線的短程車：`transferPatternReach()`（列車終點在營運路線上就只到終點站）。RAPTOR 順向依「能搭到第幾站」分組各掃一次
  （`transferPatternReaches()`、`transferPatternDeparturesReach(..., true)`），反向與 follow 只取開得到下車站的車（`transferPatternDeparturesReach()`）。
  模擬驗證：象山 07:00 往北投、07:05 往淡水，象山→淡水只會搭 07:05（原本 pattern 會算成搭 07:00 直達淡水）
- 以下是整列車的做法（停用中）：

- `libs/txapi/transfer/runs.php`：有真實站別時刻表的路線（`transferRunsRealLines()`：metro_group 有 source＝timetable）把各站時刻表串成整列車，再依停靠站序分組成路線（`$d['mroutes']`），
  RAPTOR 以路線掃描（`transferMRouteNext()` 二分搜尋；`fifo`＝各站時刻隨班次遞增）；只有模擬時刻的路線（文湖線、臺中捷運）仍用 pattern
- 串接：每條營運路線先只從首站發車串（蘆洲支線的車要從蘆洲串，不能在大橋頭被當成新的一班），再從各站沒被串到的班次串；
  每站 `transferRunMatch()`：直接行駛時間推算 ±90 秒 → 對不到改用 `transferRunSegmentMode()`（兩站時刻表發車差的眾數，例如高捷 O12→O13 資料 4.3 分、時刻表 2 分）
  → 再對不到時放寬到 10 分鐘但要「最接近的一班差距小於前後班距 1/3」（深夜時刻表的行駛時間和白天不同）
- 未對到的站：前後都有對到 → 按行駛時間比例內插（interpolated）；之後都沒有 → 最後對到的時刻＋行駛時間×這班車已對到區段的「時刻表÷行駛時間」比例（estimate，限 0.5～1.5）
- 週一中途站對到時刻表：北捷 BL／G 100%、R 99.8%、O 99.9%，高捷 100%，新北 LB 99.9%、環狀線 93.5%，安坑、高雄輕軌 100%、淡海 98.5%，機捷 78%（A13 有不在 A12 時刻表的班次，屬資料問題）
- 修正的既有錯誤：pattern 時代短程車（終點在營運路線中途）會被當成開到營運路線終點（例如象山→淡水搭往北投的車直達淡水）；runs 之後 0 筆
- 快取：`sqlite/transfer_runs_<星期遮罩>.ser`（約 6 MB／天，資料庫建置時間或 runs.php 改了就重算，建置約 1.3 秒、峰值記憶體約 100 MB；讀快取約 0.1 秒）
- 驗證：15 組含捷運、輕軌的起訖 06–10 時，剪枝與不剪枝結果相同；follow 23 個路由組合與窮舉相同

### 轉乘實景影片（2026-10 使用者要求）

- railtime 的轉乘資料（`ttlib/data.js` transStation 的 `video`，鍵是出發的車站或路線；`calc_tymetro.html` 的臺北車站轉乘導覽）有特定兩站間的 YouTube 影片（作者 melix7），
  整理成 `transfer_seed.json` 的 `Videos`：`From`、`To`（StationUID，有方向）、`Type`（YouTube）、`ID`、`Title`、`Source`、`Ref`（railtime 的 transStation id）、`Note`
- 方向用 YouTube oEmbed 的影片標題確認（例如「轉乘-南港站台鐵轉捷運」＝TRA-0980→TRTC-BL22）。臺北車站「台鐵高鐵轉機捷」「機捷轉台鐵高鐵」同時掛在臺鐵與高鐵；頭前庄、景安、大坪林只有捷運轉環狀線一個方向
- 共 25 筆（13 個轉乘點），全部對得到轉乘群組裡的轉乘。查詢時直接讀（`network.php` 的 `transferSeedVideos()`／`transferVideos()`），不影響加速清單；
  輸出在 route／follow 的轉乘步驟與 Pattern、groups 的 Transfers、network 的中途轉乘，網址由 `transfer_settings.json` 的 `Media` 樣式補上

### 車站搜尋（search.php）

- 使用者規則：基礎 API，輸入站名快篩，模糊比對前十筆；參考 railtime（`TT.fn.findStationByString`：臺→台、英文比對手寫的 estring＝拼音＋英文），
  建立中文字與英文字的所有可能：台北／臺北都能搜到，英文用 TDX 英文站名與中文站名的漢語拼音
- 自動產生、不逐站手寫：`data/hanzi_pinyin.json`（Unihan：讀音取 kMandarin、kXHC1983、kTGHZ2013、kHanyuPinlu 聯集，多音字全列；異體只取簡繁與字形層級 kHKGlyph，
  避免 kMatthews 把 綠／氯 這類不同字歸一）＋ `transfer_settings.json` 的 `Search`（ExtraFold、Aliases 例如 北車、權重）
- 比對欄位：中文站名（含去掉「車站／站」、括號內別稱）、別名、站碼與 StationUID、英文、拼音（多音字組合）、拼音首字母；
  方式：完全相同＜開頭＜包含＜依序包含（台車→台北車站）＜英文差一個字母（banciao→板橋）
- 字典重建：`php agent/skills/rail-transfer/scripts/build_hanzi.php`（自動下載 Unihan.zip）；回應有 `missingChars` 時表示站名用到字典沒有讀音的字

## 客端 API（libs/txapi/app/，2026-10 開始）

規劃文件是使用者的「TimeLink 客端 API 規劃」。`finder`、`transfer` 維持現狀（Guest 也能用），客端用的穩定格式另外放：

- `public_`：不分身分、可快取（ETag）；`app_`：同一路徑依 Authorization 判斷 Guest／Member（會員系統還沒做，全部 Guest，只有同一 IP 查詢次數限制）
- 架構：兩個演算法核心＋組合。核心 A 路由演算法（不看時刻，含同系統快慢車展開）；核心 B 點對點時刻表（兩站間直達班次，三種排序）。
  組合：routes＝A、direct＝B、route_trains＝route 每段 B 再串接、journeys＝A→每段 B→串接→分類排序。transfer/route（RAPTOR）不動，當對照組
- 查詢流程：① 找所有路由 → ② 路由層排除（bit 2 去掉高鐵、bit 1 去掉含臺鐵 express 段）→ ③ 每段取時刻 → ④ 列車層排除（在核心 B 取時刻時就排除，
  因為串接每段只接最早到的那班，取完再濾會漏掉區間車組合）→ ⑤ 分類排序（最早到達、最短行車時間、最少轉乘）
- `extra` 是排除位元（2026-10 使用者改定）：bit 1＝不搭臺鐵對號（莒光、自強以上），bit 2＝不搭高鐵，3＝兩者都不搭；預設 0 不排除。加速清單不設限，所有 extra 共用
- 慢車／快車：`transfer_seed.json` 的 `ClassChange`（臺鐵依車種代碼，LocalTypeCodes 6、7、10 是慢車，其他都是快車；高鐵、機捷依停靠站）。
  換車站在補充資料 `transfer_route_supplements.json` 的 Meta（appRelayStations、appClassChangeStations 讀 appSupplementMeta）：區間車接力 10 站（臺北、新竹、彰化、嘉義、高雄、潮州、枋寮、臺東、花蓮、蘇澳新，都已有同站換車；枋寮是 TrainChangeAuto 自動選到）；高鐵台中、機捷 A8 換車
- 路由代碼 `route`：`車站.連接.車站…`，只用英數字與 `-` `.`（各種網址編碼都不會動）。連接＝路線代碼、業者代碼（`TRA`／`THSR`＝系統直達段，不分路線查站到站）（可接 `local`／`express`）或 `walk`／`walk-<分鐘>`；
  關鍵字要整個元素完全命中（名稱在第一個 `-` 之前）。解析與驗證在 `app/route.php`，`/txapi/public_info/route` 可直接檢查
- 核心 B（`app/timetable.php` 的 appCoreDirect）：用 transferRouterData（與 transfer/route 同一份路網時刻）＋ transferFollowLeg 取兩站間每一班，取資料時就依 extra、class 排除；
  環狀線（高雄輕軌）兩個方向都到得了時，行車時間中位數超過最短方向 1.5 倍的方向不列
- 班次代碼 `RunCode`（帶給 app_train/stops）：臺鐵、高鐵＝train_key（TRA-1132）；捷運整列車＝`SYS-LINE-R<sha1(mroute key) 前 8 碼>-<班>-w<星期>`；
  營運路線（文湖線、臺中捷運等沒有真實站別時刻表的）＝`SYS-LINE-P<雜湊>-<站序>-<時刻群組>-<秒>-w<星期>`。單站時刻表的捷運班次用「時刻群組＋發車分鐘」對回整列車。
  `Train` 只放真實車次代碼（臺鐵、高鐵＝RunCode）；捷運沒有車次，`Train`、`TrainNo` 一律 null（系統編的代碼不能被前端當車次顯示）
- 途中各站與通過站：journeys、route_trains 的搭乘段帶 `StopTimes`（appRideStopTimes：transferRideStops＋appPassStations 插入通過站 `Pass`＝true），只對最後回傳的方案算（appJourneyStops）；
  app_train/stops 的 `pass=1` 同樣插入通過站。通過站只算 appClassHasSplit 的系統（臺鐵、高鐵、機捷），沿用 appTrainPath 找兩停靠站間的路線；其他捷運的 lineStations 含支線（見下），不補
- 核心 A（`app/routing.php`）理念（使用者）：從路網與轉乘站推導所有可能，推導途中遇到排除條件就整串剪掉；**寫死的只能是通則**，其他取捨一律放設定檔（transfer_route_rules.json），能用排除名單做到的就編輯名單，不加死碼。
  - **綜合指數找路（2026-11 使用者規則，app/indexroute.php）**：短程查詢（同生活圈、相鄰生活圈）與中長途分解的生活圈端共用（以幹線站為目的地和去幹線站轉車的交通行為相同）。
    不用 Deeper 依轉乘次數截斷（轉乘多但真的快的走法會被丟掉，RAPTOR 才會找出比原本演算法快的走法），改依綜合指數取前 Search.IndexK（20）條不繞圈的路徑：
    指數（分）＝站間行駛分鐘加總＋中間站數 × IndexStopMinutes（1）＋每次轉乘的步行分鐘＋要轉搭那一站的全天平均班距一半（appHeadways：平日每方向「末班−首班」÷（班數−1），
    捷運用車站時刻表、臺鐵高鐵用停靠，快取 app_headways.ser）；起站第一次上車不加候車，同一鐵路系統在交會站接續（系統直達段）不加候車、算一站停靠。
    搜尋：車站層級路網的 A*（啟發值＝反向 Dijkstra 的行駛＋步行下界），每次取指數最小的部分路徑，搭某條路線到該路線上的決策站（轉乘站、支線分岔站、迄站群組）；
    排除規則沿用 appPrefixExtend、appRoutePatternHit，延後到從佇列取出時才檢查；同一站（依抵達路線）最多展開 K 次；下界超過「最好的 × IndexCutoffRatio（2）＋ IndexCutoffPlus（30）」停止。
    同一路由代碼、不同路線串（豐原→大甲經成追線、經彰化）分開算，appCoreRoutes 再合併成 Paths。路由帶 `Index`；生活圈端（appTrunkAccess）依 Index 取前 AccessPerStation 名。
    十四張→松山：大坪林→古亭→松江南京（轉 3 次）原本排第 14（每次轉乘加 10 分），改後第 2（指數 50.0，直達 46.1）。
  - **只跑綜合指數（2026-11 使用者選方案 A；規則：演算法必須完全優於或等於原本的，最佳解不能變差）**：短程與生活圈端只用綜合指數找路，不再跑原本的 Deeper 列舉。
    曾做過「原本的找路與指數各跑一次取聯集」（Search.IndexUnion），沒上過 dev 也沒 deploy，已刪除。
    補強（單用指數前 K 名會漏「某個出發時刻才最好」的走法）：
    - 直接轉乘演算法（Search.IndexOneTransfer，appIndexOneTransfer）：起訖（含同群組車站）所在路線兩兩配對，同線列直達、不同線時兩線上的轉乘站同站或同群組就是一次轉乘，補上指數截斷會丟掉的轉乘少但慢的走法。
    - 起訖兩端同群組的每個車站各自至少 Search.IndexPerEnd（3）條：主搜尋已經有 3 條用到該站就不另外找；不夠才限定該站再找一次，
      截斷線沿用主搜尋最好的指數（bestHint，找不到的站不必探到自己的截斷線，汐止→板橋這類段落 1764 次展開降到幾十次）。不當轉乘站的別名站（臺北-環島）不當下車站。
    - 已經過迄站排除名單夥伴站（DirectPairs）的部分路徑提早丟棄（迄站一定會經過，之後必然違反）。
    - 最少轉乘保底（Search.IndexMinTransfer＝3）：直達與一次轉乘都沒有時，用原本的列舉只找最少轉乘那一層（appNetworkRoutes deeper＝0，0.001～0.006 秒），依指數取前 3 條。
      指數搜尋每站（依抵達路線）展開上限會被之後才違反規則的部分路徑用掉：興南→淡海新市鎮的高鐵走法佔滿紅樹林，機捷直達台北車站轉淡水線（轉 2 次）排不進來，
      使用者選「轉乘最少」排序時第一筆變成轉 3 次。補上後與 dev 一致（環北→大直、領航→耕莘安康院區同）。
    - 生活圈端最少轉乘：轉乘最少的走法不在依指數的前 AccessPerStation 名時另外附上（appTrunkAccess 的 extra；原本的排序轉乘次數優先一定有它）。
      古亭→臺鐵松山前 3 名是經臺北、松江南京、東門轉乘的，直達（松山新店線）排第 4，古亭→新竹新莊「轉乘最少」因此從轉 2 次變成轉 3 次。
      附上的走法組出的組合不參加 MaxCombos 的估計排序（不擠掉原本選得到的）；組合裡搭乘段數最少的一條都沒選到時，每個幹線系統補估計最好的一條。
      試過「跳過從另一個臺鐵大站搭臺鐵進幹線站的生活圈端走法」：忠孝復興→南港─臺鐵→臺北再轉西部幹線往斗六、東湖→南港往高雄這類（南港沒有直達車）是正當走法，會漏，撤掉。
    - 機捷桃園段往返臺北經臺鐵中壢（補充資料 ViaTra，使用者規則 2026-11，手動維護）：機場第一航廈～老街溪的車站往返臺北生活圈時，
      路由經過臺北、板橋轉乘群組之後到機捷下車的那一段，換成「臺鐵 臺北／板橋→中壢 → 步行老街溪 → 機捷」（回程對稱）。整條路由層級的 Logic
      （APP_SUPPLEMENT_ROUTE_LOGIC，不是逐段改寫）；換掉沿途車站，所以自己重新跑 appPrefixExtend 與 appRoutePatternHit；每個樞紐站最多 MaxRoutes（2）條
      （appCoreRoutes 依估計由好到壞先到先留）；樞紐站前一段已經是臺鐵的不改（在樞紐原地換臺鐵，和另一個樞紐那條重複）。
      原因：台電大樓→興南的指數前 20 名全是「…→高鐵台北／板橋→桃園→機捷」的捷運轉法變體，臺鐵＋機捷（指數 113.4，實際 82 分）排不進來，
      RAPTOR 只在使用者條件下補一班、多樣性標籤只從已有的路由挑，都補不到；dev 靠段數列舉（4 段在最少＋deeper 以內）才有。
      Logic 不限業者：捷運路線、車種寫在 Meta.Line、Meta.Class，別的捷運要「改搭臺鐵到某站再轉回來」只要加一條設定。
      完整比對（加上後）：台電大樓→興南出發、抵達、末班都與 dev 一致；同站較好從 16 次變 52 次（中壢、老街溪附近的查詢），新的變差只有時段最後 5 分鐘 1 次。
    - 站間步行取代法（核心共用）：同一轉乘群組有兩個以上候選幹線站（臺鐵南港＋高鐵南港）時，生活圈端共用群組之間的核心路段，只對這種候選啟用（usePool）。
    - Deeper（API 的 deeper 參數）對短程與生活圈端不再有作用（只影響中長途幹線段、TRA.Via、IndexRoutes＝false 時的列舉）；前端不送 deeper，
      「轉乘最少」排序看的是 journeys 依時刻挑出的方案，與 Deeper 無關（見下方驗證）。
    - 原本的列舉（appNetworkRoutes）與生活圈端依原本排序取前 3（appTrunkAccess 的 dev 分支）保留不刪，註解說明短程棄用原因：
      Deeper 依轉乘次數截斷，轉乘多但實際比較快的走法（捷運平行轉乘、班距短的轉乘）會被丟掉；生活圈端依轉乘次數優先排序也一樣。
    試過「最後轉乘站多元」「生活圈端前 5」「保底併進主搜尋」「記憶共用」，漏失比補回多或沒有加速，撤掉。
  - **臺鐵候車依生活圈（使用者規則 2026-11，transfer_regions.json 的 TraWaitMinutes，手寫）**：該生活圈 TraHubs 大站全日平均候車（班距一半的平均），
    新竹 3.7、臺北 4.7 … 苗栗 12.1、宜蘭 13.9、花蓮 18.4 分；指數在這個生活圈搭臺鐵一律用它，臺鐵第一次上車也算候車（班次少、要照時刻表趕車，
    終點端從幹線站出來的第一次上車其實是轉乘）。高鐵左營→聖功醫院「紅線到凹子底換輕軌」因此進入前 3（原本被「新左營搭臺鐵到科工館」及其補充變體擠掉）。
    生活圈端維持每站前 3（試過前 5：組合上限 48 擠掉區間車接力組合，汐止、東湖→愛河之心只搭區間最快晚 11 分，改回 3）。
    嚴格比對：真正漏掉的最佳解 0 筆；剩下的差異都屬於使用者同意不處理的類別（時段最後 5 分鐘、幹線中途換車、不是最佳解、50 筆上限）；新版較好 38 次
  - 驗證（2026-11 本機，嚴格標準：dev 的每個方案新版都有出發不早、到達不晚、轉乘不多的方案，各標籤不變差）：
    出發時刻：同站（臺鐵與捷運同轉乘群組：板橋、南港、松山、臺北、萬華、鶯歌、中壢 × 21 個捷運站，往返 × 2 時段 × 2 種排除）1176 次變差 27、回歸 80 次變差 1、全台隨機 400 次變差 16、山海線 1024 次變差 4，
    幾乎都是使用者同意不處理的（時段最後 5 分鐘、幹線中途換車、50 筆上限、不是最佳解）；新版較好 38 次；計算時間 dev 577 秒、新版 554 秒。
    抵達時刻（by=arr）、首班（first）、末班（last）各 780 次（回歸 80＋隨機 400＋同站抽 300）：時間最佳漏掉的 5、3、3 筆（多半在抵達時段開頭 10 分鐘內，或差 1～2 分轉乘較少）；
    「轉乘最少」排序（sort=transfers）第一筆 780 次變差 1 次（永靖→台北小城：dev 轉 2 次 5 小時 12 分、新版轉 3 次 2 小時 31 分，原本就列為「不是最佳解」）。
  - 路網 BFS（appNetworkRoutes，IndexRoutes 關掉時的短程列舉；中長途的幹線段、TRA.Via 仍用它）：成本＝搭乘的系統段數（同一鐵路系統在交會站換線成本 0）；同一路線最多搭 Search.MaxLineUses（2）次，不分鐵路捷運（文湖線→板南線→文湖線、山線→海線→山線這類捷徑要能找到）；最多 Search.MaxLines 條路線；預設 deeper＝Search.Deeper。
  - 有支線的捷運路線（中和新蘆線蘆洲支線、淡水信義線新北投支線、松山新店線小碧潭支線）：appLinePaths 用 metro_travel 的營運路線站序，appRideStations 只在同一條站序上直接搭，跨支線回 null；appLineJunctions 找分岔站，BFS 給這種路線自連邊、在分岔站同站換車（蘆洲→大橋頭→三重）；兩段合起來能直接搭到的同線換車不列。transfer 模組的 lineStations 是一條直線（迴龍接三重國小、淡水接新北投），不能直接拿來切沿途車站。
  - 通則（寫死）：重複經過同一個車站（上下車站與沿途都算，同轉乘群組視為同一站，appStationKey）；跨支線不能直接搭。里程上限（起訖直線距離倍數）試過後使用者要求拿掉（不是基礎），改用 RouteBans 範圍規則。
  - 設定（transfer_route_rules.json）：DirectPairs（兩站都經過時要有一段同時包含兩站＝直接搭同一路線；每段搭乘或步行各算一段。以 StationUID 判斷，同轉乘群組同一鐵路系統的車站視同一站 appPairStation；不要改成轉乘群組判斷，試過會誤殺高鐵台中轉臺鐵）、LineTransfers、NoTransferStations（AliasOf）、Search。
  - 已刪除的死碼（使用者要求，實驗證明被通則＋名單涵蓋）：鐵路→只搭捷運→同一鐵路系統（隨機 150 組起訖拿掉後多 0 條）、同系統繞圈（多 0 條）、同系統鐵路車站之間步行（改成 NoTransferStations.AliasOf）、路線不重複搭（它擋掉了南港軟體園區→南港展覽館→板南線→忠孝復興→文湖線這類捷徑）。
  - 不轉乘的路由（同一路線或同一鐵路系統直通）一定保留：appCoreRoutes 取前 Search.NetworkLimit 個展開時不受限；排序轉乘次數優先所以排在前面。
  - 路由清單排序：轉乘次數 → 主幹速度等級（appRouteSpeedRank：主幹＝appRouteMainSystem 搭乘里程最多的系統，算不出里程時用行駛分鐘；APP_ROUTE_SPEED_RANK 高鐵 4、臺鐵 3、捷運 2、輕軌 1）→ 里程 → 估計分鐘。轉乘次數優先所以臺鐵直達（轉乘少）仍可排第一；同轉乘次數時主幹快的在前，例如臺北→高雄轉乘 1 次時高鐵主幹排在「高鐵到苗栗再轉臺鐵」（臺鐵 263 公里為主幹）前面。這個順序也決定 journeys 取哪些基本路由，同時刻、同轉乘的等價方案會改留主幹快的那條（七張→樹林改走紅線到臺北轉臺鐵，與環狀線到板橋同一班車）。
  - journeys 的慢方案：行車時間超過最短的 1.5 倍再多 30 分、因轉乘較少才留下的（高鐵為主時的臺鐵直達），依抵達、行車時間、出發排序時排在其他方案後面，不拿最早到達、最短行車時間的標籤；前端路徑清單（routeGroups）同樣排後面。
  - 營運業者保底（appRouteCoverPick）：展開前取前 NetworkLimit 個、輸出前取前 limit 條（journeys 取基本路由）時，候選中出現過、選中的卻沒有的業者鍵（appRouteCoverKeys：每段的系統 `op:`、主幹＝行駛分鐘最多的系統 `main:`、臺鐵主幹再分 `main:TRA.express` 可搭對號（含不限車種直達）／`main:TRA.local` 全程區間車）各補一條轉乘最少、估計最短的；輸出階段擠掉排在最後、拿掉也不會讓任何鍵消失的。捷運夾在兩段臺鐵／高鐵中間的路由（appRouteCoverEligible）不拿來補，它的鍵也不算應有。案例：新店→小港原本前 40 個基本路由全是高鐵走法，臺鐵直達（轉乘 2 次）在展開前就被截掉；保底後排第 1，區間車接力也補進來。核心 B 環狀線繞遠方向的直達照列（LongWay，排在後面）；journeys 各標籤代表方案不在前 limit 個也附上。
  - railtime 比對（tratrtc.html 的 getRoute_allRouteByFromTo，node 載入 ttlib，站碼以站名對應）：北北基桃 406 組起訖，railtime 的路由我們沒有的 75 條中 65 條是重複經過車站（railtime 沒有這條通則，例如汐止→板橋→板南線→忠孝復興），其餘是轉乘多又繞遠、排在 NetworkLimit 之後的，或站名資料缺漏；最佳抵達 200 組比對新版不比舊版差（支線修正後 2 組變好）。
- **系統直達段**（使用者 2026-10 設計）：臺鐵、高鐵的連續搭乘在 appNetworkRoute 組好後由 appCollapseSystemLegs 合成一段，LineUID＝業者代碼（`TRA`），點對點查站到站直達（八堵不停的 402 次也查得到，不必再合併 Through 單元）；經過的路線串放 `Path`（[{LineUID, From, To}...]），同代碼的路由去重時聯集成 `Paths`，主要的（`Path`）取里程最短（appPathKm）。DFS 剪枝仍用合併前逐路線的段落（RouteBans、DirectPairs、重複經過都照舊）。appLegMinutes 對業者代碼用系統站到站最短；沒有直達車時估計用 Path 各段加總。核心 B 對 TRA／THSR 不分路線（line＝null）。前端顯示用 `Paths`（appRouteDescribe 帶 OperatorName、Direct）。
- **補充資料**（`libs/txapi/transfer/data/transfer_route_supplements.json`，人工維護、受版控；`app/supplement.php`）：自動找完、估計排序後，對取出的每條基本路由逐一比對 `Supplements` 陣列，條件百分之百符合的各多一條路由（一條補充只多一條；同一路由裡所有符合的搭乘段一起改寫）。條件都是陣列：`Operators`（必填，["TRA"]，跨業者 ["TRA","TRTC"]）、`Classes`（會用到的等級，extra 排除其中任何一種就不套用）、`From`／`To`（車站代碼或 [{Line, StationRange?}] 範圍，可混寫）、`ViaLines`／`ViaStations`。`Logic` 指定處理函式（APP_SUPPLEMENT_LOGIC），業者前綴的只讀自己的 `Meta`：
  - `TRA.LocalRelay`：全程區間車，在 Meta.RelayStations 分切，每小段能直達就一段，不能就在其他同站換車站再切
  - `TRA.HubTransfer`（2026-11 使用者規則）：中長途（搭乘段起訖的生活圈不相鄰，appTraHubLeg）的臺鐵段，除了起訖站直達，也經由生活圈大站轉對號（transfer_regions.json 的 `TraHubs`，參考 railtime tra.station_ary 的 big，南港另外加 e、新左營另外加 w；`TraTrunks` 定義 e 東部幹線樹林—臺東、w 西部幹線基隆—潮州含海線、s 南迴新左營—臺東，共用區段兩條都算）。
    上車端：起站是這條幹線的大站就從起站上；不是時取起點生活圈內離起站最近的大站，往行進方向、往反方向各最多 APP_HUB_PER_SIDE＝2 個（使用者規則：五堵往南只要松山、臺北或七堵，不用板橋；汐科往花蓮用七堵、八堵或南港、松山；往反方向的先搭區間車回大站，對號再經過起站），生活圈內往前已經沒有大站（鶯歌、鳳鳴往南）就沿站序找第一個大站（桃園）；起站有對號停靠時也保留起站上車。下車端對稱（到達前、越過終點各兩個）。
    臺鐵段前面（後面）接捷運、輕軌等其他系統時（appSupNeighborSystem 給 Logic 的 _PrevSystem／_NextSystem），那一端只能在端點本身上下對號（端點有對號停靠時），不產生「大站下車、區間車到小站再轉捷運」（內壢→內惟轉輕軌這類基本路由每條原本展開 5～8 種，路由數因此暴增）；捷運、輕軌在大站直接轉的走法由其他基本路由涵蓋。
    同分取捨（journeys 互不支配、保底代表）：出發、到達、轉乘都相同時，幹線（臺鐵對號、高鐵）坐得久的優先（appJourneyTrunkMinutes）：對號在最靠近目的地的大站下車（愛河之心→汐止坐到松山，不在板橋下）、最靠近起點的大站上車。
    幹線：上車端看起站往前第一個只屬於一條幹線的車站（五堵往南 w、往宜蘭 e），下車端從終點往回看。一段可以有好幾種改法（回 `Alternatives`，每種各一條路由，名稱帶「松山上、新左營下」），中長途不再套用 ExpressTransfer，起訖站是捷運、輕軌時臺鐵段也用同一規則。
    有轉乘、臺鐵段又沒有直達車的基本路由（五堵→新左營＋高雄捷運），原本在套用補充資料前就被丟掉（只留不轉乘的），現在用大站轉乘後最好的估計（appRouteHubEstimate）排進展開清單，展開時只套用大站轉乘。修正前五堵、內壢往高雄捷運不搭高鐵時，只剩機捷繞路（五堵→臺北→機捷→老街溪→中壢轉對號），汐止只有汐止直接上的對號
  - `TRA.ExpressTransfer`（中長途不套用）：Meta.Board／Alight（big 大站＝快車每日停靠 ≥10 且有同站換車；major 主要站＝快車停靠次數 ≥ 沿途最多那站 × MajorRatio）四種組合，區間車到上快車站、對號到下快車站、區間車到終點
  - `TRA.ChangeAt`：在 Meta.Stations（路線交會站）換車；OnlyLineChange＝只在路線串真的換線的地方切，切出的後段標 Through（同一班車繼續坐也串得到，不算轉乘）
  - `TRA.Via`：搭乘段改成「起站→Meta.Via 的站→迄站」兩段臺鐵系統直達段（各取里程最短的路線串），每個換車站各一條（Alternatives）。
    用在路線串路徑不經過、實際上常在那裡換車折返的車站。2026-11 使用者規則：山線（造橋～成功）與海線（談文～追分）之間經竹南、彰化換車，往返各一條補充資料。
    起因：成追線班次少，最快常是到彰化（或竹南）折返；原本的系統直達段經不同路線串的走法合成同一條路由、主要路徑取里程最短（成追線），
    交會站換車只切在成功、追分，加上排除名單「成功—追分」把經彰化折返的走法刪掉（該項已設 Disabled，產生器重算沿用）。
    本機 32 組山海線起訖（往返）× 06～21 點 × 不限／只搭區間：RAPTOR 比原本演算法早到的時段 420 → 35（剩下的是臺中換成追線班次、轉兩次以上），
    方案數 +29%，其他 80 組查詢輸出不變，計算時間 +13%（只限山海線之間）
  - `ClassChange`：高鐵台中、機捷 A8 換快車或慢車（起訖一端快車很少停時）
  補充路由帶 `Supplement`（名稱）與 `BaseRoute`（基本路由代碼），routes API 也輸出。journeys 用 `variants`：取前 limit 條基本路由，它們的補充路由全部帶上（補充路由段數多，照排序會被擠出去）；本身沒有直達車、不在清單的直達路由（枋山→東海）它的補充路由也帶上。
- routes 不排除繞遠的走法（使用者 2026-10：高鐵往回坐一站再轉臺鐵，抵達較快就有保留價值，由時刻端判斷）；改算里程（appRouteDistance，transferRideDistance 沿路線相鄰站加總），依轉乘次數 → 里程 → 估計分鐘排序，附 DistanceRatio
- 組合（`app/journey.php`）：不停交會站的直達車（臺北 06:05 往花蓮的 402 次不停八堵）由系統直達段處理（舊的 appChainUnits 合併單元已刪）；補充路由切出的 Through 段串接時同一班車繼續坐也算得到。互不支配同分時取搭車數少的。appChainRoute 串接時同一班車繼續坐優先（發車不早於到站即可，不加換車分鐘），合併成一段輸出；journeys 合併所有路由的方案後取互不支配、去掉行車時間超過最短的 1.5 倍＋30 分又沒有比較少轉乘的。反向串接 appChainRouteBack（arrive、last 模式）：最後一段在抵達時段內每班都試，往前每段取「到達 ≤ 下一段發車−換車分鐘」中最晚發車的（appLatestBy，appLegTrains 的 arrs／late 索引），同一班車繼續坐優先；已用前向串接交叉驗證（反向每個方案從同一出發時刻前向都到得了）。起終點步行在迴圈裡加減，不要再另外加（曾經重複加過終點步行）
- 已完成：共用層、route 代碼、列車分類、public_、核心 B、app_trip/direct、app_station、app_train、核心 A、app_trip/routes|route_trains|journeys（depart、arrive、first、last 模式；first／last 是整個營運日 04:00～27:59）、app_line/timetable（`app/line.php`）。
- 時段參數（使用者 2026-10 定）：所有時間參數用 `start`／`end`，`by=dep|arr` 指定比出發或抵達（appTimeWindow；direct、route_trains、journeys 共用，by=arr 用 appChainRouteBack）；舊的 depart／arrive／time 照收不混用。direct 每班帶 `Path`（appTrainPath：沿停靠站找共同路線，不停交會站時在交會站切開；TripLine 2 優先海線）；方向用 TDX 的 `Direction` 0／1（使用者：TDX 有就用 TDX 的值；臺鐵 0 順行＝順時針［西部北上、東部南下］、1 逆行；高雄輕軌 0＝順時針），`direction=0|1` 篩選（舊名 dir 照收）；繞東部的車照列、由使用者判斷（使用者：只要經過路線、停靠站、時刻都查得到）。
- 使用者 2026-10 決定：只給 start 時 end＝start＋系統最長查詢時段（QUERY_MAX_RANGE_MINUTES，appTimeWindowSpan；只給 end 往前同樣長度）；路線時刻表支援 by=arr（比最後一個顯示車站的時刻 ArrivalSortMinute），單站時刻表不用；時刻欄位不寫 24 時以後，維持 00～23 時，但每個時刻都附營運日分鐘數（*Minute、Times 的 ArrMinute／DepMinute、停靠站的 ArrivalMinute／DepartureMinute，appMinuteNear／appStopMinutes），前端用分鐘數判斷跨日；離線資料只用星期幾（不接指定日期時刻表）；會員系統先保留
- 使用者 2026-10 決定（不要再提案）：方向不靠 TDX 的 Direction 判斷（找車一律看同一班車先停上車站、後停下車站，區間車、支線自然正確；大橋頭往迴龍、往蘆洲在 TDX 都是 Direction 0，只看 Direction 會錯）；路網搜尋不用方向剪枝，也不加「同一營運路線同方向不搭兩次」：有時是必要且比較快，例如新店→松山在古亭轉中和新蘆線到松江南京再搭回松山新店線、南港展覽館→土城在南港轉高鐵到板橋再搭回板南線（實測 17,790 條基本路由中同一路線反方向再搭的 569 條裡有 8 條是最佳解）。
- 線上 TDX 資料（未來的票價、指定用 TDX 最新時刻表）：用 railCacheFetchData($path, $forceCheck)（libs/railcache.php），與 /api/ 共用快取；憑證看 X-TDX-Authorization（requestUserToken；/txapi/ 的 Authorization 是本站身分，不送 TDX），沒帶用本站金鑰，自帶被拒不改用本站金鑰；回傳 source（cache／revalidated／tdx／stale／error）與 credential
- 路線時刻表 app_line/timetable：臺鐵、高鐵用路網時刻資料的車次（只有停靠站），捷運用整列車 mroutes，沒有的路線（文湖線、臺中捷運）用營運路線首站發車＋站間時間推算。appLineCells 把停靠站依序對到列：相鄰兩停靠站都在路線上才相連，中間的列是「通過」；不相連的單一站丟掉（山線車在海線的竹南、彰化），多數相鄰站往反方向的車排除。臺鐵 TripLine 1＝山線、2＝海線，兩站間沒停靠時用它避免把海線車畫成通過山線。環狀線（有班次首末站相同）首站接在最後。欄依 SortMinute（換算到第一個顯示車站的時刻，appLineOffsets 用相鄰列的時間差中位數累加）排序，start／end 也比它，翻頁用 next

## railtime 與 rocptx 的轉乘演算法（2026-10 讀過，唯讀參考）

### railtime（tratrtc.html＝index.html，ttlib/fn.js、data.js）

- 車站 ID：`tra_` ＋ v3 站碼、`trtc_` 舊數字碼或 `trtc_br01`、`tymetro_a01`；路線 `tra_xibu`（西部幹線基隆—竹南）、`tra_shan`、`tra_hai`、
  `tra_zhjy`、`tra_jygx`、`tra_pingdong`、`tra_yilan`、`tra_beihui`、`tra_huadong` 等（臺鐵路線切成區段），`trtc_1～7`、`tymetro_1`
- `transStation`（80 筆）：`changeLine`、`changeStation`、`walkMinute`；臺鐵大站同站換車也是一筆（例如 `qidutra1`）
- `routeMap`：每對路線手寫可用的轉乘站清單，並以起訖站的區域（`sect`，17 區）過濾；`routeSystem` 三類規則：
  `direct`（同公司可直通的路線組）、`trans`（依起站區域或車站決定轉車站）、`cross`（跨公司依序經過的轉乘站，每段遞迴求解）
- 臺鐵長途（`getTRA_linkRouteV1`）：路線 `link` DFS 找路線串 → 依區間車運行範圍（`running_ary`）切區間車段 →
  跨兩區以上時在第一、最後大站（`big`）之間改搭快車（`mustStop`），另加主要站、另一頭較近大站、快車本身停起站等變化；
  東西部互通經臺北切開（南迴的高雄、臺東切法已寫但未開）
- 時刻串接：每段取時刻表，下一段取「到站＋walkMinute」後第一班（同一列車不加），同一銜接只留最晚出發的（bestStart／bestEnd）

### rocptx（src/router.v2.js）

- 只處理單一捷運系統：每條線切成 block（轉乘站自成 transfer block、兩轉乘站之間的車站合成 station block），
  `near`＝同線相鄰 block、`toIDList`＝轉乘到他線車站；從起站 block 廣度展開（最多 100 輪、找到 20 條就停），
  濾掉重複經過同一轉乘站的迂迴路徑，組成搭乘段＋LineTransfer 轉乘，加總 S2S 行駛時間；依搭乘段數、站數、時間排序
- 跨公司轉乘目標直接略過；不看時刻表；機捷 A8 動態加一筆 TrainType1↔2 轉乘 2 分

### 本站與兩者的差異

- 不手寫路線組合或大站規則：轉乘群組由 TDX 與座標產生，路網結構由群組推導，時刻表搜尋（RAPTOR）用真實車次自動找出區間車轉快車、東西部互通、跨系統轉乘
- 單一 StationUID 與「系統-LineID」，臺鐵路線用 TDX 的 WL、EL 等（不切區段；區段只用於顯示，finder `line_section`）

## 已知限制

- 捷運到站為推算（上車站離站＋站間時間），沒有對到迄站時刻表
- 跨系統步行有一半是座標推算（estimate），有實際資料時加到 seed `Walks`
- 臺鐵 `臺北-環島`（TRA-1001）放在臺北群組，與臺北之間 4 分為推算
- 沒有班次的車站（新馬 TRA-7140 等）查不到方案，回 note

### 找路規則檔與組合加速（transfer_route_rules.json）

- 檔案：`libs/txapi/transfer/data/transfer_route_rules.json`（`transferDataFile('RouteRules')`），兩種名單：
  - `DirectPairs`（排除名單，不這樣走）：`{"Stations":[A,B],"Line":..,"Source":"manual|generated","Evidence":{...},"Disabled":false}`。兩站都經過時，要有一段搭乘同時包含兩站（直接搭同一路線）。
  - `RouteBans`：`{"From":[{"Line":"TRA-WL","StationRange":["TRA-4080","TRA-4400"]}],"Via":["TRA-1000"],"To":[{"Line":"TRA-EL","StationRange":[...]}]}`。Line 必填、StationRange 選填（appRouteBanScope：一般路線取兩站之間；環狀線依 lineStations 順序從 A 往後數到 B 跨過終點，先後決定弧）；To 省略＝同 From。編譯成 站 => [規則, 角色位元 1=From 2=Via 4=To]（appCompileRouteBans），appRouteBanStep 依段推進各規則階段 0→1（From）→2（Via）→To＝排除；步行或換系統重來；每段每狀態快取。產生器 `--only=bans` 讀 `RouteBanRings`（Path 依環線方向串路線範圍、Boundaries 分界站要放在路線交會站、Parallel 山海線並行區間不當 Via、MinRatio 1.5），每對區段所有起訖都同一邊遠且比值 ≥ MinRatio 才產生，Via 取遠邊中間的分界站；同區段來回一律產生。臺鐵環島 16 區段產生 168 條。規則關掉時不生效。AnySystem＝跨系統也適用（整條路由不重來，Via 放遠邊全部車站）；高雄環狀輕軌試過後停用（Disabled）：兩方向班次不對稱，短的一邊沒車時轉高捷繞過去反而快，依里程擋會擋掉已知最佳解。
  - `RouteBans` 的轉乘走法排除（2026-10，使用者：railtime 52 筆能合併就合併、同性質只寫一次、多種資料格式一次進過濾）：`{"Transfers":[[範圍清單],...],"Ends":{"Pair":[A,B],"Any":[...],"All":[...]}}`。appCompileRoutePatterns 編成群組集合；appRoutePatternsFor 依起訖挑出生效的（轉乘點含起站或迄站的整條不用：本站那是步行，走法已不同）；appNetworkRoutes 組好完整路由時 appRoutePatternHit 比轉乘點串（下車站或上車站群組，正反向完全相同才排除；不轉乘的路由一律不擋）。範圍清單 appRouteBanScope 也收單一 StationUID、{Stations}、{Regex}。railtime 改寫用 `agent/claude/compare/railtime_bypass.php --write`（fromToLine→Ends.Pair、bypassStationReg→Ends.Any、bypassBothStationReg→Ends.All、transStation→Transfers；保留人工 Disabled）。驗證：每條規則依 Ends 抽 12 組（共 586 組）× 5 個時段，開關比較，最早到達／最少轉乘／最短行車時間有變差的規則停用（11 條，DisabledReason 記例子），其餘 41 條在這些起訖少了約 3% 基本路由、方案完全不變；50 條回歸與高雄、北北基桃、bench 基準都不變。railtime 的規則是不看時刻的經驗（例如松山換松山新店線），本站以時刻算出的最佳為準。
  - `Search`：Deeper、MaxLines、MaxLineUses、NetworkLimit、DefaultLimit、MaxQueue、MaxRoutes、Index*（綜合指數找路）（appRouteSearch；沒寫用 APP_ROUTE_SEARCH_DEFAULTS；規則關掉時仍適用）。
  - `LineTransfers`（加速清單，只這樣走）：`{"Lines":[L1,L2],"Groups":[gid,...]}`，兩條路線之間只用列出的轉乘群組；目前產生器判定每個群組都對某些起訖有用，所以是空的。
  - `NoTransferStations`（人工）：`{"Station":"TRA-1001","AliasOf":"TRA-1000",...}`，資料上的別名站不當轉乘站、與 AliasOf 之間不步行；起訖是它時 appCoreRoutes 直接換成 AliasOf 查（appNoTransferStations；規則關掉時仍生效）。DirectPairs 比對用 appPairStation：同一轉乘群組裡同一鐵路系統的車站取代碼最小的（TRA-1001 視同 TRA-1000），名單本身不必重複列別名。加入後 25 組起訖基本組合 2,087 → 1,611，最佳抵達不變。
- 產生器：`php agent/skills/rail-transfer/scripts/build_route_rules.php [--dry] [--only=lines|pairs] [--line=X]`。
  - DirectPairs：同線兩個轉乘站，直接搭（臺鐵用區間車時間）＋max(5 分, 10%) ≤ 規則關閉時 appCoreRoutes 找到的最佳其他走法（有步行或搭了別的路線，含同系統換線、繞環島；系統直達段看 appLegPath 的路線串，全程在這條路線才算直接搭），才列入。系統直達段改版後重跑：239 → 256 條（多了八堵—南港、八堵—瑞芳、竹中—六家等原本被自己的直達路由當成繞道而漏掉的），回歸與比對都沒有退步。
  - 人工項目、`Disabled` 都會保留；`--line` 只重算該線。資料更新（新路線、新轉乘站）後要重跑並提交 git。
- 執行方式（routing.php）：
  - appNetworkRoutes 路線圖 BFS 先從終點路線反向 0-1 BFS 算每條路線到終點的最少成本 h，展開時 成本＋h 超過 最少＋deeper 就不排入（找到的路線串不變，399 組隨機起訖驗證相同）。
  - appNetworkRoutes 展開轉乘站組合用深度優先，每加一段就用 appPrefixExtend 逐段檢查（跨支線、同線無謂換車、重複經過、RouteBans、DirectPairs）。違反的前綴不再往下展開，最後一段（到下車站＋步行到終點）也檢查。
  - 回傳的基本路由都已檢查過，所以 appCoreRoutes 不再呼叫 appRouteRevisits。快慢車展開只在同一串車站上分切，也不再檢查。
  - DirectPairs 狀態：segOf（站 => 最早段落 << 8 | 最晚段落），每段的夥伴資訊 appLegPartnerInfo 有快取。
  - appRouteRulesEnabled(false) 可關掉規則（產生器估算、效能比較用）。
- 臺鐵／高鐵估計分鐘：appRailMinutesTable() 預先算好 `'a|b' => [any, local, express]`，快取在 `TDX_CACHE_DIR/app_rail_minutes.ser`，以 dataUpdatedAt＋ClassChange md5 為鍵。
- 效能基準（25 組起訖，week 1 08:00–10:00）：

  | 版本／規則 | 基本組合 | coreA | 串接 |
  |---|---|---|---|
  | 舊版（展開取前 400） | 9,796 | 約 1.76 s | — |
  | 現行，規則關 | 2,456 | 約 0.83 s | 約 1.00 s |
  | 現行，規則開 | 2,087 | 約 0.82 s | 約 0.88 s |
  | 重構後（死碼移到設定、支線） | 1,664 | 約 0.86 s | 約 0.54 s |
  | RouteBans 取代里程上限 | 1,699 | 約 1.05 s | 約 0.65 s |
  | 系統直達段＋補充資料（variants） | 1,746 | 約 1.9 s | 約 1.3 s |
  | ＋路線圖剪枝下界（反向 0-1 BFS） | 1,734 | 約 1.4 s | 約 1.3 s |

  最佳抵達時間完全相同。

### 路由回歸測試（改路由演算法或找路規則後一定要跑）

- `php agent/skills/rail-transfer/scripts/route_regression.php`：跑 `agent/skills/rail-transfer/tests/route_cases.json` 的已知最佳化案例（使用者要求 50 條，之後補到 53 條），全部要通過；`--case=ID` 只跑一條。
- 每條比對 journeys 三個標籤（Fastest／ShortestTime／FewestTransfers）的出發、抵達、轉乘、行車分鐘（與 API 同一個 appJourneysCompute，帶 variants），以及 routes 的 routes_include（一定要有）、paths_include（系統直達段的路線串「站.路線.站…」至少一條路由有）、routes_exclude（路由代碼與路線串都不能含有）。路由代碼不同但時刻相同只提示。
- 結果改變時先判斷是改善（更早到、更少轉乘、更短）還是退步；退步就要修演算法或規則，不能直接改期望值。確認是改善或資料更新造成的，才用 `--update` 重寫 expect（會記錄資料版本 DataUpdatedAt）。
- 案例涵蓋：汐止→樹林只走臺鐵、汐止→忠孝復興不經板橋、直達一定列出（大坪林→松山、南港展覽館→動物園）、海線保留、環島不回頭、支線換車、文湖線→板南線捷徑、別名站、機捷與高鐵快慢車換車、輕軌兩方向班次不對稱、arrive／first／last 模式、extra 排除。新增情境時加案例（id 不重複）。
