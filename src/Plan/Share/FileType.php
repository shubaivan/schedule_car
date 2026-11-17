<?php

namespace App\Plan\Share;

/**
 * Звідки файл «Плану»: файл задачі (task_attachment), документ заявки
 * (supply_attachment), документ складу (wh_document) чи файл «Звʼязку» (plan_chat_attachment). Значення пишуться
 * в share_link.file_type, plan_event.payload.fileType і в адреси фронту.
 */
enum FileType: string
{
    case Task = 'task';
    case Supply = 'supply';
    case Warehouse = 'wh';
    /** Файл у повідомленні «Звʼязку» (plan_chat_attachment). Лише Диск: поширення посиланням і /crm/files — ні. */
    case Zvyazok = 'zv';

    public function table(): string
    {
        return match ($this) {
            self::Task => 'task_attachment',
            self::Supply => 'supply_attachment',
            self::Warehouse => 'wh_document',
            self::Zvyazok => 'plan_chat_attachment',
        };
    }

    /** Сторінка файлу у фронті (повноекранний переглядач): /crm/files/12, /crm/files/supply/5, /crm/files/wh/7. */
    public function route(int $id): string
    {
        return match ($this) {
            self::Task => '/crm/files/' . $id,
            self::Zvyazok => '/crm/zvyazok',
            default => '/crm/files/' . $this->value . '/' . $id,
        };
    }
}
