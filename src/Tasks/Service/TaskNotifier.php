<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Plan\Notification\EventGroup;
use App\Plan\Notification\TelegramGate;
use App\Plan\People\PhoneFormat;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Telegram\PlanBotMode;
use App\Repository\TelegramUserRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Сповіщення по задачах у Telegram — одна точка, як SupplyNotifier у заявках.
 * Кличе лише TaskNotificationListener за подіями журналу «Плану».
 *
 * Хто що отримує:
 * - нова задача → усі учасники, крім автора («вас додали»);
 * - повідомлення в чаті → усі «в темі» (автор задачі, учасники, спостерігачі ★),
 *   крім автора повідомлення; згаданим — замість цього «🔔 вас згадали»;
 * - зміна статусу → усі «в темі», крім того, хто змінив;
 * - «На погодженні» → ще й усі, хто приймає рішення (директор, адмін).
 *
 * Перед кожним повідомленням — налаштування людини (TelegramGate: вимкнено,
 * тихі години → без звуку). «В темі» — той самий SubjectRegistry, що й у центрі сповіщень.
 *
 * Пишемо лише тим, хто справді є в боті: службові записи з CRM
 * (telegram_id «staff-…», «demo-…») пропускаємо. Помилка доставки не валить
 * операцію — задача вже збережена, причина йде в лог.
 */
class TaskNotifier
{
    public function __construct(
        private Nutgram $bot,
        private TelegramUserRepository $users,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        /** Порожній — бота на стенді ще нема: сповіщення мовчки пропускаємо. */
        #[Autowire('%env(string:TELEGRAM_TOKEN)%')]
        private ?string $token = null,
        private ?TelegramGate $gate = null,
        private ?SubjectRegistry $subjects = null,
    ) {
    }

    public function created(Task $task): void
    {
        $text = sprintf("📌 <b>Вас додали до задачі</b>\n%s\n\nПостановник: %s", $this->head($task), $this->e(PhoneFormat::named($task->getCreator()->shortName(), $task->getCreator()->getPhoneNumber())));

        if ($task->getDueAt() !== null) {
            $text .= "\nТермін: " . $task->getDueAt()->format('d.m.Y');
        }

        foreach ($this->members($task, except: $task->getCreator()) as $user) {
            $this->send($user, $text, $task, EventGroup::Assigned);
        }
    }

    /** @param TelegramUser[] $people кого щойно додали до задачі */
    public function added(Task $task, array $people, TelegramUser $by): void
    {
        $text = sprintf("📌 <b>Вас додали до задачі</b>\n%s\n\nВід: %s", $this->head($task), $this->e(PhoneFormat::named($by->shortName(), $by->getPhoneNumber())));

        foreach ($people as $user) {
            if ($user->getId() !== $by->getId()) {
                $this->send($user, $text, $task, EventGroup::Assigned);
            }
        }
    }

    public function message(TaskMessage $message): void
    {
        $task = $message->getTask();
        $author = $message->getAuthor();
        $files = $message->getAttachments()->count();
        $body = $this->e(mb_strimwidth($message->getText(), 0, 600, '…'));

        if ($files > 0) {
            $body .= ($body !== '' ? "\n" : '') . '📎 ' . $files . ' ' . $this->filesWord($files);
        }

        $mentioned = $message->getMentions();

        foreach ($this->involved($task, except: $author) as $user) {
            $mention = in_array($user->getId(), $mentioned, true);
            $title = $mention ? '🔔 <b>Вас згадали</b>' : '💬 <b>Нове повідомлення</b>';
            $this->send($user, sprintf("%s\n%s\n\n<b>%s:</b> %s", $title, $this->head($task), $this->e($author !== null ? PhoneFormat::named($author->shortName(), $author->getPhoneNumber()) : '—'), $body), $task, $mention ? EventGroup::Mention : EventGroup::Comment);
        }
    }

    /** @param TaskStatus|null $to куди перейшли (з події); null — поточний статус задачі */
    public function statusChanged(Task $task, TaskStatus $from, TelegramUser $by, ?string $comment = null, ?TaskStatus $to = null): void
    {
        $to ??= $task->getStatus();
        $text = sprintf("🔄 <b>%s → %s</b>\n%s\n\nВід: %s", $this->e($from->label()), $this->e($to->label()), $this->head($task), $this->e(PhoneFormat::named($by->shortName(), $by->getPhoneNumber())));

        if ($comment !== null && trim($comment) !== '') {
            $text .= "\n«" . $this->e(trim($comment)) . '»';
        }

        foreach ($this->involved($task, except: $by) as $user) {
            $this->send($user, $text, $task, EventGroup::Status);
        }
    }

    /** Чи можна цій людині писати в бот: справжній Telegram id — це число. */
    public static function reachable(TelegramUser $user): bool
    {
        return ! $user->isArchived() &&
            (ctype_digit((string) $user->getChatId()) || ctype_digit(ltrim((string) $user->getTelegramId(), '-')));
    }

    /**
     * Усі «в темі» задачі: автор, учасники, спостерігачі ★ (як центр сповіщень).
     * Без реєстру суб'єктів (юніт-тести) — лише учасники, як раніше.
     *
     * @return array<int, TelegramUser>
     */
    private function involved(Task $task, ?TelegramUser $except): array
    {
        $result = $this->members($task, $except);

        if ($this->subjects === null || $task->getId() === null) {
            return $result;
        }

        $ref = SubjectRef::task((int) $task->getId());
        $ids = array_diff($this->subjects->audience($ref), array_keys($result), [$except?->getId()]);

        // ★ лишається й після того, як людину прибрали з задачі, — пишемо лише тим, кому задачу ще видно.
        foreach ($ids === [] ? [] : $this->users->findBy(['id' => array_values($ids)]) as $user) {
            if ($user->isApproved() && ! $user->isArchived() && $this->subjects->visibleTo($ref, $user)) {
                $result[(int) $user->getId()] = $user;
            }
        }

        return $result;
    }

    /** @return array<int, TelegramUser> */
    private function members(Task $task, ?TelegramUser $except): array
    {
        $result = [];

        foreach ($task->getMembers() as $member) {
            $user = $member->getUser();

            if ($user->getId() !== $except?->getId()) {
                $result[(int) $user->getId()] = $user;
            }
        }

        return $result;
    }

    private function send(TelegramUser $user, string $text, Task $task, EventGroup $group): void
    {
        if ($this->token === '' || ! self::reachable($user)) {
            return;
        }

        $silent = $this->gate !== null ? $this->gate->decide($user, $group) : false;

        if ($silent === null) {
            return;
        }

        $url = $this->urls->generate('crm_index', ['path' => 'tasks/' . $task->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $user->getChatId() ?: $user->getTelegramId(),
                parse_mode: ParseMode::HTML,
                disable_notification: $silent ?: null,
                reply_markup: PlanBotMode::openButton($url),
            );
            $this->gate?->delivered($user);
        } catch (Throwable $e) {
            $this->logger->warning('tasks: не вдалось надіслати сповіщення', ['user' => $user->getId(), 'task' => $task->getId(), 'error' => $e->getMessage()]);
        }
    }

    private function head(Task $task): string
    {
        $line = sprintf('№ %s · %s', $task->number(), $this->e($task->getTitle()));

        return $task->getClient() !== null ? $line . "\n" . $this->e($task->getClient()) : $line;
    }

    private function filesWord(int $n): string
    {
        return match (true) {
            $n % 10 === 1 && $n % 100 !== 11 => 'файл',
            $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14) => 'файли',
            default => 'файлів',
        };
    }

    private function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
    }
}
