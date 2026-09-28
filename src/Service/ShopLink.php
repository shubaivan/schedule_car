<?php

namespace App\Service;

use App\Entity\TelegramUser;
use App\Warehouse\Service\WarehouseSection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * «🛒 Магазин» у меню CRM — вхід в адмінку сайту без другого логіна.
 *
 * CRM — єдина точка входу для людей (рішення Івана 28.09.2026), але товари,
 * замовлення й промокоди живуть на сервері сайту разом з оплатою й доставкою,
 * і переписувати їх тут означало б дві копії логіки грошей. Тож CRM видає
 * короткий підписаний пропуск, а адмінка сайту за ним пускає ту саму людину —
 * її впізнають за Telegram-акаунтом, спільним для обох систем.
 *
 * Пропуск: base64url(json{tg, exp, nonce, to}) . "." . HMAC-SHA256 спільним
 * секретом. Живе хвилину й одноразовий (nonce гасить сайт) — переслати його
 * чи підглянути в історії браузера безглуздо.
 *
 * Порожні SHOP_ADMIN_URL чи SHOP_SSO_SECRET — магазину в цього клієнта немає,
 * пункт меню не показується (каркас спільний із Буддеталлю).
 */
class ShopLink
{
    public const TTL_SECONDS = 60;

    public function __construct(
        #[Autowire('%env(SHOP_ADMIN_URL)%')]
        private string $shopUrl,
        #[Autowire('%env(SHOP_SSO_SECRET)%')]
        private string $secret,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->shopUrl !== '' && $this->secret !== '';
    }

    /** Магазин ведуть ті самі люди, що й склад: менеджер, адміністратор, директор. */
    public function availableTo(?TelegramUser $user): bool
    {
        return $this->isEnabled() && WarehouseSection::canManage($user) && $user?->getTelegramId() !== null;
    }

    /** Куди в адмінці сайту вести: лише її власні сторінки. */
    public static function safeTarget(?string $to): string
    {
        return $to !== null && preg_match('#^/admin(/[A-Za-z0-9/_-]*)?$#', $to) ? $to : '/admin/orders';
    }

    public function url(TelegramUser $user, ?string $to = null, ?int $now = null): string
    {
        $payload = self::base64url((string) json_encode([
            'tg' => (string) $user->getTelegramId(),
            'exp' => ($now ?? time()) + self::TTL_SECONDS,
            'nonce' => bin2hex(random_bytes(12)),
            'to' => self::safeTarget($to),
        ]));

        return sprintf(
            '%s/admin/sso?t=%s.%s',
            rtrim($this->shopUrl, '/'),
            $payload,
            self::base64url(hash_hmac('sha256', $payload, $this->secret, true)),
        );
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
