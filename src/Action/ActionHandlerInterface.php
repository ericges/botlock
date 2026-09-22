<?php declare(strict_types=1);

namespace GES\Botlock\Action;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;

/**
 * Handles one botlock action, e.g. "GET challenge" (see Request::getBotlockAction()).
 */
interface ActionHandlerInterface
{
    public function handle(Request $request): Response;
}
