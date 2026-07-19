<?php

declare(strict_types=1);

namespace Rishe\Manufacturing\Application;

use DateTimeImmutable;
use Rishe\Inventory\Domain\Quantity;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;
use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;
use RuntimeException;

final class ManufacturingService
{
    public function __construct(
        private readonly ManufacturingRepository $repository,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit
    ) {
    }

    /** @param array<string, mixed> $data */
    public function createBom(array $data, int $actorUserId): int
    {
        $outputProductId = $this->positiveId($data['output_product_id'] ?? null, 'output_product_id');
        $components = $data['components'] ?? null;
        if (!is_array($components) || $components === []) {
            throw new ManufacturingDomainException('A BOM must contain at least one component.');
        }

        $normalized = [];
        $seenProducts = [];
        foreach (array_values($components) as $index => $component) {
            if (!is_array($component)) {
                throw new ManufacturingDomainException('Each BOM component must be an object.');
            }

            $productId = $this->positiveId($component['product_id'] ?? null, 'components.product_id');
            if ($productId === $outputProductId) {
                throw new ManufacturingDomainException('A finished product cannot consume itself in its BOM.');
            }
            if (isset($seenProducts[$productId])) {
                throw new ManufacturingDomainException('A product can appear only once in a BOM version.');
            }
            $seenProducts[$productId] = true;

            $type = strtolower(trim((string) ($component['component_type'] ?? 'raw_material')));
            if (!in_array($type, ['raw_material', 'packaging'], true)) {
                throw new ManufacturingDomainException('Component type must be raw_material or packaging.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'component_type' => $type,
                'quantity_scaled' => Quantity::fromInput($component['quantity'] ?? null)->scaled(),
                'waste_basis_points' => $this->basisPoints($component['waste_basis_points'] ?? 0),
                'sequence' => $this->positiveId($component['sequence'] ?? ($index + 1), 'components.sequence'),
            ];
        }

        $version = $data['version'] ?? null;
        $payload = [
            'code' => $this->requiredCode($data['code'] ?? null),
            'name' => $this->requiredName($data['name'] ?? null),
            'version' => $version === null || $version === ''
                ? null
                : $this->positiveId($version, 'version'),
            'output_product_id' => $outputProductId,
            'output_quantity_scaled' => Quantity::fromInput($data['output_quantity'] ?? null)->scaled(),
            'effective_from' => $this->nullableDate($data['effective_from'] ?? null),
            'effective_to' => $this->nullableDate($data['effective_to'] ?? null),
            'components' => $normalized,
            'actor_user_id' => $this->actor($actorUserId),
        ];

        if (
            $payload['effective_from'] !== null
            && $payload['effective_to'] !== null
            && $payload['effective_from'] > $payload['effective_to']
        ) {
            throw new ManufacturingDomainException('BOM effective_from cannot follow effective_to.');
        }

        return $this->transactions->run(function () use ($payload): int {
            $id = $this->repository->createBom($payload);
            $this->audit->record(
                'manufacturing.bom.created',
                'bom',
                (string) $id,
                [
                    'code' => $payload['code'],
                    'output_product_id' => $payload['output_product_id'],
                    'component_count' => count($payload['components']),
                ]
            );

            return $id;
        });
    }

    public function activateBom(int $bomId, int $actorUserId): void
    {
        $this->transactions->run(function () use ($bomId, $actorUserId): void {
            $result = $this->repository->activateBom(
                $this->positiveId($bomId, 'bom_id'),
                $this->actor($actorUserId)
            );
            $this->audit->record(
                'manufacturing.bom.activated',
                'bom',
                (string) $bomId,
                [
                    'code' => (string) $result['code'],
                    'version' => (int) $result['version'],
                    'retired_bom_ids' => $result['retired_bom_ids'] ?? [],
                ]
            );
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function executeProduction(array $data, int $actorUserId): array
    {
        $payload = [
            'bom_id' => $this->positiveId($data['bom_id'] ?? null, 'bom_id'),
            'input_warehouse_id' => $this->positiveId(
                $data['input_warehouse_id'] ?? null,
                'input_warehouse_id'
            ),
            'output_warehouse_id' => $this->positiveId(
                $data['output_warehouse_id'] ?? null,
                'output_warehouse_id'
            ),
            'output_quantity_scaled' => Quantity::fromInput($data['output_quantity'] ?? null)->scaled(),
            'output_batch_code' => $this->requiredCode($data['output_batch_code'] ?? null),
            'output_expiry_date' => $this->nullableDate($data['output_expiry_date'] ?? null),
            'labor_cost_irr' => $this->nonNegativeMoney($data['labor_cost_irr'] ?? 0, 'labor_cost_irr'),
            'overhead_cost_irr' => $this->nonNegativeMoney($data['overhead_cost_irr'] ?? 0, 'overhead_cost_irr'),
            'reference_type' => $this->requiredReferenceType($data['reference_type'] ?? 'production'),
            'reference_id' => $this->requiredReference($data['reference_id'] ?? null),
            'correlation_id' => $this->nullableText($data['correlation_id'] ?? null, 64),
            'actor_user_id' => $this->actor($actorUserId),
        ];

        return $this->transactions->run(function () use ($payload): array {
            $result = $this->repository->executeProduction($payload);
            if (!(bool) ($result['idempotent'] ?? false)) {
                $this->audit->record(
                    'manufacturing.production.completed',
                    'production_order',
                    (string) $result['id'],
                    [
                        'bom_id' => $payload['bom_id'],
                        'output_batch_id' => (int) $result['output_batch_id'],
                        'output_quantity_scaled' => $payload['output_quantity_scaled'],
                        'material_cost_irr' => (int) $result['material_cost_irr'],
                        'waste_cost_irr' => (int) $result['waste_cost_irr'],
                        'total_cost_irr' => (int) $result['total_cost_irr'],
                    ],
                    $payload['correlation_id']
                );
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function boms(array $filters): array
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        if ($status !== '' && !in_array($status, ['draft', 'active', 'retired'], true)) {
            throw new ManufacturingDomainException('BOM status filter is invalid.');
        }

        return $this->repository->boms([
            'status' => $status === '' ? null : $status,
            'output_product_id' => $this->optionalPositiveId($filters['output_product_id'] ?? null),
        ]);
    }

    /** @return array<string, mixed> */
    public function productionOrder(int $orderId): array
    {
        $order = $this->repository->productionOrder($this->positiveId($orderId, 'order_id'));
        if ($order === null) {
            throw new RuntimeException('Production order not found.');
        }

        return $order;
    }

    /** @param array<string, mixed> $filters @return list<array<string, mixed>> */
    public function productionOrders(array $filters): array
    {
        return $this->repository->productionOrders([
            'bom_id' => $this->optionalPositiveId($filters['bom_id'] ?? null),
            'from' => $this->nullableDate($filters['from'] ?? null),
            'to' => $this->nullableDate($filters['to'] ?? null),
        ]);
    }

    private function actor(int $actorUserId): int
    {
        if ($actorUserId < 1) {
            throw new ManufacturingDomainException('An authenticated actor is required.');
        }

        return $actorUserId;
    }

    private function positiveId(mixed $value, string $field): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new ManufacturingDomainException($field . ' must be a positive integer.');
        }

        return (int) $id;
    }

    private function optionalPositiveId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->positiveId($value, 'filter');
    }

    private function basisPoints(mixed $value): int
    {
        $points = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 10000]]);
        if ($points === false) {
            throw new ManufacturingDomainException('waste_basis_points must be between zero and 10000.');
        }

        return (int) $points;
    }

    private function nonNegativeMoney(mixed $value, string $field): int
    {
        $amount = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($amount === false) {
            throw new ManufacturingDomainException($field . ' must be a non-negative integer in IRR.');
        }

        return (int) $amount;
    }

    private function requiredCode(mixed $value): string
    {
        $text = strtoupper(trim((string) $value));
        if ($text === '' || strlen($text) > 100 || !preg_match('/^[A-Z0-9._-]+$/', $text)) {
            throw new ManufacturingDomainException('Code must contain only letters, digits, dot, dash, or underscore.');
        }

        return $text;
    }

    private function requiredName(mixed $value): string
    {
        $text = trim((string) $value);
        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($text === '' || $length > 191) {
            throw new ManufacturingDomainException('Name is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function requiredReferenceType(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 50) {
            throw new ManufacturingDomainException('reference_type is required and must not exceed 50 characters.');
        }

        return $text;
    }

    private function requiredReference(mixed $value): string
    {
        $text = trim((string) $value);
        if ($text === '' || strlen($text) > 191) {
            throw new ManufacturingDomainException('Production reference is required and must not exceed 191 characters.');
        }

        return $text;
    }

    private function nullableText(mixed $value, int $maxLength): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $text = trim((string) $value);
        if (strlen($text) > $maxLength) {
            throw new ManufacturingDomainException('Text value exceeds the supported length.');
        }

        return $text;
    }

    private function nullableDate(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $date = trim((string) $value);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ManufacturingDomainException('Date must use the YYYY-MM-DD format.');
        }

        return $date;
    }
}
