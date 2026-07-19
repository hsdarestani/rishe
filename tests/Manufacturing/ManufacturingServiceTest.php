<?php

declare(strict_types=1);

namespace Rishe\Tests\Manufacturing;

use PHPUnit\Framework\TestCase;
use Rishe\Manufacturing\Application\ManufacturingService;
use Rishe\Manufacturing\Domain\Exception\ManufacturingDomainException;
use Rishe\Tests\Manufacturing\Fakes\ImmediateTransactionRunner;
use Rishe\Tests\Manufacturing\Fakes\InMemoryAuditRecorder;
use Rishe\Tests\Manufacturing\Fakes\InMemoryManufacturingRepository;

final class ManufacturingServiceTest extends TestCase
{
    public function testBomCreationNormalizesComponentsAndAuditsAtomically(): void
    {
        $repository = new InMemoryManufacturingRepository();
        $transactions = new ImmediateTransactionRunner();
        $audit = new InMemoryAuditRecorder();
        $service = new ManufacturingService($repository, $transactions, $audit);

        $id = $service->createBom([
            'code' => 'rice-500',
            'name' => 'Rice 500 gram pack',
            'output_product_id' => 10,
            'output_quantity' => '10',
            'components' => [
                [
                    'product_id' => 11,
                    'component_type' => 'raw_material',
                    'quantity' => '5',
                    'waste_basis_points' => 250,
                ],
                [
                    'product_id' => 12,
                    'component_type' => 'packaging',
                    'quantity' => '10',
                ],
            ],
        ], 7);

        self::assertSame(31, $id);
        self::assertSame(1, $transactions->runs);
        self::assertSame('RICE-500', $repository->createdBom['code']);
        self::assertSame(100000, $repository->createdBom['output_quantity_scaled']);
        self::assertSame(50000, $repository->createdBom['components'][0]['quantity_scaled']);
        self::assertSame('manufacturing.bom.created', $audit->events[0]['event_type']);
    }

    public function testFinishedProductCannotConsumeItself(): void
    {
        $service = new ManufacturingService(
            new InMemoryManufacturingRepository(),
            new ImmediateTransactionRunner(),
            new InMemoryAuditRecorder()
        );

        $this->expectException(ManufacturingDomainException::class);

        $service->createBom([
            'code' => 'INVALID',
            'name' => 'Invalid BOM',
            'output_product_id' => 10,
            'output_quantity' => '1',
            'components' => [
                ['product_id' => 10, 'quantity' => '1'],
            ],
        ], 7);
    }

    public function testProductionExecutionIsAuditedWithCostingResult(): void
    {
        $repository = new InMemoryManufacturingRepository();
        $audit = new InMemoryAuditRecorder();
        $service = new ManufacturingService(
            $repository,
            new ImmediateTransactionRunner(),
            $audit
        );

        $result = $service->executeProduction([
            'bom_id' => 31,
            'input_warehouse_id' => 1,
            'output_warehouse_id' => 2,
            'output_quantity' => '5',
            'output_batch_code' => 'PROD-1',
            'labor_cost_irr' => 75000,
            'overhead_cost_irr' => 50000,
            'reference_type' => 'production_plan',
            'reference_id' => 'PLAN-100',
            'correlation_id' => 'corr-100',
        ], 7);

        self::assertSame(44, $result['id']);
        self::assertSame(50000, $repository->execution['output_quantity_scaled']);
        self::assertSame('manufacturing.production.completed', $audit->events[0]['event_type']);
        self::assertSame('corr-100', $audit->events[0]['correlation_id']);
    }
}
