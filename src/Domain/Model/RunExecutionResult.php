<?php

declare(strict_types=1);

namespace Semitexa\Scheduler\Domain\Model;

final readonly class RunExecutionResult
{
    private function __construct(
        public bool $success,
        public ?string $error = null,
    ) {}

    public static function success(): self
    {
        return new self(success: true);
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
