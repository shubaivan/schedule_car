<?php

namespace App\Tasks\Demo;

use GdImage;
use RuntimeException;

/**
 * Файли для демо-стенду, намальовані на місці: «фото» з цеху (JPEG) і
 * документи (PDF: рахунок, специфікація, креслення, протокол).
 *
 * Сторінка PDF — це JPEG, загорнутий у мінімальний PDF (DCTDecode), тому
 * кирилиця не потребує вбудованих шрифтів, а pdftoppm дає звичайне превʼю.
 * Текст пишемо TTF-шрифтом системи (DejaVu / Liberation); якщо його немає —
 * вбудованим шрифтом GD без кирилиці (демо не впаде, лише буде простіше).
 */
class DemoFiles
{
    private const FONT_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
    ];

    private const BOLD_CANDIDATES = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf',
    ];

    /** A4 при ~150 dpi. */
    private const PAGE_W = 1240;
    private const PAGE_H = 1754;

    private ?string $font;
    private ?string $bold;

    public function __construct(?string $font = null, ?string $bold = null)
    {
        $this->font = $font ?? self::firstExisting(self::FONT_CANDIDATES);
        $this->bold = $bold ?? self::firstExisting(self::BOLD_CANDIDATES) ?? $this->font;
    }

    /**
     * Документ: заголовок, рядки тексту, необовʼязкова таблиця й креслення.
     * Кожен елемент $pages — окрема сторінка.
     *
     * @param list<array{title: string, lines?: list<string>, table?: list<list<string>>, drawing?: string}> $pages
     */
    public function pdf(string $path, array $pages): void
    {
        $jpegs = [];

        foreach ($pages as $i => $page) {
            $jpegs[] = $this->renderPage($page, $i + 1, count($pages));
        }

        file_put_contents($path, self::wrapJpegsInPdf($jpegs, self::PAGE_W, self::PAGE_H));
    }

    /**
     * «Фото» з виробництва: сцена з виробами й підпис.
     *
     * @param 'rings'|'slabs'|'truck'|'blocks'|'rebar'|'site' $scene
     */
    public function photo(string $path, string $scene, string $caption, int $seed = 1): void
    {
        $w = 1400;
        $h = 1050;
        $img = $this->canvas($w, $h);
        mt_srand($seed);

        // Небо/цех: вертикальний градієнт, підлога — темніша смуга.
        $top = $scene === 'site' || $scene === 'truck' ? [168, 196, 222] : [92, 98, 108];
        $bottom = $scene === 'site' || $scene === 'truck' ? [222, 228, 232] : [150, 152, 150];
        for ($y = 0; $y < $h * 0.62; ++$y) {
            $k = $y / ($h * 0.62);
            imageline($img, 0, $y, $w, $y, $this->rgb($img, self::mix($top, $bottom, $k)));
        }
        for ($y = (int) ($h * 0.62); $y < $h; ++$y) {
            $k = ($y - $h * 0.62) / ($h * 0.38);
            imageline($img, 0, $y, $w, $y, $this->rgb($img, self::mix([128, 122, 112], [92, 86, 78], $k)));
        }

        $concrete = [176, 176, 170];
        $shade = [132, 132, 126];

        switch ($scene) {
            case 'rings':
                foreach ([[260, 650], [560, 640], [860, 660], [1140, 650]] as [$cx, $cy]) {
                    // Кільця стоять одне на одному: низ, стінка, верх з отвором.
                    for ($level = 0; $level < 3; ++$level) {
                        $y = $cy - $level * 104;
                        imagefilledellipse($img, $cx, $y, 260, 70, $this->rgb($img, $shade));
                        imagefilledrectangle($img, $cx - 130, $y - 100, $cx + 130, $y, $this->rgb($img, $level % 2 ? $concrete : [168, 168, 162]));
                        imageline($img, $cx - 130, $y - 2, $cx + 130, $y - 2, $this->rgb($img, [120, 120, 116]));
                        imagefilledellipse($img, $cx, $y - 100, 260, 70, $this->rgb($img, [200, 200, 194]));
                        imagefilledellipse($img, $cx, $y - 100, 196, 48, $this->rgb($img, [64, 64, 62]));
                    }
                }
                break;
            case 'slabs':
                for ($i = 0; $i < 9; ++$i) {
                    $y = 820 - $i * 52;
                    imagefilledrectangle($img, 120 + $i * 6, $y - 44, 1280 - $i * 6, $y, $this->rgb($img, $i % 2 ? $concrete : [186, 184, 178]));
                    for ($hole = 0; $hole < 7; ++$hole) {
                        imagefilledellipse($img, 160 + $i * 6 + $hole * 26, $y - 22, 18, 18, $this->rgb($img, [96, 96, 92]));
                    }
                    imagefilledrectangle($img, 120, $y, 1280, $y + 8, $this->rgb($img, [120, 92, 60]));
                }
                break;
            case 'blocks':
                for ($row = 0; $row < 4; ++$row) {
                    for ($col = 0; $col < 5; ++$col) {
                        $x = 140 + $col * 230 + ($row % 2) * 40;
                        $y = 860 - $row * 120;
                        imagefilledrectangle($img, $x, $y - 110, $x + 220, $y, $this->rgb($img, $col % 2 ? $concrete : [190, 188, 182]));
                        imagerectangle($img, $x, $y - 110, $x + 220, $y, $this->rgb($img, $shade));
                    }
                }
                break;
            case 'rebar':
                for ($i = 0; $i < 46; ++$i) {
                    $y = 520 + $i * 9 + mt_rand(-2, 2);
                    imagesetthickness($img, 5);
                    imageline($img, 80, $y, 1320, $y + mt_rand(-6, 6), $this->rgb($img, [96 + mt_rand(0, 30), 70, 52]));
                }
                imagesetthickness($img, 1);
                break;
            case 'truck':
                imagefilledrectangle($img, 260, 520, 880, 760, $this->rgb($img, [214, 92, 40]));
                imagefilledrectangle($img, 880, 600, 1100, 760, $this->rgb($img, [40, 92, 160]));
                imagefilledrectangle($img, 930, 620, 1060, 690, $this->rgb($img, [190, 214, 236]));
                imagefilledrectangle($img, 300, 460, 840, 520, $this->rgb($img, $concrete));
                foreach ([360, 500, 960] as $x) {
                    imagefilledellipse($img, $x, 780, 120, 120, $this->rgb($img, [32, 32, 32]));
                    imagefilledellipse($img, $x, 780, 50, 50, $this->rgb($img, [150, 150, 150]));
                }
                break;
            default: // site
                imagefilledrectangle($img, 200, 260, 760, 820, $this->rgb($img, [160, 158, 152]));
                for ($floor = 0; $floor < 7; ++$floor) {
                    imagefilledrectangle($img, 200, 260 + $floor * 80, 760, 270 + $floor * 80, $this->rgb($img, [120, 118, 112]));
                    for ($win = 0; $win < 5; ++$win) {
                        imagefilledrectangle($img, 230 + $win * 105, 290 + $floor * 80, 290 + $win * 105, 320 + $floor * 80, $this->rgb($img, [70, 90, 110]));
                    }
                }
                imagesetthickness($img, 8);
                imageline($img, 1000, 820, 1000, 140, $this->rgb($img, [230, 180, 30]));
                imageline($img, 760, 160, 1300, 160, $this->rgb($img, [230, 180, 30]));
                imagesetthickness($img, 1);
        }

        // Трохи «шуму камери».
        for ($i = 0; $i < 9000; ++$i) {
            $x = mt_rand(0, $w - 1);
            $y = mt_rand(0, $h - 1);
            $c = mt_rand(0, 1) ? 255 : 0;
            imagesetpixel($img, $x, $y, imagecolorallocatealpha($img, $c, $c, $c, 110));
        }

        // Підпис, як на фото з телефона майстра.
        imagefilledrectangle($img, 0, $h - 90, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 60));
        $this->text($img, 28, 40, $h - 34, $caption, [255, 255, 255], true);

        imagejpeg($img, $path, 82);
    }

    public function hasCyrillicFont(): bool
    {
        return $this->font !== null;
    }

    /**
     * Мінімальний коректний PDF: кожна сторінка — одне JPEG-зображення на весь аркуш.
     *
     * @param list<string> $jpegs вміст JPEG-файлів
     */
    public static function wrapJpegsInPdf(array $jpegs, int $width, int $height): string
    {
        if ($jpegs === []) {
            throw new RuntimeException('PDF без сторінок');
        }

        $objects = [];
        $kids = [];
        $n = count($jpegs);

        // 1 — Catalog, 2 — Pages, далі по три обʼєкти на сторінку: Page, Image, Contents.
        foreach ($jpegs as $i => $jpeg) {
            $page = 3 + $i * 3;
            $kids[] = $page . ' 0 R';
            $content = "q 595 0 0 842 0 0 cm /Im0 Do Q\n";
            $objects[$page] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 %d 0 R >> >> /Contents %d 0 R >>', $page + 1, $page + 2);
            $objects[$page + 1] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n%s\nendstream", $width, $height, strlen($jpeg), $jpeg);
            $objects[$page + 2] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($content), $content);
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), $n);
        ksort($objects);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($out);
        $out .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $out .= sprintf("%010d 00000 n \n", $offset);
        }

        return $out . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    /** @param array{title: string, lines?: list<string>, table?: list<list<string>>, drawing?: string} $page */
    private function renderPage(array $page, int $number, int $total): string
    {
        $img = $this->canvas(self::PAGE_W, self::PAGE_H);
        $ink = [24, 24, 28];
        $muted = [96, 100, 110];
        $x = 110;
        $y = 170;

        $this->text($img, 30, $x, $y, $page['title'], $ink, true);
        imagesetthickness($img, 3);
        imageline($img, $x, $y + 24, self::PAGE_W - $x, $y + 24, $this->rgb($img, $ink));
        imagesetthickness($img, 1);
        $y += 90;

        foreach ($page['lines'] ?? [] as $line) {
            $this->text($img, 19, $x, $y, $line, $ink);
            $y += 40;
        }

        if (($page['table'] ?? []) !== []) {
            $y += 20;
            $y = $this->table($img, $page['table'], $x, $y);
        }

        if (($page['drawing'] ?? null) !== null) {
            $this->drawing($img, $page['drawing'], $x, $y + 30, self::PAGE_W - 2 * $x, self::PAGE_H - $y - 260);
        }

        $this->text($img, 14, $x, self::PAGE_H - 80, 'Демо-документ для стенду «План». Реквізити вигадані.', $muted);
        $this->text($img, 14, self::PAGE_W - $x - 150, self::PAGE_H - 80, sprintf('Стор. %d з %d', $number, $total), $muted);

        ob_start();
        imagejpeg($img, null, 80);

        return (string) ob_get_clean();
    }

    /** @param list<list<string>> $rows */
    private function table(GdImage $img, array $rows, int $x, int $y): int
    {
        $width = self::PAGE_W - 2 * $x;
        $cols = max(array_map('count', $rows));
        // Перша колонка (назва) ширша за решту.
        $first = (int) ($width * 0.46);
        $rest = $cols > 1 ? (int) (($width - $first) / ($cols - 1)) : 0;
        $line = $this->rgb($img, [150, 154, 160]);

        foreach ($rows as $r => $row) {
            if ($r === 0) {
                imagefilledrectangle($img, $x, $y, $x + $width, $y + 52, $this->rgb($img, [234, 238, 244]));
            }
            $cx = $x;
            foreach ($row as $c => $cell) {
                $w = $c === 0 ? $first : $rest;
                imagerectangle($img, $cx, $y, $cx + $w, $y + 52, $line);
                $this->text($img, 16, $cx + 12, $y + 34, $cell, [24, 24, 28], $r === 0);
                $cx += $w;
            }
            $y += 52;
        }

        return $y;
    }

    /** Креслення: контур виробу з розмірами й штамп. */
    private function drawing(GdImage $img, string $mark, int $x, int $y, int $w, int $h): void
    {
        $ink = $this->rgb($img, [30, 60, 120]);
        imagesetthickness($img, 3);
        imagerectangle($img, $x, $y, $x + $w, $y + $h, $ink);
        imagesetthickness($img, 4);
        $bx = $x + 120;
        $by = $y + 120;
        $bw = $w - 240;
        $bh = (int) min(260, $h * 0.35);
        imagerectangle($img, $bx, $by, $bx + $bw, $by + $bh, $ink);
        imagesetthickness($img, 2);
        for ($i = 1; $i < 8; ++$i) {
            imageellipse($img, $bx + (int) ($bw * $i / 8), $by + (int) ($bh / 2), 60, 60, $ink);
        }
        imageline($img, $bx, $by + $bh + 60, $bx + $bw, $by + $bh + 60, $ink);
        imageline($img, $bx, $by + $bh + 40, $bx, $by + $bh + 80, $ink);
        imageline($img, $bx + $bw, $by + $bh + 40, $bx + $bw, $by + $bh + 80, $ink);
        $this->text($img, 18, $bx + (int) ($bw / 2) - 40, $by + $bh + 50, '5980', [30, 60, 120]);
        imageline($img, $bx - 50, $by, $bx - 50, $by + $bh, $ink);
        $this->text($img, 18, $bx - 110, $by + (int) ($bh / 2), '1190', [30, 60, 120]);

        // Штамп у правому нижньому куті.
        $sx = $x + $w - 520;
        $sy = $y + $h - 170;
        imagesetthickness($img, 2);
        imagerectangle($img, $sx, $sy, $x + $w, $y + $h, $ink);
        imageline($img, $sx, $sy + 60, $x + $w, $sy + 60, $ink);
        $this->text($img, 22, $sx + 20, $sy + 42, $mark, [30, 60, 120], true);
        $this->text($img, 15, $sx + 20, $sy + 100, 'Розробив: Марченко І.', [30, 60, 120]);
        $this->text($img, 15, $sx + 20, $sy + 135, 'Перевірив: Поліщук В.', [30, 60, 120]);
        imagesetthickness($img, 1);
    }

    private function canvas(int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor($w, $h);

        if ($img === false) {
            throw new RuntimeException('GD не створив зображення');
        }

        imagefill($img, 0, 0, $this->rgb($img, [255, 255, 255]));

        return $img;
    }

    /** @param array{int, int, int} $color */
    private function text(GdImage $img, int $size, int $x, int $y, string $text, array $color, bool $bold = false): void
    {
        $font = $bold ? $this->bold : $this->font;

        if ($font !== null) {
            imagettftext($img, $size, 0, $x, $y, $this->rgb($img, $color), $font, $text);

            return;
        }

        imagestring($img, 5, $x, $y - 14, self::ascii($text), $this->rgb($img, $color));
    }

    /** @param array{int, int, int} $c */
    private function rgb(GdImage $img, array $c): int
    {
        return (int) imagecolorallocate($img, max(0, min(255, $c[0])), max(0, min(255, $c[1])), max(0, min(255, $c[2])));
    }

    /**
     * @param array{int, int, int} $a
     * @param array{int, int, int} $b
     *
     * @return array{int, int, int}
     */
    private static function mix(array $a, array $b, float $k): array
    {
        return [(int) ($a[0] + ($b[0] - $a[0]) * $k), (int) ($a[1] + ($b[1] - $a[1]) * $k), (int) ($a[2] + ($b[2] - $a[2]) * $k)];
    }

    private static function ascii(string $text): string
    {
        $out = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);

        return is_string($out) ? $out : (string) preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    /** @param list<string> $paths */
    private static function firstExisting(array $paths): ?string
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
