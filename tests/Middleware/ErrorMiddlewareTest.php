<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ErrorMiddlewareTest extends TestCase
{
    private const SECRET_TEXT = "db password hunter2 in /etc/app.ini\r\nX-Injected: yes";

    private string $errorLog;
    private string|false $previousErrorLog;

    protected function setUp(): void
    {
        $this->errorLog = \tempnam(\sys_get_temp_dir(), 'botlock-errlog');
        $this->previousErrorLog = \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', (string) $this->previousErrorLog);
        @\unlink($this->errorLog);
    }

    public function testPassesResponseThroughWhenNothingThrows(): void
    {
        $response = (new ErrorMiddleware)->process(Requests::make(), static fn(): Response => new Response(204));

        self::assertSame(204, $response->getStatus());
        self::assertFalse($response->hasHeader('Botlock-Error'));
    }

    public function testUnexpectedThrowableBecomesGenericInternalError(): void
    {
        $response = (new ErrorMiddleware)->process(Requests::make(), $this->throwing());

        self::assertSame(500, $response->getStatus());
        self::assertSame('Internal Server Error', $response->getHeader('Botlock-Error'));
        self::assertSame('Internal Server Error', $response->getBody());
        self::assertStringNotContainsString('hunter2', \implode("\n", $response->getHeaders()));
    }

    public function testJsonClientGetsGenericJsonBody(): void
    {
        $request = Requests::make(headers: ['Accept' => 'application/json']);

        $response = (new ErrorMiddleware)->process($request, $this->throwing());

        self::assertSame(500, $response->getStatus());
        self::assertSame(['ok' => false, 'error' => 'Internal Server Error'], \json_decode((string) $response->getBody(), true));
        self::assertStringNotContainsString('hunter2', (string) $response->getBody());
    }

    public function testExceptionDetailsGoToTheErrorLog(): void
    {
        (new ErrorMiddleware)->process(Requests::make(), $this->throwing());

        $log = (string) \file_get_contents($this->errorLog);

        self::assertStringContainsString('RuntimeException', $log);
        self::assertStringContainsString('hunter2', $log);
    }

    public function testJsonResponseExceptionKeepsItsStatusAndMessage(): void
    {
        $next = static function (): Response {
            throw new JsonResponseException("Invalid\r\nnonce", 400);
        };

        $response = (new ErrorMiddleware)->process(Requests::make(), $next);

        self::assertSame(400, $response->getStatus());
        self::assertSame('Invalid  nonce', $response->getHeader('Botlock-Error'));
        self::assertSame(['ok' => false, 'error' => "Invalid\r\nnonce", 'code' => 400], \json_decode((string) $response->getBody(), true));
    }

    private function throwing(): callable
    {
        return static function (Request $request): Response {
            throw new \RuntimeException(self::SECRET_TEXT);
        };
    }
}
