<?php declare(strict_types=1);

namespace GES\Botlock\Challenge;

/**
 * Opens the interaction report a challenge page sends, sealed with the
 * random key its ticket was issued with (AES-256-GCM, the ticket id as
 * additional data, WebCrypto's layout of ciphertext followed by the tag).
 *
 * The key travels to the client in the challenge answer, so this is no
 * secret against anyone who reads the page: it only makes a bare POST of
 * the answer useless for automation that does not know botlock.
 */
final class InteractionCipher
{
    private const CIPHER = 'aes-256-gcm';
    private const KEY_BYTES = 32;
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** Largest sealed report accepted, in bytes; a full slider track fits easily. */
    private const MAX_BYTES = 65536;

    /** JSON nesting of a slider report: report, track, entry, samples, sample, number. */
    private const MAX_DEPTH = 6;

    public static function newKey(): string
    {
        return \random_bytes(self::KEY_BYTES);
    }

    /**
     * @param string $key raw key bytes
     * @param mixed  $iv  base64 initialisation vector as submitted
     * @param mixed  $ct  base64 ciphertext with the tag appended, as submitted
     * @return array|null the decoded JSON object, or null when anything does not fit
     */
    public static function open(string $key, string $cid, mixed $iv, mixed $ct): ?array
    {
        if (\strlen($key) !== self::KEY_BYTES
            || !\is_string($iv) || !\is_string($ct)
            || \strlen($ct) > \intdiv(self::MAX_BYTES * 4, 3) + 4
            || !\is_string($iv = \base64_decode($iv, true)) || \strlen($iv) !== self::IV_BYTES
            || !\is_string($ct = \base64_decode($ct, true)) || \strlen($ct) <= self::TAG_BYTES)
        {
            return null;
        }

        $plain = \openssl_decrypt(
            \substr($ct, 0, -self::TAG_BYTES),
            self::CIPHER,
            $key,
            \OPENSSL_RAW_DATA,
            $iv,
            \substr($ct, -self::TAG_BYTES),
            $cid,
        );

        if (!\is_string($plain)) {
            return null;
        }

        try {
            $data = \json_decode($plain, true, self::MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }
}
