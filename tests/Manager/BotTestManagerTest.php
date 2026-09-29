<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Manager;

use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Http\RequestContext;
use GES\Botlock\Manager\BotTestManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BotTestManagerTest extends TestCase
{
    private ?string $originalUserAgent;

    protected function setUp(): void
    {
        $this->originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->originalUserAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->originalUserAgent;
        }
    }

    #[DataProvider('userAgents')]
    public function testCrawlerClassification(string $userAgent, bool $crawler, bool $goodBot, ?string $provider): void
    {
        $manager = $this->manager($userAgent);

        self::assertSame($crawler, $manager->isCrawler());
        self::assertSame($goodBot, $manager->isGoodBot());
        self::assertSame($provider, $manager->getVerifyProvider());
        self::assertSame($crawler, $manager->getCrawlerMatch() !== null);
    }

    public static function userAgents(): iterable
    {
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', true, true, 'google'];
        yield 'lowercase googlebot' => ['googlebot/2.1', true, true, 'google'];
        yield 'AdsBot' => ['AdsBot-Google (+http://www.google.com/adsbot.html)', true, true, 'google'];
        yield 'bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', true, true, 'bing'];
        yield 'DuckDuckBot has no verifier' => ['DuckDuckBot/1.1; (+http://duckduckgo.com/duckduckbot.html)', true, true, null];
        yield 'unknown crawler' => ['Scrapy/2.11 (+https://scrapy.org)', true, false, null];
        yield 'browser' => ['Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0', false, false, null];
    }

    public function testEmptyProviderListVerifiesNobody(): void
    {
        $manager = $this->manager('Googlebot/2.1', new DetectionConfig(verifyBots: []));

        self::assertTrue($manager->isGoodBot());
        self::assertNull($manager->getVerifyProvider());
    }

    public function testCustomGoodBotList(): void
    {
        $manager = $this->manager('Scrapy/2.11 (+https://scrapy.org)', new DetectionConfig(goodBots: ['scrapy']));

        self::assertTrue($manager->isGoodBot());
    }

    public function testTrustedGoodBotRequiresVerifiedOrNotApplicableState(): void
    {
        $expectations = [
            'verified' => [CrawlerVerification::Verified, true],
            'not applicable' => [CrawlerVerification::NotApplicable, true],
            'failed' => [CrawlerVerification::Failed, false],
            'unverified' => [CrawlerVerification::Unverified, false],
            'never ran' => [null, false],
        ];

        foreach ($expectations as $label => [$state, $trusted]) {
            $context = new RequestContext();
            $context->crawlerVerification = $state;

            self::assertSame($trusted, $this->manager('Googlebot/2.1')->isTrustedGoodBot($context), $label);
        }

        $context = new RequestContext();
        $context->crawlerVerification = CrawlerVerification::Verified;
        self::assertFalse($this->manager('Scrapy/2.11')->isTrustedGoodBot($context), 'verification does not make an unlisted crawler good');
    }

    private function manager(string $userAgent, ?DetectionConfig $config = null): BotTestManager
    {
        // CrawlerDetect reads the User-Agent from $_SERVER
        $_SERVER['HTTP_USER_AGENT'] = $userAgent;

        return new BotTestManager($config ?? new DetectionConfig());
    }
}
