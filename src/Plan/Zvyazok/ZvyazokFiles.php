<?php

namespace App\Plan\Zvyazok;

use App\Tasks\Service\AttachmentStorage;
use DateTimeImmutable;
use GdImage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Файли «Звʼязку» (Олександр 17.11): фото, офісні документи й PDF, до 20 МБ кожен.
 *
 * Фото стискаємо сильно й одразу, до запису: довша сторона до 1600 px, JPEG якості 70,
 * поворот за EXIF, метадані (GPS, модель телефона) не зберігаємо. Якщо фото не
 * вдалося розпізнати (HEIC без підтримки в GD), лишаємо як є — його можна скачати.
 * Тип — за вмістом (finfo), не за тим, що прислав браузер; HTML/SVG/програми — ні.
 *
 *   var/storage/zvyazok/<env>/files/2026/11/<id>-<sha8>.<ext>   — файл (фото — уже стиснене)
 *   var/storage/zvyazok/<env>/previews/<id>/thumb.jpg            — мініатюра в стрічку
 */
class ZvyazokFiles
{
    public const int MAX_BYTES = 20 * 1024 * 1024;
    public const int MAX_FILES = 10;
    public const int PHOTO_SIDE = 1600;
    public const int PHOTO_QUALITY = 70;
    public const int THUMB_SIDE = 480;

    public const string KIND_IMAGE = 'image';
    public const string KIND_PDF = 'pdf';
    public const string KIND_OFFICE = 'office';

    /** HEIC не приймаємо: GD його не стисне, а з ним лишився б і GPS (iOS сам дає JPEG, якщо HEIC не просять). */
    private const array IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    /** Більше — не розпаковуємо (GD тримає ~5 байт на піксель; «бомба» 20 МБ може важити гігабайти). */
    public const int MAX_PIXELS = 50_000_000;
    private const array OFFICE_EXT = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];
    private const array OFFICE_MIME = ['application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint', 'application/rtf', 'text/rtf'];
    private const array OFFICE_PREFIX = ['application/vnd.openxmlformats-officedocument.', 'application/vnd.oasis.opendocument.'];
    /**
     * Офісні файли, які finfo бачить лише як контейнер (zip / OLE): приймаємо за розширенням,
     * але лише якщо всередині справді документ (OfficeContainer) — не будь-який zip чи exe.
     */
    private const array OFFICE_CONTAINERS = ['application/zip', 'application/cdfv2', 'application/x-ole-storage', 'application/vnd.ms-office'];

    private Filesystem $fs;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/storage/zvyazok/%kernel.environment%')]
        private readonly string $root,
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Перевірка до будь-якого запису: розмір і тип. Повертає [kind, mime за вмістом].
     *
     * @return array{0: string, 1: string}
     */
    public static function check(UploadedFile $file): array
    {
        $name = self::clientName($file);

        if (! $file->isValid()) {
            throw new ZvyazokException(sprintf('«%s» не завантажився: %s', $name, $file->getErrorMessage()), 422);
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new ZvyazokException(sprintf('«%s» — порожній файл.', $name), 422);
        }

        if ($size > self::MAX_BYTES) {
            throw new ZvyazokException(sprintf('«%s» більший за 20 МБ.', $name), 422);
        }

        $detected = AttachmentStorage::detect($file->getPathname());
        $kind = self::kindOf($name, $detected);

        if ($kind === self::KIND_OFFICE && in_array($detected, self::OFFICE_CONTAINERS, true) && ! OfficeContainer::looksLikeDocument($file->getPathname(), AttachmentStorage::extension($name))) {
            $kind = null;
        }

        if ($kind === null) {
            throw new ZvyazokException(in_array($detected, ['image/heic', 'image/heif'], true)
                ? sprintf('«%s»: формат HEIC не приймаємо — надішліть фото з галереї ще раз (телефон перетворить на JPEG) або змініть формат камери на «Найсумісніший».', $name)
                : sprintf('«%s»: такий тип файла не приймаємо. Можна фото, PDF і документи Word/Excel/PowerPoint.', $name), 422);
        }

        if ($kind === self::KIND_IMAGE) {
            [$w, $h] = @getimagesize($file->getPathname()) ?: [0, 0];

            if ($w < 1 || $h < 1) {
                throw new ZvyazokException(sprintf('«%s»: не вдалося прочитати фото.', $name), 422);
            }

            if ($w * $h > self::MAX_PIXELS) {
                throw new ZvyazokException(sprintf('«%s»: фото завелике (%d×%d). До %d мегапікселів.', $name, $w, $h, self::MAX_PIXELS / 1_000_000), 422);
            }
        }

        return [$kind, (string) $detected];
    }

    public static function kindOf(string $name, ?string $detected): ?string
    {
        if ($detected === null) {
            return null;
        }

        $ext = AttachmentStorage::extension($name);

        return match (true) {
            in_array($detected, self::IMAGE_MIME, true) => self::KIND_IMAGE,
            $detected === 'application/pdf' => self::KIND_PDF,
            in_array($detected, self::OFFICE_MIME, true) => self::KIND_OFFICE,
            array_any(self::OFFICE_PREFIX, static fn (string $p) => str_starts_with($detected, $p)) => self::KIND_OFFICE,
            in_array($detected, self::OFFICE_CONTAINERS, true) && in_array($ext, self::OFFICE_EXT, true) => self::KIND_OFFICE,
            default => null,
        };
    }

    public static function clientName(UploadedFile $file): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], ['', '-', '-'], (string) ($file->getClientOriginalName() ?: $file->getFilename())));

        return mb_substr($name !== '' ? $name : 'файл', 0, 200);
    }

    /**
     * Готує файл до запису: фото стискає у тимчасовий JPEG. Повертає, що й звідки писати.
     *
     * @return array{path: string, name: string, mime: string, size: int, originalSize: int, width: ?int, height: ?int, temp: bool}
     */
    public function prepare(UploadedFile $file, string $kind, string $detected): array
    {
        $name = self::clientName($file);
        $originalSize = (int) $file->getSize();
        $mime = $detected;

        if ($kind === self::KIND_OFFICE && in_array($detected, self::OFFICE_CONTAINERS, true)) {
            // Контейнер (zip/OLE) — тип за розширенням, щоб браузер і Диск знали, що це.
            $mime = self::officeMime(AttachmentStorage::extension($name));
        }

        if ($kind === self::KIND_IMAGE && $detected !== 'image/gif') {
            // Не розпізнали (зіпсований файл) — далі як є: розмір і тип уже перевірені в check().
            $compressed = $this->compress($file->getPathname(), $detected);

            if ($compressed !== null) {
                return [
                    'path' => $compressed['path'],
                    'name' => self::jpegName($name),
                    'mime' => 'image/jpeg',
                    'size' => (int) filesize($compressed['path']),
                    'originalSize' => $originalSize,
                    'width' => $compressed['width'],
                    'height' => $compressed['height'],
                    'temp' => true,
                ];
            }
        }

        [$width, $height] = $kind === self::KIND_IMAGE ? (@getimagesize($file->getPathname()) ?: [null, null]) : [null, null];

        return ['path' => $file->getPathname(), 'name' => $name, 'mime' => $mime, 'size' => $originalSize, 'originalSize' => $originalSize, 'width' => $width, 'height' => $height, 'temp' => false];
    }

    /** Кладе файл на місце; повертає шлях відносно сховища. */
    public function put(int $id, string $sha256, DateTimeImmutable $at, string $name, string $source): string
    {
        $ext = AttachmentStorage::extension($name);
        $relative = sprintf('files/%s/%d-%s%s', $at->format('Y/m'), $id, substr($sha256, 0, 8), $ext !== '' && preg_match('/^[a-z0-9]{1,8}$/', $ext) ? '.' . $ext : '');
        $target = $this->root . '/' . $relative;
        $this->fs->copy($source, $target, true);
        // Як у задачах: FPM (www-data) і воркер/крон Диска (deploy) читають той самий файл.
        $this->fs->chmod($target, 0o660);

        return $relative;
    }

    /** Мініатюра фото для стрічки; помилка — не біда, покажемо значок файла. */
    public function thumb(int $id, string $relative): void
    {
        try {
            $image = self::load($this->path($relative), null, self::THUMB_SIDE);

            if ($image !== null) {
                $dir = $this->root . '/previews/' . $id;
                $this->fs->mkdir($dir);
                self::saveScaled($image, self::THUMB_SIDE, $dir . '/thumb.jpg', 72);
            }
        } catch (Throwable) {
            // без мініатюри
        }
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    public function thumbPath(int $id): string
    {
        return $this->root . '/previews/' . $id . '/thumb.jpg';
    }

    /** Прибрати недописаний файл (транзакція впала після запису). */
    public function discard(string $relative, int $id): void
    {
        $this->fs->remove([$this->path($relative), $this->root . '/previews/' . $id]);
    }

    /**
     * @return array{path: string, width: int, height: int}|null null — GD не розпізнав (HEIC тощо)
     */
    private function compress(string $source, string $mime): ?array
    {
        $image = self::load($source, $mime, self::PHOTO_SIDE);

        if ($image === null) {
            return null;
        }

        $target = (string) tempnam(sys_get_temp_dir(), 'zv-photo-');
        [$width, $height] = self::saveScaled($image, self::PHOTO_SIDE, $target, self::PHOTO_QUALITY);

        return ['path' => $target, 'width' => $width, 'height' => $height];
    }

    /**
     * Розпакувати, одразу зменшити до $max і лише потім повернути за EXIF (поворот
     * маленького полотна — без другої повнорозмірної копії в пам'яті).
     */
    private static function load(string $path, ?string $mime = null, int $max = self::PHOTO_SIDE): ?GdImage
    {
        $mime ??= (string) AttachmentStorage::detect($path);
        [$w, $h] = @getimagesize($path) ?: [0, 0];

        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            return null;
        }

        // 12 Мп фото з телефона — ~60 МБ у GD; ліміт FPM буває 128 МБ.
        self::ensureMemory((int) ($w * $h * 6 + 64 * 1024 * 1024));

        $full = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };

        if (! $full instanceof GdImage) {
            return null;
        }

        $image = self::scaled($full, $max);
        unset($full);

        return $mime === 'image/jpeg' ? self::orient($image, JpegOrientation::read($path)) : $image;
    }

    private static function scaled(GdImage $image, int $max): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));

        if ($scale >= 1) {
            return $image;
        }

        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($w, $h);
        // Прозорий PNG — на білому: у JPEG прозорості немає.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return $canvas;
    }

    private static function ensureMemory(int $bytes): void
    {
        $limit = (string) ini_get('memory_limit');

        if ($limit === '-1') {
            return;
        }

        $current = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        $need = memory_get_usage(true) + $bytes;

        if ($current < $need) {
            @ini_set('memory_limit', (string) min($need, 512 * 1024 * 1024));
        }
    }

    /**
     * Фото з телефона лежать «боком» з EXIF-орієнтацією — повертаємо, як бачить людина.
     * Спершу поворот, потім дзеркало: для 5 і 7 порядок важливий (транспонування).
     */
    public static function orient(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);

            if ($rotated instanceof GdImage) {
                $image = $rotated;
            }
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    /** @return array{0: int, 1: int} розмір результату */
    private static function saveScaled(GdImage $image, int $max, string $target, int $quality): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        // Прозорий PNG — на білому: у JPEG прозорості немає.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imageinterlace($canvas, true);
        imagejpeg($canvas, $target, $quality);

        return [$w, $h];
    }

    /** «IMG_2041.HEIC.png» → «IMG_2041.HEIC.jpg»: після стиснення це JPEG. */
    private static function jpegName(string $name): string
    {
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext !== '' ? mb_substr($name, 0, mb_strlen($name) - mb_strlen($ext) - 1) : $name;

        return ($base !== '' ? $base : 'фото') . '.jpg';
    }

    private static function officeMime(string $ext): string
    {
        return match ($ext) {
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'ppt' => 'application/vnd.ms-powerpoint',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'odp' => 'application/vnd.oasis.opendocument.presentation',
            'rtf' => 'application/rtf',
            default => 'application/octet-stream',
        };
    }
}
