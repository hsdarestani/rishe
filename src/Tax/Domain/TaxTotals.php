<?php

declare(strict_types=1);

namespace Rishe\Tax\Domain;

use Rishe\Tax\Domain\Exception\TaxDomainException;

final class TaxTotals
{
    /**
     * @param list<array{gross_irr:int, discount_irr:int, vat_irr:int, total_irr:int}> $lines
     * @return array{gross_irr:int,discount_irr:int,net_irr:int,vat_irr:int,total_irr:int}
     */
    public function calculate(array $lines): array
    {
        if ($lines === []) {
            throw new TaxDomainException('A tax invoice requires at least one line.');
        }
        $totals = ['gross_irr' => 0, 'discount_irr' => 0, 'net_irr' => 0, 'vat_irr' => 0, 'total_irr' => 0];
        foreach ($lines as $line) {
            $gross = (int) ($line['gross_irr'] ?? -1);
            $discount = (int) ($line['discount_irr'] ?? -1);
            $vat = (int) ($line['vat_irr'] ?? -1);
            $total = (int) ($line['total_irr'] ?? -1);
            if ($gross < 0 || $discount < 0 || $discount > $gross || $vat < 0) {
                throw new TaxDomainException('Tax invoice line totals are invalid.');
            }
            $net = $gross - $discount;
            if ($total !== $net + $vat) {
                throw new TaxDomainException('Tax invoice line total does not equal net plus VAT.');
            }
            $totals['gross_irr'] += $gross;
            $totals['discount_irr'] += $discount;
            $totals['net_irr'] += $net;
            $totals['vat_irr'] += $vat;
            $totals['total_irr'] += $total;
        }

        return $totals;
    }
}
