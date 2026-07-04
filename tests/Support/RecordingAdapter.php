<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Support;

use Semitexa\Orm\Adapter\DatabaseAdapterInterface;
use Semitexa\Orm\Adapter\QueryResult;
use Semitexa\Orm\Adapter\ServerCapability;

/**
 * Records every execute() call so tests can assert on the raw SQL the
 * repositories emit (placeholder hygiene, param completeness) without a
 * database. An optional handler scripts the result per call; the default
 * reports one affected row.
 */
final class RecordingAdapter implements DatabaseAdapterInterface
{
    /** @var list<array{sql: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var (\Closure(string, array<string, mixed>): QueryResult)|null */
    private ?\Closure $handler;

    public function __construct(?\Closure $handler = null)
    {
        $this->handler = $handler;
    }

    public function supports(ServerCapability $capability): bool
    {
        return true;
    }

    public function getServerVersion(): string
    {
        return 'recording';
    }

    public function execute(string $sql, array $params = []): QueryResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => $params];

        return $this->handler !== null
            ? ($this->handler)($sql, $params)
            : new QueryResult(rowCount: 1);
    }

    public function query(string $sql): QueryResult
    {
        $this->calls[] = ['sql' => $sql, 'params' => []];

        return new QueryResult();
    }

    public function lastInsertId(): string
    {
        return '0';
    }
}
