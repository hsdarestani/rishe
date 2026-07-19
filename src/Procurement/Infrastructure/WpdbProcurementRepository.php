<?php

declare(strict_types=1);

namespace Rishe\Procurement\Infrastructure;

use Rishe\Procurement\Application\ProcurementRepository;

final class WpdbProcurementRepository implements ProcurementRepository
{
    use WpdbProcurementSupplierStorage;
    use WpdbProcurementOrderStorage;
    use WpdbProcurementReceiptStorage;
    use WpdbProcurementPaymentStorage;
    use WpdbProcurementStorageHelpers;
}
