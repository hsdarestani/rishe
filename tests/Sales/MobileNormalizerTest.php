<?php

declare(strict_types=1);

namespace Rishe\Tests\Sales;

use PHPUnit\Framework\TestCase;
use Rishe\Sales\Domain\Exception\SalesDomainException;
use Rishe\Sales\Domain\MobileNormalizer;

final class MobileNormalizerTest extends TestCase
{
    public function testItNormalizesIranianMobileFormsAndPersianDigits(): void
    {
        $normalizer = new MobileNormalizer();

        self::assertSame('+989121234567', $normalizer->normalize('0912 123 4567'));
        self::assertSame('+989121234567', $normalizer->normalize('0098-912-123-4567'));
        self::assertSame('+989121234567', $normalizer->normalize('۰۹۱۲۱۲۳۴۵۶۷'));
    }

    public function testItRejectsInvalidMobile(): void
    {
        $this->expectException(SalesDomainException::class);

        (new MobileNormalizer())->normalize('12345');
    }
}
