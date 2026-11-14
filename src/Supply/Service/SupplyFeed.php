<?php

namespace App\Supply\Service;

use App\Entity\TelegramUser;
use App\Supply\Entity\SupplyAttachment;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\AttachmentType;
use App\Tasks\Service\TaskPresenter;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Стрічка заявки у форматі стрічки задачі — щоб «План» малював заявку тими самими
 * MessageBubble / TaskChat, що й задачу (архітектура 14.11, розд. 2.1).
 *
 * Зливає чотири джерела: зміни статусу (перша — «заявку подано»), коментарі,
 * закупівлі й документи. Своїх таблиць не має і нічого не пише: лише читає те,
 * що вже записали CreateRequest / ChangeStatus / AddComment / RecordPurchase / AttachFile.
 *
 * Id повідомлень синтетичні й стабільні: id рядка × 4 + тип джерела, тож рядки
 * різних таблиць не перетинаються, а повторний запит дає ті самі id.
 */
class SupplyFeed
{
    public const SOURCE_STATUS = 0;
    public const SOURCE_COMMENT = 1;
    public const SOURCE_PURCHASE = 2;
    public const SOURCE_FILE = 3;

    /** Що браузер може показати сам: фото — у переглядачі, PDF — у вкладці. */
    public const INLINE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private TaskPresenter $people)
    {
    }

    /**
     * Уся стрічка заявки за часом + файли окремим списком (для панелі «Документи» й переглядача).
     *
     * @return array{messages: list<array<string, mixed>>, files: list<array<string, mixed>>, status: string, statusLabel: string, can: array{comment: bool, attach: bool, removeFiles: bool}, reads: list<never>, now: string}
     */
    public function feed(SupplyRequest $request, TelegramUser $viewer): array
    {
        $messages = [];

        foreach ($request->getStatusLogs() as $log) {
            $messages[] = $this->statusEvent($log, $viewer);
        }

        foreach ($request->getComments() as $comment) {
            $messages[] = $this->comment($comment, $viewer);
        }

        foreach ($request->getPurchases() as $purchase) {
            $messages[] = $this->purchase($purchase, $viewer);
        }

        $files = [];

        foreach ($request->getAttachments() as $attachment) {
            $file = $this->attachment($attachment);
            $files[] = $file;
            $messages[] = $this->fileMessage($attachment, $file, $viewer);
        }

        // Час із точністю до секунди, тож у межах секунди — природний порядок роботи із заявкою:
        // подали → написали → вказали закупівлю → змінили статус → доклали документ.
        usort($messages, static fn (array $a, array $b) => [$a['at'], $a['rank'], $a['id']] <=> [$b['at'], $b['rank'], $b['id']]);

        return [
            'messages' => array_map(static function (array $m): array {
                unset($m['at'], $m['rank']);

                return $m;
            }, $messages),
            'files' => $files,
            'status' => $request->getStatus()->value,
            'statusLabel' => $request->getStatus()->label(),
            'can' => [
                'comment' => $this->canComment($request, $viewer),
                'attach' => $this->canAttach($request, $viewer),
                'removeFiles' => $viewer->getSupplyRole()->canManage(),
            ],
            // «Хто прочитав» у заявок немає — поле лишаємо, щоб формат збігався з задачею.
            'reads' => [],
            'now' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
    }

    /** Ті самі правила, що в AddComment: автор, менеджер, директор. */
    public function canComment(SupplyRequest $request, TelegramUser $user): bool
    {
        $role = $user->getSupplyRole();

        return $role->canManage() || $role->canApprovePayment() || $request->getAuthor()->getId() === $user->getId();
    }

    /** Ті самі правила, що в AttachFile: автор і менеджер. */
    public function canAttach(SupplyRequest $request, TelegramUser $user): bool
    {
        return $user->getSupplyRole()->canManage() || $request->getAuthor()->getId() === $user->getId();
    }

    /** Тип документа, якщо людина не вибрала: фото — «Фото», решта — «Інше». */
    public static function guessType(string $mime): AttachmentType
    {
        return str_starts_with($mime, 'image/') ? AttachmentType::Photo : AttachmentType::Other;
    }

    public static function messageId(int $rowId, int $source): int
    {
        return $rowId * 4 + $source;
    }

    /** @return array<string, mixed> */
    public function attachment(SupplyAttachment $a): array
    {
        $base = sprintf('/api/supply/requests/%d/attachments/%d', $a->getRequest()->getId(), $a->getId());
        $mime = $a->getMime();
        $ext = mb_strtoupper((string) pathinfo($a->getOriginalName(), PATHINFO_EXTENSION));
        $kind = match (true) {
            str_starts_with($mime, 'image/') => 'image',
            $mime === 'application/pdf' => 'pdf',
            str_contains($mime, 'word') || str_contains($mime, 'excel') || str_contains($mime, 'spreadsheet') => 'office',
            default => 'other',
        };
        $inline = in_array($mime, self::INLINE_MIME, true);

        return [
            'id' => $a->getId(),
            'messageId' => self::messageId((int) $a->getId(), self::SOURCE_FILE),
            'name' => $a->getOriginalName(),
            'ext' => $ext,
            'mime' => $mime,
            'size' => $a->getSize(),
            'sizeLabel' => $a->getSizeLabel(),
            'kind' => $kind,
            'preview' => 'none',
            'pages' => null,
            'width' => null,
            'height' => null,
            'uploadedBy' => $a->getUploadedBy()?->shortName(),
            'createdAt' => $a->getCreatedAt()->format(DateTimeInterface::ATOM),
            'urls' => [
                // Окремих мініатюр у заявок немає: фото показуємо самим файлом (до 20 МБ, як і в боті).
                'thumb' => $kind === 'image' && $inline ? $base . '/inline' : null,
                'view' => $kind === 'image' && $inline ? $base . '/inline' : null,
                'page' => null,
                'inline' => $inline ? $base . '/inline' : $base,
                'download' => $base,
            ],
            // Своє, заявчине: тип документа і копія в Google Drive.
            'type' => $a->getType()->value,
            'typeLabel' => $a->getType()->label(),
            'driveUrl' => $a->getDriveUrl(),
        ];
    }

    /** @return array<string, mixed> */
    private function statusEvent(SupplyStatusLog $log, TelegramUser $viewer): array
    {
        if ($log->getStatusFrom() === null) {
            $request = $log->getRequest();
            $text = sprintf('заявку подано — %s, %s', $request->getItem(), $request->getQuantityLabel());
            $type = 'created';
        } else {
            $text = sprintf('статус %s → %s', $log->getStatusFrom()->label(), $log->getStatusTo()->label());
            $type = $log->getStatusTo()->isFinal() ? 'closed' : 'status';
        }

        if ($log->getComment() !== null && trim($log->getComment()) !== '') {
            $text .= ' · «' . trim($log->getComment()) . '»';
        }

        return $this->message(self::messageId((int) $log->getId(), self::SOURCE_STATUS), 'event', $type, $log->getAuthor(), $text, $log->getCreatedAt(), $viewer);
    }

    /** @return array<string, mixed> */
    private function comment(SupplyComment $comment, TelegramUser $viewer): array
    {
        return $this->message(self::messageId((int) $comment->getId(), self::SOURCE_COMMENT), 'comment', 'comment', $comment->getAuthor(), $comment->getText(), $comment->getCreatedAt(), $viewer);
    }

    /** @return array<string, mixed> */
    private function purchase(SupplyPurchase $purchase, TelegramUser $viewer): array
    {
        $text = sprintf('закупівля — %s · %s', $purchase->getSupplier()->getName(), $purchase->getTotalLabel());

        if ($purchase->getInvoiceNumber() !== null) {
            $text .= ' · накладна ' . $purchase->getInvoiceNumber();
        }

        return $this->message(self::messageId((int) $purchase->getId(), self::SOURCE_PURCHASE), 'event', 'updated', $purchase->getCreatedBy(), $text, $purchase->getCreatedAt(), $viewer);
    }

    /**
     * Документ — повідомлення з одним вкладенням, як файл у чаті задачі.
     *
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    private function fileMessage(SupplyAttachment $a, array $file, TelegramUser $viewer): array
    {
        $message = $this->message(
            self::messageId((int) $a->getId(), self::SOURCE_FILE),
            'comment',
            'file',
            $a->getUploadedBy(),
            $a->getType() === AttachmentType::Other ? '' : $a->getType()->emoji() . ' ' . $a->getType()->label(),
            $a->getCreatedAt(),
            $viewer,
        );
        $message['attachments'] = [$file];
        $message['can']['delete'] = $viewer->getSupplyRole()->canManage();

        return $message;
    }

    /** @return array<string, mixed> */
    private function message(int $id, string $kind, string $type, ?TelegramUser $author, string $text, DateTimeInterface $at, TelegramUser $viewer): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'eventType' => $type,
            'author' => $this->people->person($author),
            'text' => $text,
            'mentions' => [],
            'createdAt' => $at->format(DateTimeInterface::ATOM),
            'deleted' => false,
            'mine' => $author !== null && $author->getId() === $viewer->getId(),
            'attachments' => [],
            'replyTo' => null,
            'editedAt' => null,
            'pinned' => null,
            'starred' => false,
            'can' => ['edit' => false, 'delete' => false],
            'at' => $at->getTimestamp(),
            'rank' => match (true) {
                $type === 'created' => 0,
                $type === 'comment' => 1,
                $id % 4 === self::SOURCE_PURCHASE => 2,
                $type === 'file' => 4,
                default => 3,
            },
        ];
    }
}
