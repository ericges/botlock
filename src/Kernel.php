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
use GES\Botlock\Middleware\ThreatPassMiddleware;
use GES\Botlock\Middleware\IgnoreListMiddleware;

readonly class Kernel
{
    public static function boot(string $botlockRoot): static
    {
        // Read before anything that can throw: decides how a boot failure is handled.
        $failOpen = (bool) ConfigManager::envBool('FAIL_OPEN', false);

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
            if ($failOpen) {
                return static::passThrough($botlockRoot, $th->getMessage());
            }

            \http_response_code(500);
            \header('Botlock-Error: ' . $th->getMessage());
            exit('500 Internal Server Error');
        }
    }

    /**
     * Kernel that lets every request through untouched, reporting why via
     * a Botlock-Error header. Used when booting failed and BOTLOCK_FAIL_OPEN
     * is enabled.
     */
    public static function passThrough(string $botlockRoot, string $reason): static
    {
        return new static($botlockRoot, null, null, null, null, $reason);
    }

    public function __construct(
        private string                  $botlockRoot,
        private ?BotTestManager         $detective,
        private ?ConfigManager          $config,
        private ?ThreatAwarenessManager $rateLimiter,
        private ?WhitelistManager       $whitelist,
        private ?string                 $bootError = null,
    ) {}

    public function handleRequest(Request $request): void
    {
        if ($this->bootError !== null || !$this->config) {
            \header('Botlock-Error: ' . \strtr($this->bootError ?? 'Kernel not booted', ["\r" => ' ', "\n" => ' ']));
            return;
        }

        $middleware = new MiddlewareDispatcher();

        $middleware
            ->add(new ErrorMiddleware)
            ->add(new WhoIsMiddleware($this->config))
            ->add(new IgnoreListMiddleware($this->whitelist))
            ->add(new ThreatEvaluationMiddleware($this->config, $this->rateLimiter))
            ->add(new VerifyCrawlerMiddleware($this->detective, $this->config))
            ->add(new ThreatPassMiddleware($this->detective))
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
