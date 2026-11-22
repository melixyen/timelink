---
name: data-pipeline
description: 本站的資料更新流程：離線資料下載（TDX 同步、重建 finder 資料庫）、轉乘群組與里程重建（groups），以及本機新環境同步線上的離線資料（mirror），可以只跑其中一段，也可以串起來一段做完再做下一段。更新資料、排程、檢查流程或建立本機測試環境時照做。
---

# 資料更新流程（可分段、可串接）

所有程式都是 PHP：網站本身（`libs/`）與 AI 代理用的腳本（`agent/skills/*/scripts/*.php`）。腳本只需要 PHP 命令列與 curl 擴充，
透過網站的後臺 API（admin_ 開頭的 action，帶後臺 PAT）執行，所以在 AI 代理的工作區、你的電腦、或主機的排程都能跑。
站址與憑證只放環境變數：`TDX_BASE`（站址）、`TDX_PAT`（後臺 PAT；admin_ 開頭的 action 只接受 PAT）。這個站的實際站址與 PAT 存放位置見 `custom/agent/` 的私密筆記。

## 一行指令

```
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php download          # 只下載
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php groups            # 只重建轉乘群組與里程
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php download groups   # 先下載，完成後接著重建轉乘群組
TDX_BASE=https://<線上主機> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php mirror --build # 本機：同步線上的離線資料並重建本機資料庫
```

階段照寫的順序執行，前一個失敗就停下來。過程寫到 STDERR，最後一行 STDOUT 是摘要（JSON）；結束碼 0 成功、1 失敗、2 用法錯誤。舊的階段名 `learn` 照收，等於 `groups`。

| 選項 | 說明 |
| --- | --- |
| `--base=URL` | 網站，預設環境變數 `TDX_BASE`，再沒有用 `http://localhost` |
| `--key=PAT` | 後臺 PAT；建議用環境變數 `TDX_PAT`，不留在命令列歷史 |
| `--max-minutes=N` | 整個流程最多跑幾分鐘，預設 180；超過就停，進度留在網站上，下次接著做 |
| `--build` | mirror：同步完立刻重建本機 `sqlite/finder.sqlite`（不加時第一次查詢自動重建） |
| `--prune` | mirror：刪掉本機 `download/tdx/` 裡主機清單沒有的檔案 |
| `--dry-run` | mirror：只列出要下載、要刪除的檔案，不寫入 |
| `--dir=PATH` | mirror：本機資料根目錄，預設 `libs/env_path.php` 的 `appEnvPath()`（`--build` 只能用預設） |

## 階段一：離線資料下載（download）

```
重複 GET /txapi/admin_download/sync?service=tdx（Authorization: Bearer <PAT>）
  ├─ 每次最多抓 limit 個到期的檔案（參數，預設與上限見 download.php）；向 TDX 的呼叫受 `DOWNLOAD_MAX_PER_MINUTE`（每分鐘幾次，例如 3 次）限制、
  │  保留 `DOWNLOAD_RATE_RESERVE` 的流量餘額（都在 libs/config.json），超過就停，回應 next_in 秒後再呼叫
  ├─ 連續 3 次都有檔案失敗就停止
  └─ remaining = 0（done）時，網站自動判斷下載檔有沒有變：
        有變 → 重建 sqlite/finder.sqlite（等同 /txapi/admin_download_to_sqlite/build），_meta.built_at 更新
        沒變 → 不重建
```

- 哪些檔案、多久更新一次：`libs/txapi/download.php` 的下載清單（`/txapi/download/list?service=tdx` 可看）
- 只想重建資料庫：`/txapi/admin_download_to_sqlite/build`（後臺寫入權限）

## 本機同步線上的離線資料（mirror）

新建的本機開發環境（或 AI 代理的工作區）要和線上主機用**同一份離線資料**做查詢測試、比對時用。在本機執行，`TDX_BASE` 指向線上主機：

```
TDX_BASE=https://<線上主機> TDX_PAT=<後臺 PAT> php agent/skills/data-pipeline/scripts/pipeline.php mirror --build
```

```
GET /txapi/admin_download/mirror?service=tdx（Authorization: Bearer <PAT>，admin_download 讀取權限）
  ├─ 主機上已存檔的 TDX 下載檔清單：file（相對資料根目錄）、size、mtime、sha1；還沒存檔的列在 missing
  └─ 本機沒有、或大小／sha1 不同的才下載：GET …/mirror?service=tdx&path=<path>（只給既有存檔，不會讓主機向 TDX 下載、不耗額度）
       ├─ 先寫 .part，sha1 與標頭 X-Mirror-Sha1 相符才改名；mtime 設成主機的 X-Mirror-Mtime
       └─ 存到本機資料根目錄的 download/tdx/（appEnvPath()，網站根目錄的 env_path.php 可以改位置）
--build：在本機載入網站程式跑 finderDbEnsure()，依新的來源檔重建 sqlite/finder.sqlite（來源檔 mtime、大小沒變就不重建）
```

- 需要後臺帳號產生的 PAT（後臺「我的帳號」），角色要有 `admin_download` 的讀取權限；沒帶或錯誤回 401、沒有權限回 403
- 可以重複執行：已相同的檔案不下載，只補 mtime
- 避免被頻繁 dump：主機限制同一 IP 在時間窗 `QUERY_MIRROR_IP_SECONDS` 秒內最多呼叫 mirror `QUERY_MIRROR_IP_TIMES` 次（libs/config.json，
  各站可覆蓋；例如「2 小時內 200 次」）。清單與每個檔案各算一次（全新環境＝檔案數＋1，之後只下載有變的檔案）；後臺身分也受限；
  超過回 429（錯誤代碼 42904，附 `Retry-After`）
- 只同步 TDX 下載檔。轉乘產生檔（轉乘群組、里程，`custom/libs/txapi/transfer/data/`）本機第一次查詢時自動重建
- 後臺帳號、設定與私密值（`custom/`）不在同步範圍，本機自己建立

## 階段二：轉乘群組與里程重建（groups）

```
GET /txapi/admin_transfer/build_groups（Authorization: Bearer <PAT>）
  ├─ 由下載檔（TDX 捷運與臺鐵轉乘資料、車站座標）偵測轉乘連結，與 transfer_links.json 比對：只加入新的，不移除舊的
  ├─ 加上 transfer_seed.json 的人工群組、人工兩站、同站換車，產生轉乘群組與里程；人工排除（ExcludePairs）的連結刪掉
  ├─ 回應的 links.added（新加入）、links.notDetected（沒再偵測到）給人工檢查
  └─ 內容沒變就不重寫 custom/libs/txapi/transfer/data/ 的 transfer_groups.json／transfer_distance.json／transfer_links.json（有變時舊版留在 history/）
```

前後用 `GET /txapi/admin_transfer/history` 的 sha256 比對哪些產生檔有變動。轉乘加速清單（原本的 learn 演算、`transfer_accel_learned.json`）
只給舊的 `/txapi/transfer/route` 用，2026-11 隨舊查詢一起移除；主機上留下的 `transfer_accel_learned.json` 不再讀取，可以刪掉。

## 什麼時候跑哪一段

| 情況 | 指令 |
| --- | --- |
| 定期更新資料 | `download groups`（不跑 groups 時，轉乘群組也會在下一次查詢時自動重建） |
| 改了 `transfer_seed.json`（人工轉乘群組、步行分鐘） | 部署即可，第一次查詢自動重建轉乘群組 |

## 排程（不需要 AI 代理）

整個流程是確定性的，可以交給主機的排程（cron），每天一次：

```
TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php /<網站根目錄>/agent/skills/data-pipeline/scripts/pipeline.php download groups --max-minutes=50 >> /<可寫的路徑>/pipeline.log 2>&1
```

沒跑完的部分（下載未完）存在網站上，下次排程會接著做。AI 代理只需要看摘要或日誌，處理失敗（例如 TDX 改格式）。

groups 階段的摘要 `stages.groups.changedFiles` 列出這次有變動的產生檔（主機的 `custom/libs/txapi/transfer/data/`：群組、連結紀錄、里程）。
產生檔不進版控、佈署不會覆蓋；每次改寫前舊版留在 `history/`，轉車出問題用 `/txapi/admin_transfer/restore` 換回（`func=history` 看清單）。

## 腳本一覽（都是 PHP 命令列）

| 腳本 | 用途 |
| --- | --- |
| `data-pipeline/scripts/pipeline.php` | 本流程：download、groups、mirror，可分可串 |
| `data-pipeline/scripts/client.php` | 共用的網站 HTTP 用戶端（`TdxSite`），其他腳本 require 它 |
| `rail-transfer/scripts/build_hanzi.php` | 車站搜尋的漢字字典（`libs/txapi/transfer/data/hanzi_pinyin.json`），由 Unicode Unihan 產生；很少需要重跑（func=stations 回應有 `missingChars` 時） |
| `metro-travel-time/scripts/s2s_to_offsets.php` | 獨立重算 TDX S2STravelTime 的儲存型態與路線站距表，交叉比對 API |
| `rail-travel-time/scripts/timetable_matrix.php` | 獨立由車站時刻表串車推算起訖矩陣，交叉比對 API |

後兩支是刻意不載入 `libs/` 的獨立重寫版，和網站的實作互相驗證；2026-10 由 Python 改寫成 PHP，輸出與原版逐筆相同。
