<?php

declare(strict_types=1);

namespace Rishe\Tests\Support;

use Rishe\Shared\Database\TransactionRunner;

final class ImmediateTransactionRunner implements TransactionRunner
{
    public int $runs = 0;

    public function run(callable $operation): mixed
    {
        ++$this->runs;

        return $operation();
    }
}
