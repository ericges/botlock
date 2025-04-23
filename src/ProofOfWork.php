<?php

namespace GES\Botlock;

class ProofOfWork
{
    private float $difficulty = 1;

    public function __construct(private readonly Config $config) {}

    public function setDifficulty(float $difficulty): static
    {
        $this->difficulty = \max(0, $difficulty);  // >= 1

        return $this;
    }

    public function getDifficulty(): int
    {
        return $this->difficulty;
    }

    public function create(): array
    {
        $factor = $this->getDifficulty();
        $max = \floor($this->config->getMaxNumber() * $factor);
        $max = \max(100, $max);   // >= 100
        $min = \floor($max / 10);  // 10% of max, >= 10

        $number = \random_int($min, $max);
        $salt = randStr(15);
        $expire = $this->config->getExpire();
        $expire = $expire > 0 ? time() + $this->config->getExpire() : 0;

        $powAlgorithm = $this->config->getPowAlgorithm();

        if (!$target = $this->hashTarget($powAlgorithm, $number, $salt, $expire)) {
            throw new \RuntimeException('Failed to create challenge');
        }

        $signature = \hash_hmac('sha384', $target, $this->config->getSecret());

        return [
            'alg' => $powAlgorithm,
            'exp' => $expire,
            'max' => $max,
            'slt' => $salt,
            'tgt' => $target,
            'sig' => $signature,
        ];
    }

    public function verify($data): bool
    {
        if (\count($data) !== 5
            || !($number = $data['num'] ?? null)
            || !($signature = $data['sig'] ?? null)
            || !($salt = $data['slt'] ?? null)
            || !\is_numeric($expire = $data['exp'] ?? null)
            || !($algorithm = $data['alg'] ?? null))
        {
            return false;
        }

        if ($algorithm !== $this->config->getPowAlgorithm()) {
            return false;
        }

        if (!$expire || $expire > 0 && $expire < time()) {
            return false;
        }

        if (!$hash = $this->hashTarget($this->config->getPowAlgorithm(), $number, $salt, $expire)) {
            return false;
        }

        $expect = \hash_hmac('sha384', $hash, $this->config->getSecret());

        return $expect === $signature;
    }

    private function hashTarget(string $algorithm, int $number, string $salt, int $expire): ?string
    {
        if (!\in_array($algorithm, Config::POW_ALLOWED_ALGORITHMS)) {
            return null;
        }

        return \hash($algorithm, $number . ':' . $salt . ':' . $expire);
    }
}