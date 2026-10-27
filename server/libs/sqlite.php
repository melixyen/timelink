<?php
/**
 * SQLite 資料存取元件（DAO）：所有功能要用資料庫都經過這裡，不直接操作檔案。
 *
 * 資料庫檔案放在網站根目錄的 sqlite/<名稱>.sqlite：
 *   - 由本元件建立目錄與檔案，並寫入 sqlite/.htaccess 禁止直接以網址存取
 *     （根目錄 .htaccess 也已把所有路徑交給 index.php，這是第二道防線）
 *   - sqlite/ 不需要備份、不進版本控制：資料庫內容都能由 download/ 等來源重建
 *
 * 用法：
 *   $db = SqliteDao::open('finder');                          // 讀寫；檔案不存在會建立
 *   $db = SqliteDao::open('finder', ['readonly' => true]);    // 唯讀；檔案不存在會丟例外
 *   $rows = $db->all('SELECT * FROM t WHERE a = ?', [1]);
 *   $row  = $db->row('SELECT * FROM t WHERE id = :id', ['id' => 5]);
 *   $n    = $db->value('SELECT COUNT(*) FROM t');
 *   $db->exec('DELETE FROM t WHERE a < ?', [3]);             // 回傳影響筆數
 *   $db->insert('t', ['a' => 1, 'b' => 'x']);                 // 回傳 rowid
 *   $db->insertMany('t', [['a' => 1], ['a' => 2]]);           // 單一交易、共用 prepared statement
 *   $db->transaction(function (SqliteDao $db) { ... });
 *   $db->meta('source_sig', 'abc');  $db->meta('source_sig'); // 內建 _meta 鍵值表
 *
 *   // 整份重建：寫進暫存檔，完成後一次替換正式檔，查詢中的連線不受影響
 *   SqliteDao::rebuild('finder', function (SqliteDao $db) { $db->exec('CREATE TABLE ...'); ... });
 *
 *   SqliteDao::exists('finder');  SqliteDao::drop('finder');  SqliteDao::listAll();
 *
 * 錯誤一律丟出 SqliteDaoException（包住 PDOException），呼叫端自行決定回應。
 */

require_once __DIR__ . '/env_path.php';
define('SQLITE_DIR', appEnvPath('sqlite'));   // 資料根目錄的 sqlite/（預設＝網站根目錄，見 libs/env_path.php）
// SQLITE_BUSY_TIMEOUT_MS（其他連線寫入時等待的上限）：設定項目（libs/config.json）

class SqliteDaoException extends RuntimeException
{
}

class SqliteDao
{
    /** @var PDO */
    private $pdo;
    /** @var string */
    private $name;
    /** @var string */
    private $file;
    /** @var bool */
    private $readonly;
    /** @var array<string, PDOStatement> */
    private $stmtCache = [];

    // ------------------------------------------------------------
    // 建立連線

    /**
     * 開啟 sqlite/<名稱>.sqlite。
     * $opts：readonly（預設 false）、create（預設 true；readonly 時一律不建立）
     */
    public static function open($name, array $opts = [])
    {
        $file = self::fileOf($name);
        $readonly = !empty($opts['readonly']);
        $create = !$readonly && ($opts['create'] ?? true);
        if (!is_file($file) && !$create) {
            throw new SqliteDaoException('SQLite database not found: ' . $name);
        }
        self::ensureDir();
        return new self($name, $file, $readonly);
    }

    private function __construct($name, $file, $readonly)
    {
        $this->name = $name;
        $this->file = $file;
        $this->readonly = $readonly;
        try {
            $opts = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => (int) ceil(SQLITE_BUSY_TIMEOUT_MS / 1000),
            ];
            if ($readonly && defined('PDO::SQLITE_ATTR_OPEN_FLAGS')) {
                $opts[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            }
            $this->pdo = new PDO('sqlite:' . $file, null, null, $opts);
            $this->pdo->exec('PRAGMA busy_timeout = ' . SQLITE_BUSY_TIMEOUT_MS);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            if (!$readonly) {
                @chmod($file, 0644);
            }
        } catch (PDOException $e) {
            throw new SqliteDaoException('Cannot open SQLite database ' . $name . ': ' . $e->getMessage(), 0, $e);
        }
    }

    public function name()
    {
        return $this->name;
    }

    /** 需要 PDO 進階功能時取用；一般情況請用下面的方法 */
    public function pdo()
    {
        return $this->pdo;
    }

    public function close()
    {
        $this->stmtCache = [];
        $this->pdo = null;
    }

    // ------------------------------------------------------------
    // 查詢與寫入

    /** 執行 SQL，回傳 PDOStatement；參數可用 ? 位置或 :名稱 */
    public function run($sql, array $params = [])
    {
        try {
            $st = $this->stmtCache[$sql] ?? ($this->stmtCache[$sql] = $this->pdo->prepare($sql));
            $st->closeCursor();
            foreach ($params as $k => $v) {
                $key = is_int($k) ? $k + 1 : (strpos($k, ':') === 0 ? $k : ':' . $k);
                $st->bindValue($key, $v, self::pdoType($v));
            }
            $st->execute();
            return $st;
        } catch (PDOException $e) {
            throw new SqliteDaoException($e->getMessage() . ' [' . $this->name . '] ' . $sql, 0, $e);
        }
    }

    /** 所有列（關聯陣列） */
    public function all($sql, array $params = [])
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** 第一列；沒有資料回 null */
    public function row($sql, array $params = [])
    {
        $st = $this->run($sql, $params);
        $r = $st->fetch();
        $st->closeCursor();
        return $r === false ? null : $r;
    }

    /** 第一列第一欄；沒有資料回 null */
    public function value($sql, array $params = [])
    {
        $st = $this->run($sql, $params);
        $v = $st->fetchColumn();
        $st->closeCursor();
        return $v === false ? null : $v;
    }

    /** 逐列讀取（不一次載入全部，適合大量資料） */
    public function each($sql, array $params, callable $fn)
    {
        $st = $this->run($sql, $params);
        while (($r = $st->fetch()) !== false) {
            if ($fn($r) === false) {
                break;
            }
        }
        $st->closeCursor();
    }

    /** 執行不回傳資料的 SQL；有參數時用 prepared statement，否則可一次執行多句。回傳影響筆數 */
    public function exec($sql, array $params = [])
    {
        if (!$params) {
            try {
                return (int) $this->pdo->exec($sql);
            } catch (PDOException $e) {
                throw new SqliteDaoException($e->getMessage() . ' [' . $this->name . '] ' . $sql, 0, $e);
            }
        }
        return $this->run($sql, $params)->rowCount();
    }

    /** 新增一列，回傳 rowid */
    public function insert($table, array $row)
    {
        $this->run(self::insertSql($table, array_keys($row)), array_values($row));
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 大量新增：同一交易、同一個 prepared statement；各列欄位以第一列為準。
     * $mode：'INSERT'、'INSERT OR REPLACE'、'INSERT OR IGNORE'。回傳筆數
     */
    public function insertMany($table, array $rows, $mode = 'INSERT')
    {
        if (!$rows) {
            return 0;
        }
        $cols = array_keys(reset($rows));
        $sql = self::insertSql($table, $cols, $mode);
        $n = 0;
        $this->transaction(function () use ($rows, $cols, $sql, &$n) {
            foreach ($rows as $r) {
                $vals = [];
                foreach ($cols as $c) {
                    $vals[] = $r[$c] ?? null;
                }
                $this->run($sql, $vals);
                $n++;
            }
        });
        return $n;
    }

    /** 在交易中執行；丟出例外時自動回滾。可巢狀呼叫（內層併入外層交易） */
    public function transaction(callable $fn)
    {
        if ($this->pdo->inTransaction()) {
            return $fn($this);
        }
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e instanceof SqliteDaoException ? $e : new SqliteDaoException($e->getMessage(), 0, $e);
        }
    }

    public function tableExists($table)
    {
        return $this->value("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]) !== null;
    }

    /**
     * 內建鍵值表 _meta（記錄來源版本、建立時間等）。
     * meta($key) 讀取（沒有回 null）；meta($key, $value) 寫入；值存成 JSON。
     */
    public function meta($key, $value = null)
    {
        if (func_num_args() === 1) {
            if (!$this->tableExists('_meta')) {
                return null;
            }
            $v = $this->value('SELECT value FROM _meta WHERE key = ?', [$key]);
            return $v === null ? null : json_decode($v, true);
        }
        $this->exec('CREATE TABLE IF NOT EXISTS _meta (key TEXT PRIMARY KEY, value TEXT)');
        $this->run('INSERT OR REPLACE INTO _meta (key, value) VALUES (?, ?)',
                   [$key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        return $value;
    }

    // ------------------------------------------------------------
    // 資料庫檔案管理

    /**
     * 整份重建：在暫存檔上執行 $build，成功後一次替換 sqlite/<名稱>.sqlite。
     * 替換是 rename，已開啟舊檔的查詢照常完成，新的連線讀到新檔；失敗時舊檔不受影響。
     * 回傳 $build 的回傳值。
     */
    public static function rebuild($name, callable $build)
    {
        $file = self::fileOf($name);
        self::ensureDir();
        foreach (glob($file . '.build-*') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {   // 清掉一小時前中斷留下的暫存檔
                @unlink($old);
            }
        }
        $tmp = $file . '.build-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $db = new self($name, $tmp, false);
        try {
            $db->exec('PRAGMA journal_mode = OFF');   // 暫存檔失敗就整份丟掉，不需要日誌
            $db->exec('PRAGMA synchronous = OFF');
            $result = $build($db);
            $db->meta('_built_at', gmdate('c'));
            $db->close();
            if (!@rename($tmp, $file)) {
                throw new SqliteDaoException('Cannot replace SQLite database: ' . $name);
            }
            @chmod($file, 0644);
            return $result;
        } catch (Throwable $e) {
            $db->close();
            @unlink($tmp);
            throw $e instanceof SqliteDaoException ? $e : new SqliteDaoException($e->getMessage(), 0, $e);
        }
    }

    /**
     * 以 sqlite/<名稱>.lock 做互斥鎖執行 $fn（例如重建時避免多個請求同時重建）。
     * 會等到取得鎖為止；回傳 $fn 的回傳值，例外照常丟出，鎖一定會釋放。
     */
    public static function lock($name, callable $fn)
    {
        self::fileOf($name);   // 驗證名稱
        self::ensureDir();
        $fp = @fopen(SQLITE_DIR . '/' . $name . '.lock', 'c');
        if (!$fp) {
            throw new SqliteDaoException('Cannot open lock file for ' . $name);
        }
        try {
            flock($fp, LOCK_EX);
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function exists($name)
    {
        return is_file(self::fileOf($name));
    }

    /** 刪除資料庫檔（連同日誌檔）；回傳是否有刪到 */
    public static function drop($name)
    {
        $file = self::fileOf($name);
        $had = is_file($file);
        foreach ([$file, $file . '-journal', $file . '-wal', $file . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        return $had;
    }

    /** 列出所有資料庫：名稱、大小、修改時間（UTC ISO 8601） */
    public static function listAll()
    {
        $out = [];
        foreach (glob(SQLITE_DIR . '/*.sqlite') ?: [] as $f) {
            $out[] = ['name' => basename($f, '.sqlite'), 'size' => filesize($f), 'modified' => gmdate('c', filemtime($f))];
        }
        return $out;
    }

    // ------------------------------------------------------------
    // 內部工具

    /** 名稱只允許英數、底線、減號，避免路徑穿越 */
    private static function fileOf($name)
    {
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            throw new SqliteDaoException('Invalid SQLite database name: ' . (is_string($name) ? $name : gettype($name)));
        }
        return SQLITE_DIR . '/' . $name . '.sqlite';
    }

    /** 建立 sqlite/ 並寫入禁止存取的 .htaccess */
    private static function ensureDir()
    {
        if (!is_dir(SQLITE_DIR) && !@mkdir(SQLITE_DIR, 0755, true) && !is_dir(SQLITE_DIR)) {
            throw new SqliteDaoException('Cannot create directory sqlite/');
        }
        if (!is_file(SQLITE_DIR . '/.htaccess')) {
            @file_put_contents(SQLITE_DIR . '/.htaccess', "Require all denied\n");
        }
    }

    private static function ident($name)
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
            throw new SqliteDaoException('Invalid SQL identifier: ' . $name);
        }
        return '"' . $name . '"';
    }

    private static function insertSql($table, array $cols, $mode = 'INSERT')
    {
        if (!in_array($mode, ['INSERT', 'INSERT OR REPLACE', 'INSERT OR IGNORE'], true)) {
            throw new SqliteDaoException('Invalid insert mode: ' . $mode);
        }
        if (!$cols) {
            throw new SqliteDaoException('insert needs at least one column');
        }
        return $mode . ' INTO ' . self::ident($table) . ' (' . implode(', ', array_map([self::class, 'ident'], $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    }

    private static function pdoType($v)
    {
        if (is_int($v)) {
            return PDO::PARAM_INT;
        }
        if (is_bool($v)) {
            return PDO::PARAM_BOOL;
        }
        if ($v === null) {
            return PDO::PARAM_NULL;
        }
        return PDO::PARAM_STR;
    }
}
