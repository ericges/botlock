<?php

namespace GES\Botlock;

use GES\Botlock\Http\Middleware\MiddlewareDispatcher;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Middleware\ChallengeDocumentMiddleware;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Middleware\ProofOfWorkMiddleware;
use GES\Botlock\Middleware\WhitelistMiddleware;

readonly class Kernel
{
    public static function boot(string $botlockRoot): static
    {
        try
        {
            $config = new Config();
            $jwt = new JWT($config);
            $session = new Session($config, $jwt);
            $whitelist = new Whitelist($config);

            return new static($config, $session, $whitelist, $botlockRoot);
        }
        catch (\Throwable $th)
        {
            abort(500, headers: [
                'Botlock-Error: ' . $th->getMessage(),
            ]);
        }
    }

    public function __construct(
        private Config    $config,
        private Session   $session,
        private Whitelist $whitelist,
        private string    $projectRoot,
    ) {}

    public function handleRequest(Request $request): void
    {
        $middleware = new MiddlewareDispatcher();

        $middleware
            ->add(new ErrorMiddleware())
            ->add(new WhitelistMiddleware($this->whitelist))
            ->add(new ProofOfWorkMiddleware($this->config, $this->session, $this->whitelist))
            ->add(new ChallengeDocumentMiddleware($this->session, $this->projectRoot))
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