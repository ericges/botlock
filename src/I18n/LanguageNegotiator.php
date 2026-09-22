<?php declare(strict_types=1);

namespace GES\Botlock\I18n;

/**
 * Picks the challenge page language from an Accept-Language header
 * (RFC 7231 language ranges with q-values). Matching is on the primary
 * subtag, so "de-AT" selects "de". Never throws and never returns a
 * language outside the supported list plus the fallback.
 */
final readonly class LanguageNegotiator
{
    public const FALLBACK = 'en';

    private const ALIASES = ['nb' => 'no', 'nn' => 'no'];
    private const MAX_HEADER_LENGTH = 2048;
    private const MAX_RANGES = 32;
    private const TAG = '/^[a-z]{1,8}(-[a-z0-9]{1,8})*$/';
    private const QVALUE = '/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/';

    /**
     * @param list<string> $supported Lowercase primary subtags, e.g. ['de', 'en']
     * @param string       $fallback  Returned when nothing acceptable is supported
     */
    public function __construct(
        private array  $supported,
        private string $fallback = self::FALLBACK,
    ) {}

    public function negotiate(?string $header): string
    {
        if ($header === null) {
            return $this->fallback;
        }

        $header = \trim($header);

        if ($header === '' || \strlen($header) > self::MAX_HEADER_LENGTH) {
            return $this->fallback;
        }

        /** @var list<array{0: float, 1: int, 2: string}> $candidates */
        $candidates = [];

        foreach (\array_slice(\explode(',', $header), 0, self::MAX_RANGES) as $index => $range) {
            $parts = \array_map('trim', \explode(';', $range));
            $tag = \strtolower((string) \array_shift($parts));
            $q = 1.0;

            foreach ($parts as $param) {
                if (!\preg_match('/^q\s*=\s*(.*)$/i', $param, $match)) {
                    continue;
                }

                if (!\preg_match(self::QVALUE, $match[1])) {
                    continue 2; // malformed q-value: skip the whole range
                }

                $q = (float) $match[1];
            }

            // q=0 means "not acceptable"; a wildcard resolves to the fallback anyway.
            if ($q <= 0.0 || $tag === '*' || !\preg_match(self::TAG, $tag)) {
                continue;
            }

            $primary = \explode('-', $tag, 2)[0];
            $primary = self::ALIASES[$primary] ?? $primary;

            if (\in_array($primary, $this->supported, true)) {
                $candidates[] = [$q, $index, $primary];
            }
        }

        if ($candidates === []) {
            return $this->fallback;
        }

        \usort($candidates, static fn(array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[1] <=> $b[1]));

        return $candidates[0][2];
    }
}
