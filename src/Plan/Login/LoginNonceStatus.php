<?php

namespace App\Plan\Login;

/**
 * Стан спроби входу через Telegram (plan_login_nonce.status).
 *
 * pending → (бот) confirmed → (браузер) used → (людина в боті «Це був не я») revoked;
 * pending → denied (відмова: доступ закрито, чужий Telegram, «не я» до входу);
 * pending → join (номера в Плані немає — заявку на приєднання надіслано).
 */
enum LoginNonceStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Used = 'used';
    case Denied = 'denied';
    case Join = 'join';
    case Revoked = 'revoked';
}
