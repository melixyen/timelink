# Claude 工作交接

最後更新：2026-10-25。給下一個接手的 Claude 對話：前一個對話做到哪裡、接手後先做什麼、還沒做完的事、操作上踩過的坑。

協作規則與已定案的設計決策寫在 repo 根目錄 `README.md` 的「開發接續」一節，這裡不重複。這個檔案進版控、公開，**不寫站址、主機操作方式、密碼與 PAT**；那些在主機上的 `custom/agent/README.md`。交接的事做完就更新這份（完成的項目刪掉）。

## 一、接手後先做

1. 讀根目錄 `README.md`「開發接續」，再讀 `custom/agent/README.md`（主機上，私密）。連線方式與憑證由 Melix 在對話開頭提供，憑證只放環境變數，不寫進任何檔案
2. 重建工作環境：照 `custom/agent/README.md`「Git 與新對話接續」取回 `dev_sync.php`、`pull`。之後 `git status` 應該沒有變更；有變更代表線上與 `dev` 不一致，先查清楚是哪邊比較新，不要直接覆蓋
3. 確認環境正常：
   ```
   php agent/skills/rail-transfer/scripts/route_regression.php        # 53 條全過
   php custom/agent/scripts/dev_sync.php serve &                       # 本機伺服器 127.0.0.1:8099
   TDX_BASE=<線上站址> php agent/claude/compare/livecmp.php            # 本機與線上輸出全部一致
   ```
4. 試著 push 到 `dev`。被拒（403）就告訴 Melix，不要繞過；在那之前改好的檔案交給 Melix 推

## 二、目前狀態

- 線上、本機工作副本、GitHub `dev` 三邊一致（2026-10-25，含根目錄 `README.md`、本檔、`agent/claude/compare/livecmp.php`）；根目錄 `index.php` 已由 Melix 用 FTP 更新
- Melix 的兩份規劃文件（Claude 文件，連結在 `custom/agent/README.md`）已更新到 2026-10-25 的決定，只留未解決的項目

## 三、待辦

依目前的優先順序：

1. **佈署方式**：等 Melix 在 B、C 之間決定，決定後實作
   - B（GitHub Actions＋FTP 同步）：workflow 先呼叫主機工具備份；同步要排除 `custom/`、`download/`、`sqlite/`、`backup/` 與主機工具，不能開會刪除遠端檔案的選項
   - C（GitHub Actions 打包＋主機端解壓）：新增 `admin_deploy` 路由（只有這個權限的後臺 PAT 能用），先備份、解壓覆蓋 `libs/`、`agent/`，健康檢查失敗自動還原
   - 兩種都改不到根目錄的 `index.php`、`.htaccess`，那兩個檔案有變動時要給 Melix 下載、用 FTP 上傳
2. **會員系統**（暫停，Melix 開工時再說）：會員 token 的格式與簽發方式（先保留 `tlm_` 前綴）、各等級上限（`APP_TIERS`）；開工前所有人都是 Guest、不另設限制
3. **未來功能**：票價（只用 TDX 或官方公式）、路由用離線資料找出後每班車的時刻改用 TDX 線上最新資料。兩者都經由 `X-TDX-Authorization`，`railCacheFetchData()` 已經可以共用快取取資料
4. `client/` 查詢網站

## 四、操作注意（踩過的坑）

- 改線上一律先備份（`dev_sync.php push` 會自動先備份），部署後用 `livecmp.php` 與回歸測試驗證
- `dev_sync.php pull` 會把本機檔案換成線上版本：本機改了還沒部署的檔案會被蓋掉，pull 前先 `status`
- pull 依開始時抓的檔案清單下載：pull 期間線上才改的檔案不會抓到，pull 完再 `status` 一次
- pull 會保留線上的修改時間；沒保留的話，本機會以為離線資料比資料庫新而自己重建 finder.sqlite、transfer_runs，跟線上不再是同一份
- 停止背景程式不要用 `pkill -f <字串>`：會比對到自己的 shell 指令而把自己砍掉。用 `ps -eo pid,args | awk '/809[9]/'` 找到 pid 再 kill
- 本機伺服器（`serve`）要用工具的背景執行啟動；在 shell 裡用 `( … & )` 起的會隨那次指令結束被收掉
- 判斷本機伺服器有沒有在跑不要用 `ps | grep`（同樣會比對到自己），直接 `curl` 試 `/txapi/status`
- `curl --noproxy '*'` 的星號要加引號，否則會被 shell 展開
- `agent/claude/compare/` 的工具由 `boot.php` 切換工作目錄；參數裡的檔案路徑已自動轉成絕對路徑
- 網站自己呼叫 TDX 每分鐘最多 3 次；離線資料由主機排程更新，本機不要自己下載
- railtime 要比對時 clone 到暫存目錄，只讀，不改、不推
- 使用者在對話裡貼出的 token、密碼不要拿來用（也不要寫進檔案），請他撤銷
- 改找路演算法或規則：回歸測試全過、`agent/claude/README.md` 的比對沒有退步，才能部署；不能直接改基準

## 五、最近完成的工作（供參考，細節在各文件）

- 找路：核心 A 加反向 0-1 BFS 下界剪枝（結果不變、約快 25%）；變體（variants）修正；系統直達段與補充資料；DirectPairs 產生器修正
- RouteBans 轉乘走法排除（起訖條件＋轉乘站序列），併入 railtime 52 條（11 條停用，原因寫在規則檔）
- API：時段 `start`／`end`＋`by`、分鐘數欄位、direct 的 `Path` 與 `direction`、路線時刻表 `by=arr`、`X-TDX-Authorization`
- 設定：32 個寫死的上限、逾時移到 `libs/config.json`
- Repo：public、`server/`／`client/`，機密掃描與覆蓋測試通過
