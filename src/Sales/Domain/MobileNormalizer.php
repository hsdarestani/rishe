<?php

declare(strict_types=1);

namespace Rishe\Sales\Domain;

use Rishe\Sales\Domain\Exception\SalesDomainException;

final class MobileNormalizer
{
    public function normalize(mixed $value): string
    {
        $mobile = trim((string) $value);
        $mobile = strtr($mobile, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $mobile);
        if (!is_string($digits)) {
            throw new SalesDomainException('Mobile number could not be normalized.');
        }

        if (str_starts_with($digits, '0098')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '09')) {
            $digits = '98' . substr($digits, 1);
        } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
            $digits = '98' . $digits;
        }

        if (!preg_match('/^989\d{9}$/', $digits)) {
            throw new SalesDomainException('A valid Iranian mobile number is required.');
        }

        return '+' . $digits;
    }
}
