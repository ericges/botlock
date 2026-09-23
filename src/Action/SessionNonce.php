<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;

/**
 * The Botlock-Nonce header ties the challenge actions of one page load
 * together: the challenge request stores its hash in the session, every
 * later step has to present the same nonce.
 */
final class SessionNonce
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const SESSION_KEY = 'nh';

    /**
     * @throws JsonResponseException on a missing or malformed nonce
     */
    public static function remember(Request $request): void
    {
        if (!($nonce = $request->getHeader('Botlock-Nonce')) || !\preg_match(self::PATTERN, $nonce)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        $request->context->session->set(self::SESSION_KEY, \password_hash($nonce, \PASSWORD_DEFAULT));
        $request->context->session->commit();
    }

    /**
     * @throws JsonResponseException when the session holds no nonce or a different one
     */
    public static function assertMatches(Request $request): void
    {
        if (!$nonceHash = $request->context->session->get(self::SESSION_KEY)) {
            throw new JsonResponseException('Invalid session data', 400);
        }

        if (!($nonce = $request->getHeader('Botlock-Nonce')) || !\password_verify($nonce, $nonceHash)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }
    }

    public static function forget(Request $request): void
    {
        $request->context->session->remove(self::SESSION_KEY);
    }
}
