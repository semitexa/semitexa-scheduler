<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Semitexa\Scheduler\Attribute\AsScheduledJob;

final class ScheduledJobCronTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('SCHEDULER_TEST_CRON');
        unset($_ENV['SCHEDULER_TEST_CRON'], $_SERVER['SCHEDULER_TEST_CRON']);
    }

    public function testPlainExpressionIsUsedAsIs(): void
    {
        $job = new AsScheduledJob(key: 'test.plain', cronExpression: '*/5 * * * *');

        self::assertSame('*/5 * * * *', $job->cron());
    }

    public function testDefaultAppliesWhenTheEnvVarIsNotSet(): void
    {
        $job = new AsScheduledJob(key: 'test.default', cronExpression: 'env::SCHEDULER_TEST_CRON::*/5 * * * *');

        self::assertSame('*/5 * * * *', $job->cron());
    }

    public function testTheEnvVarWins(): void
    {
        putenv('SCHEDULER_TEST_CRON=15 3 * * *');
        $_ENV['SCHEDULER_TEST_CRON'] = '15 3 * * *';

        $job = new AsScheduledJob(key: 'test.env', cronExpression: 'env::SCHEDULER_TEST_CRON::*/5 * * * *');

        self::assertSame('15 3 * * *', $job->cron());
    }

    public function testAnUnparsableEnvValueIsRefusedRatherThanScheduled(): void
    {
        putenv('SCHEDULER_TEST_CRON=every five minutes');
        $_ENV['SCHEDULER_TEST_CRON'] = 'every five minutes';

        $job = new AsScheduledJob(key: 'test.broken', cronExpression: 'env::SCHEDULER_TEST_CRON::*/5 * * * *');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/test\.broken/');
        $job->cron();
    }
}
