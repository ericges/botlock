<?php

namespace GES\Botlock;

use GES\Botlock\Http\Middleware\MiddlewareDispatcher;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Config\KernelConfig;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Config\SecretProvider;
use GES\Botlock\Manager\ThreatAwarenessManager;
use GES\Botlock\Manager\WhitelistManager;
use GES\Botlock\Threat\FileThreatStateStore;
use GES\Botlock\Middleware\ChallengeDocumentMiddleware;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Middleware\VerifyCrawlerMiddleware;
use GES\Botlock\Middleware\WhoIsMiddleware;
use GES\Botlock\Middleware\ActionMiddleware;
use GES\Botlock\Action\ChallengeAction;
use GES\Botlock\Action\InteractAction;
use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Challenge\FileChallengeTicketStore;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Action\ResetAction;
use GES\Botlock\Action\StatusAction;
use GES\Botlock\Action\VerifyAction;
use GES\Botlock\Middleware\ThreatEvaluationMiddleware;
use GES\Botlock\Middleware\SessionMiddleware;
use GES\Botlock\Middleware\ThreatBlockMiddleware;
use GES\Botlock\Middleware\ThreatPassMiddleware;
use GES\Botlock\Middleware\IgnoreListMiddleware;
use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Template\RenderedPageCache;
use GES\Botlock\Template\TemplateRenderer;

readonly class Kernel
{
    public static function boot(string $botlockRoot): static
    {
        // Cannot throw; read first so a boot failure knows whether to fail open.
        $kernelConfig = KernelConfig::fromEnv();

        try
        {
            $secret = (new SecretProvider($kernelConfig->stateDir, $kernelConfig->instanceId))->get();
            $pow = ProofOfWorkConfig::fromEnv($secret);
            $detection = DetectionConfig::fromEnv();
            $rate = RateLimitConfig::fromEnv();

            $botDetect = new BotTestManager($detection);
            $whitelist = new WhitelistManager($detection);
            $store = new FileThreatStateStore($kernelConfig->stateDir, $kernelConfig->instanceId);
            $rateLimiter = new ThreatAwarenessManager($rate, $store);
            $pageCache = new RenderedPageCache($kernelConfig->stateDir, $kernelConfig->instanceId);
            $tickets = new FileChallengeTicketStore($kernelConfig->stateDir, $kernelConfig->instanceId);

            return new static($botlockRoot, $botDetect, $pow, $detection, $rate, $rateLimiter, $whitelist, $pageCache, $tickets);
        }
        catch (\Throwable $th)
        {
            if ($kernelConfig->failOpen) {
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
        return new static($botlockRoot, null, null, null, null, null, null, null, null, $reason);
    }

    public function __construct(
        private string                  $botlockRoot,
        private ?BotTestManager         $detective,
        private ?ProofOfWorkConfig      $pow,
        private ?DetectionConfig        $detection,
        private ?RateLimitConfig        $rate,
        private ?ThreatAwarenessManager $rateLimiter,
        private ?WhitelistManager       $whitelist,
        private ?RenderedPageCache      $pageCache,
        private ?ChallengeTicketStore   $tickets,
        private ?string                 $bootError = null,
    ) {}

    public function handleRequest(Request $request): void
    {
        if ($this->bootError !== null || !$this->pow || !$this->detection || !$this->rate || !$this->pageCache || !$this->tickets) {
            \header('Botlock-Error: ' . \strtr($this->bootError ?? 'Kernel not booted', ["\r" => ' ', "\n" => ' ']));
            return;
        }

        $translations = new TranslationLoader($this->botlockRoot . '/translations');
        $middleware = new MiddlewareDispatcher();

        $middleware
            ->add(new ErrorMiddleware)
            ->add(new WhoIsMiddleware($this->detection))
            ->add(new IgnoreListMiddleware($this->whitelist))
            ->add(new ThreatEvaluationMiddleware($this->rate, $this->rateLimiter))
            ->add(new VerifyCrawlerMiddleware($this->detective, $this->detection))
            ->add(new ThreatBlockMiddleware($this->rate))
            ->add(new ThreatPassMiddleware($this->detective))
            ->add(new SessionMiddleware($this->pow))
            ->add(new ActionMiddleware([
                'GET challenge' => new ChallengeAction($this->detective, $this->pow, $this->tickets, new InteractionPolicy($this->pow), $this->rate->gcProbability),
                'POST challenge' => new InteractAction($this->pow, $this->tickets),
                'POST verify' => new VerifyAction($this->pow, $this->tickets),
                'POST reset' => new ResetAction(),
                'GET status' => new StatusAction(),
            ]))
            ->add(new ChallengeDocumentMiddleware(
                new LanguageNegotiator($translations->supported()),
                $translations,
                new TemplateRenderer(),
                $this->pageCache,
                $this->botlockRoot . '/templates/challenge.php',
            ))
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
