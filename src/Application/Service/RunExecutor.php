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
        $run->status = RunStatus::Running->value;
        $run->startedAt = new \DateTimeImmutable();
        $run->attemptCount++;
        $this->runRepository->save($run);

        $this->historyRepository->append(
            $run->id, 'running', 'claimed', RunStatus::Running->value,
            $workerId, "Attempt {$run->attemptCount}/{$run->maxAttempts}",
        );

        // Switch tenant context for tenant-bound runs
        $previousContext = null;
        if ($run->tenantId !== null) {
            $newContext = TenantContext::fromResolution($run->tenantId, 'scheduler');
            $previousContext = CoroutineContextStore::swapFallback($newContext);
        }

        // Optional dev observer: a scheduler run is a process like any request,
        // and the Observatory journal is how background work becomes visible in
        // the live panel and ai:observe. 'job' roots are journal-only — no
        // trace buffer opens. Same shape and cost as every other tracer seam.
        $tracer = $this->resolveTracer();
        $tracer?->begin('job', [
            'kind' => 'scheduler',
            'route' => $run->jobClass,
            'path' => $run->id,
            'attempt' => $run->attemptCount,
            'tenant' => $run->tenantId,
        ]);
        $outcome = 'failed';

        try {
            $payload = $run->payloadJson !== null
                ? json_decode($run->payloadJson, true, 512, JSON_THROW_ON_ERROR)
                : [];

            $context = new ScheduledJobContext(
                runId: $run->id,
                jobClass: $run->jobClass,
                pool: $run->pool,
                tenantId: $run->tenantId,
                scheduleKey: $run->scheduleKey,
                sourceType: $run->sourceType,
                attemptNumber: $run->attemptCount,
                payload: $payload,
            );

            // Dynamic dispatch: the job class name lives in a DB row, so
            // attribute injection cannot express it — resolving it IS the
            // container's job. This exact class is blessed in
            // StaticContainerAccessRule (the Queue-consumer tier); jobs must
            // carry #[AsService] alongside #[AsScheduledJob] to be resolvable.
            $container = ContainerFactory::get();
            /** @var ScheduledJobInterface $job */
            $job = $container->get($run->jobClass);
            $job->handle($context);

            $output?->writeln("<info>Run '{$run->id}' executed successfully (attempt {$run->attemptCount}).</info>");

            $outcome = 'success';

            return RunExecutionResult::success();
        } catch (\Throwable $e) {
            $output?->writeln("<error>Run '{$run->id}' failed: {$e->getMessage()}</error>");
            return RunExecutionResult::failure($e->getMessage());
        } finally {
            $tracer?->end('job', ['status' => $outcome]);
            if ($run->tenantId !== null) {
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
        $container = ContainerFactory::get();
        $resolved = $container->has(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
            ? $container->get(\Semitexa\Core\Pipeline\RequestTracerInterface::class)
            : null;

        return \Semitexa\Core\Pipeline\SafeRequestTracer::wrap(
            $resolved instanceof \Semitexa\Core\Pipeline\RequestTracerInterface ? $resolved : null,
        );
    }
}
