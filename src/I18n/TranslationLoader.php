<?php declare(strict_types=1);

namespace GES\Botlock\I18n;

/**
 * Loads the challenge page strings for one language from
 * translations/<code>.php. Codes are checked against a literal allow-list
 * so the file path can never be influenced by request input.
 */
final readonly class TranslationLoader
{
    /** Alphabetical; must equal the basenames in translations/ (enforced by tests). */
    public const LANGUAGES = [
        'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'fi', 'fr', 'hr', 'hu', 'it',
        'nl', 'no', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sr', 'sv', 'tr', 'uk',
    ];

    /** Keys every dictionary must provide (checked by tests, not at runtime). */
    public const KEYS = [
        'pageTitle', 'mainHeading', 'infoParagraph', 'footerNote',
        'confirmHeading', 'confirmParagraph', 'verifyButton',
        'errorHeading', 'errorMessage', 'errorWidget', 'errorFooter',
        'successHeading', 'successMessage', 'successFooter',
        'noscriptHeading', 'noscriptText',
    ];

    /**
     * @param string $dir Directory holding the <code>.php files
     */
    public function __construct(private string $dir) {}

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return self::LANGUAGES;
    }

    /**
     * Path of the translation file for a supported language.
     *
     * @throws \InvalidArgumentException for unknown codes
     */
    public function file(string $code): string
    {
        if (!\in_array($code, self::LANGUAGES, true)) {
            throw new \InvalidArgumentException(\sprintf('Unsupported language "%s"', $code));
        }

        return $this->dir . '/' . $code . '.php';
    }

    /**
     * @return array<string,string>
     * @throws \InvalidArgumentException for unknown codes
     * @throws \RuntimeException when the file does not return an array
     */
    public function load(string $code): array
    {
        $data = include $this->file($code);

        if (!\is_array($data)) {
            throw new \RuntimeException(\sprintf('Translation file for "%s" did not return an array', $code));
        }

        return $data;
    }
}
