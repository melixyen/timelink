<?php
/**
 * 車站搜尋（func=stations）：輸入站名的一部分，模糊比對後回傳前幾筆（預設 10）。
 *
 * 參考 railtime 的 findStationByString（臺→台、英文比對 estring＝拼音＋英文），改成自動產生、不必逐站手寫：
 *   - 中文：站名與輸入先把異體字、簡繁字歸一（data/hanzi_pinyin.json 的 Fold＋transfer_settings.json 的 Search.ExtraFold），
 *           所以 台北／臺北／台北 都搜得到；也比對去掉「車站」「站」的站名
 *   - 英文：TDX 英文站名（Taipei、Hsinchu、Keelung…）與中文站名的漢語拼音（taibei、xinzhu、jilong；多音字全部組合）、拼音首字母（tb）
 *   - 站碼：StationID（BL12、1000）與 StationUID（TRA-1000）
 *   - 別名：Search.Aliases（例如 北車 → 臺北車站轉乘群組的所有車站）
 * 比對方式（完全相同＜開頭＜包含＜依序包含＜英文差一個字母）加上欄位權重為分數，越小越前面；同分依系統優先順序。
 */

require_once __DIR__ . '/settings.php';

/** 字典（讀音、歸一）＋設定的額外歸一 */
function transferHanzi()
{
    static $h = null;
    if ($h === null) {
        $h = json_decode((string) @file_get_contents(transferDataFile('Hanzi')), true);
        if (!is_array($h)) {
            throw new RuntimeException(transferSettings('Files.Hanzi') . ' is missing or invalid');
        }
        foreach (transferSettings('Search.ExtraFold') as $a => $b) {
            $h['Fold'][$a] = $h['Fold'][$b] ?? $b;
        }
    }
    return $h;
}

/** 中文歸一：異體、簡繁換成代表字，英文轉小寫，去掉空白與標點 */
function transferSearchFoldZh($s)
{
    $fold = transferHanzi()['Fold'];
    $out = '';
    foreach (mb_str_split(mb_strtolower(trim((string) $s), 'UTF-8'), 1, 'UTF-8') as $c) {
        if (preg_match('/[\s\p{P}\p{S}]/u', $c)) {
            continue;
        }
        $out .= $fold[$c] ?? $c;
    }
    return $out;
}

/** 英文歸一：只留英數小寫 */
function transferSearchFoldEn($s)
{
    return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
}

/** 站名的所有拼音組合與首字母組合（多音字展開，最多 $max 種）；沒有讀音的字（例如台語用字）略過 */
function transferSearchPinyin($name, $max)
{
    $h = transferHanzi();
    $combos = [''];
    $inits = [''];
    $missing = [];
    foreach (mb_str_split((string) $name, 1, 'UTF-8') as $c) {
        if (!preg_match('/\p{Han}/u', $c)) {
            continue;
        }
        $r = $h['Readings'][$c] ?? $h['Readings'][$h['Fold'][$c] ?? ''] ?? null;
        if ($r === null) {
            $missing[] = $c;
            continue;
        }
        $rs = explode(',', $r);
        $next = [];
        $nextI = [];
        foreach ($combos as $p) {
            foreach ($rs as $x) {
                $next[$p . $x] = true;
            }
        }
        foreach ($inits as $p) {
            foreach ($rs as $x) {
                $nextI[$p . $x[0]] = true;
            }
        }
        $combos = array_slice(array_keys($next), 0, $max);
        $inits = array_slice(array_keys($nextI), 0, $max);
    }
    return [array_values(array_filter($combos, 'strlen')), array_values(array_filter($inits, 'strlen')), $missing];
}

/** 搜尋索引：每個車站的各種比對字串（同一個請求內快取） */
function transferSearchIndex(SqliteDao $db)
{
    static $idx = null;
    if ($idx !== null) {
        return $idx;
    }
    $cfg = transferSettings('Search');
    $strip = $cfg['StripSuffixes'];
    $enStrip = array_map('strtolower', $cfg['EnStripWords']);
    $lineInfo = [];
    foreach ($db->all('SELECT system, line_id, name, color FROM line') as $r) {
        $lineInfo[$r['system'] . '-' . $r['line_id']] = ['name' => json_decode($r['name'], true), 'color' => $r['color']];
    }
    $groups = transferLoadGroups();
    $groupOf = [];
    $groupMembers = [];
    foreach ($groups['TransferGroups'] ?? [] as $g) {
        foreach ($g['Stations'] as $x) {
            $groupMembers[$g['TransferGroupID']][] = $x['StationUID'];
            if ($g['Type'] === 'multi') {
                $groupOf[$x['StationUID']][] = $g['TransferGroupID'];
            }
        }
    }
    $alias = [];
    foreach ($cfg['Aliases'] as $a) {
        $members = isset($a['TransferGroupID']) ? ($groupMembers[$a['TransferGroupID']] ?? []) : (array) ($a['StationUIDs'] ?? []);
        foreach ($members as $u) {
            $alias[$u][] = transferSearchFoldZh($a['Name']);
        }
    }
    $prio = array_flip(json_decode((string) @file_get_contents(transferDataFile('Seed')), true)['Settings']['SystemPriority'] ?? []);
    $idx = ['stations' => [], 'missing' => []];
    foreach ($db->all('SELECT uid, system, station_id, name, line_ids FROM station ORDER BY uid') as $r) {
        $name = json_decode($r['name'], true);
        $zh = $name['Zh_tw'] ?? '';
        $zhKeys = [transferSearchFoldZh($zh)];
        foreach ($strip as $suf) {
            if (mb_strlen($zh) > mb_strlen($suf) + 1 && mb_substr($zh, -mb_strlen($suf)) === $suf) {
                $zhKeys[] = transferSearchFoldZh(mb_substr($zh, 0, -mb_strlen($suf)));
            }
        }
        // 括號內的別稱（例如 灣仔內(大順鼎山)）也當成站名
        if (preg_match('/^(.+?)[（(](.+)[)）]$/u', $zh, $mm)) {
            $zhKeys[] = transferSearchFoldZh($mm[1]);
            $zhKeys[] = transferSearchFoldZh($mm[2]);
        }
        $en = strtolower($name['En'] ?? '');
        foreach ($enStrip as $w) {
            $en = preg_replace('/\b' . preg_quote($w, '/') . '\b/', '', $en);
        }
        list($py, $ini, $missing) = transferSearchPinyin(preg_replace('/(車站|站)$/u', '', $zh), (int) $cfg['MaxPinyinCombos']);
        foreach ($missing as $c) {
            $idx['missing'][$c][] = $r['uid'];
        }
        $lines = [];
        foreach (json_decode((string) $r['line_ids'], true) ?: [] as $l) {
            $lu = $r['system'] . '-' . $l;
            $lines[] = ['LineUID' => $lu, 'LineName' => $lineInfo[$lu]['name'] ?? null, 'LineColor' => $lineInfo[$lu]['color'] ?? null];
        }
        $idx['stations'][] = [
            'out' => ['StationUID' => $r['uid'], 'System' => $r['system'], 'StationID' => $r['station_id'], 'StationName' => $name,
                      'Lines' => $lines, 'TransferGroups' => $groupOf[$r['uid']] ?? []],
            'keys' => [
                'Zh'       => array_values(array_unique(array_filter($zhKeys, 'strlen'))),
                'Alias'    => $alias[$r['uid']] ?? [],
                'Id'       => array_values(array_unique([strtolower($r['station_id']), transferSearchFoldEn($r['uid'])])),
                'En'       => array_values(array_filter([transferSearchFoldEn($en)], 'strlen')),
                'Pinyin'   => $py,
                'Initials' => $ini,
            ],
            'prio' => $prio[$r['system']] ?? 99,
            'len'  => mb_strlen($zh),
        ];
    }
    return $idx;
}

/** 兩字串的編輯距離（UTF-8 安全；只用在英數） */
function transferSearchDistance($a, $b)
{
    return levenshtein($a, $b);
}

/**
 * 比對一個查詢字串與一組欄位：回傳最佳 [分數, 欄位, 比對方式] 或 null
 */
function transferSearchScore($q, array $keys, array $cfg, $isAscii)
{
    $W = $cfg['Weights'];
    $best = null;
    $consider = function ($score, $field, $how) use (&$best) {
        if ($best === null || $score < $best[0]) {
            $best = [$score, $field, $how];
        }
    };
    $qLen = strlen($q);
    foreach ($keys as $field => $list) {
        // 中文輸入只比中文站名與別名；英數輸入只比站碼、英文、拼音
        if ($isAscii === in_array($field, ['Zh', 'Alias'], true)) {
            continue;
        }
        $fw = $W[$field];
        $qq = $field === 'Pinyin' || $field === 'Initials' ? str_replace('v', 'u', $q) : $q;   // ü 可以打 v
        foreach ($list as $k) {
            if ($k === $qq) {
                $consider($W['Exact'] + $fw, $field, 'exact');
                continue;
            }
            if ($field === 'Initials' && $qLen < 2) {
                continue;
            }
            $pos = strpos($k, $qq);
            if ($pos === 0) {
                $consider($W['Prefix'] + $fw, $field, 'prefix');
            } elseif ($pos !== false && $field !== 'Initials' && $field !== 'Id') {
                $consider($W['Contains'] + $fw + min(9, $isAscii ? $pos / 4 : $pos / 3), $field, 'contains');
            }
        }
    }
    if ($best !== null) {
        return $best;
    }
    // 依序包含（中文：台車 → 台北車站）
    if (!$isAscii && mb_strlen($q) >= 2) {
        $re = '/' . implode('.*', array_map(function ($c) { return preg_quote($c, '/'); }, mb_str_split($q))) . '/u';
        foreach (['Zh', 'Alias'] as $field) {
            foreach ($keys[$field] as $k) {
                if (preg_match($re, $k)) {
                    $consider($W['Subsequence'] + $W[$field], $field, 'subsequence');
                }
            }
        }
    }
    // 英文拼錯一個字母：和英文站名、拼音的開頭（同長度）比
    if ($isAscii && $qLen >= $cfg['Fuzzy']['MinLength']) {
        foreach (['En', 'Pinyin'] as $field) {
            foreach ($keys[$field] as $k) {
                $dist = transferSearchDistance($q, substr($k, 0, $qLen));
                if ($dist <= $cfg['Fuzzy']['MaxDistance']) {
                    $consider($W['Fuzzy'] + $W[$field] + $dist, $field, 'fuzzy');
                }
            }
        }
    }
    return $best;
}

/** 搜尋：回傳 ['query' => 歸一後, 'results' => [...], 'missingChars' => …] */
function transferSearchStations(SqliteDao $db, $q, $limit = null, array $systems = [])
{
    $cfg = transferSettings('Search');
    $limit = max(1, min((int) $cfg['MaxResultsLimit'], (int) ($limit ?? $cfg['MaxResults'])));
    $raw = trim((string) $q);
    $isAscii = !preg_match('/[^\x00-\x7F]/', $raw);
    $norm = $isAscii ? transferSearchFoldEn($raw) : transferSearchFoldZh($raw);
    $idx = transferSearchIndex($db);
    $hits = [];
    if ($norm !== '') {
        $sys = $systems ? array_flip($systems) : null;
        foreach ($idx['stations'] as $s) {
            if ($sys !== null && !isset($sys[$s['out']['System']])) {
                continue;
            }
            $r = transferSearchScore($norm, $s['keys'], $cfg, $isAscii);
            if ($r !== null) {
                $hits[] = [$r, $s];
            }
        }
        usort($hits, function ($a, $b) {
            return [$a[0][0], $a[1]['prio'], $a[1]['len'], $a[1]['out']['StationUID']] <=> [$b[0][0], $b[1]['prio'], $b[1]['len'], $b[1]['out']['StationUID']];
        });
    }
    $results = [];
    foreach (array_slice($hits, 0, $limit) as list($r, $s)) {
        $results[] = $s['out'] + ['Match' => ['Field' => $r[1], 'Type' => $r[2], 'Score' => round($r[0], 2)]];
    }
    return ['query' => $norm, 'total' => count($hits), 'results' => $results,
            'missingChars' => array_map(function ($l) { return implode(',', $l); }, $idx['missing'])];
}

/** func=stations：q（必填）、limit（預設 10）、system（逗號分隔，只找這些系統） */
function transferFuncStations()
{
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        sendJson(400, ['ok' => false, 'error' => 'q is required, e.g. /txapi/transfer/stations?q=台北']);
    }
    $systems = array_values(array_filter(array_map('trim', explode(',', strtoupper((string) ($_GET['system'] ?? ''))))));
    $db = transferOpenDb();
    $t0 = microtime(true);
    $r = transferSearchStations($db, $q, isset($_GET['limit']) ? (int) $_GET['limit'] : null, $systems);
    $db->close();
    sendJson(200, ['ok' => true, 'action' => 'transfer', 'func' => 'stations', 'q' => $q, 'normalized' => $r['query'],
                   'seconds' => round(microtime(true) - $t0, 3), 'total' => $r['total'], 'count' => count($r['results']),
                   'results' => $r['results']] + ($r['missingChars'] ? ['missingChars' => $r['missingChars']] : []));
}
