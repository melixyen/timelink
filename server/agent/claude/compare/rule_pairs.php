<?php
/**
 * 轉乘走法排除（RouteBans 的 Transfers 規則）驗證用起訖：每條啟用中的 railtime 規則依 Ends 抽 12 組（固定種子 99）
 *   php rule_pairs.php <輸出.json>     再用 best.php --set=pairs --pairs=<輸出.json> 在規則開／關下各跑一次比較
 */
require __DIR__ . '/boot.php';
// 每條啟用中的 railtime 規則，從它的起訖條件抽 12 組起訖（固定種子）
$m = appModel();
$j = json_decode(file_get_contents(transferDataFile('RouteRules')), true);
mt_srand(99); $pairs = []; $seen = [];
foreach ($j['RouteBans'] as $x) {
  if (($x['Source'] ?? '') !== 'railtime' || !empty($x['Disabled'])) continue;
  $A = array_keys(appRouteBanScope($m, $x['Ends']['Pair'][0])); $B = array_keys(appRouteBanScope($m, $x['Ends']['Pair'][1]));
  $S = isset($x['Ends']['Any']) ? array_flip(array_keys(appRouteBanScope($m, $x['Ends']['Any']))) : null;
  $T = isset($x['Ends']['All']) ? array_flip(array_keys(appRouteBanScope($m, $x['Ends']['All']))) : null;
  $n = 0;
  for ($t = 0; $t < 3000 && $n < 12; $t++) {
    $a = $A[mt_rand(0, count($A) - 1)]; $b = $B[mt_rand(0, count($B) - 1)];
    if (mt_rand(0, 1)) { [$a, $b] = [$b, $a]; }
    if ($a === $b || isset($seen["$a>$b"])) continue;
    if ($S !== null && !isset($S[$a]) && !isset($S[$b])) continue;
    if ($T !== null && !(isset($T[$a]) && isset($T[$b]))) continue;
    $seen["$a>$b"] = 1; $pairs[] = ['from' => $a, 'to' => $b, 'rule' => $x['Railtime']['map'] . '#' . $x['Railtime']['index']]; $n++;
  }
}
file_put_contents($argv[1], json_encode(['pairs' => $pairs], JSON_UNESCAPED_UNICODE)); echo count($pairs), " pairs\n";
