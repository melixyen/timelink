<?php
/**
 * 路由補充資料（transfer/data/transfer_route_supplements.json，人工維護、受版控）。
 *
 * 自動路由檢索（路網＋轉乘站＋排除規則）完成後，逐條看補充資料：條件百分之百符合的，就依它的 Logic 與 Meta 多產生一條路由
 * （一條補充資料最多多一條，不會多條）。補充的是資料與路線常理以外、人工搭乘經驗上也會這樣搭的走法，例如臺鐵區間車在接力站換車、
 * 區間車轉對號列車、高鐵在台中換各站停。
 *
 * 每條補充資料：
 *   Name        名稱
 *   Operators   業者陣列（必填），例如 ["TRA"]；以後跨業者的補充寫 ["TRA", "TRTC"]
 *   Classes     會用到的車種等級陣列（選填，local／express）：extra 排除了其中任何一種就不套用
 *   From、To    搭乘段的上車站、下車站條件（選填）：車站代碼陣列，或範圍 [{Line, StationRange?}]（同 RouteBans）
 *   ViaLines    搭乘段要經過的路線（選填，任一條即可）
 *   ViaStations 搭乘段要經過的車站（選填，任一站即可）
 *   Logic       處理的函式（必填，見 APP_SUPPLEMENT_LOGIC；業者前綴的只看得懂該業者的 Meta）
 *   Meta        給 Logic 的參數
 *   Disabled    停用
 * 一條路由裡所有符合條件的搭乘段都交給 Logic 改寫；Logic 回 null 的段不改；一段都沒改就不產生。
 */

const APP_SUPPLEMENT_LOGIC = [
    'TRA.LocalRelay'      => 'appSupTraLocalRelay',       // 臺鐵全程區間車：在接力站換車
    'TRA.ExpressTransfer' => 'appSupTraExpressTransfer',  // 臺鐵區間車轉對號列車再轉區間車
    'TRA.ChangeAt'        => 'appSupTraChangeAt',         // 臺鐵在指定車站換車（不限車種）
    'ClassChange'         => 'appSupClassChange',         // 高鐵、機捷在換車站換快車／慢車
];

/** 補充資料（已過濾停用；沒有檔案回空陣列） */
function appRouteSupplements()
{
    static $list = null;
    if ($list === null) {
        $list = [];
        $j = json_decode((string) @file_get_contents(transferDataFile('RouteSupplements')), true);
        $m = appModel();
        foreach (is_array($j) ? ($j['Supplements'] ?? []) : [] as $x) {
            if (!empty($x['Disabled']) || empty($x['Operators']) || !isset(APP_SUPPLEMENT_LOGIC[$x['Logic'] ?? ''])) {
                continue;
            }
            foreach (['From', 'To', 'ViaStations'] as $k) {
                if (!empty($x[$k])) {
                    $x['_' . $k] = appSupStationSet($m, $x[$k]);
                }
            }
            $list[] = $x;
        }
    }
    return $list;
}

/** 車站條件 → 集合：車站代碼陣列，或範圍 [{Line, StationRange?}]，兩種可以混寫 */
function appSupStationSet(array $m, array $cond)
{
    $set = [];
    $ranges = [];
    foreach ($cond as $c) {
        if (is_string($c)) {
            $set[$c] = true;
        } elseif (is_array($c)) {
            $ranges[] = $c;
        }
    }
    return $set + ($ranges ? appRouteBanScope($m, $ranges) : []);
}

/** 補充資料的 Meta（給其他地方讀，例如臺鐵接力站、高鐵換車站）：Logic 相符、業者包含 $sys 的第一條 */
function appSupplementMeta($logic, $sys = null)
{
    foreach (appRouteSupplements() as $x) {
        if ($x['Logic'] === $logic && ($sys === null || in_array($sys, $x['Operators'], true))) {
            return $x['Meta'] ?? [];
        }
    }
    return [];
}

/** 這條補充資料是否百分之百符合這個搭乘段 */
function appSupplementMatch(array $m, array $x, array $leg, $extra)
{
    if ($leg['Type'] !== 'ride' || !in_array($leg['System'], $x['Operators'], true)) {
        return false;
    }
    foreach ($x['Classes'] ?? [] as $c) {
        if (!appClassAllowed($extra, $leg['System'], $c)) {
            return false;
        }
    }
    if (isset($x['_From']) && !isset($x['_From'][$leg['From']])) {
        return false;
    }
    if (isset($x['_To']) && !isset($x['_To'][$leg['To']])) {
        return false;
    }
    $path = appLegPath($leg);
    if (!empty($x['ViaLines']) && !array_intersect($x['ViaLines'], array_column($path, 'LineUID'))) {
        return false;
    }
    if (isset($x['_ViaStations'])) {
        $hit = false;
        foreach (appSegmentPath($m, $path)['stations'] as $u) {
            $hit = $hit || isset($x['_ViaStations'][$u]);
        }
        if (!$hit) {
            return false;
        }
    }
    return true;
}

/** 基本路由套用補充資料：每條符合的補充資料多一條路由，回傳 [['Name' => 補充資料名稱, 'legs' => 段落], ...] */
function appApplySupplements(array $m, array $legs, $extra)
{
    $out = [];
    foreach (appRouteSupplements() as $x) {
        $fn = APP_SUPPLEMENT_LOGIC[$x['Logic']];
        $new = [];
        $changed = false;
        foreach ($legs as $leg) {
            $rep = appSupplementMatch($m, $x, $leg, $extra) ? $fn($m, $leg, $x['Meta'] ?? [], $extra) : null;
            if ($rep) {
                $changed = true;
                $new = array_merge($new, $rep);
            } else {
                $new[] = $leg;
            }
        }
        if ($changed) {
            $out[] = ['Name' => $x['Name'], 'legs' => $new];
        }
    }
    return $out;
}

/**
 * 沿途第 a 站到第 b 站的一段（$path 是 appSegmentPath 的結果）：臺鐵、高鐵在路線交會站分成幾段系統直達段（LineUID＝業者代碼，
 * Path 是該段的路線；交會站接續的標 Through），可以直通也可以在交會站換車（與原本依路線分段的串接相同）；其他系統是路線段
 */
function appSupPiece(array $path, $a, $b, $class, $sys)
{
    $pieces = [];
    $start = $a;
    for ($i = $a; $i < $b; $i++) {
        if ($i + 1 === $b || $path['lines'][$i + 1] !== $path['lines'][$i]) {
            $pieces[] = ['Type' => 'ride', 'From' => $path['stations'][$start], 'To' => $path['stations'][$i + 1],
                         'LineUID' => $path['lines'][$i], 'System' => $sys];
            $start = $i + 1;
        }
    }
    if (!in_array($sys, FINDER_TRAIN_SYSTEMS, true)) {
        return array_map(function ($p) use ($class) { return $p + ['Class' => $class, 'Through' => false]; }, $pieces);
    }
    // 鐵路：每條路線各一段系統直達段，交會站接續的段標 Through（不算轉乘；同一班車直通就續坐，也可以在交會站換車）
    $out = [];
    foreach ($pieces as $k => $p) {
        $out[] = ['Type' => 'ride', 'From' => $p['From'], 'To' => $p['To'], 'LineUID' => $sys, 'System' => $sys,
                  'Class' => $class, 'Through' => $k > 0, 'Path' => [$p]];
    }
    return $out;
}

/** 臺鐵區間車從第 a 站到第 b 站：能直達就一段，不能就在接力站（沒有時用其他同站換車站）分切；做不到回 null */
function appSupTraLocalLegs(array $m, array $path, $a, $b, array $relay)
{
    $out = [];
    $cur = $a;
    while ($cur < $b) {
        $next = null;
        for ($j = $b; $j > $cur; $j--) {
            $u = $path['stations'][$j];
            if (($j === $b || isset($relay[$u])) && appLegMinutes('TRA', $path['stations'][$cur], $u, APP_CLASS_LOCAL) !== null) {
                $next = $j;
                break;
            }
        }
        if ($next === null) {
            for ($j = $b - 1; $j > $cur; $j--) {
                $u = $path['stations'][$j];
                if (isset($m['change'][$u]) && appLegMinutes('TRA', $path['stations'][$cur], $u, APP_CLASS_LOCAL) !== null) {
                    $next = $j;
                    break;
                }
            }
        }
        if ($next === null) {
            return null;
        }
        $out = array_merge($out, appSupPiece($path, $cur, $next, APP_CLASS_LOCAL, 'TRA'));
        $cur = $next;
    }
    return $out;
}

/**
 * TRA.LocalRelay：全程區間車，在沿途的接力站（Meta.RelayStations）分切，每一小段能直達就一段、不能就在其他同站換車站再切。
 * 同一班區間車跑過接力站時，串接會直接續坐，不算轉乘。沿途沒有接力站而且區間車能直達時不產生（與基本路由相同）
 */
function appSupTraLocalRelay(array $m, array $leg, array $meta, $extra)
{
    $path = appSegmentPath($m, appLegPath($leg));
    $st = $path['stations'];
    $n = count($st) - 1;
    if ($n < 1) {
        return null;
    }
    $relay = array_flip($meta['RelayStations'] ?? []);
    $points = [0];
    for ($i = 1; $i < $n; $i++) {
        if (isset($relay[$st[$i]])) {
            $points[] = $i;
        }
    }
    $points[] = $n;
    if (count($points) <= 2 && appLegMinutes('TRA', $st[0], $st[$n], APP_CLASS_LOCAL) !== null) {
        return null;
    }
    $out = [];
    for ($k = 0; $k + 1 < count($points); $k++) {
        $piece = appSupTraLocalLegs($m, $path, $points[$k], $points[$k + 1], $relay);
        if ($piece === null) {
            return null;
        }
        $out = array_merge($out, $piece);
    }
    return $out;
}

/**
 * TRA.ExpressTransfer：區間車到上快車站、對號列車到下快車站、再換區間車。
 * 大站＝有快車停靠而且有同站換車（或是起訖站）；主要站＝大站中快車停靠次數至少是沿途最多那站的 Meta.MajorRatio（預設一半）。
 * Meta.Board／Alight：big＝沿途第一個／最後一個大站，major＝第一個／最後一個主要站；起訖站本身有快車停就從起訖站上下
 */
function appSupTraExpressTransfer(array $m, array $leg, array $meta, $extra)
{
    $path = appSegmentPath($m, appLegPath($leg));
    $st = $path['stations'];
    $n = count($st) - 1;
    if ($n < 2) {
        return null;
    }
    $exp = array_flip(appExpressStations('TRA'));
    $cnt = appTraExpressCounts();
    $big = [];
    foreach ($st as $i => $u) {
        if (isset($exp[$u]) && (isset($m['change'][$u]) || $i === 0 || $i === $n)) {
            $big[] = $i;
        }
    }
    if (count($big) < 2) {
        return null;
    }
    $maxCnt = max(array_map(function ($i) use ($cnt, $st) { return $cnt[$st[$i]] ?? 0; }, $big));
    $ratio = $meta['MajorRatio'] ?? 0.5;
    $major = array_values(array_filter($big, function ($i) use ($cnt, $st, $maxCnt, $ratio) { return ($cnt[$st[$i]] ?? 0) >= $maxCnt * $ratio; }));
    $b1 = isset($exp[$st[0]]) ? 0 : (($meta['Board'] ?? 'big') === 'major' ? ($major[0] ?? null) : $big[0]);
    $b2 = isset($exp[$st[$n]]) ? $n : (($meta['Alight'] ?? 'big') === 'major' ? ($major ? end($major) : null) : end($big));
    if ($b1 === null || $b2 === null || $b2 - $b1 < 2 || ($b1 === 0 && $b2 === $n)) {
        return null;
    }
    if (appLegMinutes('TRA', $st[$b1], $st[$b2], APP_CLASS_EXPRESS) === null) {
        return null;
    }
    $relay = array_flip(appSupplementMeta('TRA.LocalRelay', 'TRA')['RelayStations'] ?? []);
    $out = [];
    if ($b1 > 0) {
        $pre = appSupTraLocalLegs($m, $path, 0, $b1, $relay);
        if ($pre === null) {
            return null;
        }
        $out = $pre;
    }
    $out = array_merge($out, appSupPiece($path, $b1, $b2, APP_CLASS_EXPRESS, 'TRA'));
    if ($b2 < $n) {
        $post = appSupTraLocalLegs($m, $path, $b2, $n, $relay);
        if ($post === null) {
            return null;
        }
        $out = array_merge($out, $post);
    }
    return $out;
}

/** TRA.ChangeAt：在沿途的指定車站（Meta.Stations；Meta.OnlyLineChange＝只在路線串換線的站）分切，各段不限車種；沿途沒有就不產生 */
function appSupTraChangeAt(array $m, array $leg, array $meta, $extra)
{
    $path = appSegmentPath($m, appLegPath($leg));
    $st = $path['stations'];
    $n = count($st) - 1;
    $at = array_flip($meta['Stations'] ?? []);
    $onlyChange = !empty($meta['OnlyLineChange']);   // 只在路線串換線的地方（例如西部幹線接宜蘭線的八堵）
    $points = [0];
    for ($i = 1; $i < $n; $i++) {
        if (isset($at[$st[$i]]) && (!$onlyChange || $path['lines'][$i - 1] !== $path['lines'][$i])) {
            $points[] = $i;
        }
    }
    if (count($points) < 2) {
        return null;
    }
    $points[] = $n;
    $out = [];
    for ($k = 0; $k + 1 < count($points); $k++) {
        $out = array_merge($out, appSupPiece($path, $points[$k], $points[$k + 1], null, 'TRA'));
    }
    // 交會站換車與直通是同一條路線串的兩種搭法：路由層不算轉乘（Through），實際有沒有換車由串接的班次決定
    foreach ($out as $k => &$l) {
        $l['Through'] = $k > 0;
    }
    unset($l);
    return $out;
}

/** ClassChange：起訖一端是快車不停的車站時，在換車站（Meta.Stations）換快車或慢車（高鐵台中、機捷長庚醫院） */
function appSupClassChange(array $m, array $leg, array $meta, $extra)
{
    $sys = $leg['System'];
    $path = appSegmentPath($m, appLegPath($leg));
    $st = $path['stations'];
    $n = count($st) - 1;
    $exp = array_flip(appExpressStations($sys));
    foreach ($meta['Stations'] ?? [] as $c) {
        $ic = array_search($c, $st, true);
        if ($ic === false || $ic === 0 || $ic === $n) {
            continue;
        }
        $sExp = isset($exp[$st[0]]);
        $eExp = isset($exp[$st[$n]]);
        if ($sExp === $eExp) {
            continue;
        }
        return array_merge(appSupPiece($path, 0, $ic, $sExp ? APP_CLASS_EXPRESS : APP_CLASS_LOCAL, $sys),
                           appSupPiece($path, $ic, $n, $eExp ? APP_CLASS_EXPRESS : APP_CLASS_LOCAL, $sys));
    }
    return null;
}
