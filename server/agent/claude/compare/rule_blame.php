<?php
/**
 * 找出害結果變差的轉乘走法排除規則：php rule_blame.php <規則關的結果.json> <規則開的結果.json>
 * 對每個退步的方案，列出擋掉它原本最佳路由的 railtime 規則（map#index），用來停用（Disabled＋DisabledReason）
 */
require __DIR__ . '/boot.php';
// 基準與這次比較：退步案例的基準路由被哪幾條 railtime 規則擋掉
$m = appModel(); $db = appDb();
$base = json_decode(file_get_contents($argv[1]), true)['cases'];
$run = json_decode(file_get_contents($argv[2]), true)['cases'];
$j = json_decode(file_get_contents(transferDataFile('RouteRules')), true);
$rt = array_values(array_filter($j['RouteBans'], fn($x) => ($x['Source'] ?? '') === 'railtime'));
$bad = [];
foreach ($base as $k => $b) {
  foreach (['fastest','fewest','shortest'] as $t) {
    $x = $b[$t] ?? null; $y = $run[$k][$t] ?? null; if (!$x) continue;
    $w = $y === null || ($t==='fastest' && $y[1] > $x[1]) || ($t==='fewest' && [$y[2],$y[1]] > [$x[2],$x[1]]) || ($t==='shortest' && $y[3] > $x[3]);
    if (!$w) continue;
    list(, $od) = explode('|', $k); list($f, $to) = explode('>', $od);
    $legs = appRouteValidate(appRouteParse($x[4]), $db, $m)['Legs'];
    foreach ($rt as $i => $r) {
      if (!empty($r["Disabled"])) continue; $c = appCompileRoutePatterns([$r]); if (!$c) continue;
      // 逐條套 appRoutePatternsFor 的起訖判斷
      $save = $c;
      $a = appStationKey($m, $f); $bb = appStationKey($m, $to); $e = $c[0]['Ends'] ?? [];
      $ok = !((isset($e['Any']) && !isset($e['Any'][$a]) && !isset($e['Any'][$bb])) || (isset($e['All']) && !(isset($e['All'][$a]) && isset($e['All'][$bb])))
        || (isset($e['Pair']) && !((isset($e['Pair'][0][$a]) && isset($e['Pair'][1][$bb])) || (isset($e['Pair'][0][$bb]) && isset($e['Pair'][1][$a])))));
      if ($ok && appRoutePatternHit($m, $legs, [$c[0]['Transfers']])) { $bad[$r['Railtime']['map'].'#'.$r['Railtime']['index']][] = "$k $t {$x[4]}"; }
    }
  }
}
foreach ($bad as $r => $l) { echo "$r\n"; foreach (array_unique($l) as $x) echo "   $x\n"; }
