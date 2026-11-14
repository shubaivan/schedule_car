<?php

namespace App\Tasks\Service;

use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Exception\TaskException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Файли задач на диску: оригінали й згенеровані превʼю.
 *
 *   var/storage/tasks/files/2026/11/<id>-<sha8>.<ext>   — оригінал
 *   var/storage/tasks/previews/<id>/thumb.jpg            — мініатюра в чат
 *   var/storage/tasks/previews/<id>/view.jpg             — картинка для лайтбокса
 *   var/storage/tasks/previews/<id>/doc.pdf              — офісний файл, сконвертований у PDF
 *   var/storage/tasks/previews/<id>/page-<n>.jpg         — сторінка PDF для перегляду
 */
class AttachmentStorage
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    private Filesystem $fs;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/tasks/%kernel.environment%')]
        private string $root,
    ) {
        $this->fs = new Filesystem();
    }

    public static function kindOf(string $name, string $mime): string
    {
        $ext = self::extension($name);

        return match (true) {
            in_array($ext, self::IMAGE, true) && str_starts_with($mime, 'image/') => TaskAttachment::KIND_IMAGE,
            $ext === 'pdf' || $mime === 'application/pdf' => TaskAttachment::KIND_PDF,
            in_array($ext, self::OFFICE, true) => TaskAttachment::KIND_OFFICE,
            default => TaskAttachment::KIND_OTHER,
        };
    }

    public static function extension(string $name): string
    {
        return mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }

    /** Кладе оригінал на місце й записує шлях у вкладення (id вже має бути). */
    public function put(TaskAttachment $attachment, string $sourcePath): void
    {
        $size = filesize($sourcePath);

        if ($size === false || $size === 0) {
            throw new TaskException('Порожній файл.');
        }

        if ($size > self::MAX_BYTES) {
            throw new TaskException('Файл більший за 20 МБ.');
        }

        $ext = self::extension($attachment->getOriginalName());
        $relative = sprintf(
            'files/%s/%d-%s%s',
            $attachment->getCreatedAt()->format('Y/m'),
            $attachment->getId(),
            substr($attachment->getSha256(), 0, 8),
            $ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.' . $ext : '',
        );

        $target = $this->root . '/' . $relative;
        $this->fs->copy($sourcePath, $target, true);
        // copy() keeps the source mode, and PHP upload temp files are 0600: FPM (www-data)
        // would own a file the CLI/worker (deploy) cannot read — previews and Drive copies
        // then fail with "file missing". Group www-data reads and writes, others do not.
        $this->fs->chmod($target, 0o660);
        $attachment->setPath($relative);
    }

    public function original(TaskAttachment $attachment): string
    {
        return $this->root . '/' . $attachment->getPath();
    }

    public function previewDir(TaskAttachment $attachment): string
    {
        $dir = $this->root . '/previews/' . $attachment->getId();
        $this->fs->mkdir($dir);

        return $dir;
    }

    public function thumb(TaskAttachment $attachment): string
    {
        return $this->previewDir($attachment) . '/thumb.jpg';
    }

    public function view(TaskAttachment $attachment): string
    {
        return $this->previewDir($attachment) . '/view.jpg';
    }

    /** PDF, з якого малюються сторінки: сам файл або сконвертований офісний. */
    public function pdf(TaskAttachment $attachment): string
    {
        return $attachment->getKind() === TaskAttachment::KIND_OFFICE
            ? $this->previewDir($attachment) . '/doc.pdf'
            : $this->original($attachment);
    }

    public function page(TaskAttachment $attachment, int $page): string
    {
        return $this->previewDir($attachment) . '/page-' . $page . '.jpg';
    }

    public function root(): string
    {
        $this->fs->mkdir($this->root);

        return $this->root;
    }
}
