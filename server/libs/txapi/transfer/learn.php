<?php
/**
 * 離線再演算：從離線時刻表（sqlite/finder.sqlite）跑完整搜尋，算出加速清單要用的靜態資料，
 * 取代手寫的轉乘大站與未驗證的地區走廊。結果寫成 data/transfer_accel_learned.json（PHP 與 Node.js 共用）。
 *
 * 做法（Transfer Patterns 的簡化版）：
 *   1. 起站：transfer_accel.json Learn.OriginSystems 的全部車站，加上所有多站轉乘群組的車站
 *   2. 每個起站、每個分析日（Learn.Days）、From～To 每 StepMinutes 分鐘出發，跑一次一對多的逐輪搜尋（不設迄站、不剪枝）
 *   3. 每個到得了的車站，取「搭 k 班車比搭更少班車更早到」的各輪結果（＝互不支配的方案），沿來源指標還原：
 *        - 在哪些臺鐵車站同站換車 → 次數累計，次數 ≥ MinUses 的列為推薦換車站（LearnedHubs）
 *        - 上下車經過哪些地區 → 起訖地區組合的手寫走廊（Corridors）若沒包含，就把該地區加進去（EffectiveCorridors），
 *          並記一個例子；這樣走廊只在「不影響結果」的範圍內保留排除效果
 *   4. 記下 finder 資料庫的建置時間（_meta.built_at），資料庫重建後視為過期，需要重算
 *
 * 執行方式：
 *   - 網站：/txapi/admin_transfer/learn（後臺寫入權限）（每次最多算 Learn.ChunkSeconds 秒、存進度，重複呼叫直到 done=true；reset=1 從頭算）
 *   - 命令列：php libs/txapi/transfer/learn.php（一次算完）
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/accel.php';   // transferInputsHash()

define('TRANSFER_LEARNED_FILE', transferDataFile('AccelLearned'));
define('TRANSFER_LEARN_STATE_FILE', transferDataFile('LearnState'));

/** 分析的輸入雜湊：轉乘群組檔、加速清單人工輸入（transfer_accel.json）、排除記號任一改變，算出來的清單就過期 */
function transferLearnInputsHash()
{
    return transferInputsHash();   // accel.php：轉乘群組檔、transfer_accel.json、seed 的排除記號
}

/** finder 資料庫的建置時間（用來判斷分析結果是否過期） */
function transferLearnDbBuiltAt(SqliteDao $db)
{
    return json_decode((string) $db->value("SELECT value FROM _meta WHERE key = 'built_at'"), true);
}

/** 新的分析進度：起站清單與累計器 */
function transferLearnInit(SqliteDao $db)
{
    $a = transferAccelData();
    $cfg = $a['Learn'];
    $sysSet = array_flip($cfg['OriginSystems']);
    $origins = [];
    foreach ($db->all('SELECT uid, system FROM station ORDER BY uid') as $r) {
        if (isset($sysSet[$r['system']])) {
            $origins[$r['uid']] = true;
        }
    }
    if (!empty($cfg['IncludeTransferGroupStations'])) {
        foreach (transferLoadGroups()['TransferGroups'] as $g) {
            if (count($g['Stations']) > 1) {
                foreach ($g['Stations'] as $x) {
                    $origins[$x['StationUID']] = true;
                }
            }
        }
    }
    $hm = function ($s) { return (int) substr($s, 0, 2) * 3600 + (int) substr($s, 3, 2) * 60; };
    $times = [];
    for ($t = $hm($cfg['From']); $t <= $hm($cfg['To']); $t += (int) $cfg['StepMinutes'] * 60) {
        $times[] = $t;
    }
    $tasks = [];
    foreach ($cfg['Days'] as $w) {
        foreach (array_keys($origins) as $o) {
            $tasks[] = [(int) $w, $o];
        }
    }
    return [
        'Version'     => 1,
        'DbBuiltAt'   => transferLearnDbBuiltAt($db),
        'InputsHash'  => transferLearnInputsHash(),
        'StartedAt'   => date('Y-m-d H:i:s'),
        'Config'      => $cfg,
        'Times'       => $times,
        'Tasks'       => $tasks,
        'Next'        => 0,
        'Seconds'     => 0.0,
        'Runs'        => 0,
        'Journeys'    => 0,
        'HubUses'     => [],    // StationUID => 次數
        'HubOD'       => [],    // StationUID => 不同起訖組合數
        'Corridor'    => [],    // "ra|rb" => [region => [Count, 例子]]：用到走廊外地區的方案數
        'CorridorTotal' => [],  // "ra|rb" => 這個地區組合的方案總數
    ];
}

/**
 * 處理一個起站、一天：每個出發時刻跑一次一對多搜尋，還原各站的互不支配方案並累計
 */
function transferLearnOrigin(array &$d, array &$state, $from)
{
    $m = $d['m'];
    $a = transferAccelData();
    $restrict = array_flip($a['Settings']['RestrictChangeSystems']);
    $regions = transferAccelStationRegions($d);
    $corr = transferAccelCorridorMap(false);
    $allowCache = [];
    $odSeen = [];   // 這個起站：StationUID|迄站 → true（推薦換車站的起訖組合數）
    foreach ($state['Times'] as $t0) {
        $labels = null;
        transferRaptor($d, $from, null, $t0, TRANSFER_MAX_TRIPS, [], $labels);
        $state['Runs']++;
        $arr = $labels['arr'];
        $arrPar = $labels['arrPar'];
        $readyPar = $labels['readyPar'];
        $best = [];
        foreach ($arr as $k => $layer) {
            // 第 k 輪到各站的時刻：搭車直接到，或到同群組車站再步行（例如新左營步行到高鐵左營）
            $reach = [];
            foreach ($layer as $u => $tu) {
                if (!isset($reach[$u]) || $tu < $reach[$u][0]) {
                    $reach[$u] = [$tu, $u];
                }
                foreach ($m['groupOf'][$u] ?? [] as $gid) {
                    foreach ($m['groups'][$gid]['Stations'] as $x) {
                        $v = $x['StationUID'];
                        if ($v !== $u && ($w = transferWalk($m, $u, $v, true))) {   // 走到迄站（不是中途轉乘）
                            $tv = $tu + (int) round($w['Minutes'] * 60);
                            if (!isset($reach[$v]) || $tv < $reach[$v][0]) {
                                $reach[$v] = [$tv, $u];
                            }
                        }
                    }
                }
            }
            foreach ($reach as $v => list($tv, $alight)) {
                if ($v === $from || $tv >= ($best[$v] ?? PHP_INT_MAX)) {
                    continue;
                }
                $best[$v] = $tv;
                // 還原：上下車站、同站換車站
                $used = [];
                $changes = [];
                $u = $alight;
                for ($r = $k; $r >= 1; $r--) {
                    $par = $arrPar[$r][$u] ?? null;
                    if ($par === null) {
                        break;
                    }
                    if ($par[0] === 'trip') {
                        $boardAt = $d['trips'][$par[1]]['st'][$par[2]];
                    } elseif ($par[0] === 'mrun') {
                        $boardAt = $d['mroutes'][$par[1]]['st'][$par[2]];
                    } else {
                        $boardAt = $d['patterns'][$par[1]]['st'][$par[2]];
                    }
                    $used[$u] = true;
                    $used[$boardAt] = true;
                    $rp = $readyPar[$r - 1][$boardAt] ?? ['origin'];
                    if ($rp[0] === 'origin') {
                        break;
                    }
                    if ($rp[0] === 'change') {
                        if (isset($restrict[strstr($boardAt, '-', true)])) {
                            $changes[$boardAt] = true;
                        }
                        $u = $boardAt;
                    } else {
                        $u = $rp[1];
                        if ($r - 1 === 0) {
                            break;
                        }
                    }
                }
                $state['Journeys']++;
                foreach ($changes as $c => $_) {
                    $state['HubUses'][$c] = ($state['HubUses'][$c] ?? 0) + 1;
                    if (!isset($odSeen[$c . '|' . $v])) {
                        $odSeen[$c . '|' . $v] = true;
                        $state['HubOD'][$c] = ($state['HubOD'][$c] ?? 0) + 1;
                    }
                }
                // 走廊：上下車站所在地區要在起訖地區組合的走廊內
                $ck = $v;
                if (!isset($allowCache[$ck])) {
                    $allowCache[$ck] = transferAccelAllowed($regions, $corr, $from, $v);
                }
                list($allowAll, $allow, $pairs) = $allowCache[$ck];
                if ($allowAll) {
                    continue;
                }
                $out = [];   // 這個方案用到、但不在走廊內的地區
                foreach ($used as $s => $_) {
                    if ($s === $from || $s === $v || !isset($regions[$s]) || array_intersect_key($regions[$s], $allow)) {
                        continue;
                    }
                    $out += $regions[$s] + [];
                    $via = $s;
                }
                foreach ($pairs as $pk) {
                    $state['CorridorTotal'][$pk] = ($state['CorridorTotal'][$pk] ?? 0) + 1;
                    foreach ($out as $code => $_) {
                        if (!isset($state['Corridor'][$pk][$code])) {
                            $state['Corridor'][$pk][$code] = ['Count' => 0, 'From' => $from, 'To' => $v, 'Via' => $via, 'Depart' => transferHM($t0)];
                        }
                        $state['Corridor'][$pk][$code]['Count']++;
                    }
                }
            }
        }
    }
}

/** 跑一段（最多 $seconds 秒）；回傳 state（Next 到底即完成） */
function transferLearnStep(SqliteDao $db, array $state, $seconds)
{
    $clock = microtime(true);
    $dCache = [];
    while ($state['Next'] < count($state['Tasks']) && microtime(true) - $clock < $seconds) {
        list($w, $o) = $state['Tasks'][$state['Next']];
        if (!isset($dCache[$w])) {
            $dCache = [$w => transferRouterData($db, $w)];
            $dCache[$w]['accel'] = null;
        }
        transferLearnOrigin($dCache[$w], $state, $o);
        $state['Next']++;
    }
    $state['Seconds'] += microtime(true) - $clock;
    return $state;
}

/** 由累計結果產出 transfer_accel_learned.json 的內容 */
function transferLearnFinalize(SqliteDao $db, array $state)
{
    $a = transferAccelData();
    $names = transferNetworkModel($db)['names'];
    $min = (int) $a['Learn']['MinUses'];
    $hubs = [];
    arsort($state['HubUses']);
    foreach ($state['HubUses'] as $u => $n) {
        if ($n >= $min) {
            $hubs[] = ['StationUID' => $u, 'Name' => $names[$u]['Zh_tw'] ?? '', 'Uses' => $n, 'ODPairs' => $state['HubOD'][$u] ?? 0];
        }
    }
    $manual = array_column($a['TransferHubs'], 'Name', 'StationUID');
    $learnedSet = array_flip(array_column($hubs, 'StationUID'));
    $corridors = [];
    foreach ($a['Corridors'] as $c) {
        list($x, $y) = $c['Regions'];
        // 走廊外的地區：用到它的方案占這個地區組合全部方案的比例 ≥ CorridorMinShare 才放寬（加進 Allow），
        // 否則維持排除，並把這些方案記為「加速時可能少掉」的比例（LossShare）
        $minShare = (float) $a['Learn']['CorridorMinShare'];
        $total = 0;
        $cnt = [];
        $ex = [];
        foreach (array_unique([$x . '|' . $y, $y . '|' . $x]) as $pk) {
            $total += $state['CorridorTotal'][$pk] ?? 0;
            foreach ($state['Corridor'][$pk] ?? [] as $code => $e) {
                $cnt[$code] = ($cnt[$code] ?? 0) + $e['Count'];
                $ex[$code] = $ex[$code] ?? $e;
            }
        }
        $added = [];
        $kept = [];
        $evidence = [];
        $loss = 0;
        foreach ($cnt as $code => $n) {
            if ($c['Allow'] === '*' || in_array($code, $c['Allow'], true)) {
                continue;
            }
            $share = $total ? $n / $total : 0;
            $e = $ex[$code];
            $evidence[$code] = ['Count' => $n, 'Share' => round($share, 6), 'From' => $e['From'], 'To' => $e['To'], 'Via' => $e['Via'], 'Depart' => $e['Depart'],
                                'FromName' => $names[$e['From']]['Zh_tw'] ?? '', 'ViaName' => $names[$e['Via']]['Zh_tw'] ?? '', 'ToName' => $names[$e['To']]['Zh_tw'] ?? ''];
            if ($share >= $minShare) {
                $added[] = $code;
            } else {
                $kept[] = $code;
                $loss += $n;
            }
        }
        $allow = $c['Allow'] === '*' ? '*' : array_values(array_unique(array_merge($c['Allow'], $added)));
        $corridors[] = ['Regions' => $c['Regions'], 'Allow' => $allow, 'BaseAllow' => $c['Allow'], 'Added' => $added, 'StillExcluded' => $kept,
                        'Journeys' => $total, 'LossShare' => $total ? round($loss / $total, 6) : 0, 'Evidence' => $evidence ?: new stdClass()];
    }
    return [
        'Version'     => 1,
        'GeneratedAt' => date('Y-m-d H:i:s'),
        'Description' => '由 func=learn 從離線時刻表跑完整搜尋算出的加速清單：LearnedHubs＝最佳方案中實際用來同站換車的臺鐵車站（取代手寫 TransferHubs），'
                       . 'EffectiveCorridors＝手寫走廊加上最佳方案實際經過的地區（Added 與 Evidence 為補上的地區與例子）。DbBuiltAt 與 finder 資料庫不同時視為過期。',
        'DbBuiltAt'   => $state['DbBuiltAt'],
        'InputsHash'  => $state['InputsHash'],
        'Config'      => $state['Config'],
        'Stats'       => ['Origins' => count($state['Tasks']) / max(1, count($state['Config']['Days'])), 'Tasks' => count($state['Tasks']),
                          'Runs' => $state['Runs'], 'Journeys' => $state['Journeys'], 'Seconds' => round($state['Seconds'], 1),
                          'StartedAt' => $state['StartedAt']],
        'LearnedHubs' => $hubs,
        'ManualHubsUnused' => array_values(array_diff_key($manual, $learnedSet)),
        'LearnedHubsNotInManual' => array_values(array_map(function ($h) { return $h['Name']; },
                                     array_filter($hubs, function ($h) use ($manual) { return !isset($manual[$h['StationUID']]); }))),
        'EffectiveCorridors' => $corridors,
    ];
}

/** 執行一段；完成時寫出結果檔並刪除進度檔。回傳進度摘要 */
function transferLearnRun(SqliteDao $db, $seconds, $reset = false, $force = false)
{
    $state = (!$reset && is_file(TRANSFER_LEARN_STATE_FILE)) ? json_decode((string) file_get_contents(TRANSFER_LEARN_STATE_FILE), true) : null;
    if (!$state && !$reset && !$force) {
        // 沒有進行中的分析、清單也還新：不必重算
        $d = ['db' => $db];
        $l = transferAccelLearned($d);
        if ($l && $l['Fresh']) {
            return ['done' => true, 'skipped' => true, 'reason' => '加速清單未過期（資料庫與輸入都沒變），不必重算；要重算請加 force=1',
                    'learnedAt' => $l['GeneratedAt']];
        }
    }
    if (!$state || ($state['DbBuiltAt'] ?? null) !== transferLearnDbBuiltAt($db) || ($state['InputsHash'] ?? null) !== transferLearnInputsHash()) {
        $state = transferLearnInit($db);
    }
    $state = transferLearnStep($db, $state, $seconds);
    $done = $state['Next'] >= count($state['Tasks']);
    $out = ['done' => $done, 'progress' => $state['Next'] . '/' . count($state['Tasks']), 'runs' => $state['Runs'],
            'journeys' => $state['Journeys'], 'seconds' => round($state['Seconds'], 1)];
    if ($done) {
        $learned = transferLearnFinalize($db, $state);
        transferWriteGenerated('AccelLearned', json_encode($learned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @unlink(TRANSFER_LEARN_STATE_FILE);
        $out += ['learnedHubs' => count($learned['LearnedHubs']), 'corridorsWidened' => array_values(array_filter(array_map(function ($c) {
            return $c['Added'] ? implode('-', $c['Regions']) . ' +' . implode(',', $c['Added']) : null;
        }, $learned['EffectiveCorridors']))), 'corridorsLoss' => array_values(array_filter(array_map(function ($c) {
            return $c['LossShare'] > 0 ? implode('-', $c['Regions']) . ' ' . round($c['LossShare'] * 100, 3) . '%' : null;
        }, $learned['EffectiveCorridors'])))];
    } else {
        file_put_contents(TRANSFER_LEARN_STATE_FILE, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    return $out;
}

// ---- 命令列：php libs/txapi/transfer/learn.php [--reset] [--force]（清單未過期時預設跳過，--force 照樣重算）
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $root = dirname(__DIR__, 3);
    foreach (['config.php', 'http.php', 'sqlite.php', 'tdx.php', 'proxy.php', 'ratelimit.php', 'railcache.php', 'txapi/txapi.php'] as $lib) {
        require_once $root . '/libs/' . $lib;
    }
    $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);
    $reset = in_array('--reset', $argv, true);
    $force = in_array('--force', $argv, true);
    do {
        $r = transferLearnRun($db, 60, $reset, $force);
        $reset = false;
        $force = false;
        fwrite(STDERR, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
    } while (!$r['done']);
}
