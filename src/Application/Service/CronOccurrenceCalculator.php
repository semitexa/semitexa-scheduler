<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Cron\CronExpression;

final class CronOccurrenceCalculator
{
    /**
     * Get all cron occurrences in ($from, $until] (from exclusive, until inclusive).
     *
     * @return list<\DateTimeImmutable>
     */
    public function getOccurrencesBetween(
        string $expression,
        \DateTimeImmutable $from,
        \DateTimeImmutable $until,
        string $timezone = 'UTC',
    ): array {
        $cron = new CronExpression($expression);
        $tz = new \DateTimeZone($timezone);
        $current = \DateTime::createFromImmutable($from)->setTimezone($tz);
        $untilDt = \DateTime::createFromImmutable($until)->setTimezone($tz);
        $utc = new \DateTimeZone('UTC');
        $occurrences = [];

        while (true) {
            $next = $cron->getNextRunDate($current, 0, false, $timezone);
            if ($next > $untilDt) {
                break;
            }
            $occurrences[] = \DateTimeImmutable::createFromMutable($next)->setTimezone($utc);
            $current = clone $next;
        }

        return $occurrences;
    }

    public function getNextOccurrence(
        string $expression,
        \DateTimeImmutable $from,
        string $timezone = 'UTC',
    ): \DateTimeImmutable {
        $cron = new CronExpression($expression);
        $fromDt = \DateTime::createFromImmutable($from);
        $next = $cron->getNextRunDate($fromDt, 0, false, $timezone);
        return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
    }
}
