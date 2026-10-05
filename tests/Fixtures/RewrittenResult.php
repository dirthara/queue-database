<?php

declare(strict_types=1);

namespace Dirthara\QueueDatabase\Tests\Fixtures;

use Closure;
use Dirthara\Database\Connection\Result\Result;

use function array_map;

final readonly class RewrittenResult implements Result
{
    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $rewrite
     */
    public function __construct(
        private Result $result,
        private Closure $rewrite,
    ) {}

    public function first(): ?array
    {
        $row = $this->result->first();

        return $row === null ? null : ($this->rewrite)($row);
    }

    public function all(): array
    {
        return array_map($this->rewrite, $this->result->all());
    }

    public function column(int|string $column = 0): array
    {
        return $this->result->column($column);
    }

    public function affectedRows(): int
    {
        return $this->result->affectedRows();
    }

    public function iterate(): iterable
    {
        foreach ($this->result->iterate() as $row) {
            yield ($this->rewrite)($row);
        }
    }
}
