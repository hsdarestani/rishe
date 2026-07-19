<?php

declare(strict_types=1);

namespace Rishe\Inventory\Domain;

use Rishe\Inventory\Domain\Exception\InventoryDomainException;

final class Quantity
{
    public const SCALE = 10000;

    private function __construct(private readonly int $scaled)
    {
    }

    public static function fromInput(mixed $value, bool $allowZero = false): self
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (!is_string($value)) {
            throw new InventoryDomainException('Quantity must be supplied as a decimal string or integer.');
        }

        $value = trim($value);
        if (!preg_match('/^\d+(?:\.\d{1,4})?$/', $value)) {
            throw new InventoryDomainException('Quantity must have at most four decimal places.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        if ((int) $whole > intdiv(PHP_INT_MAX, self::SCALE)) {
            throw new InventoryDomainException('Quantity exceeds the supported range.');
        }

        $scaled = ((int) $whole * self::SCALE) + (int) str_pad($fraction, 4, '0');

        if ($scaled < 0 || (!$allowZero && $scaled === 0)) {
            throw new InventoryDomainException('Quantity must be greater than zero.');
        }

        return new self($scaled);
    }

    public static function fromScaled(int $scaled, bool $allowZero = false): self
    {
        if ($scaled < 0 || (!$allowZero && $scaled === 0)) {
            throw new InventoryDomainException('Scaled quantity must be greater than zero.');
        }

        return new self($scaled);
    }

    public function scaled(): int
    {
        return $this->scaled;
    }

    public function decimal(): string
    {
        $whole = intdiv($this->scaled, self::SCALE);
        $fraction = str_pad((string) ($this->scaled % self::SCALE), 4, '0', STR_PAD_LEFT);
        $fraction = rtrim($fraction, '0');

        return $fraction === '' ? (string) $whole : $whole . '.' . $fraction;
    }
}
