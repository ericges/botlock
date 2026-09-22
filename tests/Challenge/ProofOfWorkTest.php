<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ProofOfWork;
use GES\Botlock\Config\ProofOfWorkConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProofOfWorkTest extends TestCase
{
    private const SECRET = 'unit-test-secret';
    private const SUBJECT = 'fingerprint-a';

    private ProofOfWorkConfig $config;

    protected function setUp(): void
    {
        $this->config = new ProofOfWorkConfig(secret: self::SECRET, maxNumber: 300, expire: 60);
    }

    public function testCreateProducesSolvableChallenge(): void
    {
        $challenge = (new ProofOfWork($this->config))->create(self::SUBJECT);

        self::assertSame(['alg', 'exp', 'max', 'slt', 'tgt', 'sig'], \array_keys($challenge));
        self::assertSame('sha256', $challenge['alg']);
        self::assertGreaterThan(\time(), $challenge['exp']);
        self::assertEquals(300, $challenge['max']);

        $solution = self::solve($challenge);
        self::assertNotNull($solution, 'challenge must be solvable within max');
    }

    public function testSolutionVerifiesForIssuingSubjectOnly(): void
    {
        $pow = new ProofOfWork($this->config);
        $solution = self::solve($pow->create(self::SUBJECT));

        self::assertTrue($pow->verify($solution, self::SUBJECT));
        self::assertFalse($pow->verify($solution, 'fingerprint-b'), 'a solved challenge must not be replayable by another client');
    }

    public function testVerifyIsIndependentOfInstance(): void
    {
        $solution = self::solve((new ProofOfWork($this->config))->create(self::SUBJECT));

        self::assertTrue((new ProofOfWork($this->config))->verify($solution, self::SUBJECT));
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $pow = new ProofOfWork($this->config);
        $solution = self::solve($pow->create(self::SUBJECT));
        $solution['sig'] = \strrev($solution['sig']);

        self::assertFalse($pow->verify($solution, self::SUBJECT));
    }

    public function testWrongNumberIsRejected(): void
    {
        $pow = new ProofOfWork($this->config);
        $solution = self::solve($pow->create(self::SUBJECT));
        $solution['num'] = $solution['num'] + 1;

        self::assertFalse($pow->verify($solution, self::SUBJECT));
    }

    public function testExpiredChallengeIsRejected(): void
    {
        $exp = \time() - 10;
        $salt = 'saltsaltsaltsal';
        $target = \hash('sha256', "42:$salt:$exp");
        $sig = \hash_hmac('sha384', self::SUBJECT . "\0" . $target, self::SECRET);

        $data = ['num' => 42, 'sig' => $sig, 'slt' => $salt, 'exp' => $exp, 'alg' => 'sha256'];

        self::assertFalse((new ProofOfWork($this->config))->verify($data, self::SUBJECT));
    }

    public function testAlgorithmMustMatchConfiguration(): void
    {
        $pow = new ProofOfWork($this->config);
        $solution = self::solve($pow->create(self::SUBJECT));
        $solution['alg'] = 'sha512';

        self::assertFalse($pow->verify($solution, self::SUBJECT));
    }

    #[DataProvider('malformedSolutions')]
    public function testMalformedInputReturnsFalseInsteadOfThrowing(mixed $data): void
    {
        self::assertFalse((new ProofOfWork($this->config))->verify($data, self::SUBJECT));
    }

    public static function malformedSolutions(): iterable
    {
        $valid = ['num' => 1, 'sig' => 'x', 'slt' => 's', 'exp' => \time() + 60, 'alg' => 'sha256'];

        yield 'not an array' => ['nope'];
        yield 'empty' => [[]];
        yield 'extra key' => [$valid + ['nonce' => 'n']];
        yield 'non-numeric num' => [['num' => 'abc'] + $valid];
        yield 'negative num' => [['num' => -5] + $valid];
        yield 'zero num' => [['num' => 0] + $valid];
        yield 'float string num' => [['num' => '1.5'] + $valid];
        yield 'array sig' => [['sig' => ['x']] + $valid];
        yield 'empty salt' => [['slt' => ''] + $valid];
        yield 'non-numeric exp' => [['exp' => 'soon'] + $valid];
    }

    public function testDifficultyScalesMaxAndNeverBelowOneHundred(): void
    {
        $pow = new ProofOfWork($this->config);

        self::assertEquals(300, $pow->setDifficulty(1)->create(self::SUBJECT)['max']);
        self::assertEquals(600, $pow->setDifficulty(2)->create(self::SUBJECT)['max']);
        self::assertEquals(100, $pow->setDifficulty(0)->create(self::SUBJECT)['max'], 'floor of 100 even at difficulty 0');
    }

    public function testUnsupportedAlgorithmIsRejectedByConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProofOfWorkConfig(secret: 's', algorithm: 'md5');
    }

    /**
     * Brute-forces the challenge the way the browser does.
     */
    private static function solve(array $challenge): ?array
    {
        for ($i = 1; $i <= $challenge['max']; $i++) {
            if (\hash($challenge['alg'], "$i:{$challenge['slt']}:{$challenge['exp']}") === $challenge['tgt']) {
                return [
                    'num' => $i,
                    'sig' => $challenge['sig'],
                    'slt' => $challenge['slt'],
                    'exp' => $challenge['exp'],
                    'alg' => $challenge['alg'],
                ];
            }
        }

        return null;
    }
}
