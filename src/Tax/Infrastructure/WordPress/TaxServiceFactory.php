<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure\WordPress;

use Rishe\Infrastructure\Database\TransactionManager;
use Rishe\Shared\Audit\AuditLogger;
use Rishe\Tax\Application\TaxService;
use Rishe\Tax\Domain\TaxInvoiceNumberGenerator;
use Rishe\Tax\Domain\TaxTotals;
use Rishe\Tax\Infrastructure\RsaTaxPayloadSigner;
use Rishe\Tax\Infrastructure\WpTaxGatewayRegistry;
use Rishe\Tax\Infrastructure\WpTaxSecretVault;
use Rishe\Tax\Infrastructure\WpdbTaxRepository;

final class TaxServiceFactory
{
    public function create(): TaxService
    {
        $vault = new WpTaxSecretVault();

        return new TaxService(
            new WpdbTaxRepository(),
            new WpTaxGatewayRegistry($vault),
            $vault,
            new RsaTaxPayloadSigner(),
            new TransactionManager(),
            new AuditLogger(),
            new TaxInvoiceNumberGenerator(),
            new TaxTotals()
        );
    }
}
