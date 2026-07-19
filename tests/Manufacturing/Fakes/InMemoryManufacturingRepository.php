<?php

declare(strict_types=1);

namespace Rishe\Tests\Manufacturing\Fakes;

use Rishe\Manufacturing\Application\ManufacturingRepository;

final class InMemoryManufacturingRepository implements ManufacturingRepository
{
    /** @var array<string, mixed> */
    public array $createdBom = [];

    /** @var array<string, mixed> */
    public array $execution = [];

    /** @var array<string, mixed> */
    public array $activated = [];

    public function createBom(array $data): int
    {
        $this->createdBom = $data;

        return 31;
    }

    public function activateBom(int $bomId, int $actorUserId): array
    {
        $this->activated = ['bom_id' => $bomId, 'actor_user_id' => $actorUserId];

        return ['code' => 'RICE-500', 'version' => 2, 'retired_bom_ids' => [8]];
    }

    public function executeProduction(array $data): array
    {
        $this->execution = $data;

        return [
            'id' => 44,
            'output_batch_id' => 91,
            'output_quantity_scaled' => $data['output_quantity_scaled'],
            'material_cost_irr' => 700000,
            'waste_cost_irr' => 25000,
            'labor_cost_irr' => $data['labor_cost_irr'],
            'overhead_cost_irr' => $data['overhead_cost_irr'],
            'total_cost_irr' => 850000,
            'unit_cost_irr' => 170000,
            'idempotent' => false,
        ];
    }

    public function boms(array $filters): array
    {
        return [];
    }

    public function productionOrder(int $orderId): ?array
    {
        return null;
    }

    public function productionOrders(array $filters): array
    {
        return [];
    }
}
