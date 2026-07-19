<?php

declare(strict_types=1);

namespace Rishe\Logistics\Infrastructure;

use Rishe\Logistics\Application\LogisticsRepository;

final class WpdbLogisticsRepository implements LogisticsRepository
{
    use WpdbLogisticsCarrierStorage;
    use WpdbLogisticsShipmentStorage;
    use WpdbLogisticsTrackingCostStorage;
    use WpdbLogisticsStorageHelpers;
}
