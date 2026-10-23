<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

/**
 * "Now", as SQL the database evaluates: lease and lock expiry judged by one
 * clock. Each worker's PHP clock used to write and judge them, and a worker
 * whose clock ran a minute ahead took over leases and locks other workers
 * still held, running the job twice.
 *
 * Both are UTC, as PHP's default zone is: MySQL sessions are pinned to
 * +00:00 (MySqlSessionTimeZone), and SQLite's 'now' is UTC. The SQLite form
 * pads milliseconds to the six digits PHP writes, so the strings compare.
 */
final class DatabaseClock
{
    private function __construct()
    {
    }

    public static function now(string $driver): string
    {
        return self::in($driver, 0);
    }

    /** "Now" plus $seconds (negative for the past). */
    public static function in(string $driver, int $seconds): string
    {
        if ($driver === 'sqlite') {
            return $seconds === 0
                ? "strftime('%Y-%m-%d %H:%M:%f000', 'now')"
                : sprintf("strftime('%%Y-%%m-%%d %%H:%%M:%%f000', 'now', '%+d seconds')", $seconds);
        }

        return $seconds === 0 ? 'NOW(6)' : sprintf('(NOW(6) + INTERVAL %d SECOND)', $seconds);
    }
}
