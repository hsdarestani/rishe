<?php

declare(strict_types=1);

namespace Rishe\B2B\Domain;

use Rishe\B2B\Domain\Exception\B2BDomainException;

final class ConsignmentLineBalance
{
    public function availableToReturn(int $dispatched, int $sold, int $returned): int
    {
        if ($dispatched < 1 || $sold < 0 || $returned < 0 || $sold + $returned > $dispatched) {
            throw new B2BDomainException('Consignment line balances are invalid.');
        }

        return $dispatched - $sold - $returned;
    }

    public function assertCanReturn(int $dispatched, int $sold, int $returned, int $requested): void
    {
        if ($requested < 1 || $requested > $this->availableToReturn($dispatched, $sold, $returned)) {
            throw new B2BDomainException('Consignment return exceeds the available unsold quantity.');
        }
    }
}
