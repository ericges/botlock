<?php

namespace GES\Botlock;

class JWT
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function tryGetPayload(string $jwt, string $secret, string $subject): ?array
    {
        $token = \explode('.', $jwt);

        if (\count($token) !== 3) {
            return null;
        }

        [$headerRaw, $payloadRaw, $signature] = $token;
        $signature = base64url_decode($signature);

        $header = \json_decode(base64url_decode($headerRaw), true);
        $payload = \json_decode(base64url_decode($payloadRaw), true);

        if (!$header || !$payload) return null;

        if (($header['typ'] ?? null) !== 'JWT') {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expectedSignature = \hash_hmac('sha256', $headerRaw . '.' . $payloadRaw, $secret, true);

        if ($signature !== $expectedSignature) {
            return null;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp && time() > $exp) {
            return null;
        }

        $nbf = (int) ($payload['nbf'] ?? 0);
        if ($nbf && time() < $nbf) {
            return null;
        }

        $iss = $payload['iss'] ?? null;
        if ($iss && $iss !== $this->config->getIssuer()) {
            return null;
        }

        $aud = $payload['aud'] ?? null;
        if ($aud && $aud !== $this->getAudience()) {
            return null;
        }

        $sub = $payload['sub'] ?? null;
        if ($sub && $subject && $sub !== $subject) {
            return null;
        }

        return $payload;
    }

    public function create(array $payload, string $secret, int $ttl = 3600): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload['iat'] = $now;
        $payload['exp'] = $now + $ttl;
        $payload['nbf'] = $now;
        $payload['iss'] = $this->config->getIssuer();
        $payload['aud'] = $this->getAudience();
        $payload['jti'] = \bin2hex(\random_bytes(16));

        $header = base64url_encode(\json_encode($header));
        $payload = base64url_encode(\json_encode($payload));
        $signature = base64url_encode(\hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $signature;
    }

    public function getAudience(): string
    {
        return \rtrim($this->config->getIssuer(), '/') . '/?_botlock';
    }
}
