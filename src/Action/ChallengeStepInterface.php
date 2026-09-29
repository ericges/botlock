<?php declare(strict_types=1);

namespace GES\Botlock\Action;

/**
 * A step of a challenge after it was issued: it only redeems an existing
 * ticket and is always answered by its handler, never by the site. Such
 * requests count toward the client's rate but not the global one, so
 * re-challenging every visitor after a global escalation does not raise
 * the global level further.
 */
interface ChallengeStepInterface extends ActionHandlerInterface
{
}
