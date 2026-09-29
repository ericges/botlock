<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;

/**
 * The Botlock-Nonce header ties the steps of one challenge together: the
 * challenge request stores its hash with the ticket, and redeeming the
 * ticket takes the same nonce. Each page load has its own ticket, so two
 * tabs of one session never replace each other's nonce.
 *
 * The nonce is a random UUID, so a plain hash keeps it unguessable.
 */
final class ChallengeNonce
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @throws JsonResponseException on a missing or malformed nonce
     */
    public static function hash(Request $request): string
    {
        if (!($nonce = $request->getHeader('Botlock-Nonce')) || !\preg_match(self::PATTERN, $nonce)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        return \hash('sha256', \strtolower($nonce));
    }
}
