<?php
declare(strict_types=1);

namespace Tests\Helpers;

/**
 * cURL wrapper para smoke tests HTTP.
 * Mantém cookie jar para session handling.
 */
class HttpSmokeClient
{
    private string $baseUrl;
    private string $cookieJar;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl   = rtrim($baseUrl ?? ($_ENV['TEST_BASE_URL'] ?? 'http://localhost/OKR_system'), '/');
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'okr_smoke_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    /**
     * Login via session (auth/auth_login.php)
     */
    public function sessionLogin(string $email, string $pass): array
    {
        return $this->post('/auth/auth_login.php', [
            'email'    => $email,
            'password' => $pass,
        ]);
    }

    /**
     * Login via API (api/api_platform/v1/auth/login.php)
     */
    public function apiLogin(string $email, string $pass): array
    {
        return $this->postJson('/api/api_platform/v1/auth/login.php', [
            'email'    => $email,
            'password' => $pass,
        ]);
    }

    /**
     * Extrai token CSRF de HTML
     */
    public function extractCsrf(string $html): ?string
    {
        if (preg_match('/name=["\']csrf_token["\'].*?value=["\']([^"\']+)["\']/i', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, [
            CURLOPT_POSTFIELDS => http_build_query($data),
        ]);
    }

    public function postJson(string $path, array $data = []): array
    {
        return $this->request('POST', $path, [
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
    }

    /**
     * @return array{http_code: int, body: string, json: ?array}
     */
    private function request(string $method, string $path, array $extraOpts = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'OKR-Smoke-Test/1.0',
        ] + $extraOpts);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        $body     = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['http_code' => 0, 'body' => "cURL error: $error", 'json' => null];
        }

        $json = json_decode($body, true);
        return [
            'http_code' => $httpCode,
            'body'      => $body,
            'json'      => (json_last_error() === JSON_ERROR_NONE) ? $json : null,
        ];
    }
}
