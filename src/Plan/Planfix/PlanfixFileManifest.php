<?php

namespace App\Plan\Planfix;

use App\Tasks\Service\AttachmentStorage;
use JsonException;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * manifest.jsonl — файли, які забрали з Planfix через REST API (по рядку на файл:
 * file_id, name, task — номер задачі, comment — id дії або null, size, sha256, local_path).
 *
 * Тип — за вмістом (finfo), як і при звичайному завантаженні в задачу. Плюс те, що на
 * заводі в Planfix реально лежить, а в чат через браузер не приймаємо: відео й TIFF/DNG.
 * Ліміт 20 МБ на історичні файли не діє — переносимо як є. HTML, ярлики, програми — ні.
 */
final class PlanfixFileManifest
{
    public const string FILE = 'manifest.jsonl';

    /** Додатково до AttachmentStorage::allowed() — лише для перенесення з Planfix. */
    private const array IMPORT_ONLY_MIME = ['video/mp4', 'video/quicktime', 'image/tiff', 'image/x-adobe-dng'];

    /** @var array<int, list<array<string, mixed>>> id дії → файли */
    public array $byAction = [];

    /** @var array<int, list<array<string, mixed>>> номер задачі → файли самої задачі */
    public array $byTask = [];

    /** @var array<string, int> розширення → скільки не приймаємо */
    public array $rejected = [];

    public int $missing = 0;

    public static function read(string $dir): self
    {
        $path = rtrim($dir, '/') . '/' . self::FILE;

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('--files: у теці немає %s.', self::FILE));
        }

        $out = new self();
        $handle = fopen($path, 'rb') ?: throw new RuntimeException('--files: не вдалося відкрити ' . self::FILE);

        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $f = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new RuntimeException(sprintf('%s: рядок не JSON (%s).', self::FILE, $e->getMessage()), 0, $e);
            }

            $local = (string) ($f['local_path'] ?? '');

            if (($f['error'] ?? null) !== null || $local === '' || ! is_file($local) || (int) filesize($local) === 0) {
                ++$out->missing;
                continue;
            }

            $name = trim((string) ($f['name'] ?? '')) ?: 'file-' . (int) $f['file_id'];
            $detected = AttachmentStorage::detect($local);

            if (! AttachmentStorage::allowed($name, $detected) && ! in_array($detected, self::IMPORT_ONLY_MIME, true)) {
                $ext = AttachmentStorage::extension($name) ?: '(без розширення)';
                $out->rejected[$ext] = ($out->rejected[$ext] ?? 0) + 1;
                continue;
            }

            $mime = AttachmentStorage::storedMime($name, (string) $detected, MimeTypes::getDefault()->getMimeTypes(AttachmentStorage::extension($name))[0] ?? '');
            $row = [
                'planfix_id' => (int) $f['file_id'],
                'name' => mb_substr($name, 0, 255),
                'mime' => mb_substr($mime, 0, 127),
                'kind' => AttachmentStorage::kindOf($name, $mime),
                'size' => (int) filesize($local),
                'sha256' => (string) ($f['sha256'] ?? '') ?: (string) hash_file('sha256', $local),
                'local_path' => $local,
            ];

            if (($f['comment'] ?? null) !== null) {
                $out->byAction[(int) $f['comment']][] = $row;
            } else {
                $out->byTask[(int) $f['task']][] = $row;
            }
        }

        fclose($handle);

        return $out;
    }
}
