<?php

namespace App\Plan\Event;

/** Суб'єкт перейшов на погодження — рішення за директором. */
final class ApprovalRequested extends StatusEvent
{
    public const string TYPE = 'approval_requested';
}
