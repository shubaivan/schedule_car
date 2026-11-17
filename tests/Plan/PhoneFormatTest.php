<?php

namespace App\Tests\Plan;

use App\Entity\TelegramUser;
use App\Plan\People\PhoneFormat;
use App\Tasks\Service\TaskPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** Телефон поруч з іменем: один формат на весь План. */
class PhoneFormatTest extends KernelTestCase
{
    /** @return iterable<array{?string, ?string, ?string}> */
    public static function phones(): iterable
    {
        yield 'локальний' => ['0933504442', '093 350 44 42', '+380933504442'];
        yield 'з кодом і пробілами' => ['+380 93 350-44-42', '093 350 44 42', '+380933504442'];
        yield '9 цифр' => ['933504442', '093 350 44 42', '+380933504442'];
        yield 'інша країна' => ['+48 512 345 678', '+48512345678', '+48512345678'];
        yield 'порожній' => [null, null, null];
        yield 'замало цифр' => ['123', null, null];
    }

    #[DataProvider('phones')]
    public function testFormats(?string $raw, ?string $display, ?string $tel): void
    {
        self::assertSame($display, PhoneFormat::display($raw));
        self::assertSame($tel, PhoneFormat::tel($raw));
    }

    public function testBotTextsUseATelegramTappableFormat(): void
    {
        self::assertSame('+380 93 350 44 42', PhoneFormat::international('0933504442'));
        self::assertSame('Олександр Чигрин (+380 93 350 44 42)', PhoneFormat::named('Олександр Чигрин', '0933504442'));
        self::assertSame('Без номера', PhoneFormat::named('Без номера', null));
        self::assertSame('Гість (+48512345678)', PhoneFormat::named('Гість', '+48 512 345 678'));
    }

    public function testPersonPayloadCarriesThePhone(): void
    {
        self::bootKernel();
        $presenter = self::getContainer()->get(TaskPresenter::class);
        $user = new TelegramUser()->setPlanFullAccess(true)->setId(140)->setTelegramId('staff-x')->setFirstName('Олександр')->setLastName('Чигрин')->setPhoneNumber('0933504442');
        $person = $presenter->person($user);
        self::assertSame('093 350 44 42', $person['phone'] ?? null);
        self::assertSame('+380933504442', $person['tel'] ?? null);

        $user->setPhoneNumber(null);
        self::assertNull($presenter->person($user)['phone'] ?? null);
    }
}
