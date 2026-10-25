<?php
/**
 * 路由代碼（route）：看得懂、放進網址參數不必編碼的路由寫法。只用英數字與 - .（JS encodeURIComponent、URLSearchParams、
 * PHP urlencode／rawurlencode／http_build_query 都不會編碼這些字元）。
 *
 *   車站.連接.車站.連接 … 車站          每兩個車站之間一個「連接」，說明這一段怎麼走；中途的車站就是轉乘站
 *   連接＝搭車  路線代碼，可再接車種等級  TRA-WL、TRA-WL.local、TRA-WL.express
 *              鐵路（臺鐵、高鐵）用業者代碼＝系統直達段，不分路線查站到站直達  TRA、TRA.local、THSR.express
 *   連接＝步行  walk 或 walk-<分鐘>        walk-6＝兩站間步行 6 分；只寫 walk 用轉乘群組的分鐘
 *
 * 關鍵字（walk、local、express）要整個元素完全命中（名稱在第一個 - 之前，- 之後是參數）才算；其他一律當車站或路線代碼。
 * 代碼都是大寫系統代碼開頭（TRA-、TRTC-…），不會跟關鍵字衝突。
 *
 *   基隆→東湖  TRA-0900.TRA-WL.TRA-1000.walk.TRTC-BL12.TRTC-BL.TRTC-BL15.TRTC-BR.TRTC-BR22
 *   五堵→臺中  TRA-0950.TRA.local.TRA-1000.TRA.express.TRA-3300
 */

// APP_ROUTE_MAX_LEGS、APP_ROUTE_WALK_MAX：設定項目（libs/config.json）

/** 路線資訊：LineUID → [System, LineID, LineName, LineColor, IsBranch] */
function appLineInfo()
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (transferLoadGroups()['Lines'] ?? [] as $l) {
            $map[$l['LineUID']] = ['LineUID' => $l['LineUID'], 'System' => $l['System'], 'LineID' => $l['LineID'],
                                   'LineName' => $l['LineName'], 'LineColor' => $l['LineColor'], 'IsBranch' => (bool) $l['IsBranch']];
        }
    }
    return $map;
}

/**
 * 元素是不是關鍵字：回傳 [名稱, 參數陣列] 或 null（不是關鍵字）。名稱是第一個 - 之前的部分，要完全等於關鍵字
 */
function appRouteKeyword($tok)
{
    $parts = explode('-', $tok);
    return in_array($parts[0], ['walk', 'local', 'express'], true) ? [$parts[0], array_slice($parts, 1)] : null;
}

/**
 * 只看語法解析路由代碼（不查資料）。回傳 ['Stations' => [...], 'Legs' => [['Type' => 'ride', 'From', 'To', 'LineUID', 'Class'], ['Type' => 'walk', 'From', 'To', 'Minutes', 'MinutesGiven'], ...]]。
 * 語法錯誤丟 InvalidArgumentException（訊息可直接顯示）
 */
function appRouteParse($code)
{
    $code = trim((string) $code);
    if ($code === '') {
        throw new InvalidArgumentException('路由代碼是空的');
    }
    if (!preg_match('/^[A-Za-z0-9.\-]+$/', $code)) {
        throw new InvalidArgumentException('路由代碼只能有英數字、- 與 .');
    }
    $toks = explode('.', $code);
    $n = count($toks);
    $stations = [];
    $legs = [];
    $i = 0;
    $station = function ($i) use ($toks) {
        if (!isset($toks[$i]) || $toks[$i] === '') {
            throw new InvalidArgumentException('第 ' . ($i + 1) . ' 個元素應該是車站代碼');
        }
        if (appRouteKeyword($toks[$i]) !== null) {
            throw new InvalidArgumentException('第 ' . ($i + 1) . ' 個元素「' . $toks[$i] . '」是關鍵字，這裡應該是車站代碼');
        }
        return $toks[$i];
    };
    $stations[] = $station($i++);
    while ($i < $n) {
        $from = end($stations);
        $tok = $toks[$i];
        if ($tok === '') {
            throw new InvalidArgumentException('第 ' . ($i + 1) . ' 個元素是空的（多了一個 .）');
        }
        $kw = appRouteKeyword($tok);
        if ($kw !== null && $kw[0] !== 'walk') {
            throw new InvalidArgumentException('第 ' . ($i + 1) . ' 個元素「' . $tok . '」要接在路線代碼後面');
        }
        if ($kw !== null) {
            // 步行：walk 或 walk-<分鐘>
            $min = null;
            if ($kw[1]) {
                if (count($kw[1]) !== 1 || !preg_match('/^\d{1,2}$/', $kw[1][0]) || (int) $kw[1][0] < 1 || (int) $kw[1][0] > APP_ROUTE_WALK_MAX) {
                    throw new InvalidArgumentException('「' . $tok . '」：walk 的參數是步行分鐘（1～' . APP_ROUTE_WALK_MAX . '），例如 walk-6');
                }
                $min = (int) $kw[1][0];
            }
            $i++;
            $to = $station($i++);
            $legs[] = ['Type' => 'walk', 'From' => $from, 'To' => $to, 'Minutes' => $min, 'MinutesGiven' => $min !== null];
        } else {
            // 搭車：路線代碼，可再接 local／express
            $line = $tok;
            $class = null;
            $i++;
            $kw2 = isset($toks[$i]) ? appRouteKeyword($toks[$i]) : null;
            if ($kw2 !== null && in_array($kw2[0], ['local', 'express'], true)) {
                if ($kw2[1]) {
                    throw new InvalidArgumentException('「' . $toks[$i] . '」：' . $kw2[0] . ' 不接參數');
                }
                $class = $kw2[0];
                $i++;
            }
            $to = $station($i++);
            $legs[] = ['Type' => 'ride', 'From' => $from, 'To' => $to, 'LineUID' => $line, 'Class' => $class];
        }
        $stations[] = $to;
        if (count($legs) > APP_ROUTE_MAX_LEGS) {
            throw new InvalidArgumentException('路由最多 ' . APP_ROUTE_MAX_LEGS . ' 段');
        }
    }
    if (!$legs) {
        throw new InvalidArgumentException('路由至少要有「車站.連接.車站」');
    }
    return ['Stations' => $stations, 'Legs' => $legs];
}

/**
 * 對照資料驗證並正規化（大小寫、TDX 寫法 → 正式代碼；步行沒給分鐘時補資料的分鐘）。
 * 錯誤丟 InvalidArgumentException
 */
function appRouteValidate(array $r, SqliteDao $db, array $m)
{
    $lines = appLineInfo();
    $lineKey = [];
    foreach (array_keys($lines) as $lu) {
        $lineKey[strtoupper($lu)] = $lu;
    }
    $canon = function ($v) use ($db) {
        $p = finderParseUID($v);
        $row = $p ? finderStation($db, $p[0], $p[1]) : null;
        if (!$row) {
            throw new InvalidArgumentException('找不到車站 ' . $v);
        }
        return $row['uid'];
    };
    $r['Stations'] = array_map($canon, $r['Stations']);
    foreach ($r['Legs'] as $k => &$leg) {
        $leg['From'] = $r['Stations'][$k];
        $leg['To'] = $r['Stations'][$k + 1];
        $no = '第 ' . ($k + 1) . ' 段';
        if ($leg['From'] === $leg['To']) {
            throw new InvalidArgumentException($no . '的起訖是同一站 ' . $leg['From']);
        }
        if ($leg['Type'] === 'walk') {
            if ($k > 0 && $r['Legs'][$k - 1]['Type'] === 'walk') {
                throw new InvalidArgumentException($no . '：不能連續兩段步行');
            }
            $w = transferWalk($m, $leg['From'], $leg['To'], true, true);
            if ($w === null) {
                throw new InvalidArgumentException($no . '：' . $leg['From'] . ' 與 ' . $leg['To'] . ' 不在同一個轉乘群組，沒有步行轉乘資料');
            }
            $leg['DataMinutes'] = $w['Minutes'];
            $leg['Source'] = $w['Source'];
            if ($leg['Minutes'] === null) {
                $leg['Minutes'] = $w['Minutes'];
            }
            continue;
        }
        // 系統直達段：連接寫業者代碼（TRA、THSR），兩站都是該系統的車站就好，不分路線
        $sysCode = strtoupper($leg['LineUID']);
        if (in_array($sysCode, FINDER_TRAIN_SYSTEMS, true)) {
            foreach (['From', 'To'] as $end) {
                if (strpos($leg[$end], $sysCode . '-') !== 0) {
                    throw new InvalidArgumentException($no . '：' . $leg[$end] . ' 不是 ' . $sysCode . ' 的車站');
                }
            }
            if ($leg['Class'] !== null && !appClassHasSplit($sysCode)) {
                throw new InvalidArgumentException($no . '：' . $sysCode . ' 沒有分慢車與快車，不能加 ' . $leg['Class']);
            }
            $leg['LineUID'] = $sysCode;
            $leg['System'] = $sysCode;
            continue;
        }
        $lu = $lineKey[strtoupper($leg['LineUID'])] ?? null;
        if ($lu === null) {
            throw new InvalidArgumentException($no . '：找不到路線 ' . $leg['LineUID']);
        }
        $leg['LineUID'] = $lu;
        foreach (['From', 'To'] as $end) {
            if (!isset($m['stationLines'][$leg[$end]][$lu])) {
                throw new InvalidArgumentException($no . '：' . $leg[$end] . ' 不在 ' . $lu . ' 上');
            }
        }
        $sys = $lines[$lu]['System'];
        if ($leg['Class'] !== null && !appClassHasSplit($sys)) {
            throw new InvalidArgumentException($no . '：' . $lu . ' 沒有分慢車與快車，不能加 ' . $leg['Class']);
        }
        $leg['System'] = $sys;
    }
    unset($leg);
    return $r;
}

/** 由解析結果產生路由代碼（步行有指定分鐘才寫 walk-<分鐘>） */
function appRouteFormat(array $r)
{
    $out = [$r['Legs'][0]['From']];
    foreach ($r['Legs'] as $leg) {
        if ($leg['Type'] === 'walk') {
            $out[] = !empty($leg['MinutesGiven']) ? 'walk-' . $leg['Minutes'] : 'walk';
        } else {
            $out[] = $leg['LineUID'];
            if ($leg['Class'] !== null) {
                $out[] = $leg['Class'];
            }
        }
        $out[] = $leg['To'];
    }
    return implode('.', $out);
}

/** 車站中文名 */
function appStationName(array $m, $uid)
{
    return $m['names'][$uid]['Zh_tw'] ?? $uid;
}

/** 給畫面顯示的路由文字：「五堵 ─西部幹線（區間車）→ 臺北 ─西部幹線（對號列車）→ 臺中」 */
function appRouteText(array $r, array $m)
{
    $lines = appLineInfo();
    $s = appStationName($m, $r['Legs'][0]['From']);
    foreach ($r['Legs'] as $leg) {
        if ($leg['Type'] === 'walk') {
            $mid = '步行' . ($leg['Minutes'] !== null ? ' ' . $leg['Minutes'] . ' 分' : '');
        } else {
            $sys = $lines[$leg['LineUID']]['System'] ?? ($leg['System'] ?? $leg['LineUID']);
            $label = appClassLabel($sys, $leg['Class']);
            $name = isset($lines[$leg['LineUID']]) ? $lines[$leg['LineUID']]['LineName']['Zh_tw'] : appSystemOperator($sys)['OperatorName'];
            $mid = $name . ($label ? '（' . $label . '）' : '');
        }
        $s .= ' ─' . $mid . '→ ' . appStationName($m, $leg['To']);
    }
    return $s;
}

/** 回應用的路由內容：route、RouteText、各段（含名稱、路線色、等級、步行分鐘） */
function appRouteDescribe(array $r, array $m)
{
    $lines = appLineInfo();
    $st = function ($u) use ($m) {
        return ['StationUID' => $u, 'StationName' => $m['names'][$u] ?? null];
    };
    $legs = [];
    foreach ($r['Legs'] as $leg) {
        $x = ['Type' => $leg['Type'], 'From' => $st($leg['From']), 'To' => $st($leg['To'])];
        if ($leg['Type'] === 'walk') {
            $x += ['Minutes' => $leg['Minutes'], 'DataMinutes' => $leg['DataMinutes'] ?? null, 'Source' => $leg['Source'] ?? null,
                   'Videos' => transferVideos($leg['From'], $leg['To'])];
        } elseif (isset($lines[$leg['LineUID']])) {
            $l = $lines[$leg['LineUID']];
            $x += ['System' => $l['System'], 'LineUID' => $l['LineUID'], 'LineName' => $l['LineName'], 'LineColor' => $l['LineColor'],
                   'Class' => $leg['Class'], 'ClassLabel' => appClassLabel($l['System'], $leg['Class'])];
        } else {
            // 系統直達段：LineUID＝業者代碼；經過的路線串在 Paths（第一條是主要的，其餘是其他路線的走法，例如北迴、南迴）
            $sys = $leg['System'] ?? $leg['LineUID'];
            $op = appSystemOperator($sys);
            $x += ['System' => $sys, 'LineUID' => $sys, 'LineName' => ['Zh_tw' => $op['OperatorName'], 'En' => $sys], 'LineColor' => null,
                   'Class' => $leg['Class'], 'ClassLabel' => appClassLabel($sys, $leg['Class']), 'Direct' => true];
            $paths = $leg['Paths'] ?? (isset($leg['Path']) ? [$leg['Path']] : []);
            $x['Paths'] = array_map(function ($p) use ($lines, $st) {
                return array_map(function ($pl) use ($lines, $st) {
                    return ['LineUID' => $pl['LineUID'], 'LineName' => $lines[$pl['LineUID']]['LineName'] ?? null,
                            'LineColor' => $lines[$pl['LineUID']]['LineColor'] ?? null, 'From' => $st($pl['From']), 'To' => $st($pl['To'])];
                }, $p);
            }, $paths);
        }
        $legs[] = $x;
    }
    return ['route' => appRouteFormat($r), 'RouteText' => appRouteText($r, $m), 'TransferCount' => max(0, count(array_filter($r['Legs'], function ($l) {
        return $l['Type'] === 'ride';
    })) - 1), 'Legs' => $legs];
}

/** 等級要求：extra 排除的列車出現在路由裡時，這條路由不能用（例如 bit 1 排除對號卻有臺鐵 express 段、bit 2 排除高鐵卻有高鐵段）。回傳不允許的段落說明，空陣列＝可用 */
function appRouteExtraConflicts(array $r, $extra)
{
    $out = [];
    foreach ($r['Legs'] as $k => $leg) {
        if ($leg['Type'] !== 'ride') {
            continue;
        }
        $sys = $leg['System'] ?? (appLineInfo()[$leg['LineUID']]['System'] ?? '');
        if (!appSystemAllowed($extra, $sys)) {
            $out[] = '第 ' . ($k + 1) . ' 段是高鐵，但 extra 含 2（不搭高鐵）';
        } elseif ($leg['Class'] === APP_CLASS_EXPRESS && !appClassAllowed($extra, $sys, APP_CLASS_EXPRESS)) {
            $out[] = '第 ' . ($k + 1) . ' 段指定' . (appClassLabel($sys, APP_CLASS_EXPRESS) ?? '快車') . '，但 extra 含 1（不搭臺鐵對號）';
        }
    }
    return $out;
}
