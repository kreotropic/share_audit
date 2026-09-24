<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

/**
 * A browser, as far as Nextcloud can tell: it logs in through the login form,
 * keeps the session cookies and can send the CSRF token the way the frontend's
 * axios does. Requests go to the server's own Apache, so what is exercised is
 * the whole App Framework — routing, the SecurityMiddleware's login, admin and
 * CSRF checks, then the controller — and not a controller method called directly.
 */
final class HttpSession {

    private string $cookieJar;
    private ?string $requestToken = null;

    public function __construct(
        private string $baseUrl,
        private ?string $user = null,
        private ?string $password = null,
    ) {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'sad-http-');
        if ($user !== null) {
            $this->login();
        }
    }

    public function __destruct() {
        @unlink($this->cookieJar);
    }

    /**
     * The CSRF token of this session, or null for an anonymous one.
     */
    public function requestToken(): ?string {
        return $this->requestToken;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    public function get(string $path, array $query = [], bool $withToken = true): array {
        return $this->request('GET', $path, $query, null, $withToken);
    }

    /**
     * @param array<string, mixed> $body sent as JSON, like the frontend does
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    public function post(string $path, array $body = [], bool $withToken = true): array {
        return $this->request('POST', $path, [], $body, $withToken);
    }

    /**
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    public function delete(string $path, bool $withToken = true): array {
        return $this->request('DELETE', $path, [], null, $withToken);
    }

    /**
     * Send several requests AT THE SAME TIME, each on its own connection, and
     * wait for all of them: what two admins, or one double-clicking one, do.
     *
     * @param array<int, array{method: string, path: string, body?: array<string, mixed>}> $requests
     * @return array<int, array{status: int, body: string, json: mixed, headers: array<string, string>}>
     */
    public function parallel(array $requests): array {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($requests as $i => $r) {
            $handles[$i] = $this->handle($r['method'], $r['path'], [], $r['body'] ?? null, true);
            curl_multi_add_handle($multi, $handles[$i]);
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running && $status === CURLM_OK);

        $out = [];
        foreach ($handles as $i => $handle) {
            $out[$i] = $this->finish($handle, curl_multi_getcontent($handle));
            curl_multi_remove_handle($multi, $handle);
        }
        curl_multi_close($multi);
        return $out;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    private function request(string $method, string $path, array $query, ?array $body, bool $withToken): array {
        $handle = $this->handle($method, $path, $query, $body, $withToken);
        $raw = curl_exec($handle);
        $result = $this->finish($handle, $raw === false ? '' : $raw);
        return $result;
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function handle(string $method, string $path, array $query, ?array $body, bool $withToken): \CurlHandle {
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $headers = ['Accept: application/json'];
        if ($withToken && $this->requestToken !== null) {
            $headers[] = 'requesttoken: ' . $this->requestToken;
        }
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
            CURLOPT_TIMEOUT => 120,
        ]);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        return $handle;
    }

    /**
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    private function finish(\CurlHandle $handle, string $raw): array {
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $headerText = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $headers = [];
        foreach (explode("\r\n", $headerText) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }
        return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true), 'headers' => $headers];
    }

    /**
     * Log in through the login form and pick up the CSRF token the page then
     * carries — the same token the frontend reads from the document head.
     */
    private function login(): void {
        $page = $this->rawGet('/login');
        $token = $this->tokenFrom($page);
        $this->rawPost('/login', [
            'user' => $this->user,
            'password' => $this->password,
            'timezone' => 'UTC',
            'timezone_offset' => '0',
            'requesttoken' => $token,
        ]);
        // The token changes on login: read the new one from a page of the session.
        $this->requestToken = $this->tokenFrom($this->rawGet('/apps/files/'));
        if ($this->requestToken === null || $this->requestToken === '') {
            throw new \RuntimeException("Could not log in as {$this->user}: no session token on the page after the login form.");
        }
    }

    private function tokenFrom(string $html): ?string {
        return preg_match('/data-requesttoken="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
    }

    private function rawGet(string $path): string {
        $handle = curl_init(preg_replace('#/index\.php/apps/share_audit_dashboard$#', '', $this->baseUrl) . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieJar, CURLOPT_COOKIEFILE => $this->cookieJar, CURLOPT_TIMEOUT => 60,
        ]);
        $body = (string)curl_exec($handle);
        return $body;
    }

    /**
     * @param array<string, string|null> $fields
     */
    private function rawPost(string $path, array $fields): void {
        $server = preg_replace('#/index\.php/apps/share_audit_dashboard$#', '', $this->baseUrl);
        $handle = curl_init($server . $path);
        curl_setopt_array($handle, [
            // The login form refuses a POST that does not say where it came from.
            CURLOPT_HTTPHEADER => ['Origin: ' . $server, 'Referer: ' . $server . '/login'],
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR => $this->cookieJar, CURLOPT_COOKIEFILE => $this->cookieJar, CURLOPT_TIMEOUT => 60,
        ]);
        curl_exec($handle);
    }
}
