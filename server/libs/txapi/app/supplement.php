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
 * Logic 也可以回 ['Alternatives' => [[段落, 名稱後綴], ...]]：同一段有好幾種改法（生活圈大站轉乘），每一種各多一條路由
 * （一條路由裡有好幾段時取組合，最多 APP_SUPPLEMENT_MAX_COMBOS 條）。
 * 整條路由層級的 Logic（APP_SUPPLEMENT_ROUTE_LOGIC，例如 ViaTra）：要換掉好幾段、換掉沿途車站的，拿整條路由改寫，自己重新檢查排除規則。
 *   MaxRoutes   同一個名稱（含後綴）在一次查詢最多幾條（appCoreRoutes 依估計由好到壞先到先留；選填）
 */

const APP_SUPPLEMENT_MAX_COMBOS = 25;

const APP_SUPPLEMENT_LOGIC = [
    'TRA.LocalRelay'      => 'appSupTraLocalRelay',       // 臺鐵全程區間車：在接力站換車
    'TRA.ExpressTransfer' => 'appSupTraExpressTransfer',  // 臺鐵區間車轉對號列車再轉區間車
    'TRA.HubTransfer'     => 'appSupTraHubTransfer',      // 臺鐵中長途：經由生活圈大站轉對號列車（一個搭乘段可以有好幾種）
    'TRA.ChangeAt'        => 'appSupTraChangeAt',         // 臺鐵在指定車站換車（不限車種）
    'TRA.Via'             => 'appSupTraVia',              // 臺鐵改經指定車站換車（例如山線、海線之間經竹南或彰化折返；一個搭乘段可以有好幾種）
    'ClassChange'         => 'appSupClassChange',         // 高鐵、機捷在換車站換快車／慢車
];

/** 整條路由層級的 Logic（不是逐段改寫：要換掉好幾段），回傳 [[段落, 名稱後綴], ...] */
const APP_SUPPLEMENT_ROUTE_LOGIC = [
    'ViaTra'              => 'appSupViaTra',              // 捷運某段車站往返某生活圈：改搭臺鐵到轉乘站再轉回這條捷運（機捷桃園段經中壢、老街溪）
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
            if (!empty($x['Disabled']) || empty($x['Operators'])
                || (!isset(APP_SUPPLEMENT_LOGIC[$x['Logic'] ?? '']) && !isset(APP_SUPPLEMENT_ROUTE_LOGIC[$x['Logic'] ?? '']))) {
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
    if (isset($leg['_part']) && $leg['_part'] !== 'trunk') {
        return false;   // 中長途分解的生活圈端：已在它自己的短程找路套用過補充資料
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
function appApplySupplements(array $m, array $legs, $extra, $onlyLogic = null)
{
    $out = [];
    foreach (appRouteSupplements() as $x) {
        if ($onlyLogic !== null && !in_array($x['Logic'], $onlyLogic, true)) {
            continue;
        }
        if (isset(APP_SUPPLEMENT_ROUTE_LOGIC[$x['Logic']])) {
            foreach (call_user_func(APP_SUPPLEMENT_ROUTE_LOGIC[$x['Logic']], $m, $legs, $x, $extra) as list($alt, $suffix)) {
                $out[] = ['Name' => $x['Name'] . ($suffix !== null ? '（' . $suffix . '）' : ''), 'legs' => $alt, 'Max' => $x['MaxRoutes'] ?? null];
            }
            continue;
        }
        $fn = APP_SUPPLEMENT_LOGIC[$x['Logic']];
        $combos = [[[], []]];   // [段落, 名稱後綴]
        $changed = false;
        foreach ($legs as $li => $leg) {
            // 給 Logic 的前後文：前一段、後一段搭乘的系統（中間的步行略過；沒有是 null）
            $ctx = $leg + ['_PrevSystem' => appSupNeighborSystem($legs, $li, -1), '_NextSystem' => appSupNeighborSystem($legs, $li, 1)];
            $rep = appSupplementMatch($m, $x, $leg, $extra) ? $fn($m, $ctx, $x['Meta'] ?? [], $extra) : null;
            $alts = $rep ? ($rep['Alternatives'] ?? [[$rep, null]]) : [];
            if (!$alts) {
                foreach ($combos as &$c) {
                    $c[0][] = $leg;
                }
                unset($c);
                continue;
            }
            $changed = true;
            $next = [];
            foreach ($combos as $c) {
                foreach ($alts as $a) {
                    if (count($next) < APP_SUPPLEMENT_MAX_COMBOS) {
                        $next[] = [array_merge($c[0], $a[0]), $a[1] !== null ? array_merge($c[1], [$a[1]]) : $c[1]];
                    }
                }
            }
            $combos = $next;
        }
        if ($changed) {
            foreach ($combos as $c) {
                $out[] = ['Name' => $x['Name'] . ($c[1] ? '（' . implode('；', $c[1]) . '）' : ''), 'legs' => $c[0]];
            }
        }
    }
    return $out;
}

/** 第 $i 段往前（$dir＝-1）或往後（1）最近一段搭乘的系統，中間的步行略過；沒有回 null */
function appSupNeighborSystem(array $legs, $i, $dir)
{
    for ($k = $i + $dir; $k >= 0 && $k < count($legs); $k += $dir) {
        if ($legs[$k]['Type'] === 'ride') {
            return $legs[$k]['System'];
        }
    }
    return null;
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
    // 中長途分解的幹線段：全程區間車從起站直接接力到終點端，前面接臺鐵（先搭到大站）或後面接臺鐵（大站下車再搭區間車）的不產生
    if (($leg['_part'] ?? null) === 'trunk' && (($leg['_PrevSystem'] ?? null) === 'TRA' || ($leg['_NextSystem'] ?? null) === 'TRA')) {
        return null;
    }
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
    // 區間車能直達時：沿途沒有接力站，或不是中長途（起訖生活圈相同或相鄰，例如汐止→板橋不在臺北切開）就不產生
    if ((count($points) <= 2 || appRegionRule($st[0], $st[$n]) === null) && appLegMinutes('TRA', $st[0], $st[$n], APP_CLASS_LOCAL) !== null) {
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
    if (appTraHubLeg($leg)) {
        return null;   // 中長途改用生活圈大站轉乘（TRA.HubTransfer），轉乘站的選法一致
    }
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

/**
 * TRA.HubTransfer：中長途（搭乘段起訖的生活圈不相鄰）的臺鐵段，除了起訖站自己直達，也經由生活圈大站（transfer_regions.json 的 TraHubs）
 * 轉對號列車：區間車到上車大站、對號列車到下車大站、再換區間車。
 *   上車端：起站本身是這條幹線的大站就只從起站上。不是時取起點生活圈內離起站最近的大站，往行進方向、往反方向各最多
 *          APP_HUB_PER_SIDE 個（五堵往南：松山、臺北，反方向七堵；汐科往花蓮：七堵、八堵，反方向南港、松山）；往反方向的先搭
 *          區間車回大站，再搭對號經過起站。生活圈內往行進方向沒有大站時（鶯歌、鳳鳴往南），沿車站順序往下找第一個大站（桃園）。
 *          起站有對號停靠時也保留從起站上車。
 *   下車端：對稱（到達前的大站、越過終點的大站，各最多 APP_HUB_PER_SIDE 個；到達前沒有時往回找最後一個大站）。
 *   幹線：上車端看起站往前第一個只屬於一條幹線的車站（五堵往南是 w，往宜蘭是 e），下車端從終點往回看；大站要是這條幹線的大站。
 * 起訖站是捷運、輕軌的路由，臺鐵段（經轉乘群組接上）也用同一個規則：臺鐵段前面（後面）接的是捷運、輕軌等其他系統時，
 * 那一端的轉乘要在大站，只保留對號直接在這一站上（下）車（這一站有對號停靠時），不產生「大站下車、區間車到小站再轉捷運」
 * 的組合（高雄捷運、輕軌的轉乘由其他基本路由在大站直接轉）。回傳 ['Alternatives' => [[段落, 名稱後綴], ...]]
 */
const APP_HUB_PER_SIDE = 2;

function appSupTraHubTransfer(array $m, array $leg, array $meta, $extra)
{
    if (isset($leg['_part']) || !appTraHubLeg($leg)) {   // 中長途分解（app/trunk.php）已經在大站切開
        return null;
    }
    $pieces = appLegPath($leg);
    $path = appSegmentPath($m, $pieces);
    $st = $path['stations'];
    $n = count($st) - 1;
    if ($n < 2) {
        return null;
    }
    $hubs = appTraHubs();
    $trunkOf = appTraTrunkOf($m);
    $exp = array_flip(appExpressStations('TRA'));
    // 這一端的幹線：從端點往內第一個只屬於一條幹線的車站；都是共用區段時用端點所屬的全部幹線
    $side = function ($order) use ($st, $trunkOf) {
        foreach ($order as $i) {
            if (count($trunkOf[$st[$i]] ?? []) === 1) {
                return $trunkOf[$st[$i]];
            }
        }
        return $trunkOf[$st[$order[0]]] ?? [];
    };
    $isHub = function ($u, $tr) use ($hubs) {
        return isset($hubs[$u]) && array_intersect_key($hubs[$u], $tr);
    };
    // 端點所在路線上、往路由外側（上車端往反方向、下車端越過終點）同一生活圈內最近的大站
    $outward = function ($piece, $end, $other, $tr) use ($m, $isHub) {
        $seq = $m['lineStations'][$piece['LineUID']] ?? [];
        $i0 = array_search($end, $seq, true);
        $i1 = array_search($other, $seq, true);
        if ($i0 === false || $i1 === false || $i0 === $i1) {
            return [];
        }
        $step = $i1 > $i0 ? -1 : 1;
        $rg = appRegionOf($end);
        $out = [];
        for ($j = $i0 + $step; $j >= 0 && $j < count($seq) && count($out) < APP_HUB_PER_SIDE; $j += $step) {
            if ($rg === null || appRegionOf($seq[$j]) !== $rg) {
                break;
            }
            if ($isHub($seq[$j], $tr)) {
                $out[] = $seq[$j];
            }
        }
        return $out;
    };
    // 一端的選項：['in', 路由上的索引] 或 ['out', 外側的大站]；$fixed＝這一端接其他系統，只能在端點本身上下對號
    $pick = function ($order, $end, $piece, $other, $fixed) use ($st, $isHub, $exp, $side, $outward) {
        $tr = $side($order);
        if ($isHub($st[$end], $tr) || $fixed) {
            return $isHub($st[$end], $tr) || isset($exp[$st[$end]]) ? [['in', $end]] : [];
        }
        $rg = appRegionOf($st[$end]);
        $in = [];
        $first = null;
        foreach ($order as $i) {
            if ($i === $end || !$isHub($st[$i], $tr)) {
                continue;
            }
            if ($rg !== null && appRegionOf($st[$i]) === $rg) {
                if (count($in) < APP_HUB_PER_SIDE) {
                    $in[] = ['in', $i];
                }
            } elseif ($first === null) {
                $first = ['in', $i];
            }
        }
        if (!$in && $first !== null) {
            $in = [$first];
        }
        $out = array_map(function ($u) { return ['out', $u]; }, $outward($piece, $st[$end], $other, $tr));
        return array_merge(isset($exp[$st[$end]]) ? [['in', $end]] : [], $in, $out);
    };
    $fwd = range(0, $n);
    $back = array_reverse($fwd);
    $firstP = $pieces[0];
    $lastP = $pieces[count($pieces) - 1];
    $otherSys = function ($s) { return $s !== null && $s !== 'TRA'; };
    $boards = $pick($fwd, 0, $firstP, $firstP['To'], $otherSys($leg['_PrevSystem'] ?? null));
    $alights = $pick($back, $n, $lastP, $lastP['From'], $otherSys($leg['_NextSystem'] ?? null));
    $relay = array_flip(appSupplementMeta('TRA.LocalRelay', 'TRA')['RelayStations'] ?? []);
    $name = function ($u) use ($m) { $x = $m['names'][$u] ?? null; return is_array($x) ? ($x['Zh_tw'] ?? $u) : ($x ?? $u); };
    $localTo = function ($a, $b, $line) use ($m, $relay) {
        $p = appSegmentPath($m, [['LineUID' => $line, 'From' => $a, 'To' => $b]]);
        return count($p['stations']) < 2 ? null : appSupTraLocalLegs($m, $p, 0, count($p['stations']) - 1, $relay);
    };
    $alts = [];
    foreach ($boards as list($bk, $bv)) {
        foreach ($alights as list($ak, $av)) {
            $bIn = $bk === 'in';
            $aIn = $ak === 'in';
            if ($bIn && $aIn && ($av <= $bv || ($bv === 0 && $av === $n))) {
                continue;
            }
            // 對號段的路線串：往外側的大站時把第一段的起站／最後一段的迄站延伸到大站
            $ext = $pieces;
            if (!$bIn) {
                $ext[0]['From'] = $bv;
            }
            if (!$aIn) {
                $ext[count($ext) - 1]['To'] = $av;
            }
            $ep = $bIn && $aIn ? $path : appSegmentPath($m, $ext);
            $off = $bIn ? 0 : array_search($st[0], $ep['stations'], true);   // 起站在延伸後路線串的位置
            if ($off === false) {
                continue;
            }
            $e1 = $bIn ? $bv : 0;
            $e2 = $aIn ? $av + $off : count($ep['stations']) - 1;
            $u1 = $ep['stations'][$e1];
            $u2 = $ep['stations'][$e2];
            if ($e2 <= $e1 || appLegMinutes('TRA', $u1, $u2, APP_CLASS_EXPRESS) === null) {
                continue;
            }
            $pre = $bIn ? ($bv > 0 ? appSupTraLocalLegs($m, $path, 0, $bv, $relay) : []) : $localTo($st[0], $bv, $firstP['LineUID']);
            $post = $aIn ? ($av < $n ? appSupTraLocalLegs($m, $path, $av, $n, $relay) : []) : $localTo($av, $st[$n], $lastP['LineUID']);
            if ($pre === null || $post === null) {
                continue;
            }
            $tag = array_filter([$u1 !== $st[0] ? $name($u1) . '上' : null, $u2 !== $st[$n] ? $name($u2) . '下' : null]);
            $alts[] = [array_merge($pre, appSupPiece($ep, $e1, $e2, APP_CLASS_EXPRESS, 'TRA'), $post), implode('、', $tag)];
        }
    }
    return $alts ? ['Alternatives' => $alts] : null;
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

/**
 * TRA.Via：搭乘段改成「起站→換車站→迄站」兩段臺鐵系統直達段，換車站逐一取 Meta.Via（每站各一種改法）。
 * 用在路線串的路徑不經過、但實際上常在那裡換車折返的車站：山線、海線車站之間經竹南或彰化換車（成追線班次少，
 * 原本的路徑走成追線，交會站換車只會切在成功、追分）。換車站就是起站或迄站、或任一段沒有臺鐵直達時跳過；都不行回 null
 */
function appSupTraVia(array $m, array $leg, array $meta, $extra)
{
    $alts = [];
    foreach ($meta['Via'] ?? [] as $v) {
        if ($v === $leg['From'] || $v === $leg['To']) {
            continue;
        }
        $a = appSupTraSystemLeg($m, $leg['From'], $v, $extra);
        $b = appSupTraSystemLeg($m, $v, $leg['To'], $extra);
        if ($a === null || $b === null) {
            continue;
        }
        $keep = array_intersect_key($leg, ['_part' => true]);
        $name = $m['names'][$v] ?? $v;
        $alts[] = [[$a + $keep, $b + $keep], '經' . (is_array($name) ? $name['Zh_tw'] : $name)];
    }
    return $alts ? ['Alternatives' => $alts] : null;
}

/** 臺鐵兩站之間的系統直達段（不轉乘的臺鐵路由，有好幾種路線串時取里程最短的；有快取）；沒有回 null */
function appSupTraSystemLeg(array $m, $a, $b, $extra)
{
    static $cache = [];
    $k = $a . '|' . $b . '|' . $extra;
    if (!array_key_exists($k, $cache)) {
        $best = null;
        foreach (appNetworkRoutes($m, $a, $b, $extra, 0, ['TRA']) as $legs) {
            if (count($legs) === 1 && $legs[0]['Type'] === 'ride' && $legs[0]['System'] === 'TRA'
                && ($best === null || appPathKm($m, $legs[0]['Path']) < appPathKm($m, $best['Path']))) {
                $best = $legs[0];
            }
        }
        $cache[$k] = $best;
    }
    return $cache[$k];
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

/**
 * ViaTra（整條路由層級，不限業者）：捷運路線 Meta.Line（車種 Meta.Class）上某段車站（Meta.Stations）往返某些生活圈（Meta.Regions）時，
 * 除了原本的走法，也改搭臺鐵到轉乘站、再轉回這條捷運（Meta.Transfer＝[臺鐵站, 捷運站]）。
 * 路由裡經過臺鐵樞紐站（Meta.Hubs）同轉乘群組的車站之後、到這條捷運下車為止的這一段，換成
 * 「步行到樞紐站 → 臺鐵 樞紐站→轉乘站 → 步行到捷運站 → 捷運 → 目的站」（回程對稱）；每個樞紐站各一條。
 * 現有設定：機捷第一航廈～老街溪往返臺北生活圈，臺北、板橋搭臺鐵到中壢、老街溪轉機捷。
 * 例：台電大樓→興南的基本路由全是「…台北車站→高鐵台北→桃園→機捷」或「機捷台北車站直達」，指數比較高的臺鐵＋機捷排不進來
 */
function appSupViaTra(array $m, array $legs, array $x, $extra)
{
    static $sets = [];
    $meta = $x['Meta'] ?? [];
    $key = $x['Name'];
    if (!isset($sets[$key])) {
        $sets[$key] = appSupStationSet($m, $meta['Stations'] ?? []);
    }
    $range = $sets[$key];
    list($traX, $metroX) = $meta['Transfer'] + [null, null];
    $line = $meta['Line'] ?? null;
    if (!$range || $traX === null || $metroX === null || $line === null || !$legs) {
        return [];
    }
    $sys = substr($line, 0, strpos($line, '-'));
    $cls = $meta['Class'] ?? null;
    foreach ($legs as $l) {
        if (isset($l['_part'])) {
            return [];   // 中長途分解的組合：生活圈端已在自己的短程找路套用過
        }
    }
    $regions = array_flip($meta['Regions'] ?? []);
    $inGroup = function ($s, $h) use ($m) {
        if ($s === $h) {
            return true;
        }
        foreach ($m['groupOf'][$s] ?? [] as $gid) {
            foreach ($m['groups'][$gid]['Stations'] as $y) {
                if ($y['StationUID'] === $h) {
                    return true;
                }
            }
        }
        return false;
    };
    $walk = function ($a, $b) { return ['Type' => 'walk', 'From' => $a, 'To' => $b, 'Minutes' => null, 'MinutesGiven' => false]; };
    $metro = function ($a, $b) use ($line, $sys, $cls) {
        return ['Type' => 'ride', 'From' => $a, 'To' => $b, 'LineUID' => $line, 'Class' => $cls, 'System' => $sys, 'Through' => false];
    };
    $name = function ($u) use ($m) { $n = $m['names'][$u] ?? $u; return is_array($n) ? $n['Zh_tw'] : $n; };
    $n = count($legs);
    $out = [];
    foreach ($meta['Hubs'] ?? [] as $h) {
        // 去程：最後一段機捷在範圍內下車、起站在臺北生活圈；回程：第一段機捷在範圍內上車、迄站在臺北生活圈
        foreach ([true, false] as $outbound) {
            $end = $outbound ? $legs[0]['From'] : $legs[$n - 1]['To'];
            if ($regions && !isset($regions[appRegionOf($end)])) {
                continue;
            }
            $k = null;
            for ($i = 0; $i < $n; $i++) {
                $l = $legs[$i];
                if ($l['Type'] === 'ride' && ($l['LineUID'] === $line || ($l['Path'][0]['LineUID'] ?? null) === $line) && isset($range[$outbound ? $l['To'] : $l['From']])) {
                    $k = $i;
                    if (!$outbound) {
                        break;
                    }
                }
            }
            if ($k === null) {
                continue;
            }
            $y = $outbound ? $legs[$k]['To'] : $legs[$k]['From'];
            // 樞紐站的轉乘點：去程取機捷段之前最後一個搭乘段的上車站在樞紐群組的；回程取機捷段之後第一個搭乘段的下車站在樞紐群組的
            $j = null;
            if ($outbound) {
                for ($i = $k; $i >= 0; $i--) {
                    if ($legs[$i]['Type'] === 'ride' && $inGroup($legs[$i]['From'], $h)) {
                        $j = $i;
                        break;
                    }
                }
            } else {
                for ($i = $k; $i < $n; $i++) {
                    if ($legs[$i]['Type'] === 'ride' && $inGroup($legs[$i]['To'], $h)) {
                        $j = $i;
                        break;
                    }
                }
            }
            if ($j === null) {
                continue;
            }
            $tra = $outbound ? appSupTraSystemLeg($m, $h, $traX, $extra) : appSupTraSystemLeg($m, $traX, $h, $extra);
            if ($tra === null) {
                continue;
            }
            // 樞紐站之前（回程：之後）已經是臺鐵（臺鐵臺北→板橋再上高鐵）：接上就是在樞紐站原地換臺鐵，和從另一個樞紐站上臺鐵那條重複
            $nb = $outbound ? appSupNeighborSystem($legs, $j, -1) : appSupNeighborSystem($legs, $j, 1);
            if ($nb === 'TRA') {
                continue;
            }
            if ($outbound) {
                $pre = array_slice($legs, 0, $j);
                $s = $legs[$j]['From'];
                $mid = [];
                if ($s !== $h) {
                    if ($pre && end($pre)['Type'] === 'walk' && $inGroup(end($pre)['From'], $h)) {
                        $last = array_pop($pre);
                        $s = $last['From'];
                    }
                    $mid[] = $walk($s, $h);
                }
                $mid[] = $tra;
                $mid[] = $walk($traX, $metroX);
                if ($y !== $metroX) {
                    $mid[] = $metro($metroX, $y);
                }
                $alt = array_merge($pre, $mid, array_slice($legs, $k + 1));
            } else {
                $post = array_slice($legs, $j + 1);
                $t = $legs[$j]['To'];
                $mid = [];
                if ($y !== $metroX) {
                    $mid[] = $metro($y, $metroX);
                }
                $mid[] = $walk($metroX, $traX);
                $mid[] = $tra;
                if ($t !== $h) {
                    if ($post && $post[0]['Type'] === 'walk' && $inGroup($post[0]['To'], $h)) {
                        $first = array_shift($post);
                        $t = $first['To'];
                    }
                    $mid[] = $walk($h, $t);
                }
                $alt = array_merge(array_slice($legs, 0, $k), $mid, $post);
            }
            if ($alt[0]['Type'] === 'walk' && $alt[0]['From'] === $alt[0]['To']) {
                array_shift($alt);
            }
            // 換掉了沿途車站（不是在同一串車站上切段），排除規則要重新檢查：重複經過車站（板橋上高鐵到台北、再搭臺鐵經過板橋）、排除名單等。
            // 系統直達段依它的路線串逐段檢查
            $st = appIndexState0($alt[0]['From'], end($alt)['To']);
            foreach ($alt as $l) {
                foreach ($l['Type'] === 'ride' && isset($l['Path']) ? $l['Path'] : [$l] as $piece) {
                    $st = appPrefixExtend($m, $st, $piece + ['Class' => null, 'Through' => false]);
                    if ($st === null) {
                        continue 3;
                    }
                }
            }
            if (appRoutePatternHit($m, $alt, appRoutePatternsFor($m, $alt[0]['From'], end($alt)['To']))) {
                continue;
            }
            $out[appRouteFormat(['Legs' => $alt])] = [$alt, '臺鐵' . $name($h) . '－' . $name($traX)];
            // 每條基本路由各改出一條：同一個查詢會有很多條（前面接的捷運轉法不同）；appCoreRoutes 依 MaxRoutes 只留估計最好的基本路由改出來的前幾條
        }
    }
    return array_values($out);
}

