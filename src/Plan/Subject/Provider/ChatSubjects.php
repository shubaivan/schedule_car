<?php

namespace App\Plan\Subject\Provider;

use App\Entity\TelegramUser;
use App\Plan\Subject\SubjectProvider;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectType;
use App\Plan\Zvyazok\ZvyazokAccess;
use Doctrine\DBAL\Connection;

/**
 * Чат «Звʼязку» як суб'єкт — лише для службових подій (поломка Диска на файлі чату).
 * Назву чату не розкриваємо (журнал бачать і ті, хто не учасник): «Чат №7».
 * «В темі» — нікого: «Звʼязок» не шле сповіщень, лише лічильник непрочитаних.
 */
final readonly class ChatSubjects implements SubjectProvider
{
    public function __construct(
        private Connection $db,
        private ZvyazokAccess $access,
    ) {
    }

    public function supports(SubjectType $type): bool
    {
        return $type === SubjectType::Chat;
    }

    public function title(SubjectRef $ref): ?string
    {
        return $this->db->fetchOne('SELECT 1 FROM plan_chat WHERE id = ?', [$ref->id]) !== false ? 'Чат №' . $ref->id : null;
    }

    public function url(SubjectRef $ref): string
    {
        return '/crm/zvyazok/' . $ref->id;
    }

    public function audience(SubjectRef $ref): array
    {
        return [];
    }

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool
    {
        return $this->access->can($user) && $this->db->fetchOne(
            'SELECT 1 FROM plan_chat_member WHERE chat_id = ? AND user_id = ? AND left_at IS NULL',
            [$ref->id, $user->getId()],
        ) !== false;
    }
}
