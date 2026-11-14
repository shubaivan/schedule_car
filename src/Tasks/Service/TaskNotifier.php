<?php

namespace App\Tasks\Service;

use App\Entity\TelegramUser;
use App\Repository\TelegramUserRepository;
use App\Tasks\Entity\Task;
use App\Tasks\Entity\TaskMessage;
use App\Tasks\Enum\TaskStatus;
use Psr\Log\LoggerInterface;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Сповіщення по задачах у Telegram — одна точка, як SupplyNotifier у заявках.
 *
 * Хто що отримує:
 * - нова задача → усі учасники, крім автора («вас додали»);
 * - повідомлення в чаті → усі учасники, крім автора; згаданим — окремо «🔔 вас згадали»;
 * - зміна статусу → учасники, крім того, хто змінив;
 * - «На погодженні» → ще й усі, хто приймає рішення (директор, адмін).
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
    ) {
    }

    public function created(Task $task): void
    {
        $text = sprintf("📌 <b>Вас додали до задачі</b>\n%s\n\nПоставив(ла): %s", $this->head($task), $this->e($task->getCreator()->shortName()));

        if ($task->getDueAt() !== null) {
            $text .= "\nТермін: " . $task->getDueAt()->format('d.m.Y');
        }

        foreach ($this->members($task, except: $task->getCreator()) as $user) {
            $this->send($user, $text, $task);
        }
    }

    /** @param TelegramUser[] $people кого щойно додали до задачі */
    public function added(Task $task, array $people, TelegramUser $by): void
    {
        $text = sprintf("📌 <b>Вас додали до задачі</b>\n%s\n\nДодав(ла): %s", $this->head($task), $this->e($by->shortName()));

        foreach ($people as $user) {
            if ($user->getId() !== $by->getId()) {
                $this->send($user, $text, $task);
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

        foreach ($this->members($task, except: $author) as $user) {
            $title = in_array($user->getId(), $mentioned, true) ? '🔔 <b>Вас згадали</b>' : '💬 <b>Нове повідомлення</b>';
            $this->send($user, sprintf("%s\n%s\n\n<b>%s:</b> %s", $title, $this->head($task), $this->e($author?->shortName() ?? '—'), $body), $task);
        }
    }

    public function statusChanged(Task $task, TaskStatus $from, TelegramUser $by, ?string $comment = null): void
    {
        $text = sprintf("🔄 <b>%s → %s</b>\n%s\n\nЗмінив(ла): %s", $this->e($from->label()), $this->e($task->getStatus()->label()), $this->head($task), $this->e($by->shortName()));

        if ($comment !== null && trim($comment) !== '') {
            $text .= "\n«" . $this->e(trim($comment)) . '»';
        }

        $recipients = $this->members($task, except: $by);

        // На погодження — тим, хто вирішує, навіть якщо їх у задачі не позначили.
        if ($task->getStatus() === TaskStatus::Approval) {
            foreach ($this->users->findAll() as $user) {
                if ($user->getWorkRole()->decides() && $user->getId() !== $by->getId() && ! $user->isArchived()) {
                    $recipients[$user->getId()] = $user;
                }
            }

            $text = "⚖️ <b>Потрібне ваше рішення</b>\n" . $text;
        }

        foreach ($recipients as $user) {
            $this->send($user, $text, $task);
        }
    }

    /** Чи можна цій людині писати в бот: справжній Telegram id — це число. */
    public static function reachable(TelegramUser $user): bool
    {
        return ! $user->isArchived() &&
            (ctype_digit((string) $user->getChatId()) || ctype_digit(ltrim((string) $user->getTelegramId(), '-')));
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

    private function send(TelegramUser $user, string $text, Task $task): void
    {
        if ($this->token === '' || ! self::reachable($user)) {
            return;
        }

        $url = $this->urls->generate('crm_index', ['path' => 'tasks/' . $task->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $markup = str_starts_with($url, 'https://')
            ? InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('Відкрити задачу', url: $url))
            : null;

        try {
            $this->bot->sendMessage(
                text: $text,
                chat_id: $user->getChatId() ?: $user->getTelegramId(),
                parse_mode: ParseMode::HTML,
                reply_markup: $markup,
            );
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
