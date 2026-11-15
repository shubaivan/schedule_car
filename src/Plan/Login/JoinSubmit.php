<?php

namespace App\Plan\Login;

/** Що сталося з заявкою на приєднання, яку подав бот. */
enum JoinSubmit
{
    case Created;
    case AlreadyPending;
    /** Забагато заявок з цього Telegram за добу або щойно відхилили. */
    case Limited;
}
