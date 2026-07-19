<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database;

use Rishe\Shared\Database\TransactionRunner;
use RuntimeException;
use Throwable;

final class TransactionManager implements TransactionRunner
{
    private int $level = 0;

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        global $wpdb;

        $isOutermost = $this->level === 0;
        $savepoint = 'rishe_sp_' . $this->level;
        $started = $isOutermost
            ? $wpdb->query('START TRANSACTION')
            : $wpdb->query("SAVEPOINT {$savepoint}");

        if ($started === false) {
            throw new RuntimeException('Unable to start database transaction.');
        }

        ++$this->level;

        try {
            $result = $operation();
        } catch (Throwable $exception) {
            --$this->level;

            if ($isOutermost) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            throw $exception;
        }

        --$this->level;
        $committed = $isOutermost
            ? $wpdb->query('COMMIT')
            : $wpdb->query("RELEASE SAVEPOINT {$savepoint}");

        if ($committed === false) {
            if ($isOutermost) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            throw new RuntimeException('Unable to commit database transaction.');
        }

        return $result;
    }
}
