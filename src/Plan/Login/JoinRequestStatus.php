<?php

namespace App\Plan\Login;

enum JoinRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Очікує',
            self::Approved => 'Затверджено',
            self::Rejected => 'Відхилено',
        };
    }
}
