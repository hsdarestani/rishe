<?php

declare(strict_types=1);

namespace Rishe\B2B\Infrastructure;

use Rishe\B2B\Application\B2BRepository;

final class WpdbB2BRepository implements B2BRepository
{
    use WpdbB2BMasterStorage;
    use WpdbB2BDispatchStorage;
    use WpdbB2BReturnStorage;
    use WpdbB2BSalesStorage;
    use WpdbB2BSettlementStorage;
    use WpdbB2BStorageHelpers;
}
