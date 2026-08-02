<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Application;

interface ManufacturingCompletionRepository
{
    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function configureOutputs(int $bomId, array $data): array;

    /** @return list<array<string, mixed>> */
    public function bomOutputs(int $bomId): array;

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function executeJointProduction(array $data): array;
}
