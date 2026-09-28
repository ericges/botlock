<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Action;

use GES\Botlock\Action\ChallengeAction;
use GES\Botlock\Action\InteractAction;
use GES\Botlock\Action\TicketService;
use GES\Botlock\Action\VerifyAction;
use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Session;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Tests\Support\InMemoryChallengeTicketStore;
use GES\Botlock\Tests\Support\Reports;
use GES\Botlock\Tests\Support\Tracks;
use GES\Botlock\Tests\Support\Requests;
use PHPUnit\Framework\TestCase;

/**
 * GET challenge → (POST challenge) → POST verify, against one shared
 * session, store and clock.
 */
final class TicketFlowTest extends TestCase
{
    private const FP = 'fingerprint-a';
    private const NONCE = '123e4567-e89b-42d3-a456-426614174000';

    private ProofOfWorkConfig $config;
    private InMemoryChallengeTicketStore $store;
    private Session $session;
    private float $now;
    private ?string $originalUserAgent;

    protected function setUp(): void
    {
        $this->originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0';

        $this->config = new ProofOfWorkConfig(secret: 'test-secret', maxNumber: 200, minSolveMs: 1000);
        $this->store = new InMemoryChallengeTicketStore();
        $this->session = self::session();
        $this->now = \microtime(true);
    }

    protected function tearDown(): void
    {
        if ($this->originalUserAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $this->originalUserAgent;
        }
    }

    public function testLevelOneBrowserGetsTheProofRightAway(): void
    {
        $challenge = $this->challenge(level: 1);

        self::assertSame(1, $challenge['lvl']);
        self::assertSame('none', $challenge['int']);
        self::assertSame(1000, $challenge['min_ms']);
        self::assertArrayHasKey('pow', $challenge);
        self::assertSame($challenge['exp'], $challenge['pow']['exp'], 'the proof expires with the ticket');

        $this->now += 1.5;
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 1));
        self::assertSame(1, $this->session->get('grant'));
        self::assertNull($this->session->get('nh'));
    }

    public function testLevelTwoBrowserGetsTheProofOnlyAfterTheClick(): void
    {
        $challenge = $this->challenge(level: 2);

        self::assertSame('click', $challenge['int']);
        self::assertArrayNotHasKey('pow', $challenge);

        $ready = $this->interact($challenge, level: 2);
        self::assertSame($challenge['cid'], $ready['cid']);
        self::assertArrayHasKey('pow', $ready);

        $this->now += 1.5;
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
        self::assertSame(2, $this->session->get('grant'));
    }

    public function testLevelThreeSliderMustHitTheGap(): void
    {
        $challenge = $this->challenge(level: 3);

        self::assertSame('slider', $challenge['int']);
        self::assertArrayNotHasKey('pow', $challenge);
        self::assertArrayHasKey('puzzle', $challenge);
        $target = $this->store->find($challenge['cid'])->sliderTarget;
        self::assertIsInt($target);
        self::assertStringNotContainsString('"' . $target . '"', \json_encode($challenge), 'the target is not in the answer');

        $this->now += 3;
        $ready = $this->interact($challenge, level: 3, report: self::slide($target + 3));
        self::assertSame(200, $ready['pow']['max'], 'a drag keeps the difficulty');

        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 3));
        self::assertSame(3, $this->session->get('grant'));
    }

    public function testSliderSolvedByKeysGetsAHarderProof(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->find($challenge['cid'])->sliderTarget;
        $track = Tracks::keyboard($target);

        $this->now += Tracks::end($track) / 1000 + 1;
        $ready = $this->interact($challenge, level: 3, report: ['pos' => $target, 'track' => $track]);

        self::assertSame(800, $ready['pow']['max'], 'the difficulty times the assisted factor');
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 3));
    }

    public function testMissedSliderSpendsTheTicket(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: self::slide($target + 20)));
        $this->assertRejected(400, fn() => $this->interact($challenge, level: 3, report: self::slide($target)));
    }

    public function testSliderWithoutPositionOrTrackIsRejected(): void
    {
        foreach ([[], ['pos' => 0], 'no track' => ['pos' => null], 'string pos' => null] as $case => $report) {
            $challenge = $this->challenge(level: 3);
            $target = $this->store->find($challenge['cid'])->sliderTarget;
            $report = match ($case) {
                'no track' => ['pos' => $target],
                'string pos' => ['pos' => (string) $target, 'track' => Tracks::humanDrag($target)],
                default => $report,
            };

            $this->now += 3;
            $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: $report));
        }
    }

    public function testScriptedSlideIsRejectedLikeAMiss(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: ['pos' => $target, 'track' => Tracks::linearDrag($target)]));
        self::assertSame([], $this->store->tickets, 'the ticket is spent');
    }

    public function testSlideAnsweredTooSoonIsRejected(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 0.5;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: self::slide($target)));
    }

    public function testOnlyInteractiveChallengesCarryAKey(): void
    {
        self::assertArrayNotHasKey('key', $this->challenge(level: 1));
        self::assertSame(32, \strlen(\base64_decode($this->challenge(level: 3)['key'], true)));

        $ready = $this->interact($this->challenge(level: 2), level: 2);
        self::assertArrayNotHasKey('key', $ready, 'the proof answer does not echo the key');
    }

    public function testUnsealedReportIsRejected(): void
    {
        $challenge = $this->challenge(level: 3);
        $target = $this->store->find($challenge['cid'])->sliderTarget;
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $request = $this->request('POST', level: 3, body: ['cid' => $challenge['cid'], 'pos' => $target]);

        $this->assertRejected(403, fn() => $action->handle($request));
        self::assertSame([], $this->store->tickets, 'the ticket is spent');
    }

    public function testReportSealedWithAnotherKeyIsRejected(): void
    {
        $challenge = $this->challenge(level: 2);
        $challenge['key'] = $this->challenge(level: 2)['key'];

        $this->assertRejected(403, fn() => $this->interact($challenge, level: 2));
    }

    public function testVerifyingWithoutTheInteractionIsRejected(): void
    {
        $challenge = $this->challenge(level: 2);
        $pow = (new \GES\Botlock\Challenge\ProofOfWork($this->config))->create(self::FP, $challenge['cid'] . '|none|2');

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($pow), level: 2));
        self::assertSame([], $this->store->tickets, 'a failed attempt consumes the ticket');
    }

    public function testProofForgedWithAnotherInteractionFailsTheSignature(): void
    {
        $challenge = $this->challenge(level: 2);
        $this->interact($challenge, level: 2);

        // A solution signed for the same ticket id but a lower level must not verify.
        $challenge1 = $this->challenge(level: 1);
        $foreign = self::solve($challenge1['pow']);

        $this->now += 1.5;
        self::assertSame(401, $this->verify($challenge['cid'], $foreign, level: 2));
        self::assertNull($this->session->get('grant'));
    }

    public function testInteractingTwiceIsRejected(): void
    {
        $challenge = $this->challenge(level: 2);
        $this->interact($challenge, level: 2);

        $this->assertRejected(400, fn() => $this->interact($challenge, level: 2));
    }

    public function testInteractingWithASelfStartingTicketIsRejected(): void
    {
        $challenge = $this->challenge(level: 1);

        $this->assertRejected(400, fn() => $this->interact($challenge, level: 1));
    }

    public function testVerifyingTooFastIsRejected(): void
    {
        $challenge = $this->challenge(level: 1);

        $this->now += 0.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 1));
        self::assertNull($this->session->get('grant'));
    }

    public function testTicketIsSingleUse(): void
    {
        $challenge = $this->challenge(level: 1);
        $solution = self::solve($challenge['pow']);

        $this->now += 1.5;
        self::assertSame(200, $this->verify($challenge['cid'], $solution, level: 1));

        $this->session->set('nh', \password_hash(self::NONCE, \PASSWORD_DEFAULT));
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], $solution, level: 1));
    }

    public function testEscalationMidFlowAsksForARestart(): void
    {
        $challenge = $this->challenge(level: 1);

        $this->now += 1.5;
        $this->assertRejected(409, fn() => $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 2));
        self::assertNull($this->session->get('grant'));

        $challenge = $this->challenge(level: 2);
        $this->assertRejected(409, fn() => $this->interact($challenge, level: 3));
        self::assertSame([], $this->store->tickets);
    }

    public function testDeescalationKeepsTheTicketsLevel(): void
    {
        $challenge = $this->challenge(level: 2);
        $ready = $this->interact($challenge, level: 2);

        $this->now += 1.5;
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 1));
        self::assertSame(2, $this->session->get('grant'));
    }

    public function testExpiredTicketIsRejected(): void
    {
        $challenge = $this->challenge(level: 1);

        $this->now += ChallengeTicket::TTL + 2;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 1));
    }

    public function testTicketOfAnotherClientIsRejected(): void
    {
        $challenge = $this->challenge(level: 1);
        $solution = self::solve($challenge['pow']);

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], $solution, level: 1, fingerprint: 'fingerprint-b'));
        self::assertNotNull($this->store->find($challenge['cid']), 'the foreign attempt leaves the ticket');
        self::assertSame(200, $this->verify($challenge['cid'], $solution, level: 1), 'the owner can still redeem it');
    }

    public function testMissingNonceIsRejected(): void
    {
        $request = $this->request('GET', level: 1, headers: []);

        $this->assertRejected(400, fn() => $this->challengeAction()->handle($request));
    }

    public function testIssuedIdCarriesTheIssueMinute(): void
    {
        $challenge = $this->challenge(level: 1);

        self::assertSame(\intdiv((int) $this->now, 60), ChallengeTicket::issueMinute($challenge['cid']));
    }

    public function testSweepBudgetOutpacesTheTicketsIssuedBetweenSweeps(): void
    {
        $this->challenge(level: 1, gcProbability: 1);

        self::assertSame([[$this->now - ChallengeTicket::MAX_LIFETIME - 60, 2]], $this->store->gcCalls);
    }

    public function testASlowInteractionStillGetsAFullProofWindow(): void
    {
        $challenge = $this->challenge(level: 2);

        $this->now += ChallengeTicket::TTL - 10;
        $ready = $this->interact($challenge, level: 2);
        self::assertSame((int) \floor($this->now) + ChallengeTicket::TTL, $ready['exp']);
        self::assertSame($ready['exp'], $ready['pow']['exp'], 'the proof signs the renewed deadline');

        $this->now += 200;
        self::assertGreaterThan($challenge['exp'], $this->now, 'past the first deadline');
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
    }

    public function testSweepKeepsARenewedTicket(): void
    {
        $challenge = $this->challenge(level: 2);
        $this->now += 250;
        $ready = $this->interact($challenge, level: 2);

        $this->now += 200;
        $this->challenge(level: 1, gcProbability: 1);

        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
    }

    public function testUnwritableStoreAnswers503(): void
    {
        $this->store->failing = true;

        $this->assertRejected(503, fn() => $this->challenge(level: 1));
    }

    private function challenge(int $level, int $gcProbability = 0): array
    {
        return self::json($this->challengeAction($gcProbability)->handle($this->request('GET', $level)));
    }

    /**
     * Reports the interaction sealed with the challenge's key, the way the
     * challenge page does; a challenge without a key sends only its id.
     */
    private function interact(array $challenge, int $level, array $report = []): array
    {
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $body = isset($challenge['key'])
            ? Reports::seal(\base64_decode($challenge['key']), $challenge['cid'], $report)
            : ['cid' => $challenge['cid']];

        return self::json($action->handle($this->request('POST', $level, body: $body)));
    }

    private function verify(string $cid, array $solution, int $level, string $fingerprint = self::FP): int
    {
        $action = new VerifyAction($this->config, $this->service());

        return $action->handle($this->request('POST', $level, body: $solution + ['cid' => $cid], fingerprint: $fingerprint))->getStatus();
    }

    private function challengeAction(int $gcProbability = 0): ChallengeAction
    {
        return new ChallengeAction(
            new BotTestManager(new DetectionConfig()),
            new InteractionPolicy($this->config),
            $this->service($gcProbability),
        );
    }

    private function service(int $gcProbability = 0): TicketService
    {
        return new TicketService($this->store, $this->config, $gcProbability, fn(): float => $this->now);
    }

    private function request(string $method, int $level, ?array $body = null, string $fingerprint = self::FP, ?array $headers = null): Request
    {
        $request = Requests::make(
            method: $method,
            headers: $headers ?? ['Botlock-Nonce' => self::NONCE],
            body: $body === null ? '' : \json_encode($body),
        );
        $request->context->fingerprint = $fingerprint;
        $request->context->threatLevel = $level;
        $request->context->session = $this->session;

        return $request;
    }

    private function assertRejected(int $status, \Closure $call): void
    {
        try {
            $call();
            self::fail("expected a $status rejection");
        } catch (JsonResponseException $exception) {
            self::assertSame($status, $exception->getCode(), $exception->getMessage());
        }
    }

    private static function json(\GES\Botlock\Http\Response $response): array
    {
        self::assertSame(200, $response->getStatus());

        return \json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function session(): Session
    {
        return new Session(secret: 'test-secret', ttl: 300, sub: self::FP, origin: 'https://example.test', host: 'example.test', secure: true);
    }

    /**
     * A human-like drag ending at $pos, as the slider's report.
     */
    private static function slide(int $pos): array
    {
        return ['pos' => $pos, 'track' => Tracks::humanDrag($pos)];
    }

    private static function solve(array $pow): array
    {
        for ($i = 1; $i <= $pow['max']; $i++) {
            if (\hash($pow['alg'], "$i:{$pow['slt']}:{$pow['exp']}") === $pow['tgt']) {
                return ['num' => $i, 'sig' => $pow['sig'], 'slt' => $pow['slt'], 'exp' => $pow['exp'], 'alg' => $pow['alg']];
            }
        }

        self::fail('unsolvable proof of work');
    }
}
