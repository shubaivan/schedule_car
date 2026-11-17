<?php

namespace App\Plan\Zvyazok;

use ZipArchive;

/**
 * Чи справді «офісний контейнер» — документ. finfo бачить docx/xlsx/odt як zip, а doc/xls/ppt —
 * як OLE; без цієї перевірки під розширенням .docx пройшов би будь-який архів, а під .doc —
 * що завгодно.
 */
final class OfficeContainer
{
    private const array OOXML = ['docx' => 'word/', 'xlsx' => 'xl/', 'pptx' => 'ppt/'];
    private const array ODF = ['odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet', 'odp' => 'application/vnd.oasis.opendocument.presentation'];
    private const array OLE = ['doc', 'xls', 'ppt'];

    public static function looksLikeDocument(string $path, string $ext): bool
    {
        if (in_array($ext, self::OLE, true)) {
            // Сигнатура OLE2 (Compound File Binary).
            return @file_get_contents($path, false, null, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        }

        if ($ext === 'rtf') {
            return str_starts_with((string) @file_get_contents($path, false, null, 0, 5), '{\rtf');
        }

        if (! isset(self::OOXML[$ext]) && ! isset(self::ODF[$ext])) {
            return false;
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        try {
            if (isset(self::ODF[$ext])) {
                return $zip->getFromName('mimetype') === self::ODF[$ext];
            }

            if ($zip->locateName('[Content_Types].xml') === false) {
                return false;
            }

            for ($i = 0; $i < $zip->numFiles; ++$i) {
                if (str_starts_with((string) $zip->getNameIndex($i), self::OOXML[$ext])) {
                    return true;
                }
            }

            return false;
        } finally {
            $zip->close();
        }
    }
}
