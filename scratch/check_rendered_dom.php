<?php
require_once __DIR__ . '/../app/bootstrap.php';
$client = new class {
    public string $cookies = '';
    public function get($url) {
        $ch = curl_init('http://127.0.0.1:8080' . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($this->cookies) curl_setopt($ch, CURLOPT_COOKIE, $this->cookies);
        $res = curl_exec($ch);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdrs = substr($res, 0, $hdrSize);
        $body = substr($res, $hdrSize);
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $hdrs, $m)) {
            $this->cookies = implode('; ', $m[1]);
        }
        return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }
    public function post($url, $fields) {
        $ch = curl_init('http://127.0.0.1:8080' . $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        if ($this->cookies) curl_setopt($ch, CURLOPT_COOKIE, $this->cookies);
        $res = curl_exec($ch);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdrs = substr($res, 0, $hdrSize);
        $body = substr($res, $hdrSize);
        if (preg_match_all('/Set-Cookie:\s*([^;]+)/i', $hdrs, $m)) {
            $this->cookies = implode('; ', $m[1]);
        }
        return ['status' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => $body];
    }
};

$lp = $client->get('/staff/login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $lp['body'], $m);
$client->post('/staff/login', ['csrf_token' => $m[1], 'email' => 'superadmin@vetgrpet.local', 'password' => 'SuperAdmin!2026']);

echo "=== SUPER ADMIN DASHBOARD DOM ===\n";
$dash = $client->get('/super-admin');
preg_match('/<header class="topbar">.*?<\/header>/s', $dash['body'], $h);
echo $h[0] . "\n\n";
preg_match('/<div class="admin-workspace-banner">.*?<\/div>\s*<\/div>/s', $dash['body'], $b);
echo ($b[0] ?? 'NO BANNER') . "\n\n";
preg_match('/<nav class="admin-subnav".*?<\/nav>/s', $dash['body'], $s);
echo "Subnav exists? " . ($s[0] ? 'YES (DUPLICATE FOUND)' : 'NO (CORRECT, CONSOLIDATED)') . "\n\n";

echo "=== SUPER ADMIN SYSTEM-ADMINS DOM ===\n";
$sysadmins = $client->get('/super-admin/system-admins');
preg_match('/<nav aria-label="Main">(.*?)<\/nav>/s', $sysadmins['body'], $nav);
echo "TOPBAR NAV LINKS ON /super-admin/system-admins:\n" . trim($nav[1]) . "\n";

