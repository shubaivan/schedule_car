<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class ScheduleLimit extends Constraint
{
    public string $message = 'За один день дозволенно не більше восьми годин бронювання';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}