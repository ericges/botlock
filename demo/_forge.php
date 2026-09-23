<?php declare(strict_types=1);

/**
 * Request forging relay of the demo:
 *
 *     /_forge.php/<identity>/protected/<path>?<query>
 *
 * relays the request to http://localhost/protected/<path>?<query> inside
 * the container, with the identity's User-Agent, X-Forwarded-For,
 * Accept-Language and extra headers. The direct peer is loopback, a
 * trusted proxy by default, so BOTLOCK takes the forged address as the
 * client IP. Host and X-Forwarded-Proto are passed on so BOTLOCK sees the
 * visitor's origin.
 *
 * The browser keeps each identity's BOTLOCK cookies under a prefixed name
 * scoped to the identity's path, so the challenge page loaded through the
 * relay can be solved in the browser for the forged identity without
 * touching the browser's own session. Every exchange is logged for the
 * panel. The relay lies outside the protected area and only reaches it.
 */

use GES\Botlock\Demo\ForgeLog;
use GES\Botlock\Demo\Identity;
use GES\Botlock\Demo\Settings;

require_once __DIR__ . '/_lib/Settings.php';
require_once __DIR__ . '/_lib/Identity.php';
require_once __DIR__ . '/_lib/ForgeLog.php';

const LOG_BODY_BYTES = 4096;
const SOURCES = ['frame', 'status', 'burst', 'reset'];

/** Response headers that describe the upstream connection, not the resource. */
const HOP_HEADERS = ['connection', 'keep-alive', 'transfer-encoding', 'content-length', 'content-encoding', 'upgrade'];

$fail = static function (int $status, string $message): never {
    \http_response_code($status);
    \header('Content-Type: text/plain; charset=utf-8');
    exit($message);
};

/** Shortens long cookie values (session JWTs) in logged header lines. */
$shorten = static fn(string $line): string => (string) \preg_replace('/(=)([A-Za-z0-9._-]{24})[A-Za-z0-9._-]+/', '$1$2…', $line);

$path = (string) \parse_url($_SERVER['REQUEST_URI'] ?? '', \PHP_URL_PATH);

if (!\preg_match('#^/_forge\.php/([A-Za-z0-9_-]+)/(.*)$#', $path, $match)) {
    $fail(400, 'Expected /_forge.php/<identity>/protected/...');
}

[, $id, $rest] = $match;

try {
    $identity = Identity::decode($id);
} catch (\InvalidArgumentException $e) {
    $fail(400, $e->getMessage());
}

if (!\str_starts_with($rest, 'protected/') || \str_contains($rest, '..')) {
    $fail(404, 'The relay only reaches /protected/');
}

$prefix = Identity::cookiePrefix($id);
$base = Identity::basePath($id);
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$target = '/' . $rest . ($query !== '' ? '?' . $query : '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$source = \in_array($_SERVER['HTTP_X_DEMO_SOURCE'] ?? '', SOURCES, true) ? $_SERVER['HTTP_X_DEMO_SOURCE'] : 'frame';
$secure = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$origin = ($secure ? 'https' : 'http') . '://' . $host;

// --- Upstream request headers ---

$headers = [
    'Host: ' . $host,
    'X-Forwarded-Proto: ' . ($secure ? 'https' : 'http'),
    'User-Agent: ' . ($identity->ua !== '' ? $identity->ua : (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
];

foreach (['Accept' => 'HTTP_ACCEPT', 'Content-Type' => 'CONTENT_TYPE', 'Botlock-Nonce' => 'HTTP_BOTLOCK_NONCE'] as $name => $key) {
    if (isset($_SERVER[$key]) && \is_string($_SERVER[$key]) && !\preg_match('/[\r\n]/', $_SERVER[$key])) {
        $headers[] = $name . ': ' . $_SERVER[$key];
    }
}

if ($identity->lang !== '') {
    $headers[] = 'Accept-Language: ' . $identity->lang;
}

if ($identity->ip !== '') {
    $headers[] = 'X-Forwarded-For: ' . $identity->ip;
}

$cookies = [];
foreach ($_COOKIE as $name => $value) {
    if (\is_string($value) && \str_starts_with((string) $name, $prefix)) {
        $cookies[] = \substr((string) $name, \strlen($prefix)) . '=' . $value;
    }
}

if ($cookies) {
    $headers[] = 'Cookie: ' . \implode('; ', $cookies);
}

foreach ($identity->headers as [$name, $value]) {
    $headers[] = $name . ': ' . $value;
}

// --- Relay ---

$responseHeaders = [];
$status = 0;
$started = \microtime(true);

$curl = \curl_init('http://127.0.0.1' . $target);
\curl_setopt_array($curl, [
    \CURLOPT_CUSTOMREQUEST => $method,
    \CURLOPT_HTTPHEADER => $headers,
    \CURLOPT_RETURNTRANSFER => true,
    \CURLOPT_FOLLOWLOCATION => false,
    \CURLOPT_TIMEOUT => 30,
    \CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders, &$status): int {
        $trimmed = \rtrim($line, "\r\n");

        if (\preg_match('#^HTTP/\S+\s+(\d{3})#', $trimmed, $m)) {
            // A new status line starts a new header block (e.g. after 100 Continue).
            $status = (int) $m[1];
            $responseHeaders = [];
        } elseif ($trimmed !== '') {
            $responseHeaders[] = $trimmed;
        }

        return \strlen($line);
    },
]);

if (!\in_array($method, ['GET', 'HEAD'], true)) {
    \curl_setopt($curl, \CURLOPT_POSTFIELDS, (string) \file_get_contents('php://input'));
}

$body = \curl_exec($curl);
$error = $body === false ? \curl_error($curl) : null;
\curl_close($curl);

$log = new ForgeLog((new Settings(\dirname(__DIR__)))->forgeLogFile());
$entry = [
    'id' => \bin2hex(\random_bytes(4)),
    'time' => \date('H:i:s'),
    'source' => $source,
    'ua' => $identity->ua,
    'method' => $method,
    'target' => $target,
    'request' => \array_map($shorten, $headers),
    'ms' => (int) \round((\microtime(true) - $started) * 1000),
];

if ($error !== null) {
    $log->append($entry + ['status' => 502, 'response' => [], 'body' => $error, 'bytes' => 0]);
    $fail(502, 'Relay failed: ' . $error);
}

$log->append($entry + [
    'status' => $status,
    'response' => \array_map($shorten, $responseHeaders),
    'body' => \mb_strcut((string) $body, 0, LOG_BODY_BYTES),
    'bytes' => \strlen((string) $body),
]);

// --- Relay the response ---

\http_response_code($status);
\header_remove('X-Powered-By');

foreach ($responseHeaders as $line) {
    [$name, $value] = \array_map('trim', \explode(':', $line, 2) + [1 => '']);
    $lower = \strtolower($name);

    if (\in_array($lower, HOP_HEADERS, true)) {
        continue;
    }

    if ($lower === 'set-cookie') {
        // Rename and scope the cookie to this identity; drop Domain and the original Path.
        $parts = \array_map('trim', \explode(';', $value));
        $parts[0] = $prefix . \ltrim($parts[0]);
        $parts = \array_filter($parts, static fn(string $part): bool => !\preg_match('/^(path|domain)\s*=/i', $part));
        $value = \implode('; ', $parts) . '; Path=' . $base . '/';
    }

    if ($lower === 'location') {
        $location = \str_starts_with($value, $origin . '/') ? \substr($value, \strlen($origin)) : $value;

        if (\str_starts_with($location, '/protected/')) {
            $value = $base . $location;
        }
    }

    \header($name . ': ' . $value, false);
}

\header('X-Demo-Relay: ' . $source);

echo $body;
