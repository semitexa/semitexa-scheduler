<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Application\Console\Command\SchedulerRunNowCommand;
use Semitexa\Scheduler\Domain\Contract\ScheduleDefinitionRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Contract\SchedulerLockRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduleDefinition;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Tenancy\Domain\Contract\TenantRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * scheduler:run-now --inline rewrites its lease by the database's clock
 * before it runs. Refused, the reclaim had taken the run already (this host's
 * clock that far behind): the command ran it inline anyway, beside the worker
 * that claimed it.
 */
final class SchedulerRunNowLeaseTest extends TestCase
{
    private ?\Closure $previousResolver = null;

    protected function setUp(): void
    {
        // execute() sets the ORM's process-wide dispatcher resolver: the one
        // found is put back.
        $this->previousResolver = (new \ReflectionProperty(OrmManager::class, 'defaultEventDispatcherResolver'))->getValue();
    }

    protected function tearDown(): void
    {
        OrmManager::setDefaultEventDispatcherResolver($this->previousResolver);
    }

    #[Test]
    public function a_lease_the_database_refuses_stops_the_inline_run(): void
    {
        $definition = new ScheduleDefinition();
        $definition->setScheduleKey('report');
        $definition->setJobClass(\stdClass::class);
        $definition->setPool('default');
        $definition->setOverlapPolicy('allow');
        $definition->setTenantMode('global');
        $definitions = $this->createMock(ScheduleDefinitionRepositoryInterface::class);
        $definitions->method('findByKey')->willReturn($definition);

        $runs = $this->createMock(ScheduledRunRepositoryInterface::class);
        $runs->expects(self::once())->method('save')->willReturnCallback(static function (ScheduledRun $run): void {
            $run->setId('0192f0a0-0000-7000-8000-00000000000a');
        });
        $runs->expects(self::once())->method('renewLease')
            ->with('0192f0a0-0000-7000-8000-00000000000a', self::stringStartsWith('run-now-inline-'), self::greaterThan(0))
            ->willReturn(false);
        $runs->expects(self::never())->method('findById');
        $runs->expects(self::never())->method('finalizeIfOwned');

        $command = new SchedulerRunNowCommand();
        foreach ([
            'definitionRepo' => $definitions,
            'runRepo' => $runs,
            'events' => $this->createMock(EventDispatcherInterface::class),
            'lockRepo' => $this->createMock(SchedulerLockRepositoryInterface::class),
            'tenantRepo' => $this->createMock(TenantRepositoryInterface::class),
        ] as $property => $value) {
            (new \ReflectionProperty(SchedulerRunNowCommand::class, $property))->setValue($command, $value);
        }
        $tester = new CommandTester($command);

        $exit = $tester->execute(['schedule-key' => 'report', '--inline' => true]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertSame(
            "[ERROR] Run '0192f0a0-0000-7000-8000-00000000000a' lost its lease before it could run inline: a worker runs it instead. Check this host's clock against the database's.",
            preg_replace('/\s+/', ' ', trim($tester->getDisplay())),
        );
    }
}
