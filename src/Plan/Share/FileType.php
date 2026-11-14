<?php

namespace App\Plan\Share;

/**
 * Звідки файл «Плану»: файл задачі (task_attachment), документ заявки
 * (supply_attachment) чи документ складу (wh_document). Значення пишуться
 * в share_link.file_type, plan_event.payload.fileType і в адреси фронту.
 */
enum FileType: string
{
    case Task = 'task';
    case Supply = 'supply';
    case Warehouse = 'wh';

    public function table(): string
    {
        return match ($this) {
            self::Task => 'task_attachment',
            self::Supply => 'supply_attachment',
            self::Warehouse => 'wh_document',
        };
    }

}
