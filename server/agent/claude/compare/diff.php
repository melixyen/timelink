<?php
/**
 * 比較兩份 best.php 結果：php diff.php <基準.json> <這次.json> [--all]
 * 退步（worse）：最早到達變晚、最少轉乘的轉乘數變多（同轉乘數時到達變晚）、最短行車時間變長、原本有方案現在沒有；改善（better）反之。
 * 有退步時結束代碼 1。--all 連改善也逐筆列出
 */
$a = json_decode((string) @file_get_contents($argv[1] ?? ''), true);
$b = json_decode((string) @file_get_contents($argv[2] ?? ''), true);
if (!$a || !$b) {
    fwrite(STDERR, "用法：php diff.php <基準.json> <這次.json> [--all]\n");
    exit(2);
}
$all = in_array('--all', $argv, true);
if (($a['DataUpdatedAt'] ?? null) !== ($b['DataUpdatedAt'] ?? null)) {
    printf("注意：離線資料版本不同（%s → %s），差異可能來自時刻表\n", $a['DataUpdatedAt'] ?? '-', $b['DataUpdatedAt'] ?? '-');
}
$score = [
    'fastest'  => function ($x) { return [$x[1]]; },
    'fewest'   => function ($x) { return [$x[2], $x[1]]; },
    'shortest' => function ($x) { return [$x[3]]; },
];
$worse = [];
$better = [];
foreach ($a['cases'] as $k => $ra) {
    $rb = $b['cases'][$k] ?? null;
    if ($rb === null) {
        continue;
    }
    foreach ($score as $tag => $f) {
        $x = $ra[$tag] ?? null;
        $y = $rb[$tag] ?? null;
        if ($x === null && $y === null) {
            continue;
        }
        $c = $x === null ? -1 : ($y === null ? 1 : $f($y) <=> $f($x));
        $line = sprintf('%s %s：%s → %s', $k, $tag, $x ? json_encode(array_slice($x, 0, 4)) . ' ' . $x[4] : '無', $y ? json_encode(array_slice($y, 0, 4)) . ' ' . $y[4] : '無');
        if ($c > 0) {
            $worse[] = $line;
        } elseif ($c < 0) {
            $better[] = $line;
        }
    }
}
printf("%d 組：退步 %d、改善 %d；計算秒數 %s → %s\n", count($a['cases']), count($worse), count($better), $a['Seconds'] ?? '-', $b['Seconds'] ?? '-');
foreach ($worse as $x) {
    echo "  ✗ $x\n";
}
if ($all) {
    foreach ($better as $x) {
        echo "  ✓ $x\n";
    }
}
exit($worse ? 1 : 0);
