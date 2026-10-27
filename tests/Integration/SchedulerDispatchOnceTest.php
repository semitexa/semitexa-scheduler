<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Orm\Application\Service\Uuid7;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Service\DelayedRunFactory;
use Semitexa\Scheduler\Application\Service\SchedulerService;
use Semitexa\Scheduler\Domain\Contract\DeduplicatingSchedulerInterface;

/**
 * Two runs asking for the same follow-up at once (a reclaimed run and the
 * slow original) scheduled it twice. Against the orm:sync'ed MySQL database.
 */
final class SchedulerDispatchOnceTest extends TestCase
{
    private OrmManager $orm;

    /** A second connection, as a second worker has. */
    private OrmManager $other;

    private string $key;

    private string $pool;

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
        $this->other = new OrmManager();
        $suffix = bin2hex(random_bytes(6));
        $this->key = 'dispatch-once-test-' . $suffix;
        $this->pool = 'dispatch-once-' . $suffix;
    }

    protected function tearDown(): void
    {
        $this->orm->getAdapter()->execute('DELETE FROM scheduler_runs WHERE pool = :p', ['p' => $this->pool]);
        $this->orm->shutdown();
        $this->other->shutdown();
    }

    #[Test]
    public function one_key_is_scheduled_once_across_two_connections(): void
    {
        $first = $this->scheduler($this->orm)->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['n' => 1], pool: $this->pool);
        $second = $this->scheduler($this->other)->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['n' => 2], pool: $this->pool);

        self::assertNull($second, 'scheduled already');
        self::assertSame([[$first, '{"n":1}']], $this->rows(), 'one row, the first call\'s, under the id it returned');
    }

    #[Test]
    public function a_key_is_per_tenant(): void
    {
        // The unique index had no tenant in it: tenant B's 'daily-report' was
        // reported scheduled, and only tenant A's ran.
        $scheduler = $this->scheduler($this->orm);
        $a = $scheduler->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['t' => 'a'], pool: $this->pool, tenantId: 'tenant-a');
        $b = $scheduler->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['t' => 'b'], pool: $this->pool, tenantId: 'tenant-b');
        $global = $scheduler->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['t' => null], pool: $this->pool);
        $againA = $this->scheduler($this->other)->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['t' => 'a2'], pool: $this->pool, tenantId: 'tenant-a');

        self::assertNull($againA, "tenant-a's key is taken");
        self::assertEqualsCanonicalizing(
            [[$a, '{"t":"a"}'], [$b, '{"t":"b"}'], [$global, '{"t":null}']],
            $this->rows(),
        );
    }

    #[Test]
    public function a_consumer_can_inject_the_deduplicating_scheduler(): void
    {
        // SchedulerService advertised SchedulerInterface only, so a property
        // typed DeduplicatingSchedulerInterface could not be resolved.
        $scheduler = ContainerFactory::get()->get(DeduplicatingSchedulerInterface::class);

        self::assertInstanceOf(SchedulerService::class, $scheduler);
    }

    private function scheduler(OrmManager $orm): SchedulerService
    {
        $runs = new ScheduledRunRepository();
        (new \ReflectionProperty(ScheduledRunRepository::class, 'orm'))->setValue($runs, $orm);
        $factory = new DelayedRunFactory();
        (new \ReflectionProperty(DelayedRunFactory::class, 'runRepository'))->setValue($factory, $runs);
        $scheduler = new SchedulerService();
        (new \ReflectionProperty(SchedulerService::class, 'factory'))->setValue($scheduler, $factory);
        (new \ReflectionProperty(SchedulerService::class, 'runs'))->setValue($scheduler, $runs);

        return $scheduler;
    }

    /** @return list<array{string, string}> each run of this test's pool: its id and payload */
    private function rows(): array
    {
        $rows = $this->orm->getAdapter()->execute(
            'SELECT id, payload_json FROM scheduler_runs WHERE pool = :p',
            ['p' => $this->pool],
        )->rows;

        return array_map(
            static fn (array $row): array => [Uuid7::fromBytes((string) $row['id']), (string) $row['payload_json']],
            $rows,
        );
    }
}
