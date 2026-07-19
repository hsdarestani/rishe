<?php

declare(strict_types=1);

namespace Rishe\Logistics\Domain;

use Rishe\Logistics\Domain\Exception\LogisticsDomainException;

final class PackageMetrics
{
    public function __construct(
        public readonly int $weightGrams,
        public readonly int $lengthMm,
        public readonly int $widthMm,
        public readonly int $heightMm,
        public readonly int $quantity = 1
    ) {
        if ($weightGrams < 1 || $weightGrams > 1000000) {
            throw new LogisticsDomainException('Package weight must be between 1 and 1,000,000 grams.');
        }
        foreach ([$lengthMm, $widthMm, $heightMm] as $dimension) {
            if ($dimension < 1 || $dimension > 5000) {
                throw new LogisticsDomainException('Package dimensions must be between 1 and 5,000 millimetres.');
            }
        }
        if ($quantity < 1 || $quantity > 1000) {
            throw new LogisticsDomainException('Package quantity must be between 1 and 1,000.');
        }
    }

    public function totalWeightGrams(): int
    {
        return $this->weightGrams * $this->quantity;
    }

    public function volumetricWeightGrams(int $divisor = 5000): int
    {
        if ($divisor < 1) {
            throw new LogisticsDomainException('Volumetric divisor must be positive.');
        }

        $centimetreVolume = ($this->lengthMm / 10) * ($this->widthMm / 10) * ($this->heightMm / 10);

        return (int) ceil(($centimetreVolume / $divisor) * 1000) * $this->quantity;
    }
}
