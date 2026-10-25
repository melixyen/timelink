# agent/claude/：Claude 的工具與資料

Claude（AI 代理）自己的比對工具、基準資料與重建指引。內容公開、進版控，只放通則與公開資料（railtime 是公開專案）。
站台私密的操作細節（主機維護方式、PAT、本機環境同步）在主機的 `custom/agent/`（不進版控）。

工作環境會被回收（雲端容器閒置一段時間就清空），所以：程式與資料以線上為準；這裡的工具重建後照下面步驟就能再跑。

## 目錄

| 位置 | 內容 |
| --- | --- |
| `compare/railtime_load.js` | 在 node 載入 railtime（接軌時刻）的 `ttlib`（defined、data、fn、fn_tymetro），jQuery 只做 `$.extend` 的替身。只讀 railtime，不修改 |
| `compare/railtime_dump.js` | 輸出 railtime 的車站、路線站序、`routeMap` 裡帶 `bypass*Reg` 的走法、指定起訖的所有路由（JSON） |
| `compare/station_map.php` | railtime 車站 → 本站 StationUID（臺鐵依代碼、機捷依站號、其他依站名；`trtc_6`／`trtc_7` 對新北捷運），railtime 路線 → 本站路線＋車站範圍 |
| `compare/make_pairs.php` | 固定亂數種子產生比對起訖：北北基桃 400 組、高雄 120 組（已存在就不重產，`--force` 重產） |
| `compare/best.php` | 用 `appJourneysCompute`（與 `/txapi/app_trip/journeys` 同一個計算）算每組的最早到達、最短行車時間、最少轉乘方案 |
| `compare/diff.php` | 比較兩份 best 結果，列出退步與改善；有退步時結束代碼 1 |
| `compare/railtime_routes.php` | railtime 路由與本站路由的轉乘站比對，列出 railtime 有、本站沒有的走法並分類原因 |
| `compare/railtime_bypass.php` | railtime 的 52 條「依起訖站排除的轉乘走法」改寫成 RouteBans 的轉乘走法排除（`--write` 寫進規則檔，保留人工 Disabled） |
| `compare/rule_pairs.php`、`compare/rule_blame.php` | 轉乘走法排除的驗證：依每條規則的起訖條件抽起訖；比較規則開／關的結果，找出害方案變差的規則 |
| `compare/livecmp.php` | 本機與線上的輸出比對：同一組查詢打兩邊的 `/txapi/`，去掉 meta 與計時欄位後比較 data（`TDX_BASE=<線上站址> php livecmp.php [本機站址] [查詢清單.json]`）；部署後確認線上與本機一致用 |
| `data/railtime_*.json` | 從 railtime 匯出的車站、路線、對照表、北北基桃路由、bypass 走法 |
| `data/pairs_*.json` | 比對起訖（題目固定，資料更新也不換） |
| `data/baseline_*.json` | 目前的基準（標頭記錄離線資料版本、找路程式與規則檔的雜湊） |
| `data/run_*.json` | 最近一次執行結果（暫存，不上傳） |

腳本用 PHP；只有讀 railtime 的兩個 `.js` 用 node（railtime 是 JavaScript，要執行它的找路函式只能用 node），
匯出結果存在 `data/`，railtime 沒更新就不必再跑 node。

## 重建步驟

1. 本機環境：照 `custom/agent/README.md` 的「本機開發環境」把程式、資料庫與快取抓回本機（與線上同一份）
2. railtime（唯讀）：`git clone --depth 1 https://github.com/melixyen/railtime.git <暫存目錄>/railtime`，放在工作目錄以外的暫存位置，**不要修改、不要推回**
3. 只有 railtime 更新過才需要重匯（在 `agent/claude/compare/` 執行）：
   ```
   RT=<暫存目錄>/railtime
   node railtime_dump.js $RT stations > ../data/railtime_stations.json
   node railtime_dump.js $RT lines    > ../data/railtime_lines.json
   node railtime_dump.js $RT bypass   > ../data/railtime_bypass.json
   php station_map.php ../data/railtime_stations.json
   php -r '$p=json_decode(file_get_contents("../data/pairs_nnkt.json"),true)["pairs"]; echo json_encode(array_map(fn($x)=>$x["rt"],$p));' > /tmp/rt_pairs.json
   node railtime_dump.js $RT routes /tmp/rt_pairs.json > ../data/railtime_routes_nnkt.json
   ```
4. 題目：`data/pairs_*.json` 已存在就沿用；要換題目才 `php make_pairs.php --force`（換了要重做基準）

## 改找路演算法或規則時

```
php agent/skills/rail-transfer/scripts/route_regression.php          # 已知最佳化案例（目前 53 條），全部要過
php agent/claude/compare/best.php --set=bench                        # 25 組代表起訖＋計算秒數
php agent/claude/compare/best.php --set=kh                           # 高雄 480 組
php agent/claude/compare/best.php --set=nnkt                         # 北北基桃 400 組
php agent/claude/compare/diff.php agent/claude/data/baseline_kh.json agent/claude/data/run_kh.json
php agent/claude/compare/diff.php agent/claude/data/baseline_nnkt.json agent/claude/data/run_nnkt.json
php agent/claude/compare/diff.php agent/claude/data/baseline_bench.json agent/claude/data/run_bench.json
php agent/claude/compare/railtime_routes.php agent/claude/data/railtime_routes_nnkt.json [--list]
```

- 退步（更晚到、轉乘變多、行車變長、原本有方案變沒有）就要修演算法或規則，不能直接改基準
- 確認是改善或離線資料更新造成的差異，才把 `run_*.json` 複製成 `baseline_*.json`（diff 會提示資料版本不同）
- `railtime_routes.php` 的原因分類：重複經過車站（本站通則排除）、轉乘站不相通（本站轉乘群組沒有這組步行）、其他（排在前 50 條外、找路規則排除，或補充資料不套用的快慢車換車）

## 轉乘走法排除（railtime 規則）的驗證

```
php agent/claude/compare/rule_pairs.php /tmp/rule_pairs.json
# 規則檔拿掉 Source=railtime 的項目跑一次（off）、原檔跑一次（on），時段多換幾個（--week、--start、--end）
php agent/claude/compare/best.php --set=pairs --pairs=/tmp/rule_pairs.json --week=1 --start=08:00 --end=10:00 --out=/tmp/off.json
php agent/claude/compare/best.php --set=pairs --pairs=/tmp/rule_pairs.json --week=1 --start=08:00 --end=10:00 --out=/tmp/on.json
php agent/claude/compare/diff.php /tmp/off.json /tmp/on.json
php agent/claude/compare/rule_blame.php /tmp/off.json /tmp/on.json     # 列出害方案變差的規則 map#index → 設 Disabled＋DisabledReason
```

2026-10-25 的結果：52 條中 11 條停用（擋掉的是本站依時刻算出的最早到達、最少轉乘或最短行車時間），41 條啟用：
586 組起訖 × 5 個時段方案完全不變，基本路由少約 3%。

## 目前的基準（2026-10-25，離線資料 2026-10-15）

- 回歸測試 53 條全過（新增 3 條 railtime 規則停用的例子）
- bench 25 組約 2.2 秒、高雄 480 組約 13 秒、北北基桃 400 組約 21 秒（journeys 完整計算）
- railtime 北北基桃 783 條路由，本站沒有的 79 條：重複經過車站 51、其他 28（多為機捷 A8 換車變化、頭前庄轉環狀線、三次以上轉乘的繞路）
