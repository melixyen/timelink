<?php
/**
 * 算比對基準：每組起訖、時段用 appJourneysCompute（與 /txapi/app_trip/journeys 同一個計算）取三個標籤的方案
 *   php best.php --set=nnkt|kh|bench [--out=檔案]    預設寫到 ../data/run_<set>.json（基準是 baseline_<set>.json）
 *   php best.php --set=pairs --pairs=<起訖檔> --out=檔案   自訂起訖（例如某條規則影響得到的起訖）；--week=0～6、--start、--end 改時段（預設週一 08:00～10:00）
 * nnkt：北北基桃 400 組，週一 08:00～10:00 出發
 * kh：高雄 120 組 × 週一、週四 × 07:00～09:00、16:00～18:00 出發
 * bench：25 組代表起訖（含跨系統、長途、環狀線），週一 08:00～10:00，另記每組的計算秒數
 * 每筆：{fastest|shortest|fewest: [出發, 到達, 轉乘, 行車分鐘, route]}；比較用 diff.php
 */
require __DIR__ . '/boot.php';
$o = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--(\w+)=(.*)$/', $a, $mm)) {
        $o[$mm[1]] = $mm[2];
    }
}
$set = $o['set'] ?? '';
const CMP_BENCH = [['TRA-0950','TRA-1210'],['TRA-1000','TRA-3300'],['TRA-1000','TRA-6000'],['KRTC-R10','TRTC-BR22'],['TRTC-BL01','TYMC-A13'],
 ['TRTC-BL22','TRTC-BL14'],['TRTC-BR24','TRTC-R05'],['TRTC-R28','TRTC-G01'],['NTMC-Y07','TRTC-BL23'],['TRTC-O54','TRTC-BR01'],
 ['NTDLRT-V28','TRTC-R10'],['TYMC-A22','TRTC-BL12'],['TMRT-G10a','TRA-3300'],['KRTC-O1','KRTC-R24'],['KLRT-C1','KRTC-R11'],
 ['THSR-1000','THSR-1043'],['TRA-0900','TRTC-BR22'],['TRA-5120','TRA-0900'],['NTALRT-K09','TRTC-R28'],['TRTC-G19','NTMC-LB12'],
 ['TRA-1020','TRA-0980'],['TRTC-BL07','TRTC-O01'],['TRTC-BR13','TRTC-BL05'],['TRA-7000','THSR-1070'],['TRTC-R13','TRTC-G08']];
$cases = [];
if ($set === 'nnkt') {
    foreach (cmpJson(CMP_DATA . '/pairs_nnkt.json')['pairs'] as $p) {
        $cases[] = [$p['from'], $p['to'], 1, 480, 600];
    }
} elseif ($set === 'kh') {
    foreach ([1, 4] as $w) {
        foreach (cmpJson(CMP_DATA . '/pairs_kh.json')['pairs'] as $p) {
            foreach ([[420, 540], [960, 1080]] as list($a, $b)) {
                $cases[] = [$p['from'], $p['to'], $w, $a, $b];
            }
        }
    }
} elseif ($set === 'pairs' && !empty($o['pairs'])) {
    // 自訂起訖檔（{"pairs": [{"from","to"}...]}）
    $hm = function ($t) { return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2); };
    foreach (cmpJson($o['pairs'])['pairs'] as $p) {
        $cases[] = [$p['from'], $p['to'], (int) ($o['week'] ?? 1), $hm($o['start'] ?? '08:00'), $hm($o['end'] ?? '10:00')];
    }
} elseif ($set === 'bench') {
    foreach (CMP_BENCH as $p) {
        $cases[] = [$p[0], $p[1], 1, 480, 600];
    }
} else {
    fwrite(STDERR, "用法：php best.php --set=nnkt|kh|bench [--out=檔案]\n");
    exit(2);
}
appDb();
appModel();
$out = [];
$t0 = microtime(true);
$secs = 0.0;
foreach ($cases as list($from, $to, $week, $start, $end)) {
    $q = ['from' => $from, 'to' => $to, 'extra' => 0, 'week' => $week, 'date' => null, 'mode' => 'depart', 'start' => $start, 'end' => $end,
          'sort' => 'arrival', 'limit' => 50,
          'opt' => ['extra' => 0, 'max_transfers' => null, 'via' => null, 'limit' => APP_JOURNEY_ROUTES, 'variants' => true, 'deeper' => appRouteSearch()['Deeper']]];
    $t1 = microtime(true);
    list($data) = appJourneysCompute($q);
    $dt = microtime(true) - $t1;
    $secs += $dt;
    $r = [];
    foreach ($data['journeys'] as $j) {
        foreach (['Fastest' => 'fastest', 'ShortestTime' => 'shortest', 'FewestTransfers' => 'fewest'] as $tag => $k) {
            if (in_array($tag, $j['Tags'], true)) {
                $r[$k] = [$j['DepartureMinute'], $j['ArrivalMinute'], $j['TransferCount'], $j['TravelMinutes'], $j['route']];
            }
        }
    }
    if ($set === 'bench') {
        $r['seconds'] = round($dt, 3);
    }
    $out["$week|$from>$to|$start"] = $r;
}
$file = $o['out'] ?? CMP_DATA . "/run_$set.json";
cmpSave($file, cmpHeader() + ['Set' => $set, 'Seconds' => round($secs, 2), 'cases' => $out]);
printf("%s：%d 組，計算 %.1f 秒（總 %.1f 秒）→ %s\n", $set, count($out), $secs, microtime(true) - $t0, $file);
