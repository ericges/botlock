<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Action\ActionHandlerInterface;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

/**
 * Routes ?_botlock=<action> requests to their handler. Requests without an
 * action, or with an unknown one, continue down the middleware stack.
 */
final readonly class ActionMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, ActionHandlerInterface> $handlers keyed by "<METHOD> <action>",
     *                                                        e.g. "GET challenge" (see Request::getBotlockAction())
     */
    public function __construct(private array $handlers)
    {
        foreach ($handlers as $key => $handler) {
            if (!\is_string($key) || !$handler instanceof ActionHandlerInterface) {
                throw new \InvalidArgumentException('Action handlers must be keyed by action string and implement ActionHandlerInterface');
            }
        }
    }

    public function process(Request $request, callable $next): Response
    {
        $action = $request->getBotlockAction();

        if ($action === null || !isset($this->handlers[$action])) {
            return $next($request);
        }

        return $this->handlers[$action]->handle($request);
    }
}
