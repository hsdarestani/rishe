<?php

declare(strict_types=1);

namespace Rishe\Tests\Analytics;

use Rishe\Shared\Database\TransactionRunner;

final class ImmediateTransactions implements TransactionRunner
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
