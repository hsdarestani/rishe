<?php

declare(strict_types=1);

namespace Rishe\Shared\Database;

interface TransactionRunner
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
