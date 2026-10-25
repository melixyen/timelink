---
name: data-pipeline
description: 本站的資料更新流程：離線資料下載（TDX 同步、重建 finder 資料庫）與最佳化加速清單演算（轉乘群組、里程、推薦換車站與走廊），可以只跑其中一段，也可以串起來一段做完再做下一段。更新資料、排程或檢查流程時照做。
---

# 資料更新流程（可分段、可串接）

所有程式都是 PHP：網站本身（`libs/`）與 AI 代理用的腳本（`agent/skills/*/scripts/*.php`）。腳本只需要 PHP 命令列與 curl 擴充，
透過網站的後臺 API（admin_ 開頭的 action，帶後臺 PAT）執行，所以在 AI 代理的工作區、你的電腦、或主機的排程都能跑。
站址與憑證只放環境變數：`TDX_BASE`（站址）、`TDX_PAT`（後臺 PAT；admin_ 開頭的 action 只接受 PAT）。這個站的實際站址與 PAT 存放位置見 `custom/agent/` 的私密筆記。

## 一行指令

```
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php download          # 只下載
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php learn --verify=60 # 只重算加速清單並驗證
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php download learn    # 先下載，完成後接著重算
```

階段照寫的順序執行，前一個失敗就停下來。過程寫到 STDERR，最後一行 STDOUT 是摘要（JSON）；結束碼 0 成功、1 失敗或驗證不一致、2 用法錯誤。

| 選項 | 說明 |
| --- | --- |
| `--base=URL` | 網站，預設環境變數 `TDX_BASE`，再沒有用 `http://localhost` |
| `--key=PAT` | 後臺 PAT；建議用環境變數 `TDX_PAT`，不留在命令列歷史 |
| `--verify=N` | learn 完成後抽 N 組起訖，比對加速開與關的結果 |
| `--force` | learn：加速清單沒過期也重算 |
| `--reset` | learn：捨棄沒算完的進度，從頭算 |
| `--max-minutes=N` | 整個流程最多跑幾分鐘，預設 180；超過就停，進度留在網站上，下次接著做 |

## 階段一：離線資料下載（download）

```
重複 GET /txapi/admin_download/sync?service=tdx（Authorization: Bearer <PAT>）
  ├─ 每次最多抓 4 個到期的檔案；TDX 每分鐘最多 3 次呼叫、保留流量餘額，超過就停，回應 next_in 秒後再呼叫
  ├─ 連續 3 次都有檔案失敗就停止
  └─ remaining = 0（done）時，網站自動判斷下載檔有沒有變：
        有變 → 重建 sqlite/finder.sqlite（等同 /txapi/admin_download_to_sqlite/build），_meta.built_at 更新
        沒變 → 不重建
```

- 哪些檔案、多久更新一次：`libs/txapi/download.php` 的下載清單（`/txapi/download/list?service=tdx` 可看）
- 只想重建資料庫：`/txapi/admin_download_to_sqlite/build`（後臺寫入權限）
- 資料庫重建後，加速清單自動視為過期，查詢改為不套用加速（結果正確，只是慢一點），直到跑完階段二

## 階段二：最佳化加速清單演算（learn）

```
GET /txapi/admin_transfer/build_groups（Authorization: Bearer <PAT>）
  ├─ 由下載檔（TDX 捷運與臺鐵轉乘資料、車站座標）偵測轉乘連結，與 transfer_links.json 比對：只加入新的，不移除舊的
  ├─ 加上 transfer_seed.json 的人工群組、人工兩站、同站換車，產生轉乘群組與里程；人工排除（ExcludePairs）的連結刪掉
  ├─ 回應的 links.added（新加入）、links.notDetected（沒再偵測到）給人工檢查
  └─ 內容沒變就不重寫 custom/libs/txapi/transfer/data/ 的 transfer_groups.json／transfer_distance.json／transfer_links.json（有變時舊版留在 history/）
重複 GET /txapi/admin_transfer/learn（Authorization: Bearer <PAT>）
  ├─ 加速清單沒過期（資料庫建置時間、轉乘群組檔、transfer_accel.json 都沒變）→ 直接回 skipped，不重算
  ├─ 否則每次算 20 秒、存進度，重複呼叫直到 done（上線版約 16 分鐘、約 50 次呼叫）
  └─ 完成時寫出 transfer_accel_learned.json（推薦換車站、實際使用的走廊），查詢預設開始套用
（--verify=N）抽 N 組起訖比對加速開與關的方案
```

細節見 [rail-transfer-learn](../rail-transfer-learn/SKILL.md)。

## 什麼時候跑哪一段

| 情況 | 指令 |
| --- | --- |
| 定期更新資料 | `download learn`（下載沒變時 learn 會自動跳過） |
| 改了 `transfer_seed.json`（人工轉乘群組、步行分鐘） | `learn` |
| 改了 `transfer_accel.json`（地區、走廊、分析設定） | `learn --verify=60` |
| 改了演算法程式，想確認加速清單仍正確 | `learn --force --verify=60` |

## 排程（不需要 AI 代理）

整個流程是確定性的，可以交給主機的排程（cron），每天一次：

```
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php /<網站根目錄>/agent/skills/data-pipeline/scripts/pipeline.php download learn --max-minutes=50 >> /<可寫的路徑>/pipeline.log 2>&1
```

沒跑完的部分（下載未完、加速清單算到一半）都存在網站上，下次排程會接著做。AI 代理只需要看摘要或日誌，處理失敗（例如 TDX 改格式）。

learn 階段的摘要 `stages.learn.changedFiles` 列出這次有變動的產生檔（主機的 `custom/libs/txapi/transfer/data/`：群組、連結紀錄、里程、加速清單）。
產生檔不進版控、佈署不會覆蓋；每次改寫前舊版留在 `history/`，轉車出問題用 `/txapi/admin_transfer/restore` 換回（`func=history` 看清單）。

## 腳本一覽（都是 PHP 命令列）

| 腳本 | 用途 |
| --- | --- |
| `data-pipeline/scripts/pipeline.php` | 本流程：download、learn 兩段，可分可串 |
| `data-pipeline/scripts/client.php` | 共用的網站 HTTP 用戶端（`TdxSite`），其他腳本 require 它 |
| `rail-transfer/scripts/build_hanzi.php` | 車站搜尋的漢字字典（`libs/txapi/transfer/data/hanzi_pinyin.json`），由 Unicode Unihan 產生；很少需要重跑（func=stations 回應有 `missingChars` 時） |
| `rail-transfer-learn/scripts/verify_accel.php` | 比對加速清單開與關的轉乘結果（pipeline 的 `--verify` 也用它） |
| `metro-travel-time/scripts/s2s_to_offsets.php` | 獨立重算 TDX S2STravelTime 的儲存型態與路線站距表，交叉比對 API |
| `rail-travel-time/scripts/timetable_matrix.php` | 獨立由車站時刻表串車推算起訖矩陣，交叉比對 API |

後兩支是刻意不載入 `libs/` 的獨立重寫版，和網站的實作互相驗證；2026-10 由 Python 改寫成 PHP，輸出與原版逐筆相同。
