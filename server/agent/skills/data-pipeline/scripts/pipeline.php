<?php
/**
 * 本站的資料更新流程（可分段、可串接）。透過網站的管理 API 執行，任何有 PHP（curl 擴充）的機器都能跑：
 * AI 代理的工作區、你的電腦、或主機的排程（cron）。
 *
 * 用法：
 *   TDX_BASE=https://<本站> TDX_PAT=<後臺 PAT> php pipeline.php <階段…> [選項]
 *
 * 階段（照寫的順序一個做完再做下一個；前一個失敗就停止）：
 *   download   離線資料下載：重複呼叫 /txapi/admin_download/sync 直到 done（遵守每分鐘 3 次的 TDX 呼叫限制，依 next_in 等待）；
 *              全部同步完成時網站會自動判斷資料有沒有變，有變就重建 sqlite/finder.sqlite
 *   learn      最佳化加速清單演算：/txapi/admin_transfer/build_groups（轉乘群組與里程，內容沒變不重寫）→
 *              重複呼叫 /txapi/admin_transfer/learn 直到 done（資料庫與輸入都沒變時直接跳過）；
 *              摘要的 changedFiles 列出這次有變動的產生檔（custom/libs/txapi/transfer/data/，舊版在 history/）
 *
 * 選項：
 *   --base=URL        網站（預設環境變數 TDX_BASE，再沒有用 http://localhost）
 *   --key=PAT         後臺 PAT（建議改用環境變數 TDX_PAT，避免留在命令列歷史）
 *   --force           learn：清單未過期也重算
 *   --reset           learn：捨棄沒算完的進度，從頭算
 *   --verify=N        learn 完成後抽 N 組起訖比對加速開關（verify_accel.php），結果不同時結束碼為 1
 *   --max-minutes=N   整個流程最多跑幾分鐘（預設 180）
 *
 * 例：
 *   php pipeline.php download            只下載（必要時重建資料庫）
 *   php pipeline.php learn --verify=60   只重算加速清單並驗證
 *   php pipeline.php download learn      先下載，完成後接著重算
 *
 * 結束碼：0 成功、1 失敗或驗證不一致、2 用法錯誤。最後一行 STDOUT 是整個流程的摘要（JSON）。
 */

require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../../rail-transfer-learn/scripts/verify_accel.php';

const PIPELINE_STAGES = ['download', 'learn'];

/** 階段 download：同步 TDX 下載檔直到完成；回傳摘要 */
function pipelineDownload(TdxSite $site, $deadline)
{
    $calls = 0;
    $fetched = 0;
    $failedStreak = 0;
    while (true) {
        if (time() > $deadline) {
            throw new RuntimeException('超過時間上限，下載尚未完成（下次執行會接著同步）');
        }
        $j = $site->get(['action' => 'admin_download', 'service' => 'tdx', 'func' => 'sync'], true, 600);
        $calls++;
        if (($j['_http'] ?? 200) >= 400 && !isset($j['processed'])) {
            throw new RuntimeException('/txapi/admin_download/sync 失敗：' . ($j['error'] ?? 'HTTP ' . $j['_http']));
        }
        $fetched += $j['processed'] ?? 0;
        $failedStreak = !empty($j['failed']) ? $failedStreak + 1 : 0;
        tdxLog(sprintf('download：本次 %d 個（失敗 %d），剩 %d 個%s', $j['processed'] ?? 0, $j['failed'] ?? 0, $j['remaining'] ?? -1,
                       !empty($j['stopped']) ? '；' . $j['stopped'] : ''));
        if ($failedStreak >= 3) {
            throw new RuntimeException('連續 3 次都有檔案下載失敗，停止：' . json_encode(array_values(array_filter($j['results'] ?? [], function ($r) {
                return empty($r['ok']);
            })), JSON_UNESCAPED_UNICODE));
        }
        if (!empty($j['done'])) {
            $sq = $j['sqlite'] ?? null;
            if ($sq && empty($sq['ok'])) {
                throw new RuntimeException('下載完成，但資料庫重建失敗：' . ($sq['error'] ?? '') . (isset($sq['missing']) ? ' 缺 ' . json_encode($sq['missing']) : ''));
            }
            $rebuilt = !empty($sq['rebuilt']);
            tdxLog('download：完成；資料庫' . ($rebuilt ? '已重建' : '沒有變動，不必重建'));
            return ['calls' => $calls, 'fetched' => $fetched, 'dbRebuilt' => $rebuilt];
        }
        sleep(max(1, (int) ($j['next_in'] ?? 5)));
    }
}

/** 階段 learn：重建轉乘群組與里程，再重算加速清單；回傳摘要 */
function pipelineLearn(TdxSite $site, array $opt, $deadline)
{
    $before = $site->get(['action' => 'transfer', 'func' => 'accel'])['generatedFiles'] ?? [];
    $b = $site->get(['action' => 'admin_transfer', 'func' => 'build_groups'], true, 600);
    if (empty($b['ok'])) {
        throw new RuntimeException('/txapi/admin_transfer/build_groups 失敗：' . ($b['error'] ?? ''));
    }
    $diff = $b['diff'] ?? [];
    tdxLog(sprintf('learn：轉乘群組 %s（新增 %d、刪除 %d、改變 %d；無法對應的車站 %d）', !empty($b['unchanged']) ? '沒有變動' : '已更新',
                   count($diff['added'] ?? []), count($diff['removed'] ?? []), count($diff['changed'] ?? []), count($b['Unresolved'] ?? [])));
    $lk = $b['links'] ?? [];
    tdxLog(sprintf('learn：轉乘連結 %d 筆；新加入 %d、人工排除 %d、沒再偵測到 %d', $lk['total'] ?? 0, count($lk['added'] ?? []),
                   count($lk['excluded'] ?? []), count($lk['notDetected'] ?? [])));
    foreach ($lk['added'] ?? [] as $k) {
        tdxLog('learn：  新加入 ' . $k);
    }
    $params = ['action' => 'admin_transfer', 'func' => 'learn'];
    if (!empty($opt['reset'])) {
        $params['reset'] = 1;
    }
    if (!empty($opt['force'])) {
        $params['force'] = 1;
    }
    $calls = 0;
    while (true) {
        if (time() > $deadline) {
            throw new RuntimeException('超過時間上限，加速清單尚未算完（進度已存在網站上，下次執行會接著算）');
        }
        $j = $site->get($params, true, 600);
        $calls++;
        unset($params['reset'], $params['force']);   // 只有第一次帶
        if (empty($j['ok'])) {
            throw new RuntimeException('/txapi/admin_transfer/learn 失敗：' . ($j['error'] ?? ''));
        }
        if (!empty($j['skipped'])) {
            tdxLog('learn：' . $j['reason']);
            $res = ['groupsChanged' => empty($b['unchanged']), 'linksAdded' => $lk['added'] ?? [], 'linksNotDetected' => $lk['notDetected'] ?? [],
                    'learnCalls' => $calls, 'skipped' => true, 'learnedAt' => $j['learnedAt'] ?? null];
            break;
        }
        tdxLog(sprintf('learn：%s（已算 %.0f 秒）', $j['progress'] ?? '?', $j['seconds'] ?? 0));
        if (!empty($j['done'])) {
            tdxLog('learn：完成；推薦換車站 ' . ($j['learnedHubs'] ?? '?') . ' 個；放寬的走廊 ' . json_encode($j['corridorsWidened'] ?? [], JSON_UNESCAPED_UNICODE));
            $res = ['groupsChanged' => empty($b['unchanged']), 'linksAdded' => $lk['added'] ?? [], 'linksNotDetected' => $lk['notDetected'] ?? [],
                    'learnCalls' => $calls, 'skipped' => false, 'learnedHubs' => $j['learnedHubs'] ?? null,
                    'corridorsWidened' => $j['corridorsWidened'] ?? [], 'corridorsLoss' => $j['corridorsLoss'] ?? []];
            break;
        }
    }
    $a = $site->get(['action' => 'transfer', 'func' => 'accel']);
    $res['accelDefault'] = $a['default'] ?? null;
    // 產生檔在主機的 custom/libs/txapi/transfer/data/（不進版控）；有變動時舊版自動留在 history/，可用 /txapi/admin_transfer/restore 換回
    $res['changedFiles'] = [];
    foreach ($a['generatedFiles'] ?? [] as $k => $f) {
        if (($before[$k]['sha256'] ?? null) !== $f['sha256']) {
            $res['changedFiles'][] = $f['file'];
        }
    }
    tdxLog($res['changedFiles'] ? 'learn：產生檔已更新（舊版在 history/）：' . implode('、', $res['changedFiles']) : 'learn：產生檔沒有變動');
    if (!empty($opt['verify'])) {
        $v = tdxVerifyAccel($site, (int) $opt['verify']);
        $res['verify'] = ['pairs' => $v['pairs'], 'secondsOff' => round($v['seconds']['0'], 2), 'secondsOn' => round($v['seconds']['1'], 2),
                          'diff' => count($v['diff']), 'examples' => array_slice($v['diff'], 0, 5)];
        tdxLog(sprintf('learn：驗證 %d 組，結果不同 %d 組（不加速 %.1f 秒、加速 %.1f 秒）', $v['pairs'], count($v['diff']), $v['seconds']['0'], $v['seconds']['1']));
    }
    return $res;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    list($stages, $opt) = tdxArgs($argv);
    $bad = array_diff($stages, PIPELINE_STAGES);
    if (!$stages || $bad) {
        fwrite(STDERR, ($bad ? '不認得的階段：' . implode(', ', $bad) . "\n" : '') . "用法：TDX_BASE=<站址> TDX_PAT=<後臺 PAT> php pipeline.php <download|learn …> [--verify=N] [--force] [--reset] [--base=URL]\n");
        exit(2);
    }
    $site = new TdxSite(tdxBase($opt), tdxCredential($opt));
    $deadline = time() + 60 * (int) ($opt['max-minutes'] ?? 180);
    $summary = ['startedAt' => date('c'), 'base' => $site->base, 'stages' => []];
    $code = 0;
    foreach ($stages as $st) {
        $t0 = microtime(true);
        tdxLog("== 階段 $st 開始");
        try {
            $r = $st === 'download' ? pipelineDownload($site, $deadline) : pipelineLearn($site, $opt, $deadline);
            $r['ok'] = true;
            if (!empty($r['verify']['diff'])) {
                $code = 1;
            }
        } catch (Throwable $e) {
            $r = ['ok' => false, 'error' => $e->getMessage()];
            $code = 1;
        }
        $r['seconds'] = round(microtime(true) - $t0, 1);
        $summary['stages'][$st] = $r;
        tdxLog("== 階段 $st " . ($r['ok'] ? '完成' : '失敗：' . $r['error']));
        if (!$r['ok']) {
            break;   // 前一個階段失敗就不做下一個
        }
    }
    $summary['finishedAt'] = date('c');
    echo json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($code);
}
