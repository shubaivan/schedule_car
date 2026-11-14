<?php

namespace App\Plan\Subject;

use App\Entity\TelegramUser;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Що спільний шар «Плану» знає про суб'єкт конкретного модуля: назву, адресу
 * в CRM, хто «в темі» і хто має право бачити. Реалізація — по одній на модуль
 * (Provider\TaskSubjects, SupplySubjects, WarehouseSubjects), реєструється тегом
 * автоматично; звертатись через SubjectRegistry.
 */
#[AutoconfigureTag('plan.subject_provider')]
interface SubjectProvider
{
    public function supports(SubjectType $type): bool;

    /** Людська назва («№ 12 · Плити П-3»); null — суб'єкта вже немає. */
    public function title(SubjectRef $ref): ?string;

    /** Шлях у CRM від кореня сайту (`/crm/tasks/board/12`), без домену. */
    public function url(SubjectRef $ref): string;

    /**
     * Хто «в темі» суб'єкта (автор, учасники, спостерігачі, відповідальні) —
     * основа для сповіщень. Без фільтра «крім того, хто зробив дію».
     *
     * @return list<int> id telegram_user
     */
    public function audience(SubjectRef $ref): array;

    public function visibleTo(SubjectRef $ref, TelegramUser $user): bool;
}
