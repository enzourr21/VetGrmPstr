<?php
$cookieFile = __DIR__ . '/test_cookies.txt';

function httpReq(string $path): array {
    global $cookieFile;
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

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

$res = httpReq('/grooming/receptionist/overview');
echo "Overview response code: {$res['code']}\n";
if ($res['code'] !== 200) {
    echo "Body:\n" . substr($res['body'], 0, 500) . "\n";
}

$res2 = httpReq('/grooming/receptionist/appointments');
echo "Appointments response code: {$res2['code']}\n";
if ($res2['code'] !== 200) {
    echo "Body:\n" . substr($res2['body'], 0, 500) . "\n";
}

