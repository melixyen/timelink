<?php
/**
 * 共用的 HTTP 工具：對外的 curl 呼叫、對前端的 JSON 回應。
 */

/** 呼叫逾時秒數 */
// HTTP_TIMEOUT、HTTP_CONNECT_TIMEOUT：設定項目（libs/config.json）

/**
 * 送出一次 HTTP 請求。
 *
 * @param string      $method  GET / HEAD / POST
 * @param string      $url     完整網址
 * @param string[]    $headers 例如 ['Authorization: Bearer xxx']
 * @param string|null $body    POST 內容
 * @return array{status:int, headers:array<string,string>, body:string, error:string|null}
 *         headers 的 key 是小寫欄位名，value 是完整的 "Name: value" 字串
 */
function httpRequest($method, $url, array $headers, $body = null)
{
    $respHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            if (strpos($line, 'HTTP/') === 0) {
                $respHeaders = [];
            } elseif (($pos = strpos($line, ':')) !== false) {
                $name = trim(substr($line, 0, $pos));
                $respHeaders[strtolower($name)] = $name . ': ' . trim(substr($line, $pos + 1));
            }
            return strlen($line);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $body);
    } elseif ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }

    $resBody = curl_exec($ch);
    $error = $resBody === false ? curl_error($ch) : null;
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [
        'status'  => $status,
        'headers' => $respHeaders,
        'body'    => $error === null ? $resBody : '',
        'error'   => $error,
    ];
}

/** 回一段 JSON 給前端後結束。錯誤訊息沿用 TDX 的 {"Message":"..."} 格式 */
function sendJson($status, array $data)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
