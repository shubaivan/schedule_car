<?php

namespace App\Warehouse\Service;

use App\Warehouse\Entity\WhClient;
use App\Warehouse\Entity\WhItem;
use App\Warehouse\Entity\WhMovement;
use App\Warehouse\Entity\WhSite;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Посилання в бот на картки складу — t.me/<бот>?start=<префікс><id> — і QR наклейки.
 *
 * Як DeepLink у Сіті Парку: одне місце, де посилання будуються, і одне, де
 * їх читають (WarehouseLinkAction), тож префікс не розійдеться з маршрутом.
 *
 *   w-<id>   QR-наклейка на майні — рахується як СКАН
 *   wi-<id>  посилання на те саме майно, надіслане людиною — рахується як ПЕРЕХІД
 *   wc-<id>  клієнт
 *   ws-<id>  об'єкт чи склад
 *   wm-<id>  рух (накладна)
 *
 * Наклейка й посилання на одне майно навмисно різні: «його відсканували на
 * об'єкті» і «хтось відкрив посилання в чаті» — різні факти, і журнал має їх
 * розрізняти.
 *
 * Номер у посиланні не секрет: бот пускає лише підтверджених працівників
 * (RequireApproval), а сторонньому покаже тільки пропозицію зареєструватись.
 */
class WarehouseLinks
{
    public const KIND_SCAN = 'scan';
    public const KIND_ITEM = 'item';
    public const KIND_CLIENT = 'client';
    public const KIND_SITE = 'site';
    public const KIND_MOVEMENT = 'movement';

    /** Довші префікси першими: «wi-» не має впізнатись як «w-». */
    public const PREFIXES = [
        self::KIND_ITEM => 'wi-',
        self::KIND_CLIENT => 'wc-',
        self::KIND_SITE => 'ws-',
        self::KIND_MOVEMENT => 'wm-',
        self::KIND_SCAN => 'w-',
    ];

    public function __construct(
        #[Autowire('%env(TELEGRAM_BOT_USERNAME)%')]
        private string $botUsername,
    ) {
    }

    /** Посилання, яким картку пересилають людині. */
    public function url(WhItem|WhClient|WhSite|WhMovement $subject): string
    {
        $kind = match (true) {
            $subject instanceof WhItem => self::KIND_ITEM,
            $subject instanceof WhClient => self::KIND_CLIENT,
            $subject instanceof WhSite => self::KIND_SITE,
            default => self::KIND_MOVEMENT,
        };

        return $this->build($kind, (int) $subject->getId());
    }

    /** Посилання, яке зашите в QR-наклейку. */
    public function scanUrl(WhItem $item): string
    {
        return $this->build(self::KIND_SCAN, (int) $item->getId());
    }

    /**
     * SVG наклейки — чітко друкується на будь-якому розмірі.
     *
     * Корекція помилок висока: наклейка живе на опалубці під бетоном і брудом,
     * і код має читатись навіть із затертою третиною площі.
     */
    public function qrSvg(WhItem $item): string
    {
        $qr = new QrCode(
            data: $this->scanUrl($item),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 300,
            margin: 0,
        );

        return (new SvgWriter())->write($qr, options: [
            SvgWriter::WRITER_OPTION_EXCLUDE_XML_DECLARATION => true,
            // Розмір задає сторінка друку, а не сам код.
            SvgWriter::WRITER_OPTION_EXCLUDE_SVG_WIDTH_AND_HEIGHT => true,
        ])->getString();
    }

    /**
     * Розібрати payload команди /start. null — це не посилання складу.
     *
     * @return array{kind: string, id: int}|null
     */
    public static function parse(string $payload): ?array
    {
        $payload = trim($payload);

        foreach (self::PREFIXES as $kind => $prefix) {
            if (str_starts_with($payload, $prefix)) {
                $id = substr($payload, strlen($prefix));

                return ctype_digit($id) && strlen($id) <= 10 && (int) $id > 0
                    ? ['kind' => $kind, 'id' => (int) $id]
                    : null;
            }
        }

        return null;
    }

    private function build(string $kind, int $id): string
    {
        return sprintf('https://t.me/%s?start=%s%d', ltrim($this->botUsername, '@'), self::PREFIXES[$kind], $id);
    }
}
