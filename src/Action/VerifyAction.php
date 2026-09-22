<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Config\ProofOfWorkConfig;

/**
 * POST ?_botlock=verify — checks a submitted proof-of-work solution against
 * the nonce stored in the session and grants the session on success.
 */
final readonly class VerifyAction implements ActionHandlerInterface
{
    public function __construct(private ProofOfWorkConfig $config) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
    {
        $session = $request->context->session;

        if (!$nonceHash = $session->get('nh')) {
            throw new JsonResponseException('Invalid session data', 400);
        }

        if (!($nonce = $request->getHeader('Botlock-Nonce')) || !\password_verify($nonce, $nonceHash)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        if (!$data = $request->getJsonBody()) {
            throw new JsonResponseException('Invalid data', 400);
        }

        unset($data['nonce']);

        $statusCode = 401;

        $challenge = new ProofOfWork($this->config);
        if ($ok = $challenge->verify($data, $request->context->fingerprint))
        {
            $session->set('grant', true);
            $session->remove('nh');
            $session->commit();
            $statusCode = 200;
        }

        return new JsonResponse($statusCode, ['ok' => $ok]);
    }
}
