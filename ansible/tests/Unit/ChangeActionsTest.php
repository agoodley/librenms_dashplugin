<?php

namespace Agoodley\LibrenmsAnsible\Tests\Unit;

use Agoodley\LibrenmsAnsible\Runner\RunnerError;
use Agoodley\LibrenmsAnsible\Services\ChangeActions;
use PHPUnit\Framework\TestCase;

/**
 * What the change lifecycle learns from ChangeActions when the runner cannot give a change's template.
 */
class ChangeActionsTest extends TestCase
{
    public function testOnlyA404ForTheTemplateItselfSaysItWasDeleted(): void
    {
        $gone = new RunnerError(RunnerError::NOT_FOUND, 'Semaphore UI does not have template 11 (HTTP 404).');
        $this->assertSame(['parts' => null, 'error' => 'Semaphore UI does not have template 11 (HTTP 404).', 'missing' => true], ChangeActions::unreadable($gone, false));
        $this->assertFalse(ChangeActions::unreadable(new RunnerError(RunnerError::NOT_FOUND, 'Semaphore UI does not have inventory 7 (HTTP 404).'), true)['missing'],
            'a 404 for its inventory, once the template was read, is not a deleted template');
        foreach ([RunnerError::UNREACHABLE, RunnerError::UNAUTHORIZED, RunnerError::FORBIDDEN, RunnerError::BAD_RESPONSE, RunnerError::NOT_CONFIGURED] as $kind) {
            $this->assertFalse(ChangeActions::unreadable(new RunnerError($kind, 'x'), false)['missing'], "$kind: the runner cannot give it now");
        }
    }
}
