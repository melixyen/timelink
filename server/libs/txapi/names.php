<?php
/**
 * 補充名稱：TDX 或本站寫死的靜態資料缺某個語言的名稱時，依 data/name_supplements.json 補上。
 *
 * 用法：在 PHP 撈取資料、輸出前，把原始值與補充的 key 傳給 txapiName()，例如
 *   txapiName($row['LineName'], 'Line', 'THSR-HSRL')
 *   txapiName(['Zh_tw' => '臺北捷運'], 'Operator', 'TRTC')
 * 原始值哪個語言是空的（不存在、null、空字串），就用 JSON 同一語言的名稱補上；有值的語言不動，每個語言各補各的。
 * 新的靜態資料要檢查並補名稱時，在 JSON 加一個類別、在輸出前呼叫 txapiName() 即可。
 */

const TXAPI_NAME_SUPPLEMENTS_FILE = __DIR__ . '/data/name_supplements.json';

/** 補充名稱表：類別 → key → 語言 → 名稱（每個請求讀一次） */
function txapiNameSupplements()
{
    static $data = null;
    if ($data === null) {
        $j = json_decode((string) @file_get_contents(TXAPI_NAME_SUPPLEMENTS_FILE), true);
        $data = [];
        foreach (is_array($j) ? $j : [] as $cat => $items) {
            if ($cat !== '' && $cat[0] !== '_' && is_array($items)) {
                $data[$cat] = $items;
            }
        }
    }
    return $data;
}

/**
 * 多語系名稱補值。$value：多語系陣列（['Zh_tw' => …, 'En' => …, 'Ja' => …]）、字串（當成 Zh_tw）或 null；
 * $category、$key：補充名稱表的類別與 key（key 不分型別，數字也可以）。
 * 回傳補過的多語系陣列；原始值與補充表都沒有任何名稱時回傳原始值（null 仍是 null）
 */
function txapiName($value, $category, $key)
{
    $sup = txapiNameSupplements()[$category][(string) $key] ?? null;
    if (!is_array($sup)) {
        return is_string($value) ? ['Zh_tw' => $value] : $value;
    }
    $out = is_array($value) ? $value : (is_string($value) && $value !== '' ? ['Zh_tw' => $value] : []);
    foreach ($sup as $lang => $name) {
        if (is_string($name) && $name !== '' && (!isset($out[$lang]) || !is_string($out[$lang]) || trim($out[$lang]) === '')) {
            $out[$lang] = $name;
        }
    }
    return $out ?: $value;
}
