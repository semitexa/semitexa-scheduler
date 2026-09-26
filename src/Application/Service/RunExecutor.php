<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Application\Service;

use Semitexa\Scheduler\Domain\Model\RunExecutionResult;

use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Scheduler\Application\Db\MySQL\Repository\SchedulerRunHistoryRepository;
use Semitexa\Scheduler\Domain\Contract\ScheduledJobInterface;
use Semitexa\Scheduler\Domain\Contract\ScheduledRunRepositoryInterface;
use Semitexa\Scheduler\Domain\Model\ScheduledRun;
use Semitexa\Scheduler\Domain\Model\ScheduledJobContext;
use Semitexa\Scheduler\Domain\Enum\RunStatus;
use Semitexa\Tenancy\Context\CoroutineContextStore;
use Semitexa\Tenancy\Context\TenantContext;
use Symfony\Component\Console\Output\OutputInterface;

final class RunExecutor
{
    public function __construct(
        private readonly ScheduledRunRepositoryInterface $runRepository,
        private readonly SchedulerRunHistoryRepository $historyRepository,
    ) {}

    public function execute(
        ScheduledRun $run,
        string $workerId,
        LeaseHeartbeat $heartbeat,
        ?OutputInterface $output = null,
    ): RunExecutionResult {
        // Mark as running and increment attempt count
        $run->setStatus(RunStatus::Running->value);
        $run->setStartedAt(new \DateTimeImmutable());
        $run->setAttemptCount($run->getAttemptCount() + 1);
        $this->runRepository->save($run);

        $this->historyRepository->append(
            $run->getId(), 'running', 'claimed', RunStatus::Running->value,
            $workerId, "Attempt {$run->getAttemptCount()}/{$run->getMaxAttempts()}",
        );

        // Switch tenant context for tenant-bound runs
        $previousContext = null;
        if ($run->getTenantId() !== null) {
            $newContext = TenantContext::fromResolution($run->getTenantId(), 'scheduler');
            $previousContext = CoroutineContextStore::swapFallback($newContext);
        }

        // Optional dev observer: a scheduler run is a process like any request,
        // and the Observatory journal is how background work becomes visible in
        // the live panel and ai:observe. 'job' roots are journal-only — no
        // trace buffer opens. Same shape and cost as every other tracer seam.
        $tracer = $this->resolveTracer();
        $tracer?->begin('job', [
            'kind' => 'scheduler',
            'route' => $run->getJobClass(),
            'path' => $run->getId(),
            'attempt' => $run->getAttemptCount(),
            'tenant' => $run->getTenantId(),
        ]);
        $outcome = 'failed';
        $error = null;

        try {
            $payload = $run->getPayloadJson() !== null
                ? json_decode($run->getPayloadJson(), true, 512, JSON_THROW_ON_ERROR)
                : [];

            $context = new ScheduledJobContext(
                runId: $run->getId(),
                jobClass: $run->getJobClass(),
                pool: $run->getPool(),
                tenantId: $run->getTenantId(),
                scheduleKey: $run->getScheduleKey(),
                sourceType: $run->getSourceType(),
                attemptNumber: $run->getAttemptCount(),
                payload: $payload,
                renewLease: $heartbeat->tick(...),
            );

            // Dynamic dispatch: the job class name lives in a DB row, so
            // attribute injection cannot express it — resolving it IS the
            // container's job. This exact class is blessed in
            // StaticContainerAccessRule (the Queue-consumer tier); jobs must
            // carry #[AsService] alongside #[AsScheduledJob] to be resolvable.
            $container = ContainerFactory::get();
            /** @var ScheduledJobInterface $job */
            $job = $container->get($run->getJobClass());
            $job->handle($context);

            $output?->writeln("<info>Run '{$run->getId()}' executed successfully (attempt {$run->getAttemptCount()}).</info>");

            $outcome = 'success';

            return RunExecutionResult::success();
        } catch (\Throwable $e) {
            $output?->writeln("<error>Run '{$run->getId()}' failed: {$e->getMessage()}</error>");
            $error = self::truncate($e->getMessage());
            return RunExecutionResult::failure($e->getMessage());
        } finally {
            // The reason rides the end line so the live panel can say WHY a
            // run failed when the cursor lands on it; the schedule key lets
            // it pin the run to its cron row.
            $tracer?->end('job', array_filter([
                'status' => $outcome,
                'error' => $error,
                'schedule' => $run->getScheduleKey(),
                'attempt' => $run->getAttemptCount(),
            ], static fn ($v) => $v !== null && $v !== ''));
            if ($run->getTenantId() !== null) {
                CoroutineContextStore::swapFallback($previousContext);
            }
        }
    }

    /**
     * The optional dev tracer, wrapped so it can never throw into a run.
     * This class already lives on the rule's dynamic-dispatch allowlist, so
     * resolving from the container here is the blessed path.
     */
    private function resolveTracer(): ?\Semitexa\Core\Pipeline\RequestTracerInterface
    {
        // Wrapped whole: get() can throw even after has() said true (a broken
        // binding), and an optional observer failing to RESOLVE must degrade
        // to "no observer", never abort the work it wanted to watch.
        try {
            $container = ContainerFactory::get();
            $resolved = $container->has(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                ? $container->get(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
                : null;

            return \Semitexa\Core\Pipeline\SafeRequestTracer::wrap(
                $resolved instanceof \Semitexa\Core\Pipeline\RequestTracerInterface ? $resolved : null,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Truncate for the journal without reaching for mbstring.
     *
     * ext-mbstring is not in this package's requirements, and this runs while
     * a job failure is already being handled: a fatal here would escape
     * execute() instead of returning RunExecutionResult::failure(), so the
     * worker would never schedule the retry. PCRE's /u ships with PHP and does
     * the same job; an invalid-UTF-8 subject makes preg_match fail rather than
     * throw, and the byte-wise fallback covers it.
     */
    private static function truncate(string $text, int $limit = 200): string
    {
        return preg_match('/^.{0,' . $limit . '}/us', $text, $m) === 1 ? $m[0] : substr($text, 0, $limit);
    }
}
