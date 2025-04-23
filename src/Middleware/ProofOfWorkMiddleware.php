<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Config;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\ProofOfWork;
use GES\Botlock\Whitelist;

readonly class ProofOfWorkMiddleware implements MiddlewareInterface
{
    public function __construct(
        private Config    $config,
        private Request   $request,
        private Whitelist $whitelist,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function process(Request $request, callable $next): Response
    {
        $action = (string) $request->get('_botlock');

        if (!$action) {
            return $next($request);
        }

        $action = \preg_replace('/[^a-z0-9_]/i', '', $action);

        return match ($request->getMethod() . ' ' . $action) {
            'GET challenge' => $this->handleGetChallengeRequest($request),
            'POST verify' => $this->handlePostChallengeRequest($request),
            'POST reset' => $this->handleResetRequest($request),
            default => $next($request)
        };
    }

    /**
     * @throws JsonResponseException
     */
    public function handleGetChallengeRequest(Request $request): Response
    {
        if (!($nonce = $request->getHeader('Botlock-Nonce'))
            || !\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $nonce))
        {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        $pow = new ProofOfWork($this->config);
        $goodActor = true;

        if ($this->whitelist->isBot())
        {
            $goodActor = $this->whitelist->isGoodBot();
            $pow->setDifficulty($goodActor ? 0.5 : $this->config->getCrawlerFactor());
        }

        $data = $pow->create();
        $data['auto_start'] = $goodActor;

        $this->request->session->set('nh', \password_hash($nonce, \PASSWORD_DEFAULT));
        $this->request->session->commit();

        return new Response\JsonResponse(200, $data);
    }

    /**
     * @throws JsonResponseException
     */
    public function handlePostChallengeRequest(Request $request): Response
    {
        if (!$nonceHash = $this->request->session->get('nh')) {
            throw new JsonResponseException('Invalid session data', 400);
        }

        if (!($nonce = $_SERVER['HTTP_BOTLOCK_NONCE'] ?? null) || !\password_verify($nonce, $nonceHash)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        $data = \json_decode(\file_get_contents('php://input'), true);

        if (!\is_array($data)) {
            throw new JsonResponseException('Invalid data', 400);
        }

        unset($data['nonce']);

        $statusCode = 401;

        $challenge = new ProofOfWork($this->config);
        if ($ok = $challenge->verify($data))
        {
            $this->request->session->set('grant', true);
            $this->request->session->remove('nh');
            $this->request->session->commit();
            $statusCode = 200;
        }

        return new Response\JsonResponse($statusCode, ['ok' => $ok]);
    }

    public function handleResetRequest(Request $request): Response
    {
        $this->request->session->clear();
        $this->request->session->commit();

        if (($location = $_POST['location'] ?? null)
            && \filter_var($location = $request->getAbsoluteUrl($location), \FILTER_VALIDATE_URL))
        {
            return new Response\RedirectResponse($location);
        }

        return new Response\JsonResponse(200, [
            'ok' => true,
            'message' => 'Session cleared',
        ]);
    }
}