<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Action;

use GES\Botlock\Action\ChallengeAction;
use GES\Botlock\Action\InteractAction;
use GES\Botlock\Action\PuzzleAction;
use GES\Botlock\Action\TicketService;
use GES\Botlock\Action\VerifyAction;
use GES\Botlock\Challenge\ChallengeTicket;
use GES\Botlock\Challenge\InteractionPolicy;
use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Challenge\PuzzleBudget;
use GES\Botlock\Config\DetectionConfig;
use GES\Botlock\Config\ProofOfWorkConfig;
use GES\Botlock\Config\RateLimitConfig;
use GES\Botlock\Exception\JsonResponseException;
use GES\Botlock\Middleware\ErrorMiddleware;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Session;
use GES\Botlock\Manager\BotTestManager;
use GES\Botlock\Tests\Support\InMemoryChallengeTicketStore;
use GES\Botlock\Tests\Support\InMemoryThreatStateStore;
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
    private const OTHER_NONCE = '00000000-0000-4000-8000-000000000000';

    private ProofOfWorkConfig $config;
    private InMemoryChallengeTicketStore $store;
    private RateLimitConfig $rate;
    private InMemoryThreatStateStore $budgetStore;
    private Session $session;
    private float $now;
    private ?string $originalUserAgent;

    protected function setUp(): void
    {
        $this->originalUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) Firefox/130.0';

        $this->config = new ProofOfWorkConfig(secret: 'test-secret', maxNumber: 200, minSolveMs: 1000);
        $this->store = new InMemoryChallengeTicketStore();
        $this->rate = new RateLimitConfig(gcProbability: 0);
        $this->budgetStore = new InMemoryThreatStateStore();
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
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $ready = $this->interact($challenge, level: 3, report: self::slide($target + 3));
        self::assertSame(200, $ready['pow']['max'], 'a drag keeps the difficulty');

        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 3));
        self::assertSame(3, $this->session->get('grant'));
    }

    public function testOnlyInteractiveChallengesCarryAKey(): void
    {
        self::assertArrayNotHasKey('key', $this->challenge(level: 1));
        self::assertArrayNotHasKey('key', $this->challenge(level: 3), 'the slider key comes with its puzzle');
        self::assertSame(32, \strlen(\base64_decode($this->sliderChallenge()['key'], true)));

        $ready = $this->interact($this->challenge(level: 2), level: 2);
        self::assertArrayNotHasKey('key', $ready, 'the proof answer does not echo the key');
    }

    public function testSliderSolvedByKeysGetsAHarderProof(): void
    {
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;
        $track = Tracks::keyboard($target);

        $this->now += Tracks::end($track) / 1000 + 1;
        $ready = $this->interact($challenge, level: 3, report: ['pos' => $target, 'track' => $track]);

        self::assertSame(800, $ready['pow']['max'], 'the difficulty times the assisted factor');
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 3));
    }

    public function testMissedSliderSpendsTheTicket(): void
    {
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: self::slide($target + 20)));
        $this->assertRejected(400, fn() => $this->interact($challenge, level: 3, report: self::slide($target)));
    }

    public function testSliderWithoutPositionOrTrackIsRejected(): void
    {
        foreach ([[], ['pos' => 0], 'no track' => ['pos' => null], 'string pos' => null] as $case => $report) {
            $challenge = $this->sliderChallenge();
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
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 3;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: ['pos' => $target, 'track' => Tracks::linearDrag($target)]));
        self::assertSame([], $this->store->tickets, 'the ticket is spent');
    }

    public function testSlideAnsweredTooSoonIsRejected(): void
    {
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;

        $this->now += 0.5;
        $this->assertRejected(403, fn() => $this->interact($challenge, level: 3, report: self::slide($target)));
    }

    public function testUnsealedReportIsRejected(): void
    {
        $challenge = $this->sliderChallenge();
        $target = $this->store->find($challenge['cid'])->sliderTarget;
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $request = $this->request('POST', level: 3, body: ['cid' => $challenge['cid'], 'pos' => $target]);

        $this->assertRejected(403, fn() => $action->handle($request));
        self::assertSame([], $this->store->tickets, 'the ticket is spent');
    }

    public function testLevelThreeStartsAtTheGate(): void
    {
        $challenge = $this->challenge(level: 3);

        self::assertSame('slider', $challenge['int']);
        self::assertSame(200, $challenge['gate']['max'], 'the gate is at base difficulty');
        self::assertSame($challenge['exp'], $challenge['gate']['exp']);
        self::assertArrayNotHasKey('puzzle', $challenge);
        self::assertArrayNotHasKey('pow', $challenge);
        self::assertNull($this->store->find($challenge['cid'])->sliderTarget, 'nothing is rendered yet');
    }

    public function testCrawlersPayTheGateAtBaseDifficulty(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)';

        $challenge = $this->challenge(level: 3);

        self::assertSame('slider', $challenge['int']);
        self::assertSame(200, $challenge['gate']['max'], 'the crawler factor applies to the final proof only');
        self::assertSame(15.0, $this->store->find($challenge['cid'])->difficulty);
    }

    public function testTheGateOpensThePuzzle(): void
    {
        $challenge = $this->challenge(level: 3);
        $this->now += 2;
        $opened = $this->openPuzzle($challenge);
        $ticket = $this->store->find($opened['cid']);

        self::assertArrayHasKey('puzzle', $opened);
        self::assertSame(32, \strlen(\base64_decode($opened['key'], true)));
        self::assertIsInt($ticket->sliderTarget);
        self::assertSame($this->now, $ticket->puzzleAt);
        self::assertSame((int) \floor($this->now) + ChallengeTicket::TTL, $opened['exp'], 'the slider gets a phase of its own');
        self::assertStringNotContainsString('"' . $ticket->sliderTarget . '"', \json_encode($opened), 'the target is not in the answer');
    }

    public function testPuzzleRequiresTheTicketsNonce(): void
    {
        $challenge = $this->challenge(level: 3);

        $wrongNonce = ['Botlock-Nonce' => self::OTHER_NONCE];
        $this->assertRejected(400, fn() => $this->puzzle($challenge['cid'], self::solve($challenge['gate']), headers: $wrongNonce));

        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
        self::assertSame([], $this->budgetStore->individual, 'nothing was rendered or counted');
    }

    /**
     * Two tabs of one session each run their own challenge: the nonce is
     * kept with each ticket, so the second does not replace the first.
     */
    public function testEachTabRedeemsItsOwnTicket(): void
    {
        $first = $this->challenge(level: 2);
        $second = $this->challenge(level: 2, nonce: self::OTHER_NONCE);

        $firstReady = $this->interact($first, level: 2);
        $secondReady = $this->interact($second, level: 2, nonce: self::OTHER_NONCE);

        $this->now += 1.5;
        self::assertSame(200, $this->verify($first['cid'], self::solve($firstReady['pow']), level: 2));
        self::assertSame(200, $this->verify($second['cid'], self::solve($secondReady['pow']), level: 2, nonce: self::OTHER_NONCE));
    }

    public function testAnotherNonceSpendsTheTicket(): void
    {
        $challenge = $this->challenge(level: 1);
        self::assertStringNotContainsString(\hash('sha256', self::NONCE), \json_encode($challenge), 'the nonce hash stays on the server');

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 1, nonce: self::OTHER_NONCE));
        self::assertNull($this->store->find($challenge['cid']));
        self::assertNull($this->session->get('grant'));
    }

    public function testAProofForAnotherBindingDoesNotOpenThePuzzle(): void
    {
        $challenge = $this->challenge(level: 3);
        $foreign = (new ProofOfWork($this->config))->create(self::FP, $challenge['cid'] . '|slider|3', $challenge['exp']);

        $response = $this->puzzle($challenge['cid'], self::solve($foreign));

        self::assertSame(401, $response->getStatus());
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
        self::assertSame([], $this->budgetStore->individual, 'nothing was rendered or counted');
    }

    public function testTheGateSolutionDoesNotVerify(): void
    {
        $challenge = $this->challenge(level: 3);

        $this->now += 1.5;
        $this->assertRejected(400, fn() => $this->verify($challenge['cid'], self::solve($challenge['gate']), level: 3));
    }

    public function testOnlyAGateTicketOpensAPuzzle(): void
    {
        $click = $this->challenge(level: 2);
        $pow = (new ProofOfWork($this->config))->create(self::FP, $click['cid'] . '|gate|2', $click['exp']);
        $this->assertRejected(400, fn() => $this->puzzle($click['cid'], self::solve($pow), level: 2));

        $opened = $this->sliderChallenge();
        $pow = (new ProofOfWork($this->config))->create(self::FP, $opened['cid'] . '|gate|3', $opened['exp']);
        $this->assertRejected(400, fn() => $this->puzzle($opened['cid'], self::solve($pow)), 'no second puzzle for one ticket');
    }

    public function testSlidingBeforeThePuzzleIsRejected(): void
    {
        $this->assertRejected(400, fn() => $this->interact($this->challenge(level: 3), level: 3));
    }

    public function testTimeAtTheGateDoesNotCountAsLookingAtThePicture(): void
    {
        $challenge = $this->challenge(level: 3);
        $this->now += 5;
        $opened = $this->openPuzzle($challenge);
        $target = $this->store->find($opened['cid'])->sliderTarget;

        $this->now += 0.5;
        $this->assertRejected(403, fn() => $this->interact($opened, level: 3, report: self::slide($target)));
    }

    public function testTheClientBudgetRefusesFurtherPuzzles(): void
    {
        $this->rate = new RateLimitConfig(gcProbability: 0, sliderIpLimit: 2, sliderIpWindowSec: 600);
        $this->sliderChallenge();
        $this->sliderChallenge();

        $challenge = $this->challenge(level: 3);
        $response = $this->answer(fn() => $this->puzzle($challenge['cid'], self::solve($challenge['gate'])));

        self::assertSame(429, $response->getStatus());
        self::assertSame('Too Many Requests', $response->getHeader('Botlock-Error'));
        self::assertSame('601', $response->getHeader('Retry-After'), 'both renders count until a full window has passed');
        self::assertSame(['ok' => false, 'error' => 'Too Many Requests', 'code' => 429], \json_decode((string) $response->getBody(), true));
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
    }

    public function testTheGlobalCapAnswers503UntilTheNextMinute(): void
    {
        $this->rate = new RateLimitConfig(gcProbability: 0, sliderGlobalLimit: 1);
        $this->sliderChallenge();

        $challenge = $this->challenge(level: 3);
        $response = $this->answer(fn() => $this->puzzle($challenge['cid'], self::solve($challenge['gate'])));

        self::assertSame(503, $response->getStatus());
        self::assertSame('Busy', $response->getHeader('Botlock-Error'));
        self::assertSame((string) (60 - (int) $this->now % 60), $response->getHeader('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'Busy', 'code' => 503], \json_decode((string) $response->getBody(), true));
        self::assertNull($this->store->find($challenge['cid']), 'the ticket is spent');
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

    public function testAnOlderLowerTicketKeepsTheHigherGrant(): void
    {
        $older = $this->challenge(level: 1);

        $challenge = $this->challenge(level: 2);
        $ready = $this->interact($challenge, level: 2);

        $this->now += 1.5;
        self::assertSame(200, $this->verify($challenge['cid'], self::solve($ready['pow']), level: 2));
        self::assertSame(2, $this->session->get('grant'));

        self::assertSame(200, $this->verify($older['cid'], self::solve($older['pow']), level: 1));
        self::assertSame(2, $this->session->get('grant'));
    }

    /**
     * A tab left open past its phase starts over quietly: the page restarts
     * on 409 as it does after an escalation.
     */
    public function testExpiredTicketRestarts(): void
    {
        $challenge = $this->challenge(level: 1);
        $this->now += ChallengeTicket::TTL + 2;
        $this->assertRejected(409, fn() => $this->verify($challenge['cid'], self::solve($challenge['pow']), level: 1));

        $click = $this->challenge(level: 2);
        $this->now += ChallengeTicket::TTL + 2;
        $this->assertRejected(409, fn() => $this->interact($click, level: 2));

        $slider = $this->challenge(level: 3);
        $this->now += ChallengeTicket::TTL + 2;
        $this->assertRejected(409, fn() => $this->puzzle($slider['cid'], self::solve($slider['gate'])));
        self::assertNull($this->store->find($slider['cid']), 'the expired ticket is spent');
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

    private function challenge(int $level, int $gcProbability = 0, string $nonce = self::NONCE): array
    {
        return self::json($this->challengeAction($gcProbability)->handle($this->request('GET', $level, headers: ['Botlock-Nonce' => $nonce])));
    }

    /**
     * Reports the interaction sealed with the challenge's key, the way the
     * challenge page does; a challenge without a key sends only its id.
     */
    private function interact(array $challenge, int $level, array $report = [], string $nonce = self::NONCE): array
    {
        $action = new InteractAction($this->service(), new InteractionPolicy($this->config));
        $body = isset($challenge['key'])
            ? Reports::seal(\base64_decode($challenge['key']), $challenge['cid'], $report)
            : ['cid' => $challenge['cid']];

        return self::json($action->handle($this->request('POST', $level, body: $body, headers: ['Botlock-Nonce' => $nonce])));
    }

    /**
     * Posts a gate solution; the answer carries the puzzle and the key.
     */
    private function puzzle(string $cid, array $solution, int $level = 3, ?array $headers = null): Response
    {
        $action = new PuzzleAction(
            $this->service(),
            new PuzzleBudget($this->rate, $this->budgetStore, 'inst', fn(): int => (int) $this->now),
        );

        return $action->handle($this->request('POST', $level, body: $solution + ['cid' => $cid], headers: $headers));
    }

    /**
     * Pays the gate and merges the puzzle answer into the challenge, like the page does.
     */
    private function openPuzzle(array $challenge): array
    {
        return self::json($this->puzzle($challenge['cid'], self::solve($challenge['gate']))) + $challenge;
    }

    private function sliderChallenge(): array
    {
        return $this->openPuzzle($this->challenge(level: 3));
    }

    private function verify(string $cid, array $solution, int $level, string $fingerprint = self::FP, string $nonce = self::NONCE): int
    {
        $action = new VerifyAction($this->service());
        $request = $this->request('POST', $level, body: $solution + ['cid' => $cid], fingerprint: $fingerprint, headers: ['Botlock-Nonce' => $nonce]);

        return $action->handle($request)->getStatus();
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
        $request->context->clientIp = '203.0.113.10';
        $request->context->threatLevel = $level;
        $request->context->session = $this->session;

        return $request;
    }

    /**
     * What ErrorMiddleware answers with for an action's response or rejection.
     */
    private function answer(\Closure $call): Response
    {
        return (new ErrorMiddleware)->process($this->request('POST', 3), static fn(): Response => $call());
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
