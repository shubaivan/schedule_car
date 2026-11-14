<?php

namespace App\Plan\Event;

/** Суб'єкт закрито: задача виконана/відхилена, заявка на складі/видана/відхилена. */
final class SubjectClosed extends StatusEvent
{
    public const string TYPE = 'subject_closed';
}
