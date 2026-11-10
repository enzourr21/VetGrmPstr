<?php
$cookieFile = __DIR__ . '/test_cookies_debug.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function httpReq(string $method, string $path, array $postData = []): array {
    global $cookieFile;
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function getCsrfToken(string $html): string {
    if (preg_match('/name=["\']csrf_token["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

$r0 = httpReq('GET', '/login');
$c0 = getCsrfToken($r0['body']);
echo "Step 0 code: {$r0['code']}, CSRF: $c0\n";

$rDemo = httpReq('POST', '/demo/super-admin', ['csrf_token' => $c0]);
echo "Demo Super Admin code: {$rDemo['code']}\n";
if (preg_match('/^Location:\s*(.+)$/im', $rDemo['headers'], $m)) {
    echo "Location: {$m[1]}\n";
}

$sa = httpReq('GET', '/super-admin');
echo "Super Admin GET code: {$sa['code']}\n";
echo "Body snippet: " . substr(strip_tags($sa['body']), 0, 100) . "\n";
