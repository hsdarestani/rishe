<?php

declare(strict_types=1);

namespace Rishe\Infrastructure\Database;

interface Migration
{
    public function id(): string;

    public function up(): void;
}
