<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\OverlapPolicyHandler;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Enum\OverlapPolicy;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
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

    /** @return iterable<string, array{?string, OverlapPolicy}> */
    public static function storedPolicies(): iterable
    {
        yield 'no definition' => [null, OverlapPolicy::Skip];
        yield 'a typo' => ['bogus', OverlapPolicy::Skip];
        yield 'empty' => ['', OverlapPolicy::Skip];
        yield 'allow' => ['allow', OverlapPolicy::Allow];
        yield 'delay' => ['delay', OverlapPolicy::Delay];
        yield 'skip' => ['skip', OverlapPolicy::Skip];
    }

    /** A scheduled run takes its definition's policy; one it cannot read is skip, never a ValueError. */
    #[Test]
    #[DataProvider('storedPolicies')]
    public function a_scheduled_run_takes_its_definitions_policy(?string $stored, OverlapPolicy $expected): void
    {
        $run = new ScheduledRun();
        $run->setScheduleKey('report');
        $run->setLockKey('scheduler:report');

        $definitions = $this->createMock(ScheduleDefinitionRepositoryInterface::class);
        if ($stored === null) {
            $definitions->method('findByKey')->willReturn(null);
        } else {
            $definition = new ScheduleDefinition();
            $definition->setOverlapPolicy($stored);
            $definitions->method('findByKey')->willReturn($definition);
        }
        $handler = (new \ReflectionClass(OverlapPolicyHandler::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(OverlapPolicyHandler::class, 'definitionRepository'))->setValue($handler, $definitions);

        $policy = (new \ReflectionMethod(OverlapPolicyHandler::class, 'resolvePolicy'))->invoke($handler, $run);

        self::assertSame($expected, $policy);
    }
}
