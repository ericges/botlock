<?php declare(strict_types=1);

namespace GES\Botlock\Demo;

/**
 * A forged client for the demo relay: the User-Agent, forwarded client
 * address, Accept-Language and extra headers every relayed request of
 * this identity carries. Encoded as base64url JSON in the relay URL
 * (/_forge.php/<id>/protected/...), so identities need no storage.
 */
final readonly class Identity
{
    public const MAX_ID_LENGTH = 4096;
    private const MAX_VALUE_LENGTH = 1024;

    /** Headers the relay sets itself; an identity cannot override them. */
    private const RESERVED_HEADERS = ['host', 'cookie', 'content-length', 'transfer-encoding', 'connection', 'x-forwarded-proto'];

    /**
     * @param string                          $ua      User-Agent; empty keeps the browser's own
     * @param string                          $ip      X-Forwarded-For value (not validated, so malformed chains can be tried)
     * @param string                          $lang    Accept-Language; empty sends none
     * @param list<array{0: string, 1: string}> $headers extra headers as [name, value]
     *
     * @throws \InvalidArgumentException on an unusable value
     */
    public function __construct(
        public string $ua = '',
        public string $ip = '',
        public string $lang = '',
        public array  $headers = [],
    ) {
        foreach ([$ua, $ip, $lang] as $value) {
            self::assertValue($value);
        }

        foreach ($headers as [$name, $value]) {
            if (!\preg_match('/^[A-Za-z0-9-]{1,64}$/', $name)) {
                throw new \InvalidArgumentException('Invalid header name: ' . $name);
            }

            if (\in_array(\strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new \InvalidArgumentException('Header is set by the relay: ' . $name);
            }

            self::assertValue($value);
        }
    }

    /**
     * @throws \InvalidArgumentException when $id is not a valid encoded identity
     */
    public static function decode(string $id): self
    {
        $json = \strlen($id) <= self::MAX_ID_LENGTH ? \base64_decode(\strtr($id, '-_', '+/'), true) : false;
        $data = \is_string($json) ? \json_decode($json, true) : null;

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('Invalid identity');
        }

        $headers = [];
        foreach (\is_array($data['headers'] ?? null) ? $data['headers'] : [] as $line) {
            if (!\is_string($line) || !\str_contains($line, ':')) {
                throw new \InvalidArgumentException('Invalid header line');
            }

            [$name, $value] = \explode(':', $line, 2);
            $headers[] = [\trim($name), \trim($value)];
        }

        return new self(
            ua: self::string($data['ua'] ?? ''),
            ip: self::string($data['ip'] ?? ''),
            lang: self::string($data['lang'] ?? ''),
            headers: $headers,
        );
    }

    /**
     * Prefix under which the relay stores this identity's cookies in the
     * browser, so forged sessions never mix with each other or with the
     * browser's own.
     */
    public static function cookiePrefix(string $id): string
    {
        return 'f' . \substr(\hash('sha256', $id), 0, 10) . '_';
    }

    public static function basePath(string $id): string
    {
        return '/_forge.php/' . $id;
    }

    /**
     * Identities offered in the panel; null fields mean "This browser",
     * which loads the protected page directly instead of via the relay.
     *
     * @return array<string, array{label: string, fields: array{ua: string, ip: string, lang: string, headers: list<string>}|null}>
     */
    public static function presets(): array
    {
        $firefox = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

        return [
            'browser' => ['label' => 'This browser', 'fields' => null],
            'googlebot' => ['label' => 'Googlebot (fake)', 'fields' => [
                'ua' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'ip' => '198.51.100.23', 'lang' => '', 'headers' => [],
            ]],
            'bingbot' => ['label' => 'Bingbot', 'fields' => [
                'ua' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
                'ip' => '198.51.100.40', 'lang' => '', 'headers' => [],
            ]],
            'ahrefs' => ['label' => 'AhrefsBot', 'fields' => [
                'ua' => 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)',
                'ip' => '198.51.100.77', 'lang' => '', 'headers' => [],
            ]],
            'curl' => ['label' => 'curl', 'fields' => [
                'ua' => 'curl/8.9.1', 'ip' => '203.0.113.50', 'lang' => '', 'headers' => [],
            ]],
            'python' => ['label' => 'python-requests', 'fields' => [
                'ua' => 'python-requests/2.32.3', 'ip' => '203.0.113.51', 'lang' => '', 'headers' => [],
            ]],
            'visitor-de' => ['label' => 'Visitor from Germany', 'fields' => [
                'ua' => $firefox, 'ip' => '203.0.113.7', 'lang' => 'de-DE,de;q=0.9,en;q=0.5', 'headers' => [],
            ]],
        ];
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Invalid identity field');
        }

        return \trim($value);
    }

    private static function assertValue(string $value): void
    {
        if (\strlen($value) > self::MAX_VALUE_LENGTH || \preg_match('/[\r\n\0]/', $value)) {
            throw new \InvalidArgumentException('Invalid header value');
        }
    }
}
