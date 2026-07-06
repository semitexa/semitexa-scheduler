<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Db;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerLockResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunHistoryResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerRunResource;
use Semitexa\Scheduler\Application\Db\MySQL\Model\SchedulerScheduleDefinitionResource;

/**
 * Pins the ORM resource contract for all four scheduler resources: the
 * validator rejects anything that is not `final readonly` at the first
 * `repository()` touch, so a regression here means scheduler:plan and
 * scheduler:work are dead at runtime again.
 */
final class ResourceContractTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function resources(): array
    {
        return [
            'run' => [SchedulerRunResource::class],
            'lock' => [SchedulerLockResource::class],
            'history' => [SchedulerRunHistoryResource::class],
            'definition' => [SchedulerScheduleDefinitionResource::class],
        ];
    }

    #[Test]
    #[DataProvider('resources')]
    public function resource_is_final_readonly_with_defaulted_constructor(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        self::assertTrue($reflection->isFinal(), "{$class} must be final per the ORM contract.");
        self::assertTrue($reflection->isReadOnly(), "{$class} must be readonly per the ORM contract.");

        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            self::assertTrue(
                $parameter->isDefaultValueAvailable(),
                "{$class}::\${$parameter->getName()} needs a default so the write engine can build blank rows.",
            );
        }

        // Every constructor parameter must be a promoted column property.
        $propertyNames = array_map(
            static fn (\ReflectionProperty $p): string => $p->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );
        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            self::assertContains($parameter->getName(), $propertyNames);
        }
    }

    #[Test]
    #[DataProvider('resources')]
    public function copy_with_applies_overrides_and_refreshes_updated_at(string $class): void
    {
        $stamp = new \DateTimeImmutable('2026-01-01 00:00:00');
        $original = new $class(updated_at: $stamp, created_at: $stamp);

        $copy = $original->copyWith([]);

        self::assertNotSame($original, $copy);
        self::assertSame($stamp, $original->updated_at, 'copyWith must not mutate the source.');
        self::assertGreaterThan($stamp, $copy->updated_at, 'updated_at must auto-refresh.');
        self::assertSame($stamp, $copy->created_at, 'created_at must carry over untouched.');
    }

    #[Test]
    public function copy_with_explicit_updated_at_override_wins(): void
    {
        $pinned = new \DateTimeImmutable('2030-06-15 12:00:00');
        $copy = (new SchedulerRunResource())->copyWith(['updated_at' => $pinned]);

        self::assertSame($pinned, $copy->updated_at);
    }

    #[Test]
    public function copy_with_preserves_unlisted_properties(): void
    {
        $run = new SchedulerRunResource(
            job_class: 'Acme\\Job',
            pool: 'reports',
            status: 'pending',
            attempt_count: 2,
        );

        $claimed = $run->copyWith(['status' => 'claimed', 'lease_owner' => 'w-1']);

        self::assertSame('claimed', $claimed->status);
        self::assertSame('w-1', $claimed->lease_owner);
        self::assertSame('Acme\\Job', $claimed->job_class);
        self::assertSame('reports', $claimed->pool);
        self::assertSame(2, $claimed->attempt_count);
    }
}
