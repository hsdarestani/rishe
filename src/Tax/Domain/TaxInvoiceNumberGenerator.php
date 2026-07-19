<?php

declare(strict_types=1);

namespace Rishe\Tax\Domain;

use Rishe\Tax\Domain\Exception\TaxDomainException;

final class TaxInvoiceNumberGenerator
{
    /**
     * Generates the documented 22-character structure:
     * 6-char fiscal memory id + 5-char hex day + 10-char hex serial + Verhoeff check digit.
     */
    public function generate(string $memoryId, int $issuedAtUnix, int $serial): string
    {
        $memoryId = strtoupper(trim($memoryId));
        if (!preg_match('/^[A-Z0-9]{6}$/', $memoryId)) {
            throw new TaxDomainException('Fiscal memory id must contain exactly six Latin letters or digits.');
        }
        if ($issuedAtUnix < 0 || $serial < 1 || $serial > 0xFFFFFFFFFF) {
            throw new TaxDomainException('Tax invoice timestamp or serial is outside the supported range.');
        }

        $day = intdiv($issuedAtUnix, 86400);
        $dateHex = strtoupper(str_pad(dechex($day), 5, '0', STR_PAD_LEFT));
        $serialHex = strtoupper(str_pad(dechex($serial), 10, '0', STR_PAD_LEFT));
        if (strlen($dateHex) !== 5 || strlen($serialHex) !== 10) {
            throw new TaxDomainException('Tax invoice number components exceed their fixed lengths.');
        }

        $base = $memoryId . $dateHex . $serialHex;

        return $base . $this->verhoeff($this->numericProjection($base));
    }

    private function numericProjection(string $value): string
    {
        $digits = '';
        foreach (str_split($value) as $character) {
            $numeric = intval(base_convert($character, 36, 10));
            $digits .= str_pad((string) $numeric, 2, '0', STR_PAD_LEFT);
        }

        return $digits;
    }

    private function verhoeff(string $number): int
    {
        $d = [
            [0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],
            [3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],
            [6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],
            [9,8,7,6,5,4,3,2,1,0],
        ];
        $p = [
            [0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],
            [8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],
            [2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8],
        ];
        $inv = [0,4,3,2,1,5,6,7,8,9];
        $check = 0;
        $reversed = array_reverse(array_map('intval', str_split($number)));
        foreach ($reversed as $index => $digit) {
            $check = $d[$check][$p[($index + 1) % 8][$digit]];
        }

        return $inv[$check];
    }
}
