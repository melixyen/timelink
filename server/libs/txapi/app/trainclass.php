<?php
/**
 * 列車的慢車／快車分類（車種等級）與 extra 判斷。設定在 transfer_seed.json 的 ClassChange（受版控、人工維護）：
 *
 *   臺鐵  Classify=type：區間、普快、區間快（LocalTypeCodes）是慢車 local，其他車種代碼（莒光、自強以上與日後新增的代碼）是快車 express
 *   高鐵  Classify=stops：停靠起迄之間每一站的是慢車（各站停），有跳站的是快車（直達、跳站）
 *   機捷  Classify=stops：同上（普通車／直達車）
 *   其他系統沒有分快慢車，一律 local
 *
 * extra（app_ 找路與時刻 API 的參數）是排除位元：bit 1＝不搭臺鐵快車（對號），bit 2＝不搭高鐵，預設 0 不排除；
 * 捷運、輕軌與臺鐵慢車一律可搭。
 */

const APP_CLASS_LOCAL = 'local';
const APP_CLASS_EXPRESS = 'express';

/** 各系統慢車、快車的顯示名稱 */
const APP_CLASS_LABELS = [
    'TRA'  => ['local' => '區間車', 'express' => '對號列車'],
    'THSR' => ['local' => '各站停', 'express' => '直達車'],
    'TYMC' => ['local' => '普通車', 'express' => '直達車'],
];

/** transfer_seed.json 的 ClassChange（每個系統的分類方式、換車站） */
function appClassConfig()
{
    static $cfg = null;
    if ($cfg === null) {
        $seed = json_decode((string) @file_get_contents(transferDataFile('Seed')), true);
        $cfg = is_array($seed['ClassChange'] ?? null) ? $seed['ClassChange'] : [];
    }
    return $cfg;
}

/** 這個系統有沒有分慢車、快車 */
function appClassHasSplit($system)
{
    return isset(appClassConfig()[$system]);
}

/** 臺鐵車種代碼（train_type_code）→ local／express */
function appTrainClassByType($typeCode)
{
    $local = appClassConfig()['TRA']['LocalTypeCodes'] ?? ['6', '7', '10'];
    return in_array((string) $typeCode, $local, true) ? APP_CLASS_LOCAL : APP_CLASS_EXPRESS;
}

/**
 * 依停靠站分類：$lineStations＝路線站序，$stops＝這班車依序停靠的車站。
 * 起迄之間（路線上）每一站都停是 local，有跳過任何一站是 express；不在路線上的車站不影響判斷
 */
function appTrainClassByStops(array $lineStations, array $stops)
{
    $pos = array_flip($lineStations);
    $idx = [];
    foreach ($stops as $u) {
        if (isset($pos[$u])) {
            $idx[] = $pos[$u];
        }
    }
    for ($i = 1, $n = count($idx); $i < $n; $i++) {
        if (abs($idx[$i] - $idx[$i - 1]) > 1) {
            return APP_CLASS_EXPRESS;
        }
    }
    return APP_CLASS_LOCAL;
}

/**
 * 一班車的等級。$train：['system', 'type_code'（臺鐵）, 'stops'（依停靠順序的 StationUID）, 'line'（LineUID，依停靠站分類時用）]
 */
function appTrainClass(array $train, array $lineStations = [])
{
    $cfg = appClassConfig()[$train['system']] ?? null;
    if ($cfg === null) {
        return APP_CLASS_LOCAL;
    }
    if (($cfg['Classify'] ?? '') === 'type') {
        return appTrainClassByType($train['type_code'] ?? '');
    }
    return appTrainClassByStops($lineStations, $train['stops'] ?? []);
}

/** extra 允不允許搭這個系統、這個等級的車 */
function appClassAllowed($extra, $system, $class)
{
    if ($system === 'THSR') {
        return ($extra & APP_EXTRA_NO_HSR) === 0;
    }
    if ($system === 'TRA' && $class === APP_CLASS_EXPRESS) {
        return ($extra & APP_EXTRA_NO_EXPRESS) === 0;
    }
    return true;
}

/** extra 允不允許走這個系統（路由層：排除高鐵時整條高鐵路線都不用） */
function appSystemAllowed($extra, $system)
{
    return $system !== 'THSR' || ($extra & APP_EXTRA_NO_HSR) === 0;
}

/** extra 的說明（回應的 meta 用） */
function appExtraInfo($extra)
{
    return ['extra' => $extra, 'NoExpress' => ($extra & APP_EXTRA_NO_EXPRESS) !== 0, 'NoHighSpeedRail' => ($extra & APP_EXTRA_NO_HSR) !== 0];
}

/** 等級的顯示名稱；沒有分快慢車或沒指定等級回 null */
function appClassLabel($system, $class)
{
    return $class === null ? null : (APP_CLASS_LABELS[$system][$class] ?? null);
}

/** 純區間車旅行的接力站（臺鐵；路由補充資料 TRA.LocalRelay 的 Meta.RelayStations） */
function appRelayStations()
{
    return appSupplementMeta('TRA.LocalRelay', 'TRA')['RelayStations'] ?? [];
}

/** 快慢車互換站（高鐵、機捷；路由補充資料 ClassChange 的 Meta.Stations。臺鐵是有同站換車且快車停靠的大站） */
function appClassChangeStations($system)
{
    return appSupplementMeta('ClassChange', $system)['Stations'] ?? [];
}
