<?php
/**
 * 軌道里程：搭乘段的公里數，用來找「最短距離」方案。
 *
 * 資料檔 custom/libs/txapi/transfer/data/transfer_distance.json（/txapi/admin_transfer/build_groups 時一起產生，也可給其他專案用）：
 *   - 臺鐵：TDX /v3/Rail/TRA/StationOfLine 的 CumulativeDistance，相鄰兩站相減成一條邊（標記路線；
 *     西部幹線竹南—彰化之間另標 WL-M 山線，用來跟海線 WL-C 分開，依列車 TripLine 選山線或海線）
 *   - 捷運、輕軌：TDX /v2/Rail/Metro/StationOfRoute 的 CumulativeDistance，同一路線相鄰兩站相減；
 *     累計里程沒有給（例如全部是 0）時改用兩站座標直線距離（estimate）
 *   - 高鐵：TDX 沒有里程資料，用相鄰兩站座標直線距離（estimate）
 * 搭乘段的里程 = 列車每兩個相鄰停靠站之間在路網上的最短距離加總（捷運只走同一條路線的邊）。
 */

require_once __DIR__ . '/settings.php';

define('TRANSFER_DISTANCE_FILE', transferDataFile('Distance'));
const TRANSFER_DISTANCE_VERSION = 1;

/** 讀 download 檔裡的陣列（有的檔案外面還包一層物件） */
function transferDistanceReadList($path, $key = null)
{
    $f = DOWNLOAD_DIR . '/tdx' . $path . '.json';
    if (!is_file($f)) {
        return [];
    }
    $j = json_decode((string) file_get_contents($f), true);
    if (!is_array($j)) {
        return [];
    }
    if ($key !== null && isset($j[$key])) {
        return $j[$key];
    }
    if (array_is_list($j)) {
        return $j;
    }
    foreach ($j as $v) {
        if (is_array($v) && array_is_list($v)) {
            return $v;
        }
    }
    return [];
}

/** 兩點直線距離（公里） */
function transferDistanceKm($a, $b)
{
    $m = transferDistance($a, $b);
    return $m === null ? null : round($m / 1000, 3);
}

/** 產生里程資料 */
function transferBuildDistance()
{
    $systems = [];
    $sources = [];
    // 臺鐵
    $tra = transferDistanceReadList('/v3/Rail/TRA/StationOfLine', 'StationOfLines');
    if ($tra) {
        $sources[] = '/v3/Rail/TRA/StationOfLine';
        $edges = [];
        $splits = transferSettings('Distance.SplitSegments');   // 例：西部幹線竹南—彰化另標 WL-M（山線）
        foreach ($tra as $l) {
            $st = $l['Stations'];
            usort($st, function ($a, $b) { return $a['Sequence'] <=> $b['Sequence']; });
            $inSplit = null;
            for ($i = 0; $i + 1 < count($st); $i++) {
                $a = $st[$i];
                $b = $st[$i + 1];
                foreach ($splits as $sp) {
                    if ($sp['LineID'] === $l['LineID'] && $a['StationID'] === $sp['FromStationID']) {
                        $inSplit = $sp;
                    }
                }
                $tag = $inSplit ? $inSplit['Tag'] : $l['LineID'];
                if ($inSplit && $b['StationID'] === $inSplit['ToStationID']) {
                    $inSplit = null;    // 到區段終點為止（這條邊仍屬區段）
                }
                $km = round(abs($b['CumulativeDistance'] - $a['CumulativeDistance']), 3);
                $edges[] = ['TRA-' . $a['StationID'], 'TRA-' . $b['StationID'], $km, $tag, 'tdx:StationOfLine'];
            }
        }
        $systems['TRA'] = ['Edges' => $edges];
    }
    // 高鐵
    $thsr = transferDistanceReadList('/v2/Rail/THSR/Station');
    if ($thsr) {
        $sources[] = '/v2/Rail/THSR/Station';
        usort($thsr, function ($a, $b) { return strcmp($a['StationID'], $b['StationID']); });
        $edges = [];
        for ($i = 0; $i + 1 < count($thsr); $i++) {
            $a = $thsr[$i];
            $b = $thsr[$i + 1];
            $km = transferDistanceKm(transferDistancePos($a), transferDistancePos($b));
            $edges[] = ['THSR-' . $a['StationID'], 'THSR-' . $b['StationID'], $km, transferSettings('Distance.THSRLineTag'), 'estimate'];
        }
        $systems['THSR'] = ['Edges' => $edges];
    }
    // 捷運、輕軌
    foreach (FINDERDB_METRO_SYSTEMS as $sys) {
        $routes = transferDistanceReadList('/v2/Rail/Metro/StationOfRoute/' . $sys);
        if (!$routes) {
            continue;
        }
        $sources[] = '/v2/Rail/Metro/StationOfRoute/' . $sys;
        $pos = [];
        foreach (transferDistanceReadList('/v2/Rail/Metro/Station/' . $sys) as $s) {
            $pos[$s['StationID']] = transferDistancePos($s);
        }
        $edges = [];
        foreach ($routes as $r) {
            $st = $r['Stations'];
            usort($st, function ($a, $b) { return $a['Sequence'] <=> $b['Sequence']; });
            for ($i = 0; $i + 1 < count($st); $i++) {
                $a = $st[$i];
                $b = $st[$i + 1];
                $key = $r['LineID'] . '|' . min($a['StationID'], $b['StationID']) . '|' . max($a['StationID'], $b['StationID']);
                if (isset($edges[$key]) && $edges[$key][4] !== 'estimate') {
                    continue;
                }
                $km = isset($a['CumulativeDistance'], $b['CumulativeDistance']) ? round(abs($b['CumulativeDistance'] - $a['CumulativeDistance']), 3) : 0;
                $src = 'tdx:StationOfRoute';
                if ($km <= 0) {
                    $km = transferDistanceKm($pos[$a['StationID']] ?? null, $pos[$b['StationID']] ?? null);
                    $src = 'estimate';
                }
                if ($km === null) {
                    continue;
                }
                $edges[$key] = [$sys . '-' . $a['StationID'], $sys . '-' . $b['StationID'], $km, $r['LineID'], $src];
            }
        }
        $systems[$sys] = ['Edges' => array_values($edges)];
    }
    return [
        'Version'     => TRANSFER_DISTANCE_VERSION,
        'GeneratedAt' => date('Y-m-d H:i:s'),
        'Description' => '軌道里程（公里）：每個系統相鄰兩站之間的一條邊 [站A, 站B, 公里, 路線, 來源]。來源 tdx:StationOfLine／tdx:StationOfRoute 為 TDX 累計里程相減；estimate 為兩站座標直線距離（高鐵全部、以及 TDX 沒給里程的捷運路段）。臺鐵西部幹線竹南—彰化標為 WL-M（山線），海線為 WL-C。',
        'Sources'     => $sources,
        'Systems'     => $systems,
    ];
}

function transferDistancePos($s)
{
    $p = $s['StationPosition'] ?? null;
    return $p && isset($p['PositionLat'], $p['PositionLon']) ? ['Lat' => (float) $p['PositionLat'], 'Lon' => (float) $p['PositionLon']] : null;
}

/** 讀取里程資料並建好鄰接表（沒有檔案時當場從 download 產生） */
function transferDistanceGraph()
{
    static $g = null;
    if ($g !== null) {
        return $g;
    }
    $j = is_file(TRANSFER_DISTANCE_FILE) ? json_decode((string) file_get_contents(TRANSFER_DISTANCE_FILE), true) : null;
    if (!$j || ($j['Version'] ?? 0) !== TRANSFER_DISTANCE_VERSION) {
        $j = transferBuildDistance();
    }
    $g = [];
    foreach ($j['Systems'] as $sys => $x) {
        foreach ($x['Edges'] as list($a, $b, $km, $line, $src)) {
            $g[$sys][$a][] = [$b, $km, $line, $src];
            $g[$sys][$b][] = [$a, $km, $line, $src];
        }
    }
    return $g;
}

/**
 * 同一系統兩站在路網上的最短里程。$lines：只走這些路線的邊（null 不限）；$avoid：不走這些路線的邊。
 * 回傳 [公里, 來源（全部 tdx 為 tdx，有任何一段推估為 estimate）]，找不到回 null
 */
function transferDistanceBetween($sys, $from, $to, $lines = null, $avoid = [])
{
    static $cache = [];
    $alias = transferSettings('Distance.StationAliases');
    $from = $alias[$from] ?? $from;
    $to = $alias[$to] ?? $to;
    if ($from === $to) {
        return [0.0, 'tdx'];
    }
    $key = $sys . '|' . $from . '|' . $to . '|' . ($lines ? implode(',', $lines) : '') . '|' . implode(',', $avoid);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $g = transferDistanceGraph()[$sys] ?? [];
    if (!isset($g[$from], $g[$to])) {
        return $cache[$key] = null;
    }
    $dist = [$from => 0.0];
    $est = [$from => false];
    $done = [];
    $pq = new SplPriorityQueue();
    $pq->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    $pq->insert($from, 0);
    while (!$pq->isEmpty()) {
        $u = $pq->extract();
        if (isset($done[$u])) {
            continue;
        }
        $done[$u] = true;
        if ($u === $to) {
            break;
        }
        foreach ($g[$u] as list($v, $km, $line, $src)) {
            if (($lines && !in_array($line, $lines, true)) || in_array($line, $avoid, true)) {
                continue;
            }
            $nd = $dist[$u] + $km;
            if ($nd < ($dist[$v] ?? INF) - 1e-9) {
                $dist[$v] = $nd;
                $est[$v] = $est[$u] || $src === 'estimate';
                $pq->insert($v, -$nd);
            }
        }
    }
    return $cache[$key] = isset($dist[$to]) ? [round($dist[$to], 1), $est[$to] ? 'estimate' : 'tdx'] : null;
}

/**
 * 搭乘段里程：沿著列車（或捷運營運路線）實際停靠的站，每兩個相鄰停靠站取路網最短里程再加總。
 * $stops：依序的停靠站 StationUID；$lines：捷運只走同一路線；$tripLine：臺鐵 TripLine（1 山線、2 海線）
 */
function transferRideDistance($sys, array $stops, $lines = null, $tripLine = 0)
{
    $avoid = $sys === 'TRA' ? (transferSettings('Distance.TripLineAvoidTags')[(string) (int) $tripLine] ?? []) : [];
    $km = 0.0;
    $src = 'tdx';
    for ($i = 0; $i + 1 < count($stops); $i++) {
        $r = transferDistanceBetween($sys, $stops[$i], $stops[$i + 1], $lines, $avoid);
        if ($r === null && $lines) {
            $r = transferDistanceBetween($sys, $stops[$i], $stops[$i + 1], null, $avoid);
        }
        if ($r === null) {
            return [null, null];
        }
        $km += $r[0];
        if ($r[1] === 'estimate') {
            $src = 'estimate';
        }
    }
    return [round($km, 1), $src];
}
