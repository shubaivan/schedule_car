<?php

namespace App\Plan\Notification;

use App\Plan\Event\DriveAuthBroken;
use App\Plan\Event\PlanEvent;
use App\Plan\Event\ShareCreated;
use App\Plan\Share\FileType;
use App\Plan\Subject\SubjectRegistry;

/**
 * Куди веде сповіщення — однаково в центрі, тості й Web Push: «Диск відкликав
 * доступ» → сторінка Диска, «поділились файлом» → сам файл, решта → суб'єкт.
 */
final class NotificationLink
{
    /** @return array{title: string, url: string, known: bool} */
    public static function of(PlanEvent $event, SubjectRegistry $subjects): array
    {
        if ($event instanceof DriveAuthBroken) {
            return ['title' => 'Google Диск', 'url' => '/crm/drive', 'known' => false];
        }

        $known = $subjects->has($event->subject->type);
        $subjectUrl = $known ? $subjects->url($event->subject) : '/crm/notifications';
        $url = $event instanceof ShareCreated ? (FileType::tryFrom($event->fileType)?->route($event->fileId) ?? $subjectUrl) : $subjectUrl;

        return ['title' => $known ? ($subjects->title($event->subject) ?? 'Видалено') : 'План', 'url' => $url, 'known' => $known];
    }
}
