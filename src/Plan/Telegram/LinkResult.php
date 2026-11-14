<?php

namespace App\Plan\Telegram;

enum LinkResult
{
    case Linked;
    /** Цей самий Telegram уже прив'язаний до цієї людини. */
    case AlreadyLinked;
    /** Токен невідомий, використаний або протермінований. */
    case Invalid;
    /** Цей Telegram уже належить іншому обліковому запису Плану. */
    case Taken;
}
