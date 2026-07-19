<?php

declare(strict_types=1);

namespace Rishe\Tests\Tax\Fakes;

use Rishe\Shared\Database\TransactionRunner;

final class ImmediateTransactionRunner implements TransactionRunner
{
    public function run(callable $operation): mixed
    {
        return $operation();
    }
}
