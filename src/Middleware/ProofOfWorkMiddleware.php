<?php

namespace GES\Botlock\Middleware;

use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Challenge\ProofOfWork;

readonly class ProofOfWorkMiddleware implements MiddlewareInterface
{
    private const NONCE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private BotTestManager $detective,
        private ConfigManager  $config,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function process(Request $request, callable $next): Response
    {
        if (!$action = $request->getBotlockAction()) {
            return $next($request);
        }

        return match ($action) {
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
        if (!($nonce = $request->getHeader('Botlock-Nonce')) || !\preg_match(self::NONCE_PATTERN, $nonce)) {
            throw new JsonResponseException('Invalid nonce', 400);
        }

        $pow = new ProofOfWork($this->config);
        $goodActor = true;

        if ($this->detective->isCrawler())
        {
            $goodActor = $this->detective->isGoodBot();
            $pow->setDifficulty($goodActor ? 0.5 : $this->config->getCrawlerFactor());
        }

        $data = $pow->create($request->fingerprint);
        $data['auto_start'] = $goodActor;

        $request->session->set('nh', \password_hash($nonce, \PASSWORD_DEFAULT));
        $request->session->commit();

        return new Response\JsonResponse(200, $data);
    }

    /**
     * @throws JsonResponseException
     */
    public function handlePostChallengeRequest(Request $request): Response
    {
        if (!$nonceHash = $request->session->get('nh')) {
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
        if ($ok = $challenge->verify($data, $request->fingerprint))
        {
            $request->session->set('grant', true);
            $request->session->remove('nh');
            $request->session->commit();
            $statusCode = 200;
        }

        return new Response\JsonResponse($statusCode, ['ok' => $ok]);
    }

    public function handleResetRequest(Request $request): Response
    {
        $request->session->clear();
        $request->session->commit();

        if (\is_string($location = $request->getPost('location'))
            && $location !== ''
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
