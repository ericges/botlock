<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Middleware;

use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Manager\ThreatAwarenessManager;
use GES\Botlock\Middleware\ThreatEvaluationMiddleware;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

final class ThreatEvaluationMiddlewareTest extends TestCase
{
    private const FP = 'abcdef0123456789';

    public function testOverrideSkipsRateEvaluationEntirely(): void
    {
        $store = new InMemoryThreatStateStore();

        $request = $this->evaluate(new RateLimitConfig(threatLevelOverride: 2), $store);

        self::assertSame(2, $request->context->threatLevel);
        self::assertNull($request->context->threatLevelGlobal);
        self::assertNull($request->context->threatLevelIndividual);
        self::assertSame([], $store->global);
        self::assertSame([], $store->individual);
    }

    public function testDisabledRateLimitFailsClosedToLevelOne(): void
    {
        $store = new InMemoryThreatStateStore();

        $request = $this->evaluate(new RateLimitConfig(enableRateLimit: false), $store);

        self::assertSame(1, $request->context->threatLevel);
        self::assertSame([], $store->global);
        self::assertSame([], $store->individual);
    }

    public function testGlobalOnlyMode(): void
    {
        $store = new InMemoryThreatStateStore();
        // 204 existing hits plus this request weigh 205 * 1.05^8 ≈ 302.9 → level 2
        $store->global = ['current_level' => 0, 'level_last_changed' => 0, 'traffic_buckets' => [(string) \intdiv(\time(), 60) => 204]];

        $request = $this->evaluate(new RateLimitConfig(enableIndividualRateLimit: false), $store);

        self::assertSame(2, $request->context->threatLevel);
        self::assertSame(2, $request->context->threatLevelGlobal);
        self::assertNull($request->context->threatLevelIndividual);
        self::assertNull($request->context->individualRate);
        self::assertSame([], $store->individual);
    }

    public function testIndividualOnlyMode(): void
    {
        $store = new InMemoryThreatStateStore();
        $store->individual = [self::FP => \array_fill(0, 3, \time())];
        $config = new RateLimitConfig(
            enableGlobalRateLimit: false,
            level1ThresholdIndividual: 2,
            level2ThresholdIndividual: 3,
            level3ThresholdIndividual: 4,
            gcProbability: 0,
        );

        $request = $this->evaluate($config, $store);

        self::assertSame(3, $request->context->threatLevel);
        self::assertSame(3, $request->context->threatLevelIndividual);
        self::assertSame(4, $request->context->individualRate);
        self::assertNull($request->context->threatLevelGlobal);
        self::assertSame([], $store->global);
    }

    public function testIndividualRateReachesLevelFour(): void
    {
        $store = new InMemoryThreatStateStore();
        $store->individual = [self::FP => \array_fill(0, 4, \time())];
        $config = new RateLimitConfig(
            level1ThresholdIndividual: 2,
            level2ThresholdIndividual: 3,
            level3ThresholdIndividual: 4,
            level4ThresholdIndividual: 5,
            gcProbability: 0,
        );

        $request = $this->evaluate($config, $store);

        self::assertSame(5, $request->context->individualRate);
        self::assertSame(4, $request->context->threatLevelIndividual);
        self::assertSame(4, $request->context->threatLevel);
    }

    public function testEffectiveLevelIsTheHigherOfGlobalAndIndividual(): void
    {
        $store = new InMemoryThreatStateStore();
        $store->global = ['current_level' => 0, 'level_last_changed' => 0, 'traffic_buckets' => [(string) \intdiv(\time(), 60) => 100]]; // → level 1
        $store->individual = [self::FP => \array_fill(0, 2, \time())];
        $config = new RateLimitConfig(level1ThresholdIndividual: 2, level2ThresholdIndividual: 3, level3ThresholdIndividual: 99, gcProbability: 0);

        $request = $this->evaluate($config, $store);

        self::assertSame(1, $request->context->threatLevelGlobal);
        self::assertSame(2, $request->context->threatLevelIndividual);
        self::assertSame(2, $request->context->threatLevel);
    }

    public function testUnavailableIndividualStateFailsClosedInIndividualOnlyMode(): void
    {
        $request = $this->evaluate(
            new RateLimitConfig(enableGlobalRateLimit: false, gcProbability: 0),
            new InMemoryThreatStateStore(failing: true),
        );

        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $request->context->threatLevel);
        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $request->context->threatLevelIndividual);
        self::assertNull($request->context->individualRate);
    }

    public function testUnavailableGlobalStateFailsClosedInGlobalOnlyMode(): void
    {
        $request = $this->evaluate(
            new RateLimitConfig(enableIndividualRateLimit: false),
            new InMemoryThreatStateStore(failing: true),
        );

        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $request->context->threatLevel);
        self::assertSame(ThreatAwarenessManager::UNAVAILABLE_LEVEL, $request->context->threatLevelGlobal);
    }

    public function testMissingFingerprintIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->evaluate(new RateLimitConfig(gcProbability: 0), new InMemoryThreatStateStore(), fingerprint: null);
    }

    private function evaluate(RateLimitConfig $config, InMemoryThreatStateStore $store, ?string $fingerprint = self::FP): Request
    {
        $request = Requests::make();
        $request->context->fingerprint = $fingerprint;

        $middleware = new ThreatEvaluationMiddleware($config, new ThreatAwarenessManager($config, $store));
        $response = $middleware->process($request, static fn(Request $r): Response => new Response(204));

        self::assertSame(204, $response->getStatus());

        return $request;
    }
}
