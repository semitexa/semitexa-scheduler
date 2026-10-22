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
     *
     * @throws \InvalidArgumentException when the expression can never match
     *         (`0 0 31 2 *`): it parses, so only the first calculation finds out
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
        $fixedTime = self::isFixedTime($cron);
        $occurrences = [];

        while (true) {
            $next = self::nextRunDate($cron, $current, $timezone);
            if ($next > $untilDt) {
                break;
            }
            $current = clone $next;
            if ($fixedTime && self::repeatsWallClock($next, $tz)) {
                continue;
            }
            $occurrences[] = \DateTimeImmutable::createFromMutable($next)->setTimezone($utc);
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
        $next = self::nextRunDate($cron, $fromDt, $timezone);
        return \DateTimeImmutable::createFromMutable($next)->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * The library throws a bare RuntimeException for an expression that
     * parses but never matches; the planner passes over a broken schedule by
     * InvalidArgumentException, so a RuntimeException ended the whole plan and
     * every schedule after this one stopped running.
     */
    private static function nextRunDate(CronExpression $cron, \DateTime $current, string $timezone): \DateTime
    {
        try {
            return $cron->getNextRunDate($current, 0, false, $timezone);
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException(
                "Cron expression '{$cron->getExpression()}' never matches: {$e->getMessage()}",
                0,
                $e,
            );
        }
    }

    /**
     * A job at a time of day (`30 3 * * *`, `0,30 1-4 * * *`): neither minute
     * nor hour is a wildcard or a step. One with a wildcard or a step
     * (every 15 minutes, written with a star or as `0-59/15`) runs by the
     * clock's ticks and keeps them in the repeated hour, as cron does.
     */
    private static function isFixedTime(CronExpression $cron): bool
    {
        foreach ([CronExpression::MINUTE, CronExpression::HOUR] as $field) {
            $value = (string) $cron->getExpression($field);
            if (str_contains($value, '*') || str_contains($value, '/')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether this instant's wall-clock time already went by once, before the
     * clocks went back: on the autumn change 03:30 in Kyiv happens twice, and a
     * daily 03:30 job ran on both. Decided from the instant alone, not from the
     * occurrences before it, because the planner computes a minute at a time
     * and the first 03:30 lies in an earlier pass.
     */
    private static function repeatsWallClock(\DateTime $at, \DateTimeZone $tz): bool
    {
        $ts = $at->getTimestamp();
        $transitions = $tz->getTransitions($ts - 86400, $ts);
        if (count($transitions) < 2) {
            return false;
        }
        $last = $transitions[count($transitions) - 1];
        $before = $transitions[count($transitions) - 2];
        $shift = $before['offset'] - $last['offset'];
        if ($shift <= 0) {
            return false;
        }
        $earlier = (new \DateTimeImmutable('@' . ($ts - $shift)))->setTimezone($tz);

        return $earlier->format('Y-m-d H:i') === $at->format('Y-m-d H:i');
    }
}
