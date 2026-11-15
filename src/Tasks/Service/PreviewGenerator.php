<?php

namespace App\Tasks\Service;

use App\Tasks\Entity\TaskAttachment;
use GdImage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Превʼю вкладень — головна фішка чату задач.
 *
 * - Картинка: мініатюра 640px і копія до 1920px для лайтбокса (GD, з EXIF-поворотом).
 * - PDF: кількість сторінок (pdfinfo) і перша сторінка мініатюрою (pdftoppm);
 *   інші сторінки малюються на вимогу, коли людина гортає перегляд.
 * - Офісні (docx/xlsx/…): LibreOffice headless → PDF → далі як PDF. Це важко,
 *   тож лише по одному за раз (flock) і з таймаутом: сервер важливіший за превʼю.
 */
class PreviewGenerator
{
    private const int THUMB = 640;
    private const int VIEW = 1920;
    private const int PAGE = 1600;

    public function __construct(
        private AttachmentStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    /** Чи важка генерація (офіс) — таку відкладаємо на після відповіді. */
    public static function isHeavy(TaskAttachment $attachment): bool
    {
        return $attachment->getKind() === TaskAttachment::KIND_OFFICE;
    }

    /**
     * @return bool false — не взялися (зайнятий конвертер); стан лишається pending
     */
    public function generate(TaskAttachment $attachment): bool
    {
        try {
            return match ($attachment->getKind()) {
                TaskAttachment::KIND_IMAGE => $this->image($attachment),
                TaskAttachment::KIND_PDF => $this->pdf($attachment, $this->storage->original($attachment)),
                TaskAttachment::KIND_OFFICE => $this->office($attachment),
                default => true,
            };
        } catch (Throwable $e) {
            $this->logger->warning('Task preview failed', [
                'attachment' => $attachment->getId(),
                'kind' => $attachment->getKind(),
                'error' => $e->getMessage(),
            ]);
            $attachment->previewFailed();

            return true;
        }
    }

    /** Сторінка PDF у JPEG; малюємо один раз і кешуємо. */
    public function page(TaskAttachment $attachment, int $page): ?string
    {
        if (! $attachment->isPaged() || $page < 1 || $page > (int) $attachment->getPages()) {
            return null;
        }

        $target = $this->storage->page($attachment, $page);

        if (! is_file($target)) {
            $this->renderPdfPage($this->storage->pdf($attachment), $page, self::PAGE, $target);
        }

        return is_file($target) ? $target : null;
    }

    public static function available(): array
    {
        $finder = new ExecutableFinder();

        return [
            'pdftoppm' => $finder->find('pdftoppm') !== null,
            'libreoffice' => $finder->find('soffice') !== null || $finder->find('libreoffice') !== null,
        ];
    }

    private function image(TaskAttachment $attachment): bool
    {
        $source = $this->storage->original($attachment);
        $image = $this->load($source, $attachment->getMime());

        if ($image === null) {
            $attachment->previewFailed();

            return true;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $this->saveScaled($image, self::THUMB, $this->storage->thumb($attachment), 80);
        $this->saveScaled($image, self::VIEW, $this->storage->view($attachment), 85);
        unset($image);

        $attachment->previewReady(null, $width, $height);

        return true;
    }

    private function pdf(TaskAttachment $attachment, string $pdf): bool
    {
        $info = new Process(['pdfinfo', $pdf]);
        $info->setTimeout(20)->run();
        $pages = preg_match('/^Pages:\s+(\d+)/m', $info->getOutput(), $m) ? (int) $m[1] : 0;

        if (! $info->isSuccessful() || $pages < 1) {
            $attachment->previewFailed();

            return true;
        }

        $thumb = $this->storage->thumb($attachment);
        $this->renderPdfPage($pdf, 1, self::THUMB, $thumb);

        if (! is_file($thumb)) {
            $attachment->previewFailed();

            return true;
        }

        [$width, $height] = getimagesize($thumb) ?: [null, null];
        $attachment->previewReady($pages, $width, $height);

        return true;
    }

    private function office(TaskAttachment $attachment): bool
    {
        $dir = $this->storage->previewDir($attachment);
        $lock = fopen($this->storage->root() . '/.office.lock', 'c');

        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            return false;
        }

        try {
            $finder = new ExecutableFinder();
            $binary = $finder->find('soffice') ?? $finder->find('libreoffice');

            if ($binary === null) {
                $attachment->previewFailed();

                return true;
            }

            // Свій профіль LibreOffice у var/: у www-data немає домашньої теки,
            // а спільний профіль двох процесів LibreOffice ламає.
            $profile = $this->storage->root() . '/.lo-profile';
            $work = $dir . '/convert';
            @mkdir($work, 0775, true);

            $process = new Process([
                'nice', '-n', '10', $binary,
                '-env:UserInstallation=file://' . $profile,
                '--headless', '--norestore', '--nologo', '--nofirststartwizard',
                '--convert-to', 'pdf', '--outdir', $work,
                $this->storage->original($attachment),
            ], null, ['HOME' => $profile]);
            $process->setTimeout(90)->run();

            $produced = glob($work . '/*.pdf') ?: [];

            if ($produced === []) {
                $this->logger->warning('LibreOffice produced nothing', [
                    'attachment' => $attachment->getId(),
                    'error' => mb_substr($process->getErrorOutput(), 0, 500),
                ]);
                $attachment->previewFailed();

                return true;
            }

            rename($produced[0], $this->storage->pdf($attachment));
            @rmdir($work);

            return $this->pdf($attachment, $this->storage->pdf($attachment));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function renderPdfPage(string $pdf, int $page, int $size, string $target): void
    {
        $base = substr($target, 0, -4);
        $process = new Process([
            'pdftoppm', '-f', (string) $page, '-l', (string) $page,
            '-scale-to', (string) $size, '-jpeg', '-jpegopt', 'quality=82',
            '-singlefile', $pdf, $base,
        ]);
        $process->setTimeout(30)->run();
    }

    private function load(string $path, string $mime): ?GdImage
    {
        $image = match (true) {
            str_contains($mime, 'jpeg') || str_contains($mime, 'jpg') => @imagecreatefromjpeg($path),
            str_contains($mime, 'png') => @imagecreatefrompng($path),
            str_contains($mime, 'gif') => @imagecreatefromgif($path),
            str_contains($mime, 'webp') => @imagecreatefromwebp($path),
            default => @imagecreatefromstring((string) file_get_contents($path)),
        };

        if (! $image instanceof GdImage) {
            return null;
        }

        // Фото з телефона лежать «боком» з EXIF-орієнтацією — повертаємо, як бачить людина.
        if (str_contains($mime, 'jpeg') && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);
            $angle = match ($orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };

            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);

                if ($rotated instanceof GdImage) {
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    private function saveScaled(GdImage $image, int $max, string $target, int $quality): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($w, $h);
        // Прозорий PNG на білому — JPEG прозорості не має.
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        imageinterlace($canvas, true);
        imagejpeg($canvas, $target, $quality);
    }
}
