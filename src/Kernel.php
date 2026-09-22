<?php

namespace GES\Botlock;

use GES\Botlock\Http\Middleware\MiddlewareDispatcher;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Manager\ThreatAwarenessManager;
use GES\Botlock\Manager\WhitelistManager;
use GES\Botlock\Middleware\ChallengeDocumentMiddleware;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Middleware\StatusMiddleware;
use GES\Botlock\Middleware\VerifyCrawlerMiddleware;
use GES\Botlock\Middleware\WhoIsMiddleware;
use GES\Botlock\Middleware\ProofOfWorkMiddleware;
use GES\Botlock\Middleware\ThreatEvaluationMiddleware;
use GES\Botlock\Middleware\SessionMiddleware;
use GES\Botlock\Middleware\WhitelistMiddleware;

readonly class Kernel
{
    public static function boot(string $botlockRoot): static
    {
        try
        {
            $config = new ConfigManager();
            $botDetect = new BotTestManager($config);
            $whitelist = new WhitelistManager($config);
            $rateLimiter = new ThreatAwarenessManager($config);

            return new static($botlockRoot, $botDetect, $config, $rateLimiter, $whitelist);
        }
        catch (\Throwable $th)
        {
            \http_response_code(500);
            \header('Botlock-Error: ' . $th->getMessage());
            exit('500 Internal Server Error');
        }
    }

    public function __construct(
        private string                 $botlockRoot,
        private BotTestManager         $detective,
        private ConfigManager          $config,
        private ThreatAwarenessManager $rateLimiter,
        private WhitelistManager       $whitelist,
    ) {}

    public function handleRequest(Request $request): void
    {
        $middleware = new MiddlewareDispatcher();

        $middleware
            ->add(new ErrorMiddleware)
            ->add(new WhoIsMiddleware($this->config))
            ->add(new ThreatEvaluationMiddleware($this->config, $this->rateLimiter))
            ->add(new VerifyCrawlerMiddleware($this->detective, $this->config))
            ->add(new WhitelistMiddleware($this->detective, $this->whitelist))
            ->add(new SessionMiddleware($this->config))
            ->add(new StatusMiddleware())
            ->add(new ProofOfWorkMiddleware($this->detective, $this->config))
            ->add(new ChallengeDocumentMiddleware($this->botlockRoot))
        ;

        $response = $middleware->dispatch($request);

        if ($response instanceof PassResponse) {
            return;
        }

        $this->respondAndExit($response);
    }

    private function respondAndExit(Response $response): void
    {
        try
        {
            $response->send();
        }
        catch (\Throwable)
        {
            echo '500 Internal Server Error';
        }
        finally
        {
            exit();
        }
    }
}