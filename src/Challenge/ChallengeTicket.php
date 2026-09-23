<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Server-side record of one issued challenge. The client only ever sees the
 * id; the level, required interaction and slider target stay on the server,
 * and redeeming the ticket consumes it.
 */
final readonly class ChallengeTicket
{
    /** Lifetime of a ticket in seconds, from issuing to verifying. */
    public const TTL = 300;

    public const ID_PATTERN = '/^[0-9a-f]{32}$/';

    /**
     * @param string      $id           random hex id, see ID_PATTERN
     * @param string      $subject      fingerprint the ticket was issued to
     * @param int         $level        threat level (1–3) the ticket was issued for; the grant carries it
     * @param float       $issuedAt     Unix time with microseconds
     * @param float       $difficulty   proof-of-work difficulty factor
     * @param int|null    $sliderTarget x offset the slider piece has to reach; null unless the interaction is Slider
     * @param bool        $interacted   whether the required interaction has been completed
     */
    public function __construct(
        public string      $id,
        public string      $subject,
        public int         $level,
        public Interaction $interaction,
        public float       $issuedAt,
        public float       $difficulty = 1.0,
        public ?int        $sliderTarget = null,
        public bool        $interacted = false,
    ) {}

    public static function newId(): string
    {
        return \bin2hex(\random_bytes(16));
    }

    public static function isValidId(mixed $id): bool
    {
        return \is_string($id) && \preg_match(self::ID_PATTERN, $id) === 1;
    }

    public function expiresAt(): int
    {
        return (int) \floor($this->issuedAt) + self::TTL;
    }

    public function isExpired(float $now): bool
    {
        return $now > $this->expiresAt();
    }

    /**
     * Ready for the proof of work: nothing was required, or it was done.
     */
    public function isReadyForProof(): bool
    {
        return !$this->interaction->isInteractive() || $this->interacted;
    }

    public function withInteracted(): self
    {
        return new self(
            $this->id,
            $this->subject,
            $this->level,
            $this->interaction,
            $this->issuedAt,
            $this->difficulty,
            $this->sliderTarget,
            true,
        );
    }

    /**
     * Ticket fields the proof-of-work signature is bound to.
     */
    public function binding(): string
    {
        return $this->id . '|' . $this->interaction->value . '|' . $this->level;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sub' => $this->subject,
            'lvl' => $this->level,
            'int' => $this->interaction->value,
            'iat' => $this->issuedAt,
            'dif' => $this->difficulty,
            'tgt' => $this->sliderTarget,
            'done' => $this->interacted,
        ];
    }

    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data)
            || !self::isValidId($data['id'] ?? null)
            || !\is_string($data['sub'] ?? null)
            || !\is_int($data['lvl'] ?? null)
            || !($interaction = Interaction::tryFrom((string) ($data['int'] ?? '')))
            || !\is_numeric($data['iat'] ?? null)
            || !\is_numeric($data['dif'] ?? null))
        {
            return null;
        }

        return new self(
            $data['id'],
            $data['sub'],
            $data['lvl'],
            $interaction,
            (float) $data['iat'],
            (float) $data['dif'],
            \is_int($data['tgt'] ?? null) ? $data['tgt'] : null,
            ($data['done'] ?? false) === true,
        );
    }
}
