<?php declare(strict_types=1);

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Manager\ConfigManager;
use GES\Botlock\Manager\WhitelistManager;
use GES\Botlock\Middleware\VerifyCrawlerMiddleware;
use GES\Botlock\Middleware\WhoIsMiddleware;

require_once __DIR__ . '/../src/utils.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'GES\\Botlock\\')) {
        return;
    }

    $relative = substr($class, strlen('GES\\Botlock\\'));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

final class TestConfigManager extends ConfigManager
{
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly bool $dnsChecks = true,
        private readonly array $verifyBots = [],
        private readonly ?array $ignoreIps = null,
    ) {}

    public function getTrustedProxies(): ?array
    {
        return $this->trustedProxies;
    }

    public function getDnsChecks(): bool
    {
        return $this->dnsChecks;
    }

    public function getVerifyBots(): array
    {
        return $this->verifyBots;
    }

    public function getIgnoreIps(): ?array
    {
        return $this->ignoreIps;
    }
}

final class StubBotTestManager extends BotTestManager
{
    public function __construct(ConfigManager $config, private readonly bool $crawler)
    {
        parent::__construct($config);
    }

    public function isCrawler(): bool
    {
        return $this->crawler;
    }
}

/** @psalm-suppress UndefinedMagicPropertyFetch */
function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $prefix = $message ? $message . ' - ' : '';
        throw new RuntimeException($prefix . 'Expected ' . var_export($expected, true) . ' got ' . var_export($actual, true));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new RuntimeException($message ?: 'Failed asserting that condition is true');
    }
}

function makeRequest(array $server, array $headers = []): Request
{
    return new Request(
        method: 'GET',
        requestUrl: 'https://example.com/status',
        secure: true,
        server: $server,
        headers: $headers,
    );
}

function runWhoIs(WhoIsMiddleware $middleware, Request $request): void
{
    $middleware->process($request, static fn (Request $req): Response => new Response());
}

// --- IPv4 direct connection ---
$config = new TestConfigManager();
$middleware = new WhoIsMiddleware($config);
$request = makeRequest(['REMOTE_ADDR' => '198.51.100.7']);

runWhoIs($middleware, $request);
assertSame('198.51.100.7', $request->clientIp, 'Direct IPv4 should be bound');

// --- IPv6 direct connection ---
$ipv6Request = makeRequest(['REMOTE_ADDR' => '2001:db8::1']);
runWhoIs($middleware, $ipv6Request);
assertSame('2001:db8::1', $ipv6Request->clientIp, 'Direct IPv6 should be bound');

// --- Trusted proxy chain ---
$proxyConfig = new TestConfigManager(trustedProxies: ['203.0.113.5']);
$proxyMiddleware = new WhoIsMiddleware($proxyConfig);
$proxiedRequest = makeRequest(
    ['REMOTE_ADDR' => '203.0.113.5'],
    ['X-Forwarded-For' => '198.51.100.99, 203.0.113.5']
);

runWhoIs($proxyMiddleware, $proxiedRequest);
assertSame('198.51.100.99', $proxiedRequest->clientIp, 'Trusted proxy should expose client IP');

// --- Downstream VerifyCrawlerMiddleware observes bound IP ---
$verifyConfig = new TestConfigManager(
    trustedProxies: ['203.0.113.5'],
    dnsChecks: true,
    verifyBots: ['google' => true],
);
$verifyRequest = makeRequest(
    ['REMOTE_ADDR' => '203.0.113.5'],
    ['X-Forwarded-For' => '198.51.100.42, 203.0.113.5', 'User-Agent' => 'googlebot/1.0']
);

$whoIsForVerify = new WhoIsMiddleware($verifyConfig);
runWhoIs($whoIsForVerify, $verifyRequest);
assertSame('198.51.100.42', $verifyRequest->clientIp, 'Verify chain should bind client IP');

$verifyRequest->bind('threatLevel', 0);
$verifyRequest->bind('threatLevelIndividual', 0);
$verifyRequest->bind('threatLevelGlobal', 0);

$verifyMiddleware = new VerifyCrawlerMiddleware(new StubBotTestManager($verifyConfig, true), $verifyConfig);
$verifyMiddleware->process($verifyRequest, static fn (Request $req): Response => new Response());

assertSame(2, $verifyRequest->threatLevel, 'VerifyCrawlerMiddleware should adjust threat level when IP is present');

// --- WhitelistManager consumes bound IP ---
$whitelistConfig = new TestConfigManager(ignoreIps: ['198.51.100.42']);
$whitelist = new WhitelistManager($whitelistConfig);

assertTrue($whitelist->isRequestWhitelisted($verifyRequest), 'WhitelistManager should respect bound client IP');

echo "All WhoIsMiddleware tests passed\n";
