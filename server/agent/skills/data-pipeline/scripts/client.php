<?php
/**
 * 本站的 HTTP 用戶端（給 agent/skills 底下的 PHP 腳本共用）。站址與憑證都從環境變數或參數讀，不寫在程式裡：
 *   TDX_BASE  站址（例如 https://api.example.com；預設 http://localhost）
 *   TDX_PAT   後臺 PAT（tdxp_ 開頭，Authorization: Bearer）；admin_ 開頭的 action 只接受 PAT
 * 只用 PHP 內建的 curl 擴充；代理與 CA 依環境變數（https_proxy、CURL_CA_BUNDLE／SSL_CERT_FILE）。
 *
 *   $site = new TdxSite(tdxBase(), tdxCredential());
 *   $j = $site->get(['action' => 'transfer', 'func' => 'accel']);           // GET /txapi/transfer/accel，不帶憑證
 *   $j = $site->get(['action' => 'admin_transfer', 'func' => 'learn'], true);   // 帶憑證（admin_ 開頭的 action）
 */

class TdxSite
{
    public $base;
    private $key;

    public function __construct($base, $key = null)
    {
        $this->base = rtrim($base, '/');
        $this->key = $key ?: null;
    }

    /** GET /txapi/<action>/<func>?其餘參數；回傳解析後的 JSON（陣列）。$admin = true 時帶 PAT；連線或格式錯誤丟例外 */
    public function get(array $params, $admin = false, $timeout = 300)
    {
        if ($admin && !$this->key) {
            throw new RuntimeException('需要後臺 PAT：請設定環境變數 TDX_PAT 或使用 --key=');
        }
        $action = (string) ($params['action'] ?? '');
        $func = (string) ($params['func'] ?? '');
        unset($params['action'], $params['func']);
        $qs = http_build_query($params);
        $url = $this->base . '/txapi/' . rawurlencode($action) . ($func !== '' ? '/' . rawurlencode($func) : '') . ($qs !== '' ? '?' . $qs : '');
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($admin) {
            $headers[] = 'Authorization: Bearer ' . $this->key;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'tdx-agent-script/1.0',
        ]);
        $ca = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
        if ($ca && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException("連線失敗：$err（$url）");
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            throw new RuntimeException("回應不是 JSON（HTTP $code）：" . mb_substr((string) $body, 0, 200));
        }
        $j['_http'] = $code;
        return $j;
    }
}

/**
 * 帶 PAT 下載檔案到 $dest（先寫 $dest.part，成功才改名）；回傳 ['http' => 狀態碼, 'headers' => [小寫名稱 => 值]]。
 * 狀態碼不是 200 時不留檔（錯誤內容放在 'body'）；連線失敗丟例外
 */
function tdxDownloadFile(TdxSite $site, $key, array $params, $dest, $timeout = 600)
{
    $action = (string) ($params['action'] ?? '');
    $func = (string) ($params['func'] ?? '');
    unset($params['action'], $params['func']);
    $url = $site->base . '/txapi/' . rawurlencode($action) . ($func !== '' ? '/' . rawurlencode($func) : '') . '?' . http_build_query($params);
    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }
    $part = $dest . '.part';
    $fh = fopen($part, 'wb');
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fh,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key],
        CURLOPT_USERAGENT      => 'tdx-agent-script/1.0',
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$headers) {
            $kv = explode(':', $line, 2);
            if (count($kv) === 2) {
                $headers[strtolower(trim($kv[0]))] = trim($kv[1]);
            }
            return strlen($line);
        },
    ]);
    $ca = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE');
    if ($ca && is_file($ca)) {
        curl_setopt($ch, CURLOPT_CAINFO, $ca);
    }
    $ok = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($ok === false) {
        @unlink($part);
        throw new RuntimeException("連線失敗：$err（$url）");
    }
    if ($code !== 200) {
        $body = (string) file_get_contents($part);
        @unlink($part);
        return ['http' => $code, 'headers' => $headers, 'body' => mb_substr($body, 0, 300)];
    }
    rename($part, $dest);
    return ['http' => $code, 'headers' => $headers];
}

/** 解析命令列：位置參數與 --name=value／--flag */
function tdxArgs(array $argv)
{
    $pos = [];
    $opt = [];
    foreach (array_slice($argv, 1) as $a) {
        if (strncmp($a, '--', 2) === 0) {
            $kv = explode('=', substr($a, 2), 2);
            $opt[$kv[0]] = $kv[1] ?? true;
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $opt];
}

/** 印一行帶時間的進度到 STDERR */
function tdxLog($msg)
{
    fwrite(STDERR, '[' . date('H:i:s') . '] ' . $msg . "\n");
}

/** 站址：--base= 參數 → 環境變數 TDX_BASE → http://localhost */
function tdxBase(array $opt = [])
{
    return is_string($opt['base'] ?? null) ? $opt['base'] : (getenv('TDX_BASE') ?: 'http://localhost');
}

/** 後臺 PAT：--key= 參數 → 環境變數 TDX_PAT */
function tdxCredential(array $opt = [])
{
    return is_string($opt['key'] ?? null) ? $opt['key'] : (getenv('TDX_PAT') ?: null);
}
