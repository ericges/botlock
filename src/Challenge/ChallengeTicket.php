<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Server-side record of one issued challenge. The client only ever sees the
 * id and, for an interaction, the key to seal its report with (for the
 * slider only once it paid the gate and got its puzzle); the level,
 * required interaction and slider target stay on the server, and redeeming
 * the ticket consumes it.
 *
 * Each phase (the gate, the interaction, the proof) has TTL seconds; the
 * ticket lives MAX_LIFETIME at most.
 */
final readonly class ChallengeTicket
{
    /** Lifetime of one phase in seconds: the gate, the interaction or the proof. */
    public const TTL = 300;

    /** Longest a ticket lives across all of its phases. */
    public const MAX_LIFETIME = 3 * self::TTL;

    public const ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param string      $id           issue minute plus random hex, see newId()
     * @param string      $subject      fingerprint the ticket was issued to
     * @param string      $nonceHash    SHA-256 of the Botlock-Nonce the ticket was issued with, see ChallengeNonce
     * @param int         $level        threat level (1–3) the ticket was issued for; the grant carries it
     * @param float       $issuedAt     Unix time with microseconds
     * @param int         $expiresAt    Unix time the current phase ends at, see renewed()
     * @param float       $difficulty   proof-of-work difficulty factor
     * @param int|null    $sliderTarget x offset the slider piece has to reach; null unless the interaction is Slider
     * @param bool        $interacted   whether the required interaction has been completed
     * @param string|null $key          raw InteractionCipher key the interaction report is sealed with; null without an interaction
     * @param float|null  $puzzleAt     Unix time the slider puzzle was rendered; null before and without one
     */
    public function __construct(
        public string      $id,
        public string      $subject,
        public string      $nonceHash,
        public int         $level,
        public Interaction $interaction,
        public float       $issuedAt,
        public int         $expiresAt,
        public float       $difficulty = 1.0,
        public ?int        $sliderTarget = null,
        public bool        $interacted = false,
        public ?string     $key = null,
        public ?float      $puzzleAt = null,
    ) {}

    /**
     * 32 hex digits: the issue minute (Unix time / 60) in the first eight,
     * so the store can file the ticket by minute, then 96 random bits. The
     * minute tells the client nothing its challenge's "exp" does not.
     */
    public static function newId(float $issuedAt): string
    {
        return \sprintf('%08x', \intdiv((int) \floor($issuedAt), 60)) . \bin2hex(\random_bytes(12));
    }

    /**
     * The issue minute encoded in an id that passed isValidId().
     */
    public static function issueMinute(string $id): int
    {
        return (int) \hexdec(\substr($id, 0, 8));
    }

    public static function isValidId(mixed $id): bool
    {
        return \is_string($id) && \preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function isExpired(float $now): bool
    {
        return $now > $this->expiresAt;
    }

    /**
     * The ticket with a fresh phase: its deadline moves to TTL from $now,
     * but never past MAX_LIFETIME after issuing.
     */
    public function renewed(float $now): self
    {
        return $this->with(expiresAt: \min((int) \floor($now) + self::TTL, (int) \floor($this->issuedAt) + self::MAX_LIFETIME));
    }

    /**
     * Ready for the proof of work: nothing was required, or it was done.
     */
    public function isReadyForProof(): bool
    {
        return !$this->interaction->isInteractive() || $this->interacted;
    }

    /**
     * A slider ticket at the gate: its puzzle has not been rendered yet.
     */
    public function awaitsPuzzle(): bool
    {
        return $this->interaction === Interaction::Slider && $this->sliderTarget === null;
    }

    /**
     * @param float $now render time; the slider is timed from it, not from issuing
     */
    public function withPuzzle(int $target, string $key, float $now): self
    {
        return $this->with(sliderTarget: $target, key: $key, puzzleAt: $now);
    }

    /**
     * @param float|null $difficulty proof-of-work difficulty from now on; null keeps it
     */
    public function withInteracted(?float $difficulty = null): self
    {
        return $this->with(difficulty: $difficulty, interacted: true);
    }

    /**
     * Ticket fields the proof-of-work signature is bound to.
     */
    public function binding(): string
    {
        return $this->id . '|' . $this->interaction->value . '|' . $this->level;
    }

    /**
     * Ticket fields the gate proof of work in front of the slider puzzle is
     * bound to; never valid for the final proof, and vice versa.
     */
    public function gateBinding(): string
    {
        return $this->id . '|gate|' . $this->level;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sub' => $this->subject,
            'nh' => $this->nonceHash,
            'lvl' => $this->level,
            'int' => $this->interaction->value,
            'iat' => $this->issuedAt,
            'exp' => $this->expiresAt,
            'dif' => $this->difficulty,
            'tgt' => $this->sliderTarget,
            'done' => $this->interacted,
            'key' => $this->key === null ? null : \base64_encode($this->key),
            'pat' => $this->puzzleAt,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)
            || !self::isValidId($data['id'] ?? null)
            || !\is_string($data['sub'] ?? null)
            || !\is_string($data['nh'] ?? null)
            || !\is_int($data['lvl'] ?? null)
            || !($interaction = Interaction::tryFrom((string) ($data['int'] ?? '')))
            || !\is_numeric($data['iat'] ?? null)
            || !\is_int($data['exp'] ?? null)
            || !\is_numeric($data['dif'] ?? null))
        {
            return null;
        }

        return new self(
            id: $data['id'],
            subject: $data['sub'],
            nonceHash: $data['nh'],
            level: $data['lvl'],
            interaction: $interaction,
            issuedAt: (float) $data['iat'],
            expiresAt: $data['exp'],
            difficulty: (float) $data['dif'],
            sliderTarget: \is_int($data['tgt'] ?? null) ? $data['tgt'] : null,
            interacted: ($data['done'] ?? false) === true,
            key: \is_string($data['key'] ?? null) && \is_string($key = \base64_decode($data['key'], true)) ? $key : null,
            puzzleAt: \is_numeric($data['pat'] ?? null) ? (float) $data['pat'] : null,
        );
    }

    private function with(
        ?int $expiresAt = null,
        ?float $difficulty = null,
        ?bool $interacted = null,
        ?int $sliderTarget = null,
        ?string $key = null,
        ?float $puzzleAt = null,
    ): self {
        return new self(
            id: $this->id,
            subject: $this->subject,
            nonceHash: $this->nonceHash,
            level: $this->level,
            interaction: $this->interaction,
            issuedAt: $this->issuedAt,
            expiresAt: $expiresAt ?? $this->expiresAt,
            difficulty: $difficulty ?? $this->difficulty,
            sliderTarget: $sliderTarget ?? $this->sliderTarget,
            interacted: $interacted ?? $this->interacted,
            key: $key ?? $this->key,
            puzzleAt: $puzzleAt ?? $this->puzzleAt,
        );
    }
}
