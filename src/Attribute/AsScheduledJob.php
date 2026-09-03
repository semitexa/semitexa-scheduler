<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Attribute;

use Attribute;
use Cron\CronExpression;
use Semitexa\Core\Config\EnvValueResolver;

/**
 * Declares a job and when it runs.
 *
 * `cronExpression` accepts the framework's `env::VAR::default` form, the same
 * one routes use for their paths:
 *
 *     cronExpression: 'env::CMS_TRANSLATE_CRON::0 * * * *'
 *
 * That is what lets one install retime a job the package ships. Without it the
 * only place a schedule can be changed is the package's own source, which for
 * an install running five sites of different sizes is the wrong place — and
 * editing the row in the database does not survive, because the registry's sync
 * rewrites it from this attribute every time.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AsScheduledJob
{
    public function __construct(
        public string $key,
        public string $cronExpression,
        public string $pool = 'default',
        public string $overlapPolicy = 'skip',
        public string $misfirePolicy = 'run_once',
        public string $tenantMode = 'global',
        public int $maxAttempts = 1,
        public int $retryBackoffSeconds = 0,
        public ?int $maxCatchUpRuns = null,
    ) {}

    /**
     * The expression this install actually runs on.
     *
     * A value that does not parse is refused rather than stored: a broken cron
     * in an .env would otherwise reach the planner, where it throws on every
     * tick and takes every OTHER job's planning down with it. Named loudly,
     * because the operator who set it is the only one who can fix it.
     *
     * @throws \InvalidArgumentException when the resolved expression is not a cron
     */
    public function cron(): string
    {
        $resolved = trim((string) EnvValueResolver::resolve($this->cronExpression));

        if ($resolved === '' || !CronExpression::isValidExpression($resolved)) {
            throw new \InvalidArgumentException(sprintf(
                'Schedule "%s": "%s" is not a valid cron expression (declared as "%s").',
                $this->key,
                $resolved,
                $this->cronExpression,
            ));
        }

        return $resolved;
    }
}
