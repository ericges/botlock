<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

/**
 * Seals an interaction report the way the challenge page's WebCrypto call
 * does: AES-256-GCM, the ticket id as additional data, tag appended.
 */
final class Reports
{
    /**
     * @return array{cid: string, iv: string, ct: string}
     */
    public static function seal(string $key, string $cid, array $report): array
    {
        $iv = \random_bytes(12);
        $ct = \openssl_encrypt(\json_encode((object) $report), 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $iv, $tag, $cid);

        return ['cid' => $cid, 'iv' => \base64_encode($iv), 'ct' => \base64_encode($ct . $tag)];
    }
}
