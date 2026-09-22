<?php declare(strict_types=1);

namespace GES\Botlock\Tests\I18n;

use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LanguageNegotiatorTest extends TestCase
{
    private LanguageNegotiator $negotiator;

    protected function setUp(): void
    {
        $this->negotiator = new LanguageNegotiator(TranslationLoader::LANGUAGES);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string}>
     */
    public static function headers(): iterable
    {
        yield 'exact match' => ['de', 'de'];
        yield 'region subtag' => ['de-AT', 'de'];
        yield 'q ordering' => ['en;q=0.5,de', 'de'];
        yield 'q ordering with spaces' => ['en; q=0.5, de; q=0.9', 'de'];
        yield 'equal q keeps header order' => ['fr,de', 'fr'];
        yield 'bokmal alias' => ['nb-NO', 'no'];
        yield 'nynorsk alias' => ['nn', 'no'];
        yield 'unsupported' => ['xx-YY', 'en'];
        yield 'unsupported then supported' => ['xx,fr;q=0.8', 'fr'];
        yield 'null' => [null, 'en'];
        yield 'empty' => ['', 'en'];
        yield 'whitespace' => ['   ', 'en'];
        yield 'malformed q skipped' => ['de;q=abc,fr', 'fr'];
        yield 'malformed q only' => ['de;q=abc', 'en'];
        yield 'q zero excluded' => ['de;q=0,fr', 'fr'];
        yield 'wildcard' => ['*', 'en'];
        yield 'wildcard plus real' => ['*,de;q=0.5', 'de'];
        yield 'uppercase' => ['DE-de', 'de'];
        yield 'uppercase alias' => ['Nb', 'no'];
        yield 'garbage separators' => [';;;,,,==', 'en'];
        yield 'garbage q' => ['de;;q=', 'en'];
        yield 'markup' => ['<script>', 'en'];
        yield 'overly long' => [\str_repeat('de,', 2000), 'en'];
        yield 'winner beyond range cap' => [\str_repeat('xx,', 40) . 'de', 'en'];
    }

    #[DataProvider('headers')]
    public function testNegotiate(?string $header, string $expected): void
    {
        self::assertSame($expected, $this->negotiator->negotiate($header));
    }

    public function testResultIsAlwaysSupported(): void
    {
        foreach (['de', 'nb', 'xx', null, '*', 'sr-Latn-RS;q=0.7,en;q=0.1'] as $header) {
            self::assertContains($this->negotiator->negotiate($header), TranslationLoader::LANGUAGES);
        }
    }

    public function testFallbackIsConfigurable(): void
    {
        $negotiator = new LanguageNegotiator(['de'], 'de');

        self::assertSame('de', $negotiator->negotiate(null));
        self::assertSame('de', $negotiator->negotiate('fr'));
    }
}
