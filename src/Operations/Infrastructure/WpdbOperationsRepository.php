<?php

declare(strict_types=1);

namespace Rishe\Operations\Infrastructure;

use Rishe\Operations\Application\OperationsRepository;

final class WpdbOperationsRepository implements OperationsRepository
{
    use WpdbOperationsJobStorage;
    use WpdbOperationsEventStorage;
    use WpdbOperationsIncidentStorage;
    use WpdbOperationsReporting;
    use WpdbOperationsStorageHelpers;
}
