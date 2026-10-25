<?php
/**
 * 比對加速清單開（accel=1）與關（accel=0）的轉乘結果。
 *
 * 用法：php verify_accel.php [--base=https://<本站>（預設 TDX_BASE）] [--pairs=60] [--week=1] [--start=08:00] [--end=12:00]
 * 隨機抽起訖站（固定亂數種子，每次抽到同一批），比對方案集合（出發、到達、轉乘次數）與伺服器時間。
 * 結果不同時結束碼為 1。
 */

require_once __DIR__ . '/../../data-pipeline/scripts/client.php';

/** 回傳 ['pairs' => 有方案的組數, 'seconds' => ['0' => …, '1' => …], 'diff' => [[from, to, 只在不加速, 只在加速], …]] */
function tdxVerifyAccel(TdxSite $site, $n = 60, $week = 1, $start = '08:00', $end = '12:00')
{
    $g = $site->get(['action' => 'transfer', 'func' => 'groups', 'raw' => 1], false, 120);
    $set = [];
    foreach ($g['TransferGroups'] as $grp) {
        foreach ($grp['Stations'] as $s) {
            $set[$s['StationUID']] = true;
        }
    }
    foreach ($g['Lines'] as $l) {
        foreach ($l['Stations'] as $u) {
            $set[is_array($u) ? $u['StationUID'] : $u] = true;
        }
    }
    $uids = array_keys($set);
    sort($uids, SORT_STRING);
    mt_srand(20261015);
    $out = ['pairs' => 0, 'seconds' => ['0' => 0.0, '1' => 0.0], 'diff' => []];
    for ($i = 0; $i < $n; $i++) {
        $a = $uids[mt_rand(0, count($uids) - 1)];
        do {
            $b = $uids[mt_rand(0, count($uids) - 1)];
        } while ($b === $a);
        $res = [];
        foreach (['0', '1'] as $acc) {
            $res[$acc] = $site->get(['action' => 'transfer', 'func' => 'route', 'from' => $a, 'to' => $b, 'week' => $week,
                                     'start' => $start, 'end' => $end, 'accel' => $acc], true, 120);   // 帶管理密碼：不受查詢次數與 3 小時範圍限制
        }
        if (empty($res['0']['count'])) {
            continue;
        }
        $out['pairs']++;
        $sets = [];
        foreach (['0', '1'] as $acc) {
            $out['seconds'][$acc] += $res[$acc]['seconds'];
            $sets[$acc] = array_map(function ($o) { return $o['DepartureTime'] . '→' . $o['ArrivalTime'] . ' 轉' . $o['TransferCount']; }, $res[$acc]['options']);
        }
        $only0 = array_values(array_diff($sets['0'], $sets['1']));
        $only1 = array_values(array_diff($sets['1'], $sets['0']));
        if ($only0 || $only1) {
            $out['diff'][] = [$a, $b, array_slice($only0, 0, 3), array_slice($only1, 0, 3)];
        }
    }
    return $out;
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === __FILE__) {
    list(, $opt) = tdxArgs($argv);
    // 需要後臺 PAT（TDX_PAT 或 --key=）：比對會大量查詢，帶密碼才不受同一 IP 查詢次數與 3 小時範圍限制
    $site = new TdxSite(tdxBase($opt), tdxCredential($opt));
    $r = tdxVerifyAccel($site, (int) ($opt['pairs'] ?? 60), (int) ($opt['week'] ?? 1), $opt['start'] ?? '08:00', $opt['end'] ?? '12:00');
    printf("%d 組有方案；伺服器時間 不加速 %.2fs、加速 %.2fs；結果不同 %d 組\n", $r['pairs'], $r['seconds']['0'], $r['seconds']['1'], count($r['diff']));
    foreach (array_slice($r['diff'], 0, 20) as $x) {
        echo '  ', json_encode($x, JSON_UNESCAPED_UNICODE), "\n";
    }
    exit($r['diff'] ? 1 : 0);
}
