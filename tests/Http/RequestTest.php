<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Http;

use GES\Botlock\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    #[DataProvider('origins')]
    public function testFromGlobalsBuildsOriginWithoutDuplicatingPort(array $server, string $expectedOrigin, string $expectedHost): void
    {
        $_SERVER = $server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/path?x=1', 'REMOTE_ADDR' => '203.0.113.1'];

        $request = Request::fromGlobals();

        self::assertSame($expectedOrigin, $request->getOrigin());
        self::assertSame($expectedHost, $request->getHost());
        self::assertSame($expectedOrigin . '/path?x=1', $request->getRequestUrl());
    }

    public static function origins(): iterable
    {
        yield 'https default port' => [
            ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test', 'SERVER_PORT' => '443'],
            'https://example.test', 'example.test',
        ];
        yield 'http default port' => [
            ['HTTP_HOST' => 'example.test', 'SERVER_PORT' => '80'],
            'http://example.test', 'example.test',
        ];
        yield 'host header already has the port' => [
            ['HTTP_HOST' => '127.0.0.1:8099', 'SERVER_PORT' => '8099'],
            'http://127.0.0.1:8099', '127.0.0.1',
        ];
        yield 'host header without port on a custom port' => [
            ['HTTP_HOST' => 'example.test', 'SERVER_PORT' => '8080'],
            'http://example.test:8080', 'example.test',
        ];
        yield 'falls back to SERVER_NAME' => [
            ['SERVER_NAME' => 'fallback.test', 'SERVER_PORT' => '80'],
            'http://fallback.test', 'fallback.test',
        ];
        yield 'no SERVER_PORT' => [
            ['HTTP_HOST' => 'example.test'],
            'http://example.test', 'example.test',
        ];
        yield 'https without SERVER_PORT' => [
            ['HTTPS' => 'on', 'HTTP_HOST' => 'example.test'],
            'https://example.test', 'example.test',
        ];
    }

    public function testBotlockActionIsMethodPlusLowercasedName(): void
    {
        self::assertSame('POST verify', $this->requestWithAction('post', 'verify')->getBotlockAction());
        self::assertSame('GET status', $this->requestWithAction('GET', 'STATUS')->getBotlockAction());
        self::assertNull((new Request('GET', 'https://example.test/'))->getBotlockAction());
    }

    #[DataProvider('malformedActions')]
    public function testMalformedBotlockActionIsIgnored(mixed $value): void
    {
        self::assertNull($this->requestWithAction('GET', $value)->getBotlockAction());
    }

    public static function malformedActions(): iterable
    {
        yield 'array from ?_botlock[]=status' => [['status']];
        yield 'punctuation' => ['Ver-ify!'];
        yield 'empty' => [''];
        yield 'whitespace' => ['status '];
        yield 'nested array' => [['a' => ['status']]];
    }

    private function requestWithAction(string $method, mixed $value): Request
    {
        return new Request($method, 'https://example.test/', queryParams: ['_botlock' => $value]);
    }

    public function testJsonBodyAndPostAccessors(): void
    {
        $request = new Request('POST', 'https://example.test/', body: '{"num": 5}', postParams: ['location' => '/x']);

        self::assertSame(['num' => 5], $request->getJsonBody());
        self::assertSame('/x', $request->getPost('location'));
        self::assertNull($request->getPost('missing'));
        self::assertNull((new Request('POST', 'https://example.test/', body: 'not json'))->getJsonBody());
        self::assertNull((new Request('POST', 'https://example.test/', body: '"scalar"'))->getJsonBody());
    }

    public function testAbsoluteUrlStaysOnOrigin(): void
    {
        $request = new Request('GET', 'https://example.test/a/b');

        self::assertSame('https://example.test/foo', $request->getAbsoluteUrl('/foo'));
        self::assertSame('https://example.test/foo', $request->getAbsoluteUrl('foo/'));
        self::assertSame('https://example.test', $request->getAbsoluteUrl('/'));
    }
}
