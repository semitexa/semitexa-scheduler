<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Console\Command\SchedulerListCommand;
use Semitexa\Scheduler\Application\Service\ScheduleDefinitionRegistry;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * tk-scheduler-list-shows-schedules-that-never-run: a consumer's scheduler:list
 * printed a Next Due time for guard.demo_heartbeat, whose job class had been
 * deleted, while the planner passed it over.
 */
final class SchedulerListMarksWhatIsNotPlannedTest extends TestCase
{
    #[Test]
    public function a_row_whose_job_class_is_gone_shows_why_it_is_not_planned_instead_of_a_time(): void
    {
        $row = new ScheduleDefinition();
        $row->setScheduleKey('guard.demo_heartbeat');
        $row->setJobClass('Gone\\HeartbeatJob');
        $row->setCronExpression('* * * * *');
        $row->setTimezone('UTC');
        $row->setPool('default');
        $row->setOverlapPolicy('skip');
        $row->setMisfirePolicy('skip');
        $row->setTenantMode('global');
        $repository = $this->createStub(ScheduleDefinitionRepositoryInterface::class);
        $repository->method('findAllEnabled')->willReturn([$row]);

        $command = new SchedulerListCommand();
        (new \ReflectionProperty(SchedulerListCommand::class, 'definitionRepo'))->setValue($command, $repository);
        (new \ReflectionProperty(SchedulerListCommand::class, 'registry'))->setValue($command, new ScheduleDefinitionRegistry());
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $output = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('not planned: its job class Gone\\HeartbeatJob no longer exists', $output);
        self::assertStringNotContainsString('(UTC)', $output, 'no Next Due time for it');
    }
}
