<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;

/**
 * tk-scheduler-list-shows-schedules-that-never-run: the planner passes over an
 * enabled row whose job class is gone, whose key no job declares, or whose job
 * the container cannot build, and keeps the row on purpose (deploys and
 * rollbacks). One decision now says so, for the sync and for scheduler:list.
 */
final class ScheduleNotPlannedReasonTest extends TestCase
{
    #[Test]
    public function a_row_whose_job_class_is_gone_is_not_planned(): void
    {
        self::assertSame(
            'its job class Gone\\HeartbeatJob no longer exists',
            ScheduleDefinitionRegistry::reasonNotPlanned($this->row('guard.demo_heartbeat', 'Gone\\HeartbeatJob'), ['guard.demo_heartbeat'], ['guard.demo_heartbeat']),
        );
    }

    #[Test]
    public function a_row_no_job_declares_is_not_planned(): void
    {
        self::assertSame(
            'no #[AsScheduledJob] declares it any more',
            ScheduleDefinitionRegistry::reasonNotPlanned($this->row('report.old_name'), ['report.new_name'], ['report.new_name']),
        );
    }

    #[Test]
    public function a_row_whose_job_cannot_be_built_is_not_planned(): void
    {
        self::assertSame(
            'its job is not a service, so the scheduler cannot build it',
            ScheduleDefinitionRegistry::reasonNotPlanned($this->row('report'), ['report'], []),
        );
    }

    #[Test]
    public function a_declared_buildable_row_is_planned(): void
    {
        self::assertNull(ScheduleDefinitionRegistry::reasonNotPlanned($this->row('report'), ['report'], ['report']));
    }

    #[Test]
    public function with_no_job_discovered_every_row_whose_class_exists_is_planned(): void
    {
        // Discovery that found no job is more likely broken than right: the planner plans every row.
        self::assertNull(ScheduleDefinitionRegistry::reasonNotPlanned($this->row('report'), null, null));
    }

    private function row(string $key, string $jobClass = \stdClass::class): ScheduleDefinition
    {
        $definition = new ScheduleDefinition();
        $definition->setScheduleKey($key);
        $definition->setJobClass($jobClass);

        return $definition;
    }
}
