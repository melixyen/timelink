<?php
/**
 * 產生車站搜尋用的漢字字典 libs/txapi/transfer/data/hanzi_pinyin.json（PHP 與 Node.js 共用）：
 *   - Readings：Big5 收錄的每個漢字的漢語拼音（不標聲調、ü 寫成 u；多音字全部列出）
 *   - Fold：異體字與簡繁字歸成同一個字（臺→台、峯→峰、園→园…），搜尋時站名與輸入都先歸一，所以「台北」「臺北」「台北」都搜得到
 *
 * 資料來源：Unicode Unihan 資料庫（https://www.unicode.org/Public/UCD/latest/ucd/Unihan.zip）
 *   讀音：kMandarin、kXHC1983（現代漢語詞典）、kTGHZ2013（通用規範漢字字典）、kHanyuPinlu 的聯集；都沒有才用 kHanyuPinyin（漢語大字典，含很多古音）
 *   異體：kSimplifiedVariant、kTraditionalVariant、kZVariant、kSemanticVariant（同一組裡太多字時只保留簡繁對應）
 *
 * 用法：php build_hanzi.php [Unihan.zip 或解壓後的目錄] [輸出檔]
 *   省略第一個參數時自動下載 Unihan.zip 到暫存目錄。字典很少需要更新（新車站用到 Big5 以外的字時才要），
 *   車站搜尋會在回應的 missingChars 列出沒有讀音的字。
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only\n");
}
$root = dirname(__DIR__, 4);
$src = $argv[1] ?? null;
$out = $argv[2] ?? $root . '/libs/txapi/transfer/data/hanzi_pinyin.json';

if ($src === null) {
    $src = sys_get_temp_dir() . '/Unihan.zip';
    fwrite(STDERR, "下載 Unihan.zip …\n");
    $fp = fopen($src, 'w');
    $ch = curl_init('https://www.unicode.org/Public/UCD/latest/ucd/Unihan.zip');
    curl_setopt_array($ch, [CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300]);
    if (!curl_exec($ch) || curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) {
        exit("下載失敗\n");
    }
    fclose($fp);
}
$read = function ($name) use ($src) {
    if (is_dir($src)) {
        return file_get_contents($src . '/' . $name);
    }
    $z = new ZipArchive();
    if ($z->open($src) !== true) {
        exit("無法開啟 $src\n");
    }
    $s = $z->getFromName($name);
    $z->close();
    return $s;
};
$chr = function ($cp) { return mb_chr(hexdec($cp), 'UTF-8'); };
$isBig5 = function ($c) {
    $b = @mb_convert_encoding($c, 'BIG-5', 'UTF-8');
    return $b !== '?' && strlen($b) === 2 && mb_convert_encoding($b, 'UTF-8', 'BIG-5') === $c;
};
$plain = function ($py) {
    $py = Normalizer::normalize($py, Normalizer::FORM_D);
    $py = preg_replace('/\p{Mn}/u', '', $py);           // 去聲調（ü 分解成 u＋分音符，也一起去掉）
    return strtolower(preg_replace('/[^a-z]/i', '', $py));
};

// ---- 讀音
$modern = [];
$old = [];
foreach (explode("\n", $read('Unihan_Readings.txt')) as $line) {
    if (!preg_match('/^U\+([0-9A-F]+)\t(kMandarin|kXHC1983|kTGHZ2013|kHanyuPinlu|kHanyuPinyin)\t(.+)$/', $line, $m)) {
        continue;
    }
    $c = $chr($m[1]);
    $vals = [];
    if ($m[2] === 'kMandarin') {
        $vals = preg_split('/\s+/', $m[3]);
    } elseif ($m[2] === 'kHanyuPinlu') {
        preg_match_all('/([^\s(]+)\(/u', $m[3], $mm);
        $vals = $mm[1];
    } else {
        foreach (preg_split('/\s+/', $m[3]) as $entry) {
            $p = strrpos($entry, ':');
            foreach (explode(',', $p === false ? $entry : substr($entry, $p + 1)) as $v) {
                $vals[] = $v;
            }
        }
    }
    foreach ($vals as $v) {
        $v = $plain($v);
        if ($v === '') {
            continue;
        }
        if ($m[2] === 'kHanyuPinyin') {
            $old[$c][$v] = true;
        } else {
            $modern[$c][$v] = true;
        }
    }
}

// ---- 異體與簡繁：union-find
$parent = [];
$find = function ($x) use (&$parent, &$find) {
    if (!isset($parent[$x])) {
        $parent[$x] = $x;
    }
    while ($parent[$x] !== $x) {
        $parent[$x] = $parent[$parent[$x]] ?? $parent[$x];
        $x = $parent[$x];
    }
    return $x;
};
$edges = ['strict' => [], 'loose' => []];
foreach (explode("\n", $read('Unihan_Variants.txt')) as $line) {
    if (!preg_match('/^U\+([0-9A-F]+)\t(kSimplifiedVariant|kTraditionalVariant|kZVariant|kSemanticVariant)\t(.+)$/', $line, $m)) {
        continue;
    }
    $a = $chr($m[1]);
    preg_match_all('/U\+([0-9A-F]+)(<\S+)?/', $m[3], $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        $cp = $x[1];
        $src = $x[2] ?? '';
        // 意義相同的異體只取字形層級的（kHKGlyph，例如 峯／峰、臺／台）；kMatthews 等字典的「同義」常是不同的字（綠／氯）
        if ($m[2] === 'kSemanticVariant' && strpos($src, 'kHKGlyph') === false) {
            continue;
        }
        $b = $chr($cp);
        if ($a !== $b) {
            $edges[in_array($m[2], ['kSimplifiedVariant', 'kTraditionalVariant'], true) ? 'strict' : 'loose'][] = [$a, $b];
        }
    }
}
$big5 = [];
for ($cp = 0x3400; $cp <= 0x9FFF; $cp++) {
    $c = mb_chr($cp, 'UTF-8');
    if ($isBig5($c)) {
        $big5[$c] = true;
    }
}
fwrite(STDERR, 'Big5 漢字 ' . count($big5) . " 個\n");
// 只處理與 Big5 字有關的邊；先簡繁，再異體（異體把兩組合成超過 MAX 個字時不合，避免一大串不相干的字被歸成同一個）
const FOLD_MAX = 6;
$size = [];
foreach (['strict', 'loose'] as $kind) {
    foreach ($edges[$kind] as list($a, $b)) {
        if (!isset($big5[$a]) && !isset($big5[$b])) {
            continue;
        }
        $ra = $find($a);
        $rb = $find($b);
        if ($ra === $rb) {
            continue;
        }
        $n = ($size[$ra] ?? 1) + ($size[$rb] ?? 1);
        if ($kind === 'loose' && $n > FOLD_MAX) {
            continue;
        }
        $parent[$rb] = $ra;
        $size[$ra] = $n;
    }
}
// Big5 常用字（A440～C67E）0、次常用字 1、其他 2
$big5Rank = function ($c) {
    $b = @mb_convert_encoding($c, 'BIG-5', 'UTF-8');
    if (strlen($b) !== 2 || mb_convert_encoding($b, 'UTF-8', 'BIG-5') !== $c) {
        return 2;
    }
    $code = (ord($b[0]) << 8) | ord($b[1]);
    return $code >= 0xA440 && $code <= 0xC67E ? 0 : 1;
};
$groups = [];
foreach (array_keys($parent) as $c) {
    $groups[$find($c)][] = $c;
}
$fold = [];
foreach ($groups as $members) {
    if (count($members) < 2) {
        continue;
    }
    // 代表字：組內 Big5 常用字（碼位最小）優先，否則碼位最小
    usort($members, function ($a, $b) use ($big5Rank) {
        return [$big5Rank($a), mb_ord($a)] <=> [$big5Rank($b), mb_ord($b)];
    });
    foreach ($members as $c) {
        if ($c !== $members[0]) {
            $fold[$c] = $members[0];
        }
    }
}
ksort($fold, SORT_STRING);

$readings = [];
foreach (array_keys($big5) as $c) {
    $r = array_keys($modern[$c] ?? $old[$c] ?? []);
    if ($r) {
        sort($r);
        $readings[$c] = implode(',', $r);
    }
}
$json = [
    'Version'     => 1,
    'GeneratedAt' => date('c'),
    'Source'      => 'Unicode Unihan (https://www.unicode.org/Public/UCD/latest/ucd/Unihan.zip)；由 agent/skills/rail-transfer/scripts/build_hanzi.php 產生，不要手改',
    'Description' => 'Readings：Big5 漢字的漢語拼音（無聲調，ü 寫 u，多音字以逗號分隔；kMandarin、kXHC1983、kTGHZ2013、kHanyuPinlu 聯集，都沒有才用 kHanyuPinyin）。'
                   . 'Fold：異體與簡繁歸一（字 → 代表字），搜尋時站名與輸入都先換成代表字',
    'Readings'    => $readings,
    'Fold'        => $fold,
];
file_put_contents($out, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fwrite(STDERR, sprintf("讀音 %d 字、歸一 %d 字 → %s（%d KB）\n", count($readings), count($fold), $out, filesize($out) / 1024));
