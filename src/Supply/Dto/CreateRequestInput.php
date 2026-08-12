<?php

namespace App\Supply\Dto;

use App\Supply\Entity\Department;
use App\Supply\Enum\Unit;

/** Те, що вводить робітник — у боті або в CRM. */
class CreateRequestInput
{
    public function __construct(
        public string $item,
        public string $quantity,
        public Unit $unit,
        public ?\DateTime $needBy = null,
        public bool $urgent = false,
        public ?string $site = null,
        public ?string $note = null,
        public ?Department $department = null,
    ) {
    }
}
