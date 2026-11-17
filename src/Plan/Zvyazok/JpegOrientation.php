<?php

namespace App\Plan\Zvyazok;

/**
 * EXIF-орієнтація JPEG (тег 0x0112) без розширення exif: на сервері його може не бути,
 * а після стиснення метадані зникають — фото, зняте «боком», так би й лишилося боком.
 * Читаємо лише перші 128 КБ (APP1 завжди на початку файла).
 */
final class JpegOrientation
{
    public static function read(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $value = (int) (@exif_read_data($path)['Orientation'] ?? 0);

            if ($value >= 1 && $value <= 8) {
                return $value;
            }
        }

        $data = @file_get_contents($path, false, null, 0, 131072);

        return is_string($data) ? self::parse($data) : 1;
    }

    public static function parse(string $data): int
    {
        if (! str_starts_with($data, "\xFF\xD8")) {
            return 1;
        }

        $offset = 2;
        $length = strlen($data);

        while ($offset + 4 <= $length && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            $size = unpack('n', substr($data, $offset + 2, 2))[1] ?? 0;

            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                return self::fromTiff(substr($data, $offset + 10, max(0, $size - 8)));
            }

            if ($marker === 0xDA || $size < 2) {
                break;
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $little = str_starts_with($tiff, 'II');
        $short = static fn (int $at): int => (int) (unpack($little ? 'v' : 'n', substr($tiff, $at, 2))[1] ?? 0);
        $long = static fn (int $at): int => (int) (unpack($little ? 'V' : 'N', substr($tiff, $at, 4))[1] ?? 0);

        $ifd = $long(4);

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $count = $short($ifd);

        for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= strlen($tiff); ++$i) {
            $entry = $ifd + 2 + $i * 12;

            if ($short($entry) === 0x0112) {
                $value = $short($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
