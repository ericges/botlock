<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Http;

use GES\Botlock\Http\Middleware\MiddlewareDispatcher;
use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\PassResponse;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class MiddlewareDispatcherTest extends TestCase
{
    public function testEmptyStackPasses(): void
    {
        self::assertInstanceOf(PassResponse::class, (new MiddlewareDispatcher())->dispatch(Requests::make()));
    }

    public function testMiddlewaresRunInRegistrationOrderAndUnwindInReverse(): void
    {
        $log = [];

        $dispatcher = (new MiddlewareDispatcher())
            ->add($this->logging('a', $log))
            ->add($this->logging('b', $log))
            ->add($this->logging('c', $log));

        $response = $dispatcher->dispatch(Requests::make());

        self::assertInstanceOf(PassResponse::class, $response);
        self::assertSame(['a:in', 'b:in', 'c:in', 'c:out', 'b:out', 'a:out'], $log);
    }

    public function testShortCircuitSkipsLaterMiddlewares(): void
    {
        $log = [];

        $dispatcher = (new MiddlewareDispatcher())
            ->add($this->logging('a', $log))
            ->add(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return new Response(418);
                }
            })
            ->add($this->logging('never', $log));

        $response = $dispatcher->dispatch(Requests::make());

        self::assertSame(418, $response->getStatus());
        self::assertSame(['a:in', 'a:out'], $log);
    }

    public function testRequestObjectIsSharedDownTheChain(): void
    {
        $dispatcher = (new MiddlewareDispatcher())
            ->add(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    $request->context->fingerprint = 'set-upstream';
                    return $next($request);
                }
            })
            ->add(new class implements MiddlewareInterface {
                public function process(Request $request, callable $next): Response
                {
                    return new Response(200, [], $request->context->fingerprint);
                }
            });

        self::assertSame('set-upstream', $dispatcher->dispatch(Requests::make())->getBody());
    }

    private function logging(string $name, array &$log): MiddlewareInterface
    {
        return new class($name, $log) implements MiddlewareInterface {
            public function __construct(private readonly string $name, private array &$log) {}

            public function process(Request $request, callable $next): Response
            {
                $this->log[] = "$this->name:in";
                $response = $next($request);
                $this->log[] = "$this->name:out";
                return $response;
            }
        };
    }
}
