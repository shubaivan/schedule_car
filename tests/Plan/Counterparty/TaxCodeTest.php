<?php

namespace App\Tests\Plan\Counterparty;

use App\Plan\Counterparty\Entity\Counterparty;
use App\Plan\Counterparty\Service\TaxCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Контрольні цифри ЄДРПОУ, РНОКПП і IBAN — на справжніх кодах і опечатках у них. */
class TaxCodeTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function edrpou(): iterable
    {
        yield 'ПриватБанк' => ['14360570', true];
        yield 'Ощадбанк (з нулями попереду)' => ['00032129', true];
        yield 'діапазон 30–60 млн (інші ваги)' => ['32855961', true];
        yield 'другий прохід ваг (+2)' => ['30000005', true];
        yield 'опечатка в останній цифрі' => ['14360571', false];
        yield 'переставлені цифри' => ['14365070', false];
        yield '7 цифр' => ['1436057', false];
        yield 'літери' => ['1436057A', false];
    }

    #[DataProvider('edrpou')]
    public function testEdrpou(string $code, bool $valid): void
    {
        self::assertSame($valid, TaxCode::isEdrpou($code));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function rnokpp(): iterable
    {
        yield 'правильний' => ['2914177764', true];
        yield 'ще один' => ['3170669078', true];
        yield 'опечатка' => ['2914177765', false];
        yield '9 цифр' => ['291417776', false];
    }

    #[DataProvider('rnokpp')]
    public function testRnokpp(string $code, bool $valid): void
    {
        self::assertSame($valid, TaxCode::isRnokpp($code));
    }

    public function testIban(): void
    {
        self::assertSame('UA743052990000026007233566001', TaxCode::iban(' ua74 3052 9900 0002 6007 2335 6600 1 '));
        self::assertTrue(TaxCode::isIban('UA743052990000026007233566001'));
        self::assertFalse(TaxCode::isIban('UA743052990000026007233566002'), 'контрольна сума');
        self::assertFalse(TaxCode::isIban('DE89370400440532013000'), 'лише українські');
        self::assertNull(TaxCode::iban('  '));
    }

    public function testDigitsAndVatNumber(): void
    {
        self::assertSame('14360570', TaxCode::digits(' 1436 0570 '));
        self::assertNull(TaxCode::digits('—'));
        self::assertTrue(TaxCode::isVatNumber('143605726551'));
        self::assertFalse(TaxCode::isVatNumber('14360572655'));
    }

    public function testNameNormalizationKeepsLegalForm(): void
    {
        self::assertSame(Counterparty::normalize('ТОВ «Житлобуд-Черкаси»'), Counterparty::normalize('тов житлобуд черкаси'));
        self::assertNotSame(Counterparty::normalize('ТОВ Альфа'), Counterparty::normalize('ФОП Альфа'));
    }
}
