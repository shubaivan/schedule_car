<?php

namespace App\Security;

/**
 * Один логін — один ключ. Людина входить email-ом або телефоном у будь-якому
 * записі (0501234567, +380 50 123 45 67, (050)1234567): шукаємо за email у
 * нижньому регістрі або за останніми 9 цифрами. Цей самий ключ іде в UserBadge,
 * тож login_throttling рахує спроби на людину, а не на варіант запису номера
 * (інакше кожен формат давав би нове вікно з 5 спроб).
 */
final class LoginIdentity
{
    public const string PHONE = 'phone:';

    public static function normalize(string $login): string
    {
        $login = mb_strtolower(trim($login));

        if (str_contains($login, '@')) {
            return $login;
        }

        $digits = preg_replace('/\D+/', '', $login) ?? '';

        return strlen($digits) >= 9 ? self::PHONE . substr($digits, -9) : $login;
    }

    /** Останні 9 цифр телефону з нормалізованого ключа; null — це не телефон. */
    public static function phoneTail(string $identity): ?string
    {
        return str_starts_with($identity, self::PHONE) ? substr($identity, strlen(self::PHONE)) : null;
    }
}
