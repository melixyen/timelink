<?php
/**
 * railtime 路由與本站路由的轉乘站比對（北北基桃 pairs_nnkt.json）：
 *   node railtime_dump.js <railtime> routes <(php -r '...')   見 ../README.md 的完整指令
 *   php railtime_routes.php <railtime_routes.json> [--list]
 * 每條路由化成「轉乘站串」（同一轉乘群組視為同一站），列出 railtime 有、本站沒有的走法（本站取 appCoreRoutes 前 50 條、deeper 2），
 * 並分類原因：重複經過車站（本站通則排除，railtime 沒有這條通則）、轉乘站不相通（本站的轉乘群組沒有這組步行）、其他（排序在前 50 條外或被找路規則排除）
 * 需要 ../data/railtime_lines.json（node railtime_dump.js <railtime> lines）
 */
require __DIR__ . '/boot.php';
$rt = cmpJson($argv[1] ?? '');
$list = in_array('--list', $argv, true);
$m = appModel();
$map = cmpJson(CMP_DATA . '/railtime_map.json')['map'];
$key = function ($u) use ($m) { return appStationKey($m, $u); };
$rtLines = cmpJson(CMP_DATA . '/railtime_lines.json');
$why = ['重複經過車站' => 0, '轉乘站不相通' => 0, '其他' => 0];
$missing = 0;
$total = 0;
$lines = [];
foreach (cmpJson(CMP_DATA . '/pairs_nnkt.json')['pairs'] as $p) {
    $k = $p['rt'][0] . '>' . $p['rt'][1];
    // 本站每條路由的轉乘：[下車站群組, 上車站群組]（機捷台北車站在北門群組、railtime 算台北，兩邊都比）
    $ours = [];
    foreach (appCoreRoutes($m, $p['from'], $p['to'], ['extra' => 0, 'limit' => 50, 'deeper' => 2]) as $r) {
        $sig = [];
        $rides = array_values(array_filter($r['legs'], function ($l) { return $l['Type'] === 'ride'; }));
        foreach ($rides as $i => $l) {
            if ($i + 1 < count($rides) && empty($rides[$i + 1]['Through'])) {
                $sig[] = [$key($l['To']), $key($rides[$i + 1]['From'])];
            }
        }
        $ours[] = $sig;
    }
    $same = function (array $a, array $b) {
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $i => $x) {
            if (!array_intersect($x, $b[$i])) {
                return false;
            }
        }
        return true;
    };
    foreach ($rt[$k] ?? [] as $r) {
        $total++;
        $sig = [];
        $stations = [];
        foreach ($r['transStation'] as $ts) {
            // 轉乘站是起站或迄站本身（例如臺北站出發換捷運）：只是步行，不算轉乘（railtime 反向時 changeStation 不跟著反轉，兩邊都要看）
            if (is_array($ts) && isset($map[$ts[0]], $map[$ts[count($ts) - 1]]) && !array_intersect($ts, $p['rt'])) {
                $s = array_values(array_unique([$key($map[$ts[0]][0]), $key($map[$ts[count($ts) - 1]][0])]));
                if (array_intersect($s, [$key($p['from']), $key($p['to'])])) {
                    continue;
                }
                $sig[] = $s;
            }
        }
        // railtime 的「轉乘」若是起站本身（例如臺北站換捷運）就沒有轉乘站
        $hit = false;
        foreach ($ours as $o) {
            $hit = $hit || $same($sig, $o);
        }
        if ($hit) {
            continue;
        }
        $missing++;
        // 沿途車站（railtime 路線站序上，每段上下車站之間）
        $path = [];
        foreach ($r['takeRange'] ?? [] as $i => $tr) {
            $seq = $rtLines[$r['line'][$i] ?? ''] ?? [];
            $ia = array_search($tr[0], $seq, true);
            $ib = array_search($tr[1], $seq, true);
            $seg = $ia === false || $ib === false ? $tr : ($ia <= $ib ? array_slice($seq, $ia, $ib - $ia + 1) : array_reverse(array_slice($seq, $ib, $ia - $ib + 1)));
            foreach ($seg as $j => $id) {
                if (isset($map[$id]) && !($j === 0 && $i > 0 && end($path) === $key($map[$id][0]))) {
                    $path[] = $key($map[$id][0]);
                }
            }
        }
        $reason = '其他';
        $dedup = [];
        foreach ($path as $x) {
            if (end($dedup) !== $x) {
                $dedup[] = $x;
            }
        }
        if (count($dedup) !== count(array_unique($dedup))) {
            $reason = '重複經過車站';
        } else {
            foreach ($r['transStation'] as $ts) {
                if (is_array($ts) && isset($map[$ts[0]], $map[$ts[1]]) && $key($map[$ts[0]][0]) !== $key($map[$ts[1]][0])) {
                    $ok = false;
                    foreach ($map[$ts[0]] as $x) {
                        foreach ($map[$ts[1]] as $y) {
                            $ok = $ok || transferWalk($m, $x, $y) !== null || transferWalk($m, $y, $x) !== null;
                        }
                    }
                    $reason = $ok ? $reason : '轉乘站不相通';
                }
            }
        }
        $why[$reason]++;
        $wtxt = $reason;
        $lines[] = sprintf('[%s] %s（%s→%s）railtime：%s 經 %s', $wtxt, $k, $p['from'], $p['to'], implode('→', $r['line']), implode('→', array_map(function ($x) { return implode('/', $x); }, $sig)) ?: '（不轉乘）');
    }
}
printf("railtime 路由 %d 條，本站沒有的 %d 條：%s\n", $total, $missing, json_encode($why, JSON_UNESCAPED_UNICODE));
if ($list) {
    echo implode("\n", $lines), "\n";
}
