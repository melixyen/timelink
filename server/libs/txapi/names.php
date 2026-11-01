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

/**
 * 多語系名稱的欄位：語言代碼前兩碼 → 名稱物件的欄位。沒列的語言（en、th、vi、id、es…）用 En
 */
const TXAPI_NAME_FIELDS = ['zh' => 'Zh_tw', 'ja' => 'Ja', 'ko' => 'Ko'];

/** 預設的退回順序：選取語系的欄位之後依這個順序找（使用者規則：選取的語系 → 英文 → 中文，最後才是其他語言） */
const TXAPI_NAME_FALLBACK_DEFAULT = ['En', 'Zh_tw', 'Ja', 'Ko'];

/**
 * 特定語言的退回順序（完整順序，含選取語系本身）。沒列的語言用預設：[選取語系的欄位, ...TXAPI_NAME_FALLBACK_DEFAULT]。
 *   ja：日文缺名稱時先用中文（漢字，臺灣站牌的寫法，日本使用者看得懂），再用英文——臺鐵、高捷站名 TDX 多半沒有日文
 * 其他語言要特殊規則時加在這裡。前端由 /txapi/public_info/names 取得，不另外寫死
 */
const TXAPI_NAME_FALLBACK = [
    'ja' => ['Ja', 'Zh_tw', 'En', 'Ko'],
];

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

/**
 * 各語言取多語系名稱的完整順序：['Fields' => 語言 → 欄位, 'Default' => 其他語言, 'Orders' => 語言 → 順序]。
 * Orders 列出 TXAPI_NAME_FIELDS 與 TXAPI_NAME_FALLBACK 的每個語言；沒列的語言用 Default（En 開頭）
 */
function txapiNameOrders()
{
    $resolve = function ($primary) {
        return array_values(array_unique(array_merge([$primary], TXAPI_NAME_FALLBACK_DEFAULT)));
    };
    $orders = [];
    foreach (TXAPI_NAME_FIELDS as $lang => $field) {
        $orders[$lang] = $resolve($field);
    }
    foreach (TXAPI_NAME_FALLBACK as $lang => $order) {
        $orders[$lang] = array_values(array_unique($order));
    }
    return ['Fields' => TXAPI_NAME_FIELDS + ['*' => 'En'], 'Default' => $resolve('En'), 'Orders' => $orders];
}
