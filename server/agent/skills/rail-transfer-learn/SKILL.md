---
name: rail-transfer-learn
description: 離線時刻表更新後，重新演算轉乘加速清單（推薦換車站、實際使用的地區走廊），產生 custom/libs/txapi/transfer/data/transfer_accel_learned.json。下載同步、download_to_sqlite 重建資料庫、或修改 transfer_accel.json 的地區與走廊之後照做。
---

# 轉乘加速清單的離線再演算

轉乘查詢（`/txapi/transfer/route`）的加速清單不用手寫：在離線資料（`sqlite/finder.sqlite`）更新後，
用全部時刻表跑一次完整搜尋，從「最佳方案實際怎麼走」算出靜態資料，存成 JSON（PHP 與 Node.js 共用）。

## 什麼時候要跑

- `/txapi/admin_download_to_sqlite/build` 重建資料庫之後（回應的 `next` 會提醒）。資料庫建置時間（`_meta.built_at`）一變，
  舊的清單就算過期：查詢預設改為不套用加速，直到重算完成
- 修改 `libs/txapi/transfer/data/transfer_accel.json` 的地區（Regions）、走廊（Corridors）或 Learn 設定之後
- 轉乘群組有變（先跑 `func=build_groups`）之後

## 步驟

整套資料更新的串接流程見 [data-pipeline](../data-pipeline/SKILL.md)；這裡是「最佳化加速清單演算」階段的細節。

1. 先備份（方式見 `custom/agent/` 的私密筆記）；產生檔改寫前的舊版也會自動留在 `custom/libs/txapi/transfer/data/history/`
2. 執行：`TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php learn --verify=60`
   - 它會先呼叫 `/txapi/admin_transfer/build_groups`（轉乘群組與里程；內容沒變不重寫檔案），再重複呼叫 `/txapi/admin_transfer/learn`
     直到 `done=true`。每次最多算 `Learn.ChunkSeconds` 秒並把進度存在 `download/tdx_proxy_files/transfer/transfer_learn_state.json`（暫存，不進版控），下一次接著算；
     完成後寫出 `custom/libs/txapi/transfer/data/transfer_accel_learned.json`（舊版留在 `history/`）並刪除進度檔
   - 清單未過期（資料庫建置時間、轉乘群組檔、`transfer_accel.json` 都沒變）時 `func=learn` 直接回 `skipped`，不重算；`--force` 照樣重算，`--reset` 捨棄沒算完的進度
   - 有 shell 的機器也可以直接 `php libs/txapi/transfer/learn.php [--force] [--reset]`，一次算完（約 15 分鐘）
   - 摘要的 `changedFiles` 列出這次有變動的產生檔；轉車出問題時 `/txapi/admin_transfer/history` 看舊版、`func=restore` 換回
3. 檢查 `/txapi/transfer/accel`：`default` 要是 on、`learned.Fresh` 要是 true；看 `LearnedHubs`（推薦換車站與使用次數）、
   `EffectiveCorridors[].Added`／`Evidence`（被最佳方案證明要放寬的走廊與例子）、`ManualHubsUnused`（手寫但沒用到的站）
4. 驗證（`--verify=60` 已包含；也可單獨跑）：`TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/rail-transfer-learn/scripts/verify_accel.php --pairs=60`（帶後臺 PAT 不受查詢次數限制），
   比對 accel=1 與 accel=0 的方案集合；不同的組數應該是 0 或很少，列出的例子要合理

## 演算法（libs/txapi/transfer/learn.php）

- 起站：`Learn.OriginSystems`（臺鐵、高鐵）的全部車站，加上所有多站轉乘群組的車站（捷運與臺鐵、高鐵的轉乘站）
- 每個起站、每個分析日（`Learn.Days`，平日、週五、週六、週日）、`From`～`To` 每 `StepMinutes` 分鐘出發，
  跑一次一對多的逐輪搜尋（`transferRaptor($d, $from, null, …, $labels)`：不設迄站、不剪枝、不套用加速）
- 每個到得了的車站（搭車直接到，或到同群組車站再步行），取各輪「搭 k 班車比搭更少班車更早到」的結果（互不支配），沿來源指標還原：
  - 臺鐵同站換車的車站 → 次數累計；次數 ≥ `MinUses` 的列為推薦換車站（`LearnedHubs`），取代手寫的 `TransferHubs`
  - 上下車站所在地區不在起訖地區組合的手寫走廊內 → 累計次數。用到某個走廊外地區的方案占該地區組合全部最佳方案的比例
    ≥ `Learn.CorridorMinShare`（預設 0.001＝0.1%）就把該地區加進走廊（`EffectiveCorridors[].Added`），否則維持排除並記在 `LossShare`
    （加速時可能少掉的比例）。所以地理走廊只在「幾乎不影響結果」的範圍內保留排除效果；設 0 則只要用到就放寬
- 2026-10 第一次結果（327 個起站 × 4 天 × 每 20 分鐘，本機 992 秒、6.8 萬次搜尋、5,557 萬個方案）：
  推薦換車站 77 個（手寫 49 個，其中臺北-環島、新烏日沒用到；新增礁溪、壽豐、北湖、后里等 30 個）；
  走廊被放寬的例子：N↔N 加 C（南港→崎頂經高鐵苗栗折返 1.4%）、C↔S 加 N（苗栗→嘉義先到新竹轉高鐵 1.4%）、C↔SL 加 N；
  維持排除：N↔S 不經 SL、E、NE（0.02%）、S↔S 不經 C、N（0.02%）、S↔SL（0.03%）。
  驗證：73 組固定查詢與 197 組隨機起訖，套用與不套用方案完全相同，伺服器時間少約 4～5%
- 結果記下資料庫建置時間 `DbBuiltAt` 與輸入雜湊 `InputsHash`（`transfer_groups.json`、`transfer_accel.json` 的 SHA-1）；查詢時任一不同就視為過期（`Fresh=false`）

## 查詢時怎麼用（libs/txapi/transfer/accel.php）

- `accel` 省略：有算好且未過期的 `transfer_accel_learned.json` 就套用，否則不套用
- `accel=1`：一律套用（沒有算好的清單時用手寫備用清單）；`accel=0`：不套用（完整搜尋）
- 套用時：起訖地區組合的 `EffectiveCorridors` 以外的地區車站不展開；臺鐵同站換車只在 `LearnedHubs` 或起訖站附近
  （`Settings.LocalMinutes` 分鐘內）的車站
- 回應的 `accel` 欄位：`Source`（learned／manual）、`Fresh`、可經過的地區、不展開與不能換車的車站數

## 資料檔

| 檔案 | 誰寫 | 內容 |
| --- | --- | --- |
| `data/transfer_accel.json` | 人工 | 地區（Regions）、手寫走廊（Corridors）、手寫備用換車站（TransferHubs）、加速設定（Settings）、分析設定（Learn） |
| `custom/libs/txapi/transfer/data/transfer_accel_learned.json`（舊版在 `history/`） | /txapi/admin_transfer/learn | LearnedHubs、EffectiveCorridors、DbBuiltAt、統計 |
| `download/tdx_proxy_files/transfer/transfer_learn_state.json`（暫存，不進版控） | func=learn | 分析進度（完成後刪除） |

## 注意

- 分析是抽樣出發時刻（預設每 20 分鐘），不是每一分鐘；抽樣之間才會出現的最佳方案可能沒被記到，`verify_accel.php` 用來抓這種漏網之魚
- 起站沒有包含所有捷運站（只有轉乘站），捷運站出發的方案靠轉乘站的結果涵蓋
- 不要手改 `transfer_accel_learned.json`；要調整就改 `transfer_accel.json` 再重算
