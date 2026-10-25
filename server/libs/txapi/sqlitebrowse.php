<?php
/**
 * /txapi/sqlite：SQLite 資料庫唯讀瀏覽（給監控面板 /panel/#/sqlite）。
 *
 *   func=help（省略時）   Markdown 說明
 *   func=tables           資料庫與資料表清單（筆數、說明）；帶 db 只列該資料庫
 *   func=schema           db、table：欄位、索引、關聯、CREATE 語法
 *   func=rows             db、table：篩選、排序、後端分頁後的資料，連同 schema
 *
 * 安全（只讀、防 SQL Injection）：
 *   - 只接受 GET；資料庫以 SQLITE_OPEN_READONLY 開啟，再加 PRAGMA query_only
 *   - 不接受任何 SQL 片段：資料庫名稱須在 sqlite/ 目錄清單內，資料表須在 sqlite_master，
 *     欄位須在 pragma_table_info，運算子與排序方向只能是白名單內的值
 *   - 識別字只在通過白名單後才加雙引號拼進 SQL；所有使用者輸入的值一律用參數綁定
 *   - 每頁筆數只能 10、50、100、500；篩選條件最多 SQLITEBROWSE_MAX_FILTERS 個，值最長 SQLITEBROWSE_MAX_VALUE 字
 */

const SQLITEBROWSE_PER = [10, 50, 100, 500];
const SQLITEBROWSE_DEFAULT_PER = 100;
const SQLITEBROWSE_MAX_FILTERS = 10;
const SQLITEBROWSE_MAX_VALUE = 200;

/** 篩選運算子 → 說明、是否需要值 */
const SQLITEBROWSE_OPS = [
    'eq'       => ['等於', true],
    'ne'       => ['不等於', true],
    'contains' => ['包含', true],
    'prefix'   => ['開頭是', true],
    'suffix'   => ['結尾是', true],
    'gt'       => ['大於', true],
    'gte'      => ['大於等於', true],
    'lt'       => ['小於', true],
    'lte'      => ['小於等於', true],
    'empty'    => ['是空的', false],
    'notempty' => ['不是空的', false],
];

/** 資料庫名稱 → 提供說明與關聯的函式（沒有登記的資料庫仍可瀏覽，只是沒有說明） */
const SQLITEBROWSE_DOCS = [
    'finder' => 'finderDbBrowseDocs',
];

class SqliteBrowseError extends RuntimeException
{
}

function actionSqlite()
{
    header('Cache-Control: no-store');
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        sendJson(405, ['ok' => false, 'error' => 'Read-only: only GET is allowed']);
    }
    $func = strtolower(trim((string) ($_GET['func'] ?? '')));
    if ($func === '' || $func === 'help') {
        sqliteBrowseHelp();
    }
    if (!in_array($func, ['tables', 'schema', 'rows'], true)) {
        sendJson(400, ['ok' => false, 'error' => 'Unknown func: ' . $func,
                       'funcs' => ['help', 'tables', 'schema', 'rows'], 'help' => '/txapi/sqlite']);
    }
    @set_time_limit(TIME_LIMIT_SQLITE_BROWSE);
    try {
        if ($func === 'tables') {
            $names = isset($_GET['db']) ? [sqliteBrowseDbName($_GET['db'])] : array_column(SqliteDao::listAll(), 'name');
            $dbs = [];
            foreach ($names as $name) {
                $dbs[] = sqliteBrowseDbInfo($name);
            }
            sendJson(200, ['ok' => true, 'action' => 'sqlite', 'func' => 'tables', 'dbs' => $dbs]);
        }
        $name = sqliteBrowseDbName($_GET['db'] ?? '');
        $db = sqliteBrowseOpen($name);
        $table = sqliteBrowseTable($db, $_GET['table'] ?? '');
        $docs = sqliteBrowseDocs($name);
        $schema = sqliteBrowseSchema($db, $table, $docs);
        if ($func === 'schema') {
            sendJson(200, ['ok' => true, 'action' => 'sqlite', 'func' => 'schema', 'db' => $name] + $schema);
        }
        $out = sqliteBrowseRows($db, $table, $schema, $_GET);
        $db->close();
        sendJson(200, ['ok' => true, 'action' => 'sqlite', 'func' => 'rows', 'db' => $name] + $out + ['schema' => $schema]);
    } catch (SqliteBrowseError $e) {
        sendJson(400, ['ok' => false, 'error' => $e->getMessage()]);
    } catch (SqliteDaoException $e) {
        sendJson(500, ['ok' => false, 'error' => 'SQLite error: ' . $e->getMessage()]);
    }
}

// ================================================================
// 白名單驗證

/** 資料庫名稱：格式正確且確實存在於 sqlite/ */
function sqliteBrowseDbName($name)
{
    $name = is_string($name) ? trim($name) : '';
    if ($name === '' || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)
        || !in_array($name, array_column(SqliteDao::listAll(), 'name'), true)) {
        throw new SqliteBrowseError('Unknown database: ' . sqliteBrowseClip($name));
    }
    return $name;
}

function sqliteBrowseOpen($name)
{
    $db = SqliteDao::open($name, ['readonly' => true]);
    $db->exec('PRAGMA query_only = ON');   // 就算開檔旗標失效也無法寫入
    return $db;
}

/** 使用者看得到的資料表（不含 sqlite_ 內部表），依說明登記的順序排列 */
function sqliteBrowseTableNames(SqliteDao $db, array $docs = [])
{
    $names = array_column($db->all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name"), 'name');
    $order = array_flip(array_keys($docs));
    usort($names, function ($a, $b) use ($order) {
        $oa = $order[$a] ?? PHP_INT_MAX;
        $ob = $order[$b] ?? PHP_INT_MAX;
        return $oa === $ob ? strcmp($a, $b) : $oa <=> $ob;
    });
    return $names;
}

/** 資料表名稱須完全等於 sqlite_master 內的一筆 */
function sqliteBrowseTable(SqliteDao $db, $table)
{
    $table = is_string($table) ? $table : '';
    if (!in_array($table, sqliteBrowseTableNames($db), true)) {
        throw new SqliteBrowseError('Unknown table: ' . sqliteBrowseClip($table));
    }
    return $table;
}

/** 識別字加引號。只用在已通過白名單的名稱上 */
function sqliteBrowseQuote($name)
{
    return '"' . str_replace('"', '""', $name) . '"';
}

function sqliteBrowseClip($s)
{
    $s = (string) $s;
    return mb_strlen($s) > 64 ? mb_substr($s, 0, 64) . '…' : $s;
}

// ================================================================
// 清單與結構

function sqliteBrowseDocs($name)
{
    $fn = SQLITEBROWSE_DOCS[$name] ?? null;
    return $fn && function_exists($fn) ? $fn() : [];
}

function sqliteBrowseDbInfo($name)
{
    $file = SQLITE_DIR . '/' . $name . '.sqlite';
    $docs = sqliteBrowseDocs($name);
    $db = sqliteBrowseOpen($name);
    $builtAt = null;
    if ($db->tableExists('_meta')) {
        $builtAt = $db->meta('built_at');
    }
    $tables = [];
    foreach (sqliteBrowseTableNames($db, $docs) as $t) {
        $tables[] = [
            'name'    => $t,
            'desc'    => $docs[$t]['desc'] ?? '',
            'rows'    => (int) $db->value('SELECT COUNT(*) FROM ' . sqliteBrowseQuote($t)),
            'columns' => (int) $db->value('SELECT COUNT(*) FROM pragma_table_info(?)', [$t]),
        ];
    }
    $db->close();
    return ['name' => $name, 'size' => filesize($file), 'modified' => downloadFmtTime(filemtime($file)),
            'built_at' => $builtAt, 'tables' => $tables];
}

/** 欄位型別的親和性（決定比較時要不要把值轉成數字） */
function sqliteBrowseAffinity($type)
{
    $t = strtoupper((string) $type);
    if (strpos($t, 'INT') !== false) {
        return 'INTEGER';
    }
    if (strpos($t, 'CHAR') !== false || strpos($t, 'CLOB') !== false || strpos($t, 'TEXT') !== false) {
        return 'TEXT';
    }
    if ($t === '' || strpos($t, 'BLOB') !== false) {
        return 'BLOB';
    }
    if (strpos($t, 'REAL') !== false || strpos($t, 'FLOA') !== false || strpos($t, 'DOUB') !== false) {
        return 'REAL';
    }
    return 'NUMERIC';
}

/**
 * 欄位（名稱、型別、主鍵、說明、關聯）、索引、被參照、CREATE 語法。
 * 關聯來源：說明登記的 relations／json，以及資料庫本身的 FOREIGN KEY。目標表不存在的關聯略過。
 */
function sqliteBrowseSchema(SqliteDao $db, $table, array $docs)
{
    $tables = sqliteBrowseTableNames($db, $docs);
    $doc = $docs[$table] ?? [];

    // 所有關聯：[來源表, 來源欄位, 目標表, 目標欄位]
    $links = [];
    foreach ($docs as $t => $d) {
        foreach ($d['relations'] ?? [] as $col => $to) {
            $links[] = [$t, $col, $to[0], $to[1], $to[2] ?? []];   // 第 3 項：目標欄位 => 來源欄位（複合鍵）
        }
    }
    foreach ($tables as $t) {
        foreach ($db->all('SELECT "table" AS target, "from" AS col, "to" AS tcol FROM pragma_foreign_key_list(?)', [$t]) as $fk) {
            $links[] = [$t, $fk['col'], $fk['target'], $fk['tcol'], []];
        }
    }

    $columns = [];
    $pk = [];
    foreach ($db->all('SELECT cid, name, type, "notnull" AS nn, dflt_value, pk FROM pragma_table_info(?) ORDER BY cid', [$table]) as $c) {
        $col = [
            'name'     => $c['name'],
            'type'     => $c['type'],
            'affinity' => sqliteBrowseAffinity($c['type']),
            'notnull'  => (bool) $c['nn'],
            'default'  => $c['dflt_value'],
            'pk'       => (int) $c['pk'],
            'desc'     => $doc['columns'][$c['name']] ?? '',
            'ref'      => null,     // 這一欄指向：{table, column, with}；column 為 null 表示值本身是資料表名稱；with＝{目標表欄位: 本表欄位}（複合鍵的其他欄位）
            'json'     => null,     // JSON 內的鍵 → {table, column}
            'refby'    => [],       // 參照這一欄的：[{table, column, with}]；with＝{對方表欄位: 本表欄位}
        ];
        if ($c['pk']) {
            $pk[$c['pk']] = $c['name'];
        }
        foreach ($links as [$ft, $fc, $tt, $tc, $with]) {
            if ($ft === $table && $fc === $c['name']) {
                if ($tt === '' && $tc === null) {
                    $col['ref'] = ['table' => '', 'column' => null, 'with' => (object) []];
                } elseif (in_array($tt, $tables, true)) {
                    $col['ref'] = ['table' => $tt, 'column' => $tc, 'with' => (object) $with];
                }
            }
            if ($tt === $table && $tc === $c['name'] && in_array($ft, $tables, true)) {
                $col['refby'][] = ['table' => $ft, 'column' => $fc, 'with' => (object) array_flip($with)];
            }
        }
        foreach ($doc['json'][$c['name']] ?? [] as $key => $to) {
            if (in_array($to[0], $tables, true)) {
                $col['json'][$key] = ['table' => $to[0], 'column' => $to[1]];
            }
        }
        $columns[] = $col;
    }
    ksort($pk);

    $indexes = [];
    foreach ($db->all('SELECT name, "unique" AS uq, origin FROM pragma_index_list(?)', [$table]) as $ix) {
        $indexes[] = [
            'name'    => $ix['name'],
            'unique'  => (bool) $ix['uq'],
            'origin'  => $ix['origin'],   // c＝CREATE INDEX、pk＝主鍵、u＝UNIQUE
            'columns' => array_column($db->all('SELECT name FROM pragma_index_info(?) ORDER BY seqno', [$ix['name']]), 'name'),
        ];
    }
    $sql = (string) $db->value("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);

    return [
        'table'   => $table,
        'desc'    => $doc['desc'] ?? '',
        'rows'    => (int) $db->value('SELECT COUNT(*) FROM ' . sqliteBrowseQuote($table)),
        'pk'      => array_values($pk),
        'rowid'   => stripos($sql, 'WITHOUT ROWID') === false,
        'columns' => $columns,
        'indexes' => $indexes,
        'sql'     => $sql,
        'ops'     => array_map(function ($o) { return ['label' => $o[0], 'needs_value' => $o[1]]; }, SQLITEBROWSE_OPS),
        'per'     => SQLITEBROWSE_PER,
    ];
}

// ================================================================
// 資料

/**
 * 篩選：f[]=欄位:運算子:值（值可含冒號）；q＝所有欄位包含關鍵字；sort＝欄位；dir＝asc|desc；page；per
 */
function sqliteBrowseRows(SqliteDao $db, $table, array $schema, array $in)
{
    $cols = [];
    foreach ($schema['columns'] as $c) {
        $cols[$c['name']] = $c;
    }
    $where = [];
    $params = [];

    // 篩選條件
    $raw = $in['f'] ?? [];
    $raw = is_array($raw) ? array_values($raw) : [$raw];
    if (count($raw) > SQLITEBROWSE_MAX_FILTERS) {
        throw new SqliteBrowseError('Too many filters (max ' . SQLITEBROWSE_MAX_FILTERS . ')');
    }
    $filters = [];
    foreach ($raw as $f) {
        if (!is_string($f) || $f === '') {
            continue;
        }
        $parts = explode(':', $f, 3);
        $col = $parts[0];
        $op = strtolower($parts[1] ?? '');
        $val = $parts[2] ?? '';
        if (!isset($cols[$col])) {
            throw new SqliteBrowseError('Unknown column: ' . sqliteBrowseClip($col));
        }
        if (!isset(SQLITEBROWSE_OPS[$op])) {
            throw new SqliteBrowseError('Unknown operator: ' . sqliteBrowseClip($op));
        }
        if (mb_strlen($val) > SQLITEBROWSE_MAX_VALUE) {
            throw new SqliteBrowseError('Filter value too long (max ' . SQLITEBROWSE_MAX_VALUE . ' characters)');
        }
        $q = sqliteBrowseQuote($col);
        $bind = sqliteBrowseBindValue($val, $cols[$col]['affinity']);
        switch ($op) {
            case 'eq':       $where[] = "$q = ?";  $params[] = $bind; break;
            case 'ne':       $where[] = "($q IS NULL OR $q <> ?)"; $params[] = $bind; break;
            case 'gt':       $where[] = "$q > ?";  $params[] = $bind; break;
            case 'gte':      $where[] = "$q >= ?"; $params[] = $bind; break;
            case 'lt':       $where[] = "$q < ?";  $params[] = $bind; break;
            case 'lte':      $where[] = "$q <= ?"; $params[] = $bind; break;
            case 'contains': $where[] = "CAST($q AS TEXT) LIKE ? ESCAPE '\\'"; $params[] = '%' . sqliteBrowseLike($val) . '%'; break;
            case 'prefix':   $where[] = "CAST($q AS TEXT) LIKE ? ESCAPE '\\'"; $params[] = sqliteBrowseLike($val) . '%'; break;
            case 'suffix':   $where[] = "CAST($q AS TEXT) LIKE ? ESCAPE '\\'"; $params[] = '%' . sqliteBrowseLike($val); break;
            case 'empty':    $where[] = "($q IS NULL OR $q = '')"; $val = ''; break;
            case 'notempty': $where[] = "($q IS NOT NULL AND $q <> '')"; $val = ''; break;
        }
        $filters[] = ['column' => $col, 'op' => $op, 'value' => $val];
    }

    // 全欄位關鍵字
    $kw = isset($in['q']) && is_string($in['q']) ? trim($in['q']) : '';
    if (mb_strlen($kw) > SQLITEBROWSE_MAX_VALUE) {
        throw new SqliteBrowseError('Keyword too long (max ' . SQLITEBROWSE_MAX_VALUE . ' characters)');
    }
    if ($kw !== '') {
        $or = [];
        foreach ($cols as $name => $c) {
            $or[] = 'CAST(' . sqliteBrowseQuote($name) . " AS TEXT) LIKE ? ESCAPE '\\'";
            $params[] = '%' . sqliteBrowseLike($kw) . '%';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }

    // 排序：只接受欄位清單內的名稱；最後補上主鍵（或 rowid）讓分頁穩定
    $sort = isset($in['sort']) && is_string($in['sort']) ? $in['sort'] : '';
    if ($sort !== '' && !isset($cols[$sort])) {
        throw new SqliteBrowseError('Unknown sort column: ' . sqliteBrowseClip($sort));
    }
    $dir = strtolower(is_string($in['dir'] ?? null) ? $in['dir'] : 'asc') === 'desc' ? 'DESC' : 'ASC';
    $order = [];
    if ($sort !== '') {
        $order[] = sqliteBrowseQuote($sort) . ' ' . $dir;
    }
    $tie = $schema['pk'] ?: ($schema['rowid'] ? ['rowid'] : array_keys($cols));
    foreach ($tie as $c) {
        if ($c !== $sort) {
            $order[] = ($c === 'rowid' ? 'rowid' : sqliteBrowseQuote($c)) . ' ASC';
        }
    }

    // 分頁
    $per = (int) ($in['per'] ?? SQLITEBROWSE_DEFAULT_PER);
    if (!in_array($per, SQLITEBROWSE_PER, true)) {
        $per = SQLITEBROWSE_DEFAULT_PER;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $from = ' FROM ' . sqliteBrowseQuote($table) . $whereSql;
    $total = (int) $db->value('SELECT COUNT(*)' . $from, $params);
    $pages = max(1, (int) ceil($total / $per));
    $page = min(max(1, (int) ($in['page'] ?? 1)), $pages);

    $select = implode(', ', array_map('sqliteBrowseQuote', array_keys($cols)));
    $sql = 'SELECT ' . $select . $from . ' ORDER BY ' . implode(', ', $order) . ' LIMIT ? OFFSET ?';
    $rows = [];
    $db->each($sql, array_merge($params, [$per, ($page - 1) * $per]), function ($r) use (&$rows) {
        $out = [];
        foreach ($r as $v) {
            if (is_string($v) && !preg_match('//u', $v)) {
                $v = '[BLOB ' . strlen($v) . ' bytes]';
            }
            $out[] = $v;
        }
        $rows[] = $out;
    });

    return [
        'table'   => $table,
        'filters' => $filters,
        'q'       => $kw,
        'sort'    => $sort,
        'dir'     => strtolower($dir),
        'page'    => $page,
        'per'     => $per,
        'pages'   => $pages,
        'total'   => $total,
        'from'    => $total ? ($page - 1) * $per + 1 : 0,
        'to'      => ($page - 1) * $per + count($rows),
        'columns' => array_keys($cols),
        'rows'    => $rows,
    ];
}

/** 數值欄位的比較值轉成數字，讓 > < 以數值比較 */
function sqliteBrowseBindValue($val, $affinity)
{
    if (in_array($affinity, ['INTEGER', 'REAL', 'NUMERIC'], true) && is_numeric($val)) {
        return preg_match('/^-?\d+$/', $val) ? (int) $val : (float) $val;
    }
    return (string) $val;
}

/** LIKE 的萬用字元當一般字元 */
function sqliteBrowseLike($s)
{
    return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
}

// ================================================================
// 說明文件

function sqliteBrowseHelp()
{
    $host = $_SERVER['HTTP_HOST'] ?? SITE_HOST;
    $ops = '';
    foreach (SQLITEBROWSE_OPS as $k => $o) {
        $ops .= "| `{$k}` | {$o[0]}" . ($o[1] ? '' : '（不需要值）') . " |\n";
    }
    $per = implode('、', SQLITEBROWSE_PER);
    $def = SQLITEBROWSE_DEFAULT_PER;
    $maxF = SQLITEBROWSE_MAX_FILTERS;
    $maxV = SQLITEBROWSE_MAX_VALUE;
    $md = <<<MD
# /txapi/sqlite：SQLite 資料庫唯讀瀏覽

監控面板 `/panel/#/sqlite` 的資料來源。只能讀取 `sqlite/` 內的資料庫，不能寫入，也不接受 SQL 語法。

| func | 說明 |
| --- | --- |
| `help` | 本說明（省略 func 時相同） |
| `tables` | 資料庫與資料表清單（筆數、欄位數、說明）；帶 `db` 只列該資料庫 |
| `schema` | `db`、`table`：欄位（型別、主鍵、說明、關聯）、索引、被哪些欄位參照、CREATE 語法 |
| `rows` | `db`、`table`：篩選、排序、分頁後的資料（`columns` ＋ `rows` 二維陣列），連同 `schema` |

```
GET https://{$host}/txapi/sqlite/tables
GET https://{$host}/txapi/sqlite/rows?db=finder&table=station&f[]=system:eq:TRA&sort=station_id&per=50
GET https://{$host}/txapi/sqlite/rows?db=finder&table=train_stop&f[]=train_key:eq:TRA-123
```

## func=rows 參數

| 參數 | 說明 |
| --- | --- |
| `f[]` | 篩選條件 `欄位:運算子:值`（值可含冒號），可重複，最多 {$maxF} 個，全部都要符合 |
| `q` | 關鍵字，任一欄位包含即符合 |
| `sort`、`dir` | 排序欄位，`asc`（預設）或 `desc`；之後一律再依主鍵排序，分頁結果穩定 |
| `page` | 頁碼，從 1 起，超出範圍時取最接近的一頁 |
| `per` | 每頁筆數：{$per}，預設 {$def} |

| 運算子 | 說明 |
| --- | --- |
{$ops}
- 值最長 {$maxV} 字；`contains`、`prefix`、`suffix` 的 `%`、`_` 當一般字元，英文不分大小寫
- 數值欄位的 `gt`、`lt` 等以數值比較，文字欄位以字串比較（`HH:MM` 時刻可直接比較）

## 安全

- 只接受 GET；資料庫以唯讀旗標開啟，並設定 `PRAGMA query_only`
- 資料庫、資料表、欄位名稱都必須存在於實際的資料庫結構中，運算子與排序方向只能是固定的值；
  使用者輸入的值一律以參數綁定，不會拼進 SQL
- 瀏覽不會觸發重建，看到的是目前資料庫的內容；`sqlite/` 本身禁止網址直接存取
MD;
    http_response_code(200);
    header('Content-Type: text/markdown; charset=utf-8');
    echo $md;
    exit;
}
