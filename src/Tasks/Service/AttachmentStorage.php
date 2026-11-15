<?php

namespace App\Tasks\Service;

use App\Tasks\Entity\TaskAttachment;
use App\Tasks\Exception\TaskException;
use finfo;
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

    private const array IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const array OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];

    /**
     * Що приймаємо у вкладення задач (та сама родина, що в заявках і на складі:
     * PDF, фото, офіс), плюс те, чим реально обмінюються на заводі: текст/CSV,
     * креслення DWG/DXF, архіви. Тип — за вмістом (finfo), не за тим, що прислав
     * браузер. HTML, SVG, скрипти й програми — ні.
     */
    private const array ALLOWED_MIME = [
        'application/pdf',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/rtf', 'text/rtf', 'text/plain', 'text/csv',
        'application/zip', 'application/x-7z-compressed', 'application/vnd.rar', 'application/x-rar',
        'image/vnd.dwg', 'image/vnd.dxf', 'application/acad', 'application/dxf', 'application/x-dwg',
    ];

    private const array ALLOWED_PREFIX = ['application/vnd.openxmlformats-officedocument.', 'application/vnd.oasis.opendocument.'];

    /** Офісні файли, які finfo бачить лише як контейнер (zip / OLE) — приймаємо за розширенням. */
    private const array OFFICE_CONTAINERS = ['application/octet-stream', 'application/zip', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'];

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

    /** Тип файла за вмістом (finfo); null — не вдалося визначити. */
    public static function detect(string $path): ?string
    {
        $mime = new finfo(FILEINFO_MIME_TYPE)->file($path);

        return is_string($mime) && $mime !== '' ? strtolower($mime) : null;
    }

    public static function allowed(string $name, ?string $detected): bool
    {
        if ($detected === null) {
            return false;
        }

        if (in_array($detected, self::ALLOWED_MIME, true)) {
            return true;
        }

        foreach (self::ALLOWED_PREFIX as $prefix) {
            if (str_starts_with($detected, $prefix)) {
                return true;
            }
        }

        return in_array($detected, self::OFFICE_CONTAINERS, true) && in_array(self::extension($name), self::OFFICE, true);
    }

    /** Відмова з людським текстом, якщо такий файл у задачу не приймаємо. */
    public static function assertAllowed(string $name, string $path): string
    {
        $detected = self::detect($path);

        if (! self::allowed($name, $detected)) {
            throw new TaskException(sprintf('«%s»: такий тип файла не приймаємо. Можна PDF, фото, документи Word/Excel/PowerPoint, текст, креслення DWG/DXF і архіви.', $name));
        }

        return (string) $detected;
    }

    /**
     * Тип, що піде у вкладення: за вмістом. Лише коли finfo бачить контейнер (zip/OLE
     * офісного файла) — заявлений браузером, і то лише офісний/архівний, інакше контейнер.
     */
    public static function storedMime(string $name, string $detected, string $claimed): string
    {
        $claimed = strtolower(trim($claimed));

        if (! in_array($detected, self::OFFICE_CONTAINERS, true) || $claimed === '') {
            return $detected;
        }

        $office = str_starts_with($claimed, 'application/vnd.') || in_array($claimed, ['application/msword', 'application/zip', 'application/x-zip-compressed'], true);

        return $office ? $claimed : $detected;
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
