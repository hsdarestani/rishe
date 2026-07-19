<?php

declare(strict_types=1);

namespace Rishe\Tax\Application;

use Rishe\Shared\Audit\AuditRecorder;
use Rishe\Shared\Database\TransactionRunner;
use Rishe\Tax\Domain\TaxInvoiceNumberGenerator;
use Rishe\Tax\Domain\TaxTotals;

final class TaxService
{
    use TaxValidation;
    use TaxProfileOperations;
    use TaxInvoiceOperations;
    use TaxSubmissionOperations;

    public function __construct(
        private readonly TaxRepository $repository,
        private readonly TaxGatewayRegistry $gateways,
        private readonly TaxSecretVault $vault,
        private readonly TaxPayloadSigner $signer,
        private readonly TransactionRunner $transactions,
        private readonly AuditRecorder $audit,
        private readonly TaxInvoiceNumberGenerator $numbers,
        private readonly TaxTotals $totals
    ) {
    }
}
