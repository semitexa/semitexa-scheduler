<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Domain\Model\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function the_backoff_doubles_with_each_attempt(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, backoffSeconds: 60);

        self::assertSame(60, $this->delay($policy, 1));
        self::assertSame(120, $this->delay($policy, 2));
        self::assertSame(240, $this->delay($policy, 3));
    }

    /** Past the 60th attempt the doubling overflowed to a float the date parser refused, and the worker stopped. */
    #[Test]
    public function the_doubling_stops_at_a_day(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1000, backoffSeconds: 60);

        self::assertSame(RetryPolicy::MAX_DELAY_SECONDS, $this->delay($policy, 20));
        self::assertSame(RetryPolicy::MAX_DELAY_SECONDS, $this->delay($policy, 500));
    }

    #[Test]
    public function a_declared_backoff_longer_than_a_day_is_kept(): void
    {
        $policy = new RetryPolicy(maxAttempts: 10, backoffSeconds: 172800);

        self::assertSame(172800, $this->delay($policy, 1));
        self::assertSame(172800, $this->delay($policy, 5));
    }

    #[Test]
    public function no_backoff_is_due_now(): void
    {
        self::assertSame(0, $this->delay(new RetryPolicy(maxAttempts: 3), 2));
    }

    /** Seconds from now, read on both sides of the call: a second may tick in between. */
    private function delay(RetryPolicy $policy, int $attempt): int
    {
        $before = time();
        $at = $policy->nextAvailableAt($attempt)->getTimestamp();
        $after = time();
        self::assertSame($at - $before, $at - $after, 'the clock ticked mid-call; run again');

        return $at - $before;
    }
}
