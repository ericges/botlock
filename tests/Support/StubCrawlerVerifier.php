<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Support;

use GES\Botlock\Crawler\CrawlerVerification;
use GES\Botlock\Crawler\CrawlerVerifier;

/**
 * Test double: returns a fixed verification result and records every call.
 */
final class StubCrawlerVerifier implements CrawlerVerifier
{
    /** @var list<array{string,string}> [provider, ip] per call */
    public array $calls = [];

    public function __construct(private readonly CrawlerVerification $result = CrawlerVerification::Verified) {}

    public function verify(string $provider, string $ip): CrawlerVerification
    {
        $this->calls[] = [$provider, $ip];

        return $this->result;
    }
}
