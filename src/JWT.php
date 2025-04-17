<?php

namespace GES\Botlock;

class JWT
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function tryGetPayload(string $jwt, string $secret)
    {
        $token = \explode('.', $jwt);

        if (\count($token) !== 3) {
            return false;
        }

        [$headerRaw, $payloadRaw, $signature] = $token;
        $signature = base64url_decode($signature);

        $header = \json_decode(base64url_decode($headerRaw), true);
        $payload = \json_decode(base64url_decode($payloadRaw), true);

        if (!$header || !$payload) return false;

        if (($header['alg'] ?? null) !== 'HS256') {
            return false;
        }

        $expected = \hash_hmac('sha256', $headerRaw . '.' . $payloadRaw, $secret, true);

        if ($signature !== $expected) {
            return false;
        }

        if (($payload['exp'] ?? -1) < time()) {
            return false;
        }

        return $payload;
    }

    public function create(array $payload, string $secret, int $ttl = 3600): string
    {
        $header = [
            "alg" => "HS256",
            "typ" => "JWT",
        ];

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;
        $payload['nbf'] = $now;
        $payload['iss'] = $this->config->getIssuer();
        $payload['aud'] = \rtrim($this->config->getIssuer(), '') . '/_botlock';
        $payload['jti'] = \bin2hex(\random_bytes(16));

        $header = urlsafeB64Encode(json_encode($header));
        $payload = urlsafeB64Encode(json_encode($payload));
        $signature = urlsafeB64Encode(hash_hmac('sha256', "$header.$payload", $secret, true));
        return $header . '.' . $payload . '.' . $signature;
    }
}
