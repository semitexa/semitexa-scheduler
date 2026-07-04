<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Support;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Domain\Model\ConnectionConfig;
use Semitexa\Orm\OrmManager;

/**
 * Builds scheduler repositories around a controlled adapter: either an
 * in-memory SQLite database (integration) or a {@see RecordingAdapter}
 * (unit). The repositories resolve their adapter through OrmManager, so the
 * harness plants a manager whose adapter slot is pre-filled via reflection —
 * the same seam `#[InjectAsReadonly]` fills in production.
 */
final class RepositoryHarness
{
    public static function sqliteOrm(): OrmManager
    {
        return new OrmManager(config: new ConnectionConfig(driver: 'sqlite', sqliteMemory: true));
    }

    public static function ormWithAdapter(DatabaseAdapterInterface $adapter): OrmManager
    {
        $orm = self::sqliteOrm();
        $slot = new \ReflectionProperty(OrmManager::class, 'adapter');
        $slot->setValue($orm, $adapter);

        return $orm;
    }

    /**
     * @template T of object
     * @param class-string<T> $repositoryClass
     * @return T
     */
    public static function repository(string $repositoryClass, OrmManager $orm): object
    {
        $repository = new $repositoryClass();
        $slot = new \ReflectionProperty($repositoryClass, 'orm');
        $slot->setValue($repository, $orm);

        return $repository;
    }
}
