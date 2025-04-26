<?php

namespace GES\Botlock;

use GES\Botlock\Http\Middleware\MiddlewareDispatcher;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Middleware\ChallengeDocumentMiddleware;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Middleware\FingerprintMiddleware;
use GES\Botlock\Middleware\ProofOfWorkMiddleware;
use GES\Botlock\Middleware\RateLimiterMiddleware;
use GES\Botlock\Middleware\SessionMiddleware;
use GES\Botlock\Middleware\WhitelistMiddleware;

readonly class Kernel
{
    public static function boot(string $botlockRoot): static
    {
        try
        {
            $config = new Config();
            $whitelist = new Whitelist($config);

            return new static($botlockRoot, $config, $whitelist);
        }
        catch (\Throwable $th)
        {
            \http_response_code(500);
            \header('Botlock-Error: ' . $th->getMessage());
            exit('500 Internal Server Error');
        }
    }

    public function __construct(
        private string      $botlockRoot,
        private Config      $config,
        private Whitelist   $whitelist,
    ) {}

    public function handleRequest(Request $request): void
    {
        $middleware = new MiddlewareDispatcher();

        $middleware
            ->add(new ErrorMiddleware)
            ->add(new FingerprintMiddleware($this->config))
            ->add(new RateLimiterMiddleware($this->config))
            ->add(new WhitelistMiddleware($this->whitelist))
            ->add(new SessionMiddleware($this->config))
            ->add(new ProofOfWorkMiddleware($this->config, $request, $this->whitelist))
            ->add(new ChallengeDocumentMiddleware($request, $this->botlockRoot))
        ;

        $response = $middleware->dispatch($request);

        if ($response instanceof PassResponse) {
            return;
        }

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