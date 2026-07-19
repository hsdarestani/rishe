<?php

declare(strict_types=1);

namespace Rishe\Tax\Infrastructure;

use Rishe\Tax\Application\TaxRepository;

final class WpdbTaxRepository implements TaxRepository
{
    use WpdbTaxProfileStorage;
    use WpdbTaxInvoiceStorage;
    use WpdbTaxSubmissionStorage;
    use WpdbTaxStorageHelpers;
}
