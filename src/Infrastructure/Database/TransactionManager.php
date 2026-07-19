<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database;

use RuntimeException;
use Throwable;

final class TransactionManager
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

        $savepoint = 'rishe_sp_' . $this->level;
        $started = $this->level === 0
            ? $wpdb->query('START TRANSACTION')
            : $wpdb->query("SAVEPOINT {$savepoint}");

        if ($started === false) {
            throw new RuntimeException('Unable to start database transaction.');
        }

        ++$this->level;

        try {
            $result = $operation();
            --$this->level;

            $committed = $this->level === 0
                ? $wpdb->query('COMMIT')
                : $wpdb->query("RELEASE SAVEPOINT {$savepoint}");

            if ($committed === false) {
                throw new RuntimeException('Unable to commit database transaction.');
            }

            return $result;
        } catch (Throwable $exception) {
            --$this->level;

            if ($this->level === 0) {
                $wpdb->query('ROLLBACK');
            } else {
                $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
            }

            throw $exception;
        }
    }
}
