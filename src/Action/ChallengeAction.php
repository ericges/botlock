<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\JsonResponse;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Manager\ConfigManager;

/**
 * GET ?_botlock=challenge — issues a proof-of-work challenge bound to the
 * client fingerprint and remembers the client's nonce in the session.
 */
final readonly class ChallengeAction implements ActionHandlerInterface
{
    private const NONCE_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private BotTestManager $detective,
        private ConfigManager  $config,
    ) {}

    /**
     * @throws JsonResponseException
     */
    public function handle(Request $request): Response
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

        $data = $pow->create($request->context->fingerprint);
        $data['auto_start'] = $goodActor;

        $request->context->session->set('nh', \password_hash($nonce, \PASSWORD_DEFAULT));
        $request->context->session->commit();

        return new JsonResponse(200, $data);
    }
}
