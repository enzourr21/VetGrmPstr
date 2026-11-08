<?php
require_once __DIR__ . '/../app/bootstrap.php';
$client = new class {
    public array $cookies = [];
    public function get($url) {
        return $this->send('GET', $url);
    }
    public function post($url, $fields) {
        return $this->send('POST', $url, $fields);
    }
    private function send($method, $path, $data = []) {
        $ch = curl_init('http://127.0.0.1:8080' . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        if (!empty($this->cookies)) {
            $cookieStr = [];
            foreach ($this->cookies as $k => $v) $cookieStr[] = "$k=$v";
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookieStr));
        }
        $raw = curl_exec($ch);
        $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $hdrs = substr($raw, 0, $hdrSize);
        $body = substr($raw, $hdrSize);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        foreach (explode("\r\n", $hdrs) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $p = explode(':', $line, 2);
                $kv = explode('=', trim(explode(';', $p[1])[0]), 2);
                $this->cookies[$kv[0]] = $kv[1] ?? '';
            }
        }
        return ['status' => $status, 'body' => $body];
    }
};

$l1 = $client->get('/staff/login');
preg_match('/name="csrf_token"\s+value="([^"]+)"/', $l1['body'], $csrf);
$post = $client->post('/staff/login', ['csrf_token' => $csrf[1] ?? '', 'email' => 'david.reyes@staff.sample.vetgrpet', 'password' => 'Staff123!']);
echo "Login status: " . $post['status'] . "\n";
$dash = $client->get('/manager');
echo "Dash status: " . $dash['status'] . "\n";
preg_match('/<header class="topbar">.*?<\/header>/s', $dash['body'], $hdr);
echo "BM TOPBAR HEADER:\n" . ($hdr[0] ?? 'NOT FOUND') . "\n";
