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
    public function __construct(
        private BotTestManager $detective,
        private ConfigManager  $config,
        private Request        $request,
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
        if (!($nonce = $request->getHeader('Botlock-Nonce'))
            || !\preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $nonce))
        {
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
        if ($ok = $challenge->verify($data, $request->fingerprint))
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

/*
// In ProofOfWorkMiddleware::handleGetChallengeRequest()

$pow = new ProofOfWork($this->config);

// --> Add difficulty adjustment <--
$globalThreatLevel = $request->threatLevel ?? 0; // Get level bound by RateLimiterMiddleware
$individualRate = $request->individualRate ?? 0; // Get rate
$isGoodBot = $this->whitelist->isGoodBot();
$isBot = $this->whitelist->isBot();

$difficultyFactor = 1.0; // Default

if ($isBot) {
    $difficultyFactor = $isGoodBot ? 0.5 : $this->config->getCrawlerFactor(); // Existing logic
} elseif ($globalThreatLevel >= 2) {
     $difficultyFactor = 1.5; // Increase difficulty slightly at higher threat levels for humans too?
} elseif ($globalThreatLevel === 1 && $individualRate > $this->config->getLevel1ThresholdIndividual()) {
     $difficultyFactor = 1.2; // Slightly harder for individually flagged users at level 1
}
// You might add more complex logic based on combined factors

$pow->setDifficulty($difficultyFactor);
// --- End of adjustment ---

$data = $pow->create();
$data['auto_start'] = !$isBot; // Existing logic: Maybe only auto-start for non-bots?

// ... rest of the method ... */