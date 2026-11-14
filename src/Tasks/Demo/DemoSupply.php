<?php

namespace App\Tasks\Demo;

use App\Entity\TelegramUser;
use App\Supply\Entity\Department;
use App\Supply\Entity\Supplier;
use App\Supply\Entity\SupplyComment;
use App\Supply\Entity\SupplyPurchase;
use App\Supply\Entity\SupplyRequest;
use App\Supply\Entity\SupplyStatusLog;
use App\Supply\Enum\AttachmentType;
use App\Supply\Enum\PaymentType;
use App\Supply\Enum\SupplyAccent;
use App\Supply\Enum\SupplyRole;
use App\Supply\Enum\SupplyStatus;
use App\Supply\Enum\Unit;
use App\Supply\Repository\SupplierRepository;
use App\Supply\Repository\SupplyRequestRepository;
use App\Supply\Service\AttachFile;
use DateTime;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Демо-заявки на постачання для «Плану»: заявки живуть в одній оболонці з задачами,
 * тож на демо-стенді має бути що відкрити — різні статуси, рішення директора,
 * закупівлі, коментарі й документи. Імена й постачальники вигадані.
 *
 * Пишемо сутностями напряму (без CreateRequest/ChangeStatus): демо не повинно
 * слати сповіщень. Час подій зсуваємо назад SQL-ом, щоб стрічка виглядала живою.
 */
final class DemoSupply
{
    public const SUPPLIERS = [
        ['ТОВ «Будмаркет-Схід» (демо)', '30000001', 'Олена'],
        ['ФОП Мельник В. П. (демо)', null, 'Василь'],
        ['ТОВ «Металопрокат Плюс» (демо)', '30000002', 'Андрій'],
        ['ТОВ «Деревпром» (демо)', '30000003', null],
    ];

    /**
     * [автор, відділ, що, кількість, одиниця, обʼєкт, термін (днів від сьогодні), терміново, примітка, днів тому, мітка, кроки].
     * Крок: [дія, хто, …, годин після подачі].
     */
    public const REQUESTS = [
        ['vyrobnytstvo', '7. Виробництво', 'Цемент М500 у мішках', '40', Unit::Pack, 'Цех ЗБВ', 2, true, 'Мішки по 25 кг, бажано з однієї партії.', 1, SupplyAccent::Red, [
            ['comment', 'olha.petrenko', 'Прийняла. Уточню наявність у двох постачальників і повернусь із цінами.', 1],
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 2],
            ['comment', 'vyrobnytstvo', 'Потрібно до четверга — у п’ятницю заливка плит.', 3],
        ]],
        ['mykola.rudenko', '7. Виробництво', 'Арматура Ø12 А500С', '1.2', Unit::Ton, 'Арматурний цех', 5, false, 'Прутки по 11,7 м.', 3, SupplyAccent::Yellow, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 2],
            ['purchase', 'olha.petrenko', 'ТОВ «Металопрокат Плюс» (демо)', '38400.00', 'МП-2211', 20],
            ['file', 'olha.petrenko', 'Рахунок_на_оплату_118.pdf', AttachmentType::Bill, 21],
            ['status', 'olha.petrenko', SupplyStatus::Approval, null, 22],
            ['comment', 'olha.petrenko', 'Рахунок у вкладенні, ціна з доставкою. Чекаю рішення.', 22],
        ]],
        ['ihor.marchenko', '2. Відділ побудови', 'Фанера ламінована 18 мм', '24', Unit::Piece, 'Дільниця №3', 7, false, null, 4, SupplyAccent::None, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 5],
            ['purchase', 'olha.petrenko', 'ТОВ «Деревпром» (демо)', '27600.00', null, 26],
            ['status', 'olha.petrenko', SupplyStatus::Approval, null, 27],
            ['status', 'dyrektor', SupplyStatus::Approved, 'Беремо, але наступного разу порівняйте з іншим постачальником.', 30],
            ['comment', 'olha.petrenko', 'Добре, оплачую сьогодні.', 31],
        ]],
        ['viktor.polishchuk', '6. Технічний відділ', 'Мастило для форм', '60', Unit::Liter, 'Цех ЗБВ', 10, false, 'Емульсійне, як минулого разу.', 6, SupplyAccent::Green, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 3],
            ['purchase', 'olha.petrenko', 'ФОП Мельник В. П. (демо)', '5400.00', 'М-77', 20],
            ['status', 'olha.petrenko', SupplyStatus::Approval, null, 21],
            ['status', 'dyrektor', SupplyStatus::Approved, null, 26],
            ['status', 'olha.petrenko', SupplyStatus::Paid, null, 30],
            ['status', 'olha.petrenko', SupplyStatus::Delivery, 'Привезуть завтра до обіду.', 48],
        ]],
        ['yurii.kuzmenko', '7. Виробництво', 'Електроди АНО-21, 3 мм', '15', Unit::Kg, 'Арматурний цех', 3, false, null, 9, SupplyAccent::None, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 1],
            ['purchase', 'olha.petrenko', 'ТОВ «Будмаркет-Схід» (демо)', '2250.00', 'БС-5120', 6],
            ['status', 'olha.petrenko', SupplyStatus::Approval, null, 6],
            ['status', 'dyrektor', SupplyStatus::Approved, null, 8],
            ['status', 'olha.petrenko', SupplyStatus::Paid, null, 9],
            ['file', 'olha.petrenko', 'Плити_відвантаження.jpg', AttachmentType::Invoice, 50],
            ['status', 'olha.petrenko', SupplyStatus::InStock, null, 50],
            ['comment', 'yurii.kuzmenko', 'Забрав зі складу, дякую.', 52],
        ]],
        ['bohdan.zakharchenko', '8. Відділ логістики', 'Стрічка для пакування вантажу', '10', Unit::Piece, 'Склад №2', 6, false, null, 2, SupplyAccent::None, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 4],
            ['purchase', 'olha.petrenko', 'ТОВ «Будмаркет-Схід» (демо)', '41800.00', null, 20],
            ['status', 'olha.petrenko', SupplyStatus::Approval, null, 21],
            ['status', 'dyrektor', SupplyStatus::Waiting, 'Дорого. Повернемось наступного місяця.', 24],
        ]],
        ['vyrobnytstvo', '7. Виробництво', 'Щебінь гранітний 5–20', '30', Unit::CubicMeter, 'Цех ЗБВ', 4, true, null, 0, SupplyAccent::None, []],
        ['ihor.marchenko', '2. Відділ побудови', 'Рукавиці робочі х/б', '100', Unit::Piece, 'Дільниця №3', 14, false, null, 12, SupplyAccent::None, [
            ['status', 'olha.petrenko', SupplyStatus::Rejected, 'Є на складі 140 пар — візьміть там.', 3],
            ['comment', 'ihor.marchenko', 'Зрозумів, заберу зі складу.', 5],
        ]],
        ['mykola.rudenko', '7. Виробництво', 'Пісок митий', '20', Unit::Ton, 'Цех ЗБВ', -1, false, null, 8, SupplyAccent::Yellow, [
            ['status', 'olha.petrenko', SupplyStatus::InProgress, null, 2],
            ['file', 'mykola.rudenko', 'Розчин_М100.jpg', AttachmentType::Photo, 3],
            ['comment', 'mykola.rudenko', 'Фото попередньої партії — було забагато глини.', 3],
        ]],
    ];

    /** @var list<array{string, int, int}> таблиця, id, годин тому */
    private array $backdate = [];

    public function __construct(
        private EntityManagerInterface $em,
        private SupplyRequestRepository $requests,
        private SupplierRepository $suppliers,
        private AttachFile $attachFile,
        private string $files,
    ) {
    }

    /** Таблиці демо-заявок прибираємо до людей: автор заявки — зовнішній ключ без каскаду. */
    public static function reset(EntityManagerInterface $em): void
    {
        $conn = $em->getConnection();
        $conn->executeStatement("DELETE FROM supply_request WHERE author_id IN (SELECT id FROM telegram_user WHERE telegram_id LIKE 'demo-%')");
        $names = array_map(static fn (array $s) => $s[0], self::SUPPLIERS);
        $conn->executeStatement('DELETE FROM supply_supplier WHERE name IN (?)', [$names], [\Doctrine\DBAL\ArrayParameterType::STRING]);
    }

    /**
     * @param array<string, TelegramUser> $people
     * @param array<string, Department> $departments
     */
    public function seed(array $people, array $departments): int
    {
        // Закупівлі веде менеджер постачання — у демо це менеджерка із закупівель.
        $people['olha.petrenko']->setSupplyRole(SupplyRole::Manager);

        $suppliers = [];

        foreach (self::SUPPLIERS as [$name, $edrpou, $contact]) {
            // Позначка «(демо)» у назві — щоб не сплутати з живим постачальником і не перезаписати його.
            $supplier = $this->suppliers->findOneBy(['name' => $name]) ?? (new Supplier())->setName($name)->setEdrpou($edrpou)->setContactPerson($contact);
            $this->em->persist($supplier);
            $suppliers[$name] = $supplier;
        }

        $this->em->flush();
        $year = (int) (new DateTime('now', new DateTimeZone('Europe/Kyiv')))->format('Y');

        foreach (self::REQUESTS as [$author, $dep, $item, $qty, $unit, $site, $due, $urgent, $note, $daysAgo, $accent, $steps]) {
            $request = (new SupplyRequest())
                ->setAuthor($people[$author])
                ->setDepartment($departments[$dep])
                ->setItem($item)
                ->setQuantity($qty)
                ->setUnit($unit)
                ->setSite($site)
                ->setNeedBy((new DateTime('today', new DateTimeZone('Europe/Kyiv')))->modify(sprintf('%+d days', $due)))
                ->setUrgent($urgent)
                ->setNote($note)
                ->setAccent($accent)
                ->setStatus(SupplyStatus::New)
                ->setNumber($this->requests->nextNumber($year));
            $created = $daysAgo * 24 + 9;
            $first = (new SupplyStatusLog())->setStatusFrom(null)->setStatusTo(SupplyStatus::New)->setAuthor($people[$author]);
            $request->addStatusLog($first);
            $this->em->persist($request);
            $this->em->persist($first);
            $this->em->flush();
            $this->backdate[] = ['supply_request', (int) $request->getId(), $created];
            $this->backdate[] = ['supply_status_log', (int) $first->getId(), $created];

            foreach ($steps as $step) {
                $this->step($request, $step, $people, $suppliers, $created);
            }
        }

        $this->em->flush();
        $conn = $this->em->getConnection();

        foreach ($this->backdate as [$table, $id, $hoursAgo]) {
            $conn->executeStatement(
                sprintf("UPDATE %s SET created_at = (NOW() AT TIME ZONE 'Europe/Kyiv') - make_interval(hours => ?) WHERE id = ?", $table),
                [max(0, $hoursAgo), $id],
            );
        }

        $conn->executeStatement("UPDATE supply_request SET closed_at = created_at + interval '2 days' WHERE status IN ('in_stock', 'ready') AND closed_at IS NULL");

        return count(self::REQUESTS);
    }

    /**
     * @param array<int, mixed> $step
     * @param array<string, TelegramUser> $people
     * @param array<string, Supplier> $suppliers
     */
    private function step(SupplyRequest $request, array $step, array $people, array $suppliers, int $created): void
    {
        $who = $people[$step[1]];
        $at = $created - (int) end($step);

        switch ($step[0]) {
            case 'status':
                $log = (new SupplyStatusLog())->setStatusFrom($request->getStatus())->setStatusTo($step[2])->setAuthor($who)->setComment($step[3]);
                $request->setStatus($step[2])->addStatusLog($log);
                $this->em->persist($log);
                $this->em->flush();
                $this->backdate[] = ['supply_status_log', (int) $log->getId(), $at];
                break;
            case 'comment':
                $comment = (new SupplyComment())->setAuthor($who)->setText($step[2]);
                $request->addComment($comment);
                $this->em->persist($comment);
                $this->em->flush();
                $this->backdate[] = ['supply_comment', (int) $comment->getId(), $at];
                break;
            case 'purchase':
                $purchase = (new SupplyPurchase())
                    ->setSupplier($suppliers[$step[2]])
                    ->setTotalAmount($step[3])
                    ->setInvoiceNumber($step[4])
                    ->setPayment(PaymentType::Bank)
                    ->setCreatedBy($who);
                $request->addPurchase($purchase);
                $this->em->persist($purchase);
                $this->em->flush();
                $this->backdate[] = ['supply_purchase', (int) $purchase->getId(), $at];
                break;
            case 'file':
                $path = $this->files . '/' . $step[2];
                $mime = str_ends_with($step[2], '.pdf') ? 'application/pdf' : 'image/jpeg';
                $attachment = ($this->attachFile)($request, $who, (string) file_get_contents($path), $step[2], $mime, $step[3], notify: false);
                $this->backdate[] = ['supply_attachment', (int) $attachment->getId(), $at];
                break;
        }
    }
}
