<?php
/**
 * 路由回歸測試：用 agent/skills/rail-transfer/tests/route_cases.json 的已知最佳化結果（目前 53 條），檢查路由演算法、找路規則改動後結果是否一致。
 *
 *   php agent/skills/rail-transfer/scripts/route_regression.php            全部執行，不一致的列出差異（有失敗時結束代碼 1）
 *   php agent/skills/rail-transfer/scripts/route_regression.php --case=ID  只跑一條
 *   php agent/skills/rail-transfer/scripts/route_regression.php --update   用目前的結果重寫 expect（人工確認結果正確後才用）
 *
 * 每條案例：
 *   - journeys（與 /txapi/app_trip/journeys 同一個計算 appJourneysCompute）三個標籤的方案：
 *     Fastest 最早到達、ShortestTime 最短行車時間、FewestTransfers 最少轉乘，比對出發、抵達、轉乘次數、行車分鐘
 *     （路由代碼不同但時刻相同算通過，另外提示）
 *   - routes（appCoreRoutes，取前 50 條）：routes_include 的路由代碼都要在；paths_include 的路線串（系統直達段的 Paths，
 *     寫成「站.路線.站.路線.站」）至少一條路由要有；routes_exclude 的字串在路由代碼與路線串都不能出現
 * 期望值綁定離線資料版本（dataUpdatedAt）：版本不同時先提示，差異要人工確認後再 --update。
 */

$_SERVER['REQUEST_URI'] = '/';
$root = dirname(__DIR__, 4);
chdir($root);
foreach (['config.php', 'http.php', 'sqlite.php', 'tdx.php', 'proxy.php', 'ratelimit.php', 'railcache.php', 'txapi/txapi.php'] as $l) {
    require $root . '/libs/' . $l;
}

$opt = getopt('', ['update', 'case:']);
$update = isset($opt['update']);
$only = $opt['case'] ?? null;
$file = dirname(__DIR__) . '/tests/route_cases.json';
$doc = json_decode((string) file_get_contents($file), true);
if (!is_array($doc) || empty($doc['cases'])) {
    fwrite(STDERR, "讀不到 $file\n");
    exit(2);
}

appDb();
$m = appModel();
$ver = appDataUpdatedAt();
if (!$update && ($doc['DataUpdatedAt'] ?? null) !== $ver) {
    printf("注意：期望值的資料版本 %s，目前 %s；時刻表更新過的話差異可能來自資料，請人工確認\n\n", $doc['DataUpdatedAt'] ?? '-', $ver ?? '-');
}

$hm = function ($s) { return (int) substr($s, 0, 2) * 60 + (int) substr($s, 3, 2); };
$pick = function (array $j) {
    return $j === [] ? null : ['dep' => $j['DepartureTime'], 'arr' => $j['ArrivalTime'], 'transfers' => $j['TransferCount'],
                                'minutes' => $j['TravelMinutes'], 'route' => $j['route']];
};

$pass = 0;
$fail = 0;
$t0 = microtime(true);
foreach ($doc['cases'] as &$c) {
    if ($only !== null && $c['id'] !== $only) {
        continue;
    }
    $mode = $c['mode'] ?? 'depart';
    if ($mode === 'first' || $mode === 'last') {
        list($start, $end) = [APP_SERVICE_DAY_START, APP_SERVICE_DAY_END];
    } elseif ($mode === 'arrive') {
        $end = $hm($c['arrive']);
        $start = max(0, $end - APP_JOURNEY_DEFAULT_WINDOW);
    } else {
        list($start, $end) = [$hm($c['start']), $hm($c['end'])];
    }
    $back = $mode === 'arrive' || $mode === 'last';
    $extra = (int) ($c['extra'] ?? 0);
    $q = ['from' => $c['from'], 'to' => $c['to'], 'extra' => $extra, 'week' => (int) $c['week'], 'date' => null, 'mode' => $mode,
          'start' => $start, 'end' => $end, 'sort' => $back ? 'late' : 'arrival', 'limit' => 50,
          'opt' => ['extra' => $extra, 'max_transfers' => null, 'via' => null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true, 'deeper' => appRouteSearch()['Deeper']]];
    list($data) = appJourneysCompute($q);
    $got = ['fastest' => null, 'shortest' => null, 'fewest' => null];
    foreach ($data['journeys'] as $j) {
        foreach (['Fastest' => 'fastest', 'ShortestTime' => 'shortest', 'FewestTransfers' => 'fewest'] as $tag => $k) {
            if (in_array($tag, $j['Tags'], true)) {
                $got[$k] = $pick($j);
            }
        }
    }
    $rlist = appCoreRoutes($m, $c['from'], $c['to'], ['extra' => $extra, 'limit' => 50, 'deeper' => appRouteSearch()['Deeper']]);
    $routes = array_column($rlist, 'route');
    // 系統直達段經過的路線串（Paths）寫成「站.路線.站.路線.站」，給 paths_include、routes_exclude 比對
    $pathText = [];
    foreach ($rlist as $r) {
        $t = [$r['route']];
        foreach ($r['legs'] as $l) {
            foreach ($l['Paths'] ?? (isset($l['Path']) ? [$l['Path']] : []) as $p) {
                $x = [$p[0]['From']];
                foreach ($p as $pl) {
                    $x[] = $pl['LineUID'];
                    $x[] = $pl['To'];
                }
                $t[] = implode('.', $x);
            }
        }
        $pathText[] = implode(' | ', $t);
    }

    $errs = [];
    $notes = [];
    foreach ($c['routes_include'] ?? [] as $r) {
        if (!in_array($r, $routes, true)) {
            $errs[] = "路由少了 $r";
        }
    }
    foreach ($c['paths_include'] ?? [] as $x) {
        $hit = false;
        foreach ($pathText as $t) {
            $hit = $hit || strpos($t, $x) !== false;
        }
        if (!$hit) {
            $errs[] = "路線串少了 $x";
        }
    }
    foreach ($c['routes_exclude'] ?? [] as $x) {
        foreach ($pathText as $t) {
            if (strpos($t, $x) !== false) {
                $errs[] = "路由不該含有「{$x}」：$t";
            }
        }
    }
    if ($update) {
        $c['expect'] = $got;
    } else {
        foreach ($got as $k => $g) {
            $e = $c['expect'][$k] ?? null;
            if ($e === null && $g === null) {
                continue;
            }
            if ($e === null || $g === null) {
                $errs[] = "{$k}：期望 " . json_encode($e, JSON_UNESCAPED_UNICODE) . '，結果 ' . json_encode($g, JSON_UNESCAPED_UNICODE);
                continue;
            }
            foreach (['dep', 'arr', 'transfers', 'minutes'] as $f) {
                if ($e[$f] !== $g[$f]) {
                    $errs[] = "{$k}.{$f}：期望 {$e[$f]}，結果 {$g[$f]}（期望 {$e['route']}；結果 {$g['route']}）";
                }
            }
            if ($e['route'] !== $g['route'] && $e['arr'] === $g['arr'] && $e['dep'] === $g['dep']) {
                $notes[] = "{$k} 時刻相同、路由不同：{$e['route']} → {$g['route']}";
            }
        }
    }
    if ($errs) {
        $fail++;
        printf("✗ %-36s %s\n", $c['id'], $c['name']);
        foreach ($errs as $x) {
            echo "    - $x\n";
        }
    } else {
        $pass++;
        printf("✓ %-36s %s\n", $c['id'], $c['name']);
    }
    foreach ($notes as $x) {
        echo "    · $x\n";
    }
}
unset($c);
printf("\n%d 通過、%d 失敗（%.1f 秒）\n", $pass, $fail, microtime(true) - $t0);
if ($update) {
    $doc['DataUpdatedAt'] = $ver;
    $doc['UpdatedAt'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
    echo "已寫入期望值：$file\n";
}
exit($fail ? 1 : 0);
