# AGENTS.md

給 AI 代理（Claude Code、Codex 等）的入口。開發規則、已定案的設計決策與要先讀的文件在 [README.md](README.md) 的「開發接續」一節，
agent 資料夾的規則與 skills 在 [server/agent/README.md](server/agent/README.md)。這是公開 repo：站址、PAT、密碼不寫在這裡，
由 Melix 在對話開頭提供，只放環境變數。

## 本機環境同步線上的離線資料（查詢測試、比對前先做）

新建的本機開發環境（或 AI 代理的工作區）沒有 TDX 離線資料，路由與時刻表查詢跑不起來。不要自己向 TDX 下載：
用後臺 PAT 從線上主機鏡像已存檔的資料，本機就和線上用同一份資料。

```bash
export TDX_BASE=https://<線上主機>     # 站址由 Melix 提供
export TDX_PAT=<後臺 PAT>              # 後臺帳號「我的帳號」產生的 PAT，角色要有 admin_download 讀取權限
php server/agent/skills/data-pipeline/scripts/pipeline.php mirror --build
```

- 會做什麼：讀 `GET /txapi/admin_download/mirror?service=tdx`（帶 `Authorization: Bearer <PAT>`）的清單，本機沒有或內容不同（大小、sha1）的檔案才下載，
  驗證 sha1、mtime 設成和主機一樣，存到本機資料根目錄的 `download/tdx/`；`--build` 接著重建本機 `sqlite/finder.sqlite`
- 資料根目錄預設是 `server/`；要放到別處，在 `server/env_path.php`（不進版控）回傳路徑，見 README「資料目錄的位置」
- 可以重複執行（資料有更新時再跑一次就同步）；`--prune` 刪掉主機已不再有的舊檔
- 主機限制同一 IP 帶 PAT **30 秒內只能同步一次**（避免被頻繁 dump；設定 `QUERY_MIRROR_PAT_SECONDS`，後臺網頁登入是 2 小時）；
  `--dry-run` 也會取清單、算一次。被拒時回應會說還要等幾秒
- 只讀主機既有存檔，不會讓主機向 TDX 下載、不耗 TDX 額度；沒帶 PAT 或錯誤回 401，沒有權限回 403
- 不同步的：轉乘產生檔（本機第一次查詢自動重建）、加速清單（沒有時不套用加速，結果正確；要一樣就本機另跑 `pipeline.php learn`）、
  `custom/` 的設定與後臺帳號
- 細節：[server/agent/skills/data-pipeline/SKILL.md](server/agent/skills/data-pipeline/SKILL.md)「本機同步線上的離線資料」；API 說明在網站的 `/txapi/download/help`

查詢測試可以不經網頁伺服器，直接在命令列載入 `server/libs/` 呼叫函式（例如 `appCoreRoutes`、`appTripJourneys`）。
