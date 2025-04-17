<?php

namespace GES\Botlock;

class Challenge
{
    public function __construct(private readonly Config $config) {}

    public function create(): array
    {
        $min = \floor($this->config->getMaxNumber() / 10);
        $number = \random_int($min, $this->config->getMaxNumber());
        $salt = randStr(15);
        $expire = $this->config->getExpire();
        $expire = $expire > 0 ? time() + $this->config->getExpire() : 0;

        if (!$challenge = $this->hashChallenge($this->config->getAlgorithm(), $number, $salt, $expire)) {
            throw new \RuntimeException('Failed to create challenge');
        }

        $verify = \hash_hmac($this->config->getAlgorithm(), $challenge, $this->config->getSecret());

        return [
            'alg' => $this->config->getAlgorithm(),
            'exp' => $expire,
            'max' => $this->config->getMaxNumber(),
            'slt' => $salt,
            'try' => $challenge,
            'ver' => $verify,
        ];
    }

    public function verify($data): bool
    {
        if (\count($data) !== 5
            || !($number = $data['num'] ?? null)
            || !($verify = $data['ver'] ?? null)
            || !($salt = $data['slt'] ?? null)
            || !\is_numeric($expire = $data['exp'] ?? null)
            || !($algorithm = $data['alg'] ?? null))
        {
            return false;
        }

        if ($algorithm !== $this->config->getAlgorithm()) {
            return false;
        }

        if (!$expire || $expire > 0 && $expire < time()) {
            return false;
        }

        if (!$hash = $this->hashChallenge($this->config->getAlgorithm(), $number, $salt, $expire)) {
            return false;
        }

        $expect = \hash_hmac($this->config->getAlgorithm(), $hash, $this->config->getSecret());

        return $expect === $verify;
    }

    private function hashChallenge(string $algorithm, int $number, string $salt, int $expire): ?string
    {
        if (!\in_array($algorithm, Config::ALLOWED_ALGORITHMS)) {
            return null;
        }

        return \hash($algorithm, $number . ':' . $salt . ':' . $expire);
    }
}