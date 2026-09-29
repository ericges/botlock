<?php declare(strict_types=1);

namespace GES\Botlock\Tests\Challenge;

use GES\Botlock\Challenge\ChallengeTicketStore;
use GES\Botlock\Tests\Support\InMemoryChallengeTicketStore;

/**
 * The test double keeps the store contract, so tests built on it see what
 * the file store would do.
 */
final class InMemoryChallengeTicketStoreTest extends ChallengeTicketStoreContract
{
    protected function store(): ChallengeTicketStore
    {
        return new InMemoryChallengeTicketStore();
    }
}
