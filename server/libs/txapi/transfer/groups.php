<?php
/**
 * 轉乘群組（TransferGroup）產生器與讀取。
 *
 * 轉乘群組＝可以互相步行轉乘的一組車站（同營運單位或跨營運單位），成員一律以 TDX StationUID 記錄，
 * 路線以「系統-LineID」記錄（例如 TRA-WL、TRTC-BL，等於 finder 資料庫 line 表的主鍵）。
 * 同一車站內換車（臺鐵區間車轉自強號、機捷 A8 普通車轉直達車、捷運支線分岔站）也建成只有一站的群組。
 *
 * 成員（使用者指定，2026-10）：自動偵測只增不減，人工可加可排除
 *   自動    TDX 捷運 LineTransfer、TransferStations、StationTransfer，臺鐵 LineTransfer，座標距離（不同系統 300 m 內、同名 450 m 內）
 *           → 記在 custom/libs/txapi/transfer/data/transfer_links.json：新的加入、舊的保留（沒再偵測到標 Detected=false）
 *   人工    transfer_seed.json 的 Groups（多站群組）、Pairs（兩站）；ExcludePairs 排除（從紀錄檔刪掉且不再加入）；
 *           Bypass 排除記號（保留群組與步行資料，但查詢時不在這裡中途轉乘，例如萬華↔龍山寺）
 *   步行    TDX LineTransfer.TransferTime／MinTransferTime → TDX StationTransfer.MinTransferTime（同方向，再反方向）
 *           → railtime／rocptx 的 walkMinute（transfer_seed.json Walks）→ 兩站座標距離推算
 *   同站換車 TDX 臺鐵／捷運 LineTransfer（交會站、支線分岔）→ transfer_seed.json TrainChange（railtime 臺鐵大站、機捷 A8、淡海 V09）
 *           → 臺鐵依時刻表自動選站（快車停靠或列車起訖多的站）；高鐵每站
 *
 * 產出 custom/libs/txapi/transfer/data/transfer_groups.json（給其他專案使用的完整陣列），由 /txapi/transfer/groups 提供。
 */

require_once __DIR__ . '/settings.php';

define('TRANSFER_SEED_FILE', transferDataFile('Seed'));
define('TRANSFER_GROUPS_FILE', transferDataFile('Groups'));
const TRANSFER_SCHEMA_VERSION = 1;
const TRANSFER_GROUP_PREFIX = 'TRANSGRP_';

/** 產生器用到的 download 檔案 */
function transferSourceFiles()
{
    $files = ['/v3/Rail/TRA/LineTransfer', '/v3/Rail/TRA/StationTransfer', '/v2/Rail/TRA/Station', '/v2/Rail/THSR/Station',
              '/v3/Rail/TRA/GeneralTrainTimetable'];
    $src = transferSettings('GroupSources');
    foreach ($src['MetroLineTransfer'] as $s) {
        $files[] = '/v2/Rail/Metro/LineTransfer/' . $s;
    }
    foreach ($src['MetroStationTransfer'] as $s) {
        $files[] = '/v2/Rail/Metro/StationTransfer/' . $s;
    }
    foreach (array_keys($src['MetroTransferStations']) as $s) {
        $files[] = '/v2/Rail/Metro/TransferStations/' . $s;
    }
    foreach (FINDERDB_METRO_SYSTEMS as $s) {
        $files[] = '/v2/Rail/Metro/Station/' . $s;
    }
    return $files;
}

/** 讀取已產生的轉乘群組；沒有檔案回 null */
function transferLoadGroups()
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    if (!is_file(TRANSFER_GROUPS_FILE)) {
        return null;
    }
    $j = json_decode((string) file_get_contents(TRANSFER_GROUPS_FILE), true);
    return $cache = is_array($j) ? $j : null;
}

/** 兩點距離（公尺） */
function transferDistance($a, $b)
{
    if (!$a || !$b) {
        return null;
    }
    $r = 6371000;
    $la1 = deg2rad($a['Lat']);
    $la2 = deg2rad($b['Lat']);
    $dla = $la2 - $la1;
    $dlo = deg2rad($b['Lon'] - $a['Lon']);
    $h = sin($dla / 2) ** 2 + cos($la1) * cos($la2) * sin($dlo / 2) ** 2;
    return (int) round(2 * $r * asin(sqrt($h)));
}

/** 車站中文名正規化（用來把 TDX 舊站碼、別名對到車站）：臺→台，去掉業者前綴與「車站／站」 */
function transferNormName($n)
{
    $n = str_replace('臺', '台', (string) $n);
    $n = preg_replace('/^(台鐵|高鐵|北捷|新北捷|捷運)/u', '', $n);
    return preg_replace('/(車站|站)$/u', '', $n);
}

/** 群組代碼用的名稱：英文名轉小寫、去掉括號內容與「_地名」後綴、去掉 station／main station、只留英文字母 */
function transferIdName($en)
{
    $s = strtolower((string) $en);
    $s = preg_replace('/\(.*?\)/', '', $s);
    $s = preg_replace('/_.*$/', '', $s);
    $s = preg_replace('/\b(main\s+)?station\b/', '', $s);
    return preg_replace('/[^a-z]/', '', $s);
}

/**
 * 產生轉乘群組。回傳完整陣列（同 transfer_groups.json 的內容）
 */
function transferBuildGroups()
{
    $seed = json_decode((string) file_get_contents(TRANSFER_SEED_FILE), true);
    if (!is_array($seed)) {
        throw new RuntimeException('transfer_seed.json is not valid JSON');
    }
    $set = $seed['Settings'];
    $prio = array_flip($set['SystemPriority']);
    $db = SqliteDao::open(FINDERDB_NAME, ['readonly' => true]);

    // ---- 車站：名稱、所屬路線、座標
    $st = [];
    foreach ($db->all('SELECT uid, system, station_id, name, line_ids FROM station') as $r) {
        $st[$r['uid']] = ['StationUID' => $r['uid'], 'System' => $r['system'], 'StationID' => $r['station_id'],
                          'StationName' => json_decode($r['name'], true),
                          'Lines' => array_map(function ($l) use ($r) { return $r['system'] . '-' . $l; }, json_decode($r['line_ids'], true) ?: []),
                          'Position' => null];
    }
    $posFiles = ['TRA' => '/v2/Rail/TRA/Station', 'THSR' => '/v2/Rail/THSR/Station'];
    foreach (FINDERDB_METRO_SYSTEMS as $s) {
        $posFiles[$s] = '/v2/Rail/Metro/Station/' . $s;
    }
    foreach ($posFiles as $sys => $path) {
        foreach (finderDbLoad($path) as $x) {
            $uid = finderDbUID($sys, $x['StationID']);
            if (isset($st[$uid]) && isset($x['StationPosition']['PositionLat'])) {
                $st[$uid]['Position'] = ['Lat' => (float) $x['StationPosition']['PositionLat'], 'Lon' => (float) $x['StationPosition']['PositionLon']];
            }
        }
    }
    $byName = [];   // 系統|正規化中文名 → [uid]
    foreach ($st as $uid => $x) {
        $byName[$x['System'] . '|' . transferNormName($x['StationName']['Zh_tw'] ?? '')][] = $uid;
    }
    $unresolved = [];
    // TDX 轉乘資料裡的車站 → StationUID（臺鐵捷運資料用舊站碼時以站名對應，高鐵補前導 0）
    $gs = transferSettings('GroupSources');
    $resolve = function ($op, $id, $name = '') use ($st, $byName, &$unresolved, $gs) {
        $op = strtoupper((string) $op);
        $op = $gs['OperatorAliases'][$op] ?? $op;
        $id = (string) $id;
        $cands = [];
        if (isset($gs['ZeroPadStationId'][$op])) {
            $cands[] = $op . '-' . str_pad($id, $gs['ZeroPadStationId'][$op], '0', STR_PAD_LEFT);
        } elseif (isset($gs['SharedStationIdOperators'][$op])) {
            foreach ($gs['SharedStationIdOperators'][$op] as $o) {
                $cands[] = $o . '-' . $id;
            }
        } else {
            $cands[] = $op . '-' . $id;
        }
        // 先以站名核對：同代碼不同站（臺鐵 v2／v3 站碼混用）時以站名為準
        $nn = transferNormName($name);
        foreach ($cands as $c) {
            if (isset($st[$c]) && ($nn === '' || transferNormName($st[$c]['StationName']['Zh_tw']) === $nn)) {
                return $c;
            }
        }
        foreach ($gs['SharedStationIdOperators'][$op] ?? [$op] as $s) {
            if ($nn !== '' && count($byName[$s . '|' . $nn] ?? []) === 1) {
                return $byName[$s . '|' . $nn][0];
            }
        }
        foreach ($cands as $c) {
            if (isset($st[$c])) {
                return $c;
            }
        }
        $unresolved[] = $op . ' ' . $id . ' ' . $name;
        return null;
    };

    // ---- 證據：成對步行分鐘（有向）、同站換車分鐘、群組合併
    $walk = [];        // "a>b" => [minutes, source, isOnSite, note]
    $setWalk = function ($a, $b, $min, $src, $onSite = null, $note = '') use (&$walk) {
        if ($a === null || $b === null || $a === $b) {
            return;
        }
        if (!isset($walk[$a . '>' . $b])) {
            $walk[$a . '>' . $b] = [$min, $src, $onSite, $note];
        }
    };
    $change = [];      // uid => [minutes, source, note]
    $setChange = function ($u, $min, $src, $note = '') use (&$change, $st) {
        if ($u !== null && isset($st[$u]) && !isset($change[$u])) {
            $change[$u] = [$min, $src, $note];
        }
    };
    $parent = [];
    $find = function ($x) use (&$parent, &$find) {
        if (!isset($parent[$x])) {
            $parent[$x] = $x;
        }
        return $parent[$x] === $x ? $x : ($parent[$x] = $find($parent[$x]));
    };
    $union = function ($a, $b) use (&$parent, $find) {
        if ($a !== null && $b !== null) {
            $parent[$find($a)] = $find($b);
        }
    };
    // 自動來源（TDX 轉乘資料、座標距離）偵測到的連結，先記下來，最後與連結紀錄檔（transfer_links.json）比對：
    // 只會新增、不會自動移除；人工在 transfer_seed.json 的 ExcludePairs 排除
    $detected = [];    // "kind|a|b" => [a, b, kind, source, note]
    $detect = function ($a, $b, $kind, $src, $note = '') use (&$detected) {
        if ($a === null || $b === null || $a === $b) {
            return;
        }
        list($x, $y) = strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
        $k = $kind . '|' . $x . '|' . $y;
        if (!isset($detected[$k])) {
            $detected[$k] = [$x, $y, $kind, $src, $note];
        }
    };
    $groupSrc = [];    // root 合併前先記每個 uid 的成員來源
    $addSrc = function ($u, $src) use (&$groupSrc) {
        if ($u !== null) {
            $groupSrc[$u][$src] = true;
        }
    };

    // 1. TDX 捷運 LineTransfer（同系統跨路線；From＝To 為支線分岔站的同站換車）
    foreach ($gs['MetroLineTransfer'] as $sys) {
        foreach (finderDbLoad('/v2/Rail/Metro/LineTransfer/' . $sys) as $t) {
            $a = $resolve($sys, $t['FromStationID'], $t['FromStationName']['Zh_tw'] ?? '');
            $b = $resolve($sys, $t['ToStationID'], $t['ToStationName']['Zh_tw'] ?? '');
            $min = (int) ($t['TransferTime'] ?? 0);
            $onSite = isset($t['IsOnSiteTransfer']) ? (int) $t['IsOnSiteTransfer'] : null;
            if ($a === $b) {
                $setChange($a, $min, 'tdx:LineTransfer', (string) ($t['TransferDescription'] ?? ''));
            } else {
                $setWalk($a, $b, $min, 'tdx:LineTransfer', $onSite, (string) ($t['TransferDescription'] ?? ''));
                $detect($a, $b, 'group', 'tdx:LineTransfer');
                $addSrc($a, 'tdx:LineTransfer');
                $addSrc($b, 'tdx:LineTransfer');
            }
        }
    }
    // 2. TDX 臺鐵 LineTransfer（路線交會站，同一站碼）
    foreach (finderDbLoad('/v3/Rail/TRA/LineTransfer')['LineTransfers'] ?? [] as $t) {
        $a = $resolve('TRA', $t['FromStationID'], $t['FromStationName']['Zh_tw'] ?? '');
        $b = $resolve('TRA', $t['ToStationID'], $t['ToStationName']['Zh_tw'] ?? '');
        if ($a === $b) {
            $setChange($a, (float) $t['MinTransferTime'], 'tdx:TRA/LineTransfer', trim((string) ($t['TransferDescription'] ?? '')));
        } else {
            $setWalk($a, $b, (float) $t['MinTransferTime'], 'tdx:TRA/LineTransfer');
            $detect($a, $b, 'group', 'tdx:TRA/LineTransfer');
        }
    }
    // 3. TDX 捷運共站站點
    foreach ($gs['MetroTransferStations'] as $file => $op) {
        foreach (finderDbLoad('/v2/Rail/Metro/TransferStations/' . $file) as $g) {
            $uids = [];
            foreach ($g['Stations'] as $x) {
                $uids[] = $resolve($op, $x['StationID'], $x['StationName'] ?? '');
            }
            for ($i = 1; $i < count($uids); $i++) {
                $detect($uids[0], $uids[$i], 'group', 'tdx:TransferStations');
            }
            foreach ($uids as $u) {
                $addSrc($u, 'tdx:TransferStations');
            }
        }
    }
    // 4. 人工群組（TDX 沒有的跨系統轉乘）：與連結紀錄檔一起在 5c 合併
    // 5. TDX 跨運具轉乘（StationTransfer 的 RailTransfers）：兩站已在同一群組只補分鐘，否則另成一組兩站的群組
    $pairOnly = [];
    $stationTransfers = [];
    foreach ($gs['MetroStationTransfer'] as $sys) {
        $stationTransfers[] = [$sys, finderDbLoad('/v2/Rail/Metro/StationTransfer/' . $sys)['StationTransfers'] ?? []];
    }
    $stationTransfers[] = ['TRA', finderDbLoad('/v3/Rail/TRA/StationTransfer')['StationTransfers'] ?? []];
    foreach ($stationTransfers as list($sys, $list)) {
        foreach ($list as $s) {
            $a = $resolve($sys, $s['StationID'], $s['StationName']['Zh_tw'] ?? '');
            foreach ($s['Transfers'] ?? [] as $t) {
                foreach ($t['RailTransfers'] ?? [] as $r) {
                    $b = $resolve($r['OperatorCode'], $r['StationID'], $r['StationName']['Zh_tw'] ?? '');
                    if ($a === null || $b === null || $a === $b) {
                        continue;
                    }
                    if (isset($r['MinTransferTime']) && $r['MinTransferTime'] !== null) {
                        $setWalk($a, $b, (float) $r['MinTransferTime'], 'tdx:StationTransfer',
                                 isset($r['IsOnSiteTransfer']) ? (int) $r['IsOnSiteTransfer'] : null, (string) ($r['Description'] ?? ''));
                    }
                    $detect($a, $b, 'pair', 'tdx:StationTransfer');
                }
            }
        }
    }
    // 5b. 座標距離：不同系統的兩站相距 AutoLink.MaxMeters 內，或同名（去掉「車站、站、臺／台」差異）相距 SameNameMaxMeters 內
    $al = $set['AutoLink'];
    $uidsPos = array_keys(array_filter($st, function ($x) { return $x['Position'] !== null; }));
    $nU = count($uidsPos);
    for ($i = 0; $i < $nU; $i++) {
        $A = $st[$uidsPos[$i]];
        for ($j = $i + 1; $j < $nU; $j++) {
            $B = $st[$uidsPos[$j]];
            if (!empty($al['CrossSystemOnly']) && $A['System'] === $B['System']) {
                continue;
            }
            if (abs($A['Position']['Lat'] - $B['Position']['Lat']) > 0.006 || abs($A['Position']['Lon'] - $B['Position']['Lon']) > 0.006) {
                continue;
            }
            $dm = transferDistance($A['Position'], $B['Position']);
            $same = transferNormName($A['StationName']['Zh_tw'] ?? '') === transferNormName($B['StationName']['Zh_tw'] ?? '');
            if ($dm <= $al['MaxMeters']) {
                $detect($A['StationUID'], $B['StationUID'], 'group', 'auto:distance', "相距 {$dm} 公尺");
            } elseif ($same && $dm <= $al['SameNameMaxMeters']) {
                $detect($A['StationUID'], $B['StationUID'], 'group', 'auto:samename', "同名、相距 {$dm} 公尺");
            }
        }
    }
    // 5c. 與連結紀錄檔比對：新偵測到的加入、已有的保留（這次沒偵測到也保留，標 Detected=false 供人工檢查）、人工排除的移除
    $exclude = [];
    foreach ($seed['ExcludePairs'] ?? [] as $e) {
        list($x, $y) = strcmp($e['Stations'][0], $e['Stations'][1]) < 0 ? $e['Stations'] : array_reverse($e['Stations']);
        $exclude[$x . '|' . $y] = true;
    }
    $ledger = transferLoadLinks();
    $now = downloadFmtTime(time());
    $linkDiff = ['added' => [], 'excluded' => [], 'notDetected' => []];
    $byKey = [];
    foreach ($ledger['Links'] as $l) {
        $byKey[$l['Kind'] . '|' . $l['A'] . '|' . $l['B']] = $l;
    }
    foreach ($detected as $k => list($x, $y, $kind, $src, $note)) {
        if (isset($exclude[$x . '|' . $y])) {
            continue;
        }
        if (!isset($st[$x]) || !isset($st[$y])) {
            continue;
        }
        if (!isset($byKey[$k])) {
            $byKey[$k] = ['A' => $x, 'B' => $y, 'AName' => $st[$x]['StationName']['Zh_tw'] ?? '', 'BName' => $st[$y]['StationName']['Zh_tw'] ?? '',
                          'Kind' => $kind, 'Source' => $src, 'Note' => $note, 'AddedAt' => $now];
            $linkDiff['added'][] = $k;
        }
        $byKey[$k]['Detected'] = true;
        $byKey[$k]['LastSeenAt'] = $now;
    }
    foreach ($byKey as $k => &$l) {
        if (isset($exclude[$l['A'] . '|' . $l['B']])) {
            $linkDiff['excluded'][] = $k;
            unset($byKey[$k]);
            continue;
        }
        if (!isset($detected[$k])) {
            $l['Detected'] = false;
            $linkDiff['notDetected'][] = $k;
        }
    }
    unset($l);
    ksort($byKey);
    $linkList = array_values($byKey);
    // 合併：紀錄檔的 group 連結與人工群組 → 群組；pair 連結（StationTransfer、人工 Pairs）兩站不在同一群組就另成兩站的群組
    foreach ($linkList as $l) {
        if ($l['Kind'] === 'group') {
            $union($l['A'], $l['B']);
            $addSrc($l['A'], $l['Source']);
            $addSrc($l['B'], $l['Source']);
        }
    }
    foreach ($seed['Groups'] as $g) {
        for ($i = 1; $i < count($g['Stations']); $i++) {
            $union($g['Stations'][0], $g['Stations'][$i]);
        }
        foreach ($g['Stations'] as $u) {
            $addSrc($u, 'seed');
        }
    }
    $pairs = [];
    foreach ($linkList as $l) {
        if ($l['Kind'] === 'pair') {
            $pairs[] = [$l['A'], $l['B']];
        }
    }
    foreach ($seed['Pairs'] ?? [] as $p) {
        $pairs[] = $p['Stations'];
        $addSrc($p['Stations'][0], 'seed');
        $addSrc($p['Stations'][1], 'seed');
    }
    foreach ($pairs as list($a, $b)) {
        if (!isset($parent[$a]) || !isset($parent[$b]) || $find($a) !== $find($b)) {
            // 不在同一群組（例如機捷 A1 ↔ 北捷北門 G13）：兩站另成一組
            $pairOnly['pair:' . min($a, $b) . '|' . max($a, $b)] = [$a, $b];
        }
    }
    // 6. 反方向補值：TDX StationTransfer 常只給單向，反向沿用（仍優先於 railtime／rocptx 的推估值）
    foreach ($walk as $k => $v) {
        list($a, $b) = explode('>', $k);
        if (!isset($walk[$b . '>' . $a])) {
            $walk[$b . '>' . $a] = [$v[0], $v[1] . '(反向)', $v[2], $v[3]];
        }
    }
    // 7. railtime／rocptx 步行分鐘
    foreach ($seed['Walks'] as $w) {
        $setWalk($w['From'], $w['To'], $w['Minutes'], $w['Source'], null, $w['Ref'] ?? '');
        $setWalk($w['To'], $w['From'], $w['Minutes'], $w['Source'], null, $w['Ref'] ?? '');
    }

    // ---- 同站換車：railtime／rocptx、臺鐵自動選站（快車停靠或列車起訖多的站）、高鐵全部、預設分鐘
    foreach ($seed['TrainChange'] as $c) {
        $setChange($c['Station'], $c['Minutes'], $c['Source'], $c['Ref'] ?? '');
    }
    $auto = $set['TrainChangeAuto']['TRA'];
    $codes = $auto['ExpressTypeCodes'];
    $in = implode(',', array_fill(0, count($codes), '?'));
    $traStats = $db->all("SELECT s.station_uid,
                                 SUM(CASE WHEN t.train_type_code IN ($in) THEN 1 ELSE 0 END) AS express,
                                 SUM(CASE WHEN s.seq = 1 OR s.seq = (SELECT MAX(seq) FROM train_stop x WHERE x.train_key = s.train_key) THEN 1 ELSE 0 END) AS ends
                          FROM train_stop s JOIN train t ON t.train_key = s.train_key WHERE t.system = 'TRA' GROUP BY s.station_uid", $codes);
    foreach ($traStats as $r) {
        if ((int) $r['express'] >= $auto['MinExpressStopsPerDay'] || (int) $r['ends'] >= $auto['MinTerminalsPerDay']) {
            $setChange($r['station_uid'], $set['TrainChangeDefault']['TRA'], 'default',
                       sprintf('自動選站：每日快車停靠 %d 班、列車起訖 %d 班（全星期合計）', $r['express'], $r['ends']));
        }
    }
    foreach ($st as $uid => $x) {
        if ($x['System'] === 'THSR') {
            $setChange($uid, $set['TrainChangeDefault']['THSR'], 'default', '高鐵每站都可換車（直達車與各站停車）');
        }
    }

    // ---- 組出群組
    $members = [];
    foreach (array_keys($parent) as $u) {
        if (isset($st[$u])) {
            $members[$find($u)][$u] = true;
        }
    }
    $groups = [];
    foreach ($members as $m) {
        if (count($m) >= 2) {
            $groups[] = ['uids' => array_keys($m), 'type' => 'multi'];
        }
    }
    foreach ($pairOnly as $k => $p) {
        if (is_string($k)) {
            $groups[] = ['uids' => $p, 'type' => 'multi'];
        }
    }
    $inMulti = [];
    foreach ($groups as $g) {
        foreach ($g['uids'] as $u) {
            $inMulti[$u] = true;
        }
    }
    foreach (array_keys($change) as $u) {
        if (!isset($inMulti[$u])) {
            $groups[] = ['uids' => [$u], 'type' => 'single'];
        }
    }

    // 排除記號：seed Bypass 的 Stations（兩站以上時為其中每一對）
    $bypass = [];
    foreach ($seed['Bypass'] ?? [] as $b) {
        $list = $b['Stations'];
        foreach ($list as $i => $x) {
            foreach (array_slice($list, $i + 1) as $y) {
                $bk = strcmp($x, $y) < 0 ? "$x|$y" : "$y|$x";
                $bypass[$bk] = ['AllowAtEnds' => $b['AllowAtEnds'] ?? true, 'Note' => $b['Note'] ?? ''];
            }
        }
    }
    $est = $set['WalkEstimate'];
    $out = [];
    foreach ($groups as $g) {
        $uids = $g['uids'];
        usort($uids, function ($a, $b) use ($st, $prio) {
            return [$prio[$st[$a]['System']] ?? 99, $a] <=> [$prio[$st[$b]['System']] ?? 99, $b];
        });
        $stations = [];
        $lines = [];
        foreach ($uids as $u) {
            $x = $st[$u];
            $x['TrainChange'] = isset($change[$u]) ? ['Minutes' => $change[$u][0], 'Source' => $change[$u][1], 'Note' => $change[$u][2]] : null;
            $stations[] = $x;
            foreach ($x['Lines'] as $l) {
                $lines[$l] = true;
            }
        }
        $transfers = [];
        foreach ($uids as $a) {
            foreach ($uids as $b) {
                if ($a === $b) {
                    continue;
                }
                $d = transferDistance($st[$a]['Position'], $st[$b]['Position']);
                if (isset($walk[$a . '>' . $b])) {
                    $w = $walk[$a . '>' . $b];
                    $transfers[] = ['FromStationUID' => $a, 'ToStationUID' => $b, 'Minutes' => $w[0], 'Source' => $w[1],
                                    'IsOnSiteTransfer' => $w[2], 'DistanceMeters' => $d, 'Note' => $w[3]];
                } else {
                    $m = $d === null ? $est['MaxMinutes'] : (int) round($est['BaseMinutes'] + $d / $est['MetersPerMinute']);
                    $m = max($est['MinMinutes'], min($est['MaxMinutes'], $m));
                    $transfers[] = ['FromStationUID' => $a, 'ToStationUID' => $b, 'Minutes' => $m, 'Source' => 'estimate',
                                    'IsOnSiteTransfer' => null, 'DistanceMeters' => $d,
                                    'Note' => sprintf('座標距離 %s 公尺推算：%d 分＋距離÷每分鐘 %d 公尺', $d ?? '?', $est['BaseMinutes'], $est['MetersPerMinute'])];
                }
            }
        }
        // 排除記號（seed Bypass）：保留群組與步行資料，但查詢時不在這裡中途轉乘
        $byp = 0;
        foreach ($transfers as &$t) {
            $bk = strcmp($t['FromStationUID'], $t['ToStationUID']) < 0 ? $t['FromStationUID'] . '|' . $t['ToStationUID'] : $t['ToStationUID'] . '|' . $t['FromStationUID'];
            if (isset($bypass[$bk])) {
                $t['Bypass'] = true;
                $t['BypassAllowAtEnds'] = $bypass[$bk]['AllowAtEnds'];
                $t['BypassNote'] = $bypass[$bk]['Note'];
                $byp++;
            }
        }
        unset($t);
        $sources = [];
        foreach ($uids as $u) {
            foreach (array_keys($groupSrc[$u] ?? []) as $s) {
                $sources[$s] = true;
            }
        }
        foreach ($transfers as $t) {
            $sources[preg_replace('/\(反向\)$/u', '', $t['Source'])] = true;
        }
        foreach ($stations as $x) {
            if ($x['TrainChange']) {
                $sources[$x['TrainChange']['Source']] = true;
            }
        }
        $head = $stations[0];
        $idName = $seed['IdNames'][$head['StationUID']] ?? transferIdName($head['StationName']['En'] ?? '');
        $out[] = ['_idName' => $idName, 'TransferGroupID' => '', 'GroupName' => $head['StationName'], 'Type' => $g['type'],
                  'Bypass' => $transfers && $byp === count($transfers),
                  'Stations' => $stations, 'Lines' => array_keys($lines), 'Transfers' => $transfers, 'Sources' => array_keys($sources)];
    }
    // ---- 群組代碼：名稱重複時依優先順序加 1、2…
    usort($out, function ($a, $b) use ($prio) {
        return [$a['_idName'], $prio[$a['Stations'][0]['System']] ?? 99, $a['Stations'][0]['StationUID']]
           <=> [$b['_idName'], $prio[$b['Stations'][0]['System']] ?? 99, $b['Stations'][0]['StationUID']];
    });
    $count = array_count_values(array_column($out, '_idName'));
    $seen = [];
    foreach ($out as &$g) {
        $n = $g['_idName'];
        $seen[$n] = ($seen[$n] ?? 0) + 1;
        $g['TransferGroupID'] = TRANSFER_GROUP_PREFIX . $n . ($count[$n] > 1 ? $seen[$n] : '');
        unset($g['_idName']);
    }
    unset($g);
    usort($out, function ($a, $b) { return strcmp($a['TransferGroupID'], $b['TransferGroupID']); });

    // ---- 路線與路線連結
    $groupOf = [];   // uid => [groupId]
    foreach ($out as $g) {
        foreach ($g['Stations'] as $x) {
            $groupOf[$x['StationUID']][] = $g['TransferGroupID'];
        }
    }
    $lines = [];
    foreach ($db->all('SELECT system, line_id, name, color, is_branch FROM line ORDER BY sort') as $r) {
        $uid = $r['system'] . '-' . $r['line_id'];
        $seq = array_column($db->all('SELECT station_uid FROM line_station WHERE system = ? AND line_id = ? ORDER BY seq', [$r['system'], $r['line_id']]), 'station_uid');
        $tg = [];
        foreach ($seq as $u) {
            foreach ($groupOf[$u] ?? [] as $gid) {
                $tg[] = ['StationUID' => $u, 'TransferGroupID' => $gid];
            }
        }
        $lines[] = ['LineUID' => $uid, 'System' => $r['system'], 'LineID' => $r['line_id'], 'LineName' => json_decode($r['name'], true),
                    'LineColor' => $r['color'], 'IsBranch' => (bool) $r['is_branch'], 'Stations' => $seq, 'TransferGroups' => $tg];
    }
    $links = [];
    foreach ($out as $g) {
        foreach ($g['Lines'] as $la) {
            foreach ($g['Lines'] as $lb) {
                if ($la !== $lb) {
                    $links[$la . '|' . $lb][] = $g['TransferGroupID'];
                }
            }
        }
    }
    ksort($links);
    $lineLinks = [];
    foreach ($links as $k => $gids) {
        list($la, $lb) = explode('|', $k);
        $lineLinks[] = ['FromLineUID' => $la, 'ToLineUID' => $lb, 'TransferGroups' => array_values(array_unique($gids))];
    }

    $counts = ['TransferGroups' => count($out), 'Multi' => 0, 'Single' => 0, 'Stations' => 0, 'Transfers' => 0, 'TransferSources' => []];
    foreach ($out as $g) {
        $counts[$g['Type'] === 'multi' ? 'Multi' : 'Single']++;
        $counts['Stations'] += count($g['Stations']);
        $counts['Transfers'] += count($g['Transfers']);
        foreach ($g['Transfers'] as $t) {
            $counts['TransferSources'][$t['Source']] = ($counts['TransferSources'][$t['Source']] ?? 0) + 1;
        }
    }
    $db->close();
    return [
        'Version'      => TRANSFER_SCHEMA_VERSION,
        'GeneratedAt'  => downloadFmtTime(time()),
        'Description'  => '軌道轉乘群組：可互相步行轉乘的一組車站（StationUID），以及同站換車的車站；路線以「系統-LineID」表示。'
                        . 'Transfers 為群組內每一對車站的有向步行分鐘與來源（tdx／railtime／rocptx／estimate）；TrainChange 為同站換車分鐘。',
        'Settings'     => $set,
        'Counts'       => $counts,
        'Unresolved'   => array_values(array_unique($unresolved)),
        'TransferGroups' => $out,
        'Lines'        => $lines,
        'LineLinks'    => $lineLinks,
        '_links'       => ['Links' => $linkList, 'Diff' => $linkDiff],
    ];
}

/** 連結紀錄檔（transfer_links.json）：自動偵測到的轉乘連結，只增不減；沒有檔案回空清單 */
function transferLoadLinks()
{
    $f = transferDataFile('Links');
    $j = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($j) && isset($j['Links']) ? $j : ['Links' => []];
}
