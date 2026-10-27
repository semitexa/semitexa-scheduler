<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Orm\OrmManager;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\ScheduledRunRepository;
use Semitexa\Scheduler\Application\Service\DelayedRunFactory;
use Semitexa\Scheduler\Application\Service\SchedulerService;

/**
 * Two runs asking for the same follow-up at once (a reclaimed run and the
 * slow original) scheduled it twice. Against the orm:sync'ed MySQL database.
 */
final class SchedulerDispatchOnceTest extends TestCase
{
    private OrmManager $orm;

    private string $key;

    protected function setUp(): void
    {
        $this->orm = new OrmManager();
        self::assertSame('mysql', $this->orm->getDriver(), 'bin/semitexa test:run runs on MySQL');
        $this->key = 'dispatch-once-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->orm->getAdapter()->execute('DELETE FROM scheduler_runs WHERE occurrence_key = :k', ['k' => $this->key]);
        $this->orm->shutdown();
    }

    #[Test]
    public function one_key_is_scheduled_once(): void
    {
        $runs = new ScheduledRunRepository();
        (new \ReflectionProperty(ScheduledRunRepository::class, 'orm'))->setValue($runs, $this->orm);
        $factory = new DelayedRunFactory();
        (new \ReflectionProperty(DelayedRunFactory::class, 'runRepository'))->setValue($factory, $runs);
        $scheduler = new SchedulerService();
        (new \ReflectionProperty(SchedulerService::class, 'factory'))->setValue($scheduler, $factory);

        $first = $scheduler->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['n' => 1], pool: 'dispatch-once-test');
        $second = $scheduler->dispatchOnceAt($this->key, \stdClass::class, new \DateTimeImmutable('+1 hour'), ['n' => 2], pool: 'dispatch-once-test');

        self::assertIsString($first);
        self::assertNull($second, 'scheduled already');
        $rows = $this->orm->getAdapter()->execute('SELECT COUNT(*) AS n FROM scheduler_runs WHERE occurrence_key = :k', ['k' => $this->key])->rows;
        self::assertSame(1, (int) ($rows[0]['n'] ?? 0));
    }
}
