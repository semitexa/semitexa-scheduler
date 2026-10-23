<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Domain\Enum\OverlapPolicy;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;

final class OverlapPolicyOneOffTest extends TestCase
{
    #[Test]
    public function a_one_off_run_whose_lock_is_held_waits_rather_than_being_dropped(): void
    {
        // Skipped, a workflow timeout or a dispatchAt() job was lost whenever
        // its lock happened to be held.
        $run = new ScheduledRun();
        $run->setLockKey('workflow_timeout_wf-1_expire');

        $policy = (new \ReflectionMethod(OverlapPolicyHandler::class, 'resolvePolicy'))
            ->invoke((new \ReflectionClass(OverlapPolicyHandler::class))->newInstanceWithoutConstructor(), $run);

        self::assertSame(OverlapPolicy::Delay, $policy);
    }
}
