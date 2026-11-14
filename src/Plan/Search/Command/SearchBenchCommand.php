<?php

namespace App\Plan\Search\Command;

use App\Entity\TelegramUser;
use App\Plan\Search\PlanSearch;
use App\Plan\Search\SearchProjector;
use App\Plan\Search\SearchQuery;
use App\Repository\TelegramUserRepository;
use App\Tasks\Enum\WorkRole;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * Навантажувальна перевірка пошуку на синтетичних документах:
 *
 *     php bin/console plan:search:bench --docs=50000 --runs=5 [--explain] [--keep]
 *
 * Додає N документів (ref_id від SearchProjector::BENCH_REF_BASE — у звичайному
 * пошуку їх не видно, reindex їх не чіпає), робить ANALYZE, проганяє типові
 * запити (слово, два слова, 2 літери, «прецеденти людини» без слова, фільтри
 * типу/статусу/відділу/контрагента/періоду) від імені директора, менеджера й
 * працівника і друкує p50/p95/max повного пошуку (фасети + сторінка). Наприкінці
 * синтетику прибирає (крім --keep). НЕ для продакшн-бази.
 */
#[AsCommand(name: 'plan:search:bench', description: 'Бенчмарк пошуку на синтетичних документах (p50/p95)')]
final class SearchBenchCommand extends Command
{
    private const WORDS = [
        'плита', 'плити', 'перекриття', 'цемент', 'пц-500', 'пц-400', 'бетон', 'розчин', 'арматура', 'а500с', 'щебінь', 'пісок',
        'палі', 'ригель', 'колона', 'кільце', 'фбс', 'блок', 'сходовий', 'марш', 'тротуарна', 'плитка', 'бордюр', 'опалубка',
        'щит', 'стійка', 'вібратор', 'перфоратор', 'доставка', 'відвантаження', 'рахунок', 'оплата', 'знижка', 'рекламація', 'скол', 'креслення',
        'специфікація', 'погодження', 'договір', 'акт', 'звірки', 'тендер', 'школа', 'житловий', 'комплекс', 'секція', 'котедж', 'склад',
        'цех', 'бсу', 'партія', 'машина', 'кран', 'монтаж', 'замовник', 'підрядник', 'терміново', 'завтра', 'тиждень', 'ціна',
        'маржа', 'кошторис', 'проєкт', 'об\'єкт', 'фундамент', 'стрічковий', 'паркінг', 'майданчик', 'дорога', 'лоток', 'труба', 'колодязь',
        'кришка', 'днище', 'перемичка', 'балка', 'ферма', 'прогон', 'панель', 'стінова', 'зовнішня', 'внутрішня', 'утеплювач', 'пінопласт',
        'мінвата', 'гідроізоляція', 'бітум', 'мастика', 'праймер', 'фарба', 'грунтовка', 'шпаклівка', 'штукатурка', 'цегла', 'газоблок', 'клей',
        'сітка', 'дріт', 'в\'язальний', 'електроди', 'зварювання', 'зварник', 'болт', 'гайка', 'шайба', 'анкер', 'дюбель', 'саморіз',
        'лист', 'профнастил', 'металочерепиця', 'кутник', 'швелер', 'двотавр', 'труба', 'профільна', 'фанера', 'дошка', 'брус', 'ламінат',
        'лінолеум', 'плівка', 'геотекстиль', 'щебеневий', 'відсів', 'гравій', 'керамзит', 'перліт', 'добавка', 'пластифікатор', 'протиморозна', 'прискорювач',
        'водопонижувач', 'бетононасос', 'автобетонозмішувач', 'самоскид', 'тягач', 'напівпричіп', 'маніпулятор', 'навантажувач', 'екскаватор', 'трактор', 'генератор', 'компресор',
        'ліфт', 'підйомник', 'риштування', 'драбина', 'лабораторія', 'випробування', 'зразок', 'кубик', 'міцність', 'морозостійкість', 'водонепроникність', 'паспорт',
        'сертифікат', 'декларація', 'бухгалтерія', 'накладна', 'видаткова', 'прибуткова', 'податкова', 'пдв', 'аванс', 'передоплата', 'заборгованість', 'претензія',
        'штраф', 'пеня', 'менеджер', 'директор', 'економіст', 'технолог', 'інженер', 'прораб', 'майстер', 'комірник', 'водій', 'логіст',
        'юрист', 'кадровик', 'охорона', 'понеділок', 'вівторок', 'середа', 'четвер', 'пʼятниця', 'субота', 'неділя', 'січень', 'лютий',
        'березень', 'квітень', 'травень', 'червень', 'липень', 'серпень', 'вересень', 'жовтень', 'листопад', 'грудень', 'київ', 'львів',
        'одеса', 'дніпро', 'харків', 'вінниця', 'житомир', 'полтава', 'черкаси', 'суми', 'рівне', 'луцьк',
    ];

    private const SCENARIOS = [
        'часте слово «плит»' => ['q' => 'плит'],
        'код «пк 60»' => ['q' => 'пк 60'],
        'два слова «цемент пц»' => ['q' => 'цемент пц'],
        'рідше «рекламац»' => ['q' => 'рекламац'],
        '2 літери «зб»' => ['q' => 'зб'],
        'людина без слова' => ['person' => '@user'],
        'тип+статус без слова' => ['type' => 'supply', 'status' => 'supply:in_progress'],
        'слово+тип+відділ' => ['q' => 'бетон', 'type' => 'comment', 'department' => '@department'],
        'контрагент без слова' => ['client' => 'будінвест'],
        'усе (порожній)' => [],
        'слово+період' => ['q' => 'щебінь', 'from' => '@from', 'to' => '@to'],
        'слово, сорт. новіші, 3-тя сторінка' => ['q' => 'плит', 'sort' => 'recent', 'offset' => '40'],
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly PlanSearch $search,
        private readonly TelegramUserRepository $users,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment = 'dev',
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('docs', null, InputOption::VALUE_REQUIRED, 'Скільки синтетичних документів додати', '50000')
            ->addOption('runs', null, InputOption::VALUE_REQUIRED, 'Повторів кожного запиту на кожну людину', '5')
            ->addOption('explain', null, InputOption::VALUE_NONE, 'Надрукувати EXPLAIN ANALYZE для кількох запитів')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Не прибирати синтетику після прогону')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Запустити в APP_ENV=prod (лише на локальній копії!)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->environment === 'prod' && ! $input->getOption('force')) {
            $io->error('Це навантажувальний тест із тисячами синтетичних рядків — не для бойової бази. На локальній копії в prod-режимі додайте --force.');

            return Command::FAILURE;
        }

        $docs = max(100, min(500000, (int) $input->getOption('docs')));
        $runs = max(1, min(50, (int) $input->getOption('runs')));
        $people = array_values(array_filter($this->users->findAll(), static fn (TelegramUser $u) => $u->isApproved() && ! $u->isArchived()));

        if ($people === []) {
            $io->error('Немає жодної підтвердженої людини — нема від чийого імені шукати.');

            return Command::FAILURE;
        }

        $viewers = $this->viewers($people);
        $userIds = array_map(static fn (TelegramUser $u) => (int) $u->getId(), $people);
        $departments = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM supply_department')) ?: [1];

        $this->clear();
        $started = microtime(true);
        $this->seed($docs, $userIds, $departments);
        $this->db->executeStatement('ANALYZE search_document');
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM search_document');
        $io->writeln(sprintf('Синтетика: %d документів за %.1f с; усього в таблиці: %d.', $docs, microtime(true) - $started, $total));

        $values = [
            '@user' => (string) $userIds[intdiv(count($userIds), 2)],
            '@department' => (string) $departments[0],
            '@from' => (new DateTimeImmutable('-90 days'))->format('Y-m-d'),
            '@to' => (new DateTimeImmutable())->format('Y-m-d'),
        ];

        $all = [];
        $rows = [];

        try {
            foreach (self::SCENARIOS as $name => $params) {
                $params = array_map(static fn (string $v) => $values[$v] ?? $v, $params);
                $times = [];
                $found = [];

                foreach ($viewers as $role => $viewer) {
                    $query = SearchQuery::fromRequest(new Request($params));
                    $result = $this->search->search($viewer, $query, bench: true); // прогрів

                    for ($i = 0; $i < $runs; ++$i) {
                        $t = hrtime(true);
                        $result = $this->search->search($viewer, $query, bench: true);
                        $times[] = (hrtime(true) - $t) / 1e6;
                    }

                    $found[] = $role . ' ' . $result['total'] . ($result['approximate'] ? '+' : '');
                }

                array_push($all, ...$times);
                $rows[] = [$name, self::pct($times, 50), self::pct($times, 95), round(max($times), 1), implode(', ', $found)];
            }

            $io->table(['Запит', 'p50, мс', 'p95, мс', 'max, мс', 'Знайдено (директор, менеджер, працівник)'], $rows);
            $io->success(sprintf('Усі запити (%d вимірів): p50 %.1f мс, p95 %.1f мс, max %.1f мс.', count($all), self::pct($all, 50), self::pct($all, 95), max($all)));

            if ($input->getOption('explain')) {
                foreach (['часте слово «плит»', 'людина без слова', '2 літери «зб»'] as $name) {
                    $params = array_map(static fn (string $v) => $values[$v] ?? $v, self::SCENARIOS[$name]);
                    $plan = $this->search->explain($viewers['менеджер'], SearchQuery::fromRequest(new Request($params)), bench: true);
                    $io->section('EXPLAIN ANALYZE — ' . $name . ' (менеджер)');
                    $io->writeln('-- фасети + кількість');
                    $io->writeln($plan['facets']);
                    $io->writeln('-- сторінка результатів');
                    $io->writeln($plan['hits']);
                }
            }
        } finally {
            if (! $input->getOption('keep')) {
                $this->clear();
                $this->db->executeStatement('ANALYZE search_document');
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Директор (бачить усі задачі), менеджер і працівник (лише свої).
     *
     * @param list<TelegramUser> $people
     *
     * @return array<string, TelegramUser>
     */
    private function viewers(array $people): array
    {
        $pick = static function (array $roles) use ($people): TelegramUser {
            foreach ($people as $p) {
                if (in_array($p->getWorkRole(), $roles, true)) {
                    return $p;
                }
            }

            return $people[0];
        };

        return [
            'директор' => $pick([WorkRole::Director, WorkRole::Admin]),
            'менеджер' => $pick([WorkRole::Manager]),
            'працівник' => $pick([WorkRole::Production, WorkRole::Accountant]),
        ];
    }

    /**
     * Синтетичні документи одним INSERT … SELECT generate_series: типи як у житті
     * (коментарів найбільше), задачі видно 2–5 людям, заявки й склад — усім/складу.
     *
     * @param list<int> $users
     * @param list<int> $departments
     */
    private function seed(int $docs, array $users, array $departments): void
    {
        $words = 'ARRAY[' . implode(',', array_map(fn (string $w) => $this->db->quote($w), self::WORDS)) . ']';
        $userArr = 'ARRAY[' . implode(',', $users) . ']';
        $depArr = 'ARRAY[' . implode(',', $departments) . ']';
        $clients = "ARRAY['ТОВ «Будінвест»','ТОВ «Житлобуд»','ОСББ «Затишок»','ПрАТ «Зерно»','ФОП Савенко','ТОВ «Еко-Квартал»','ТОВ «Логістик-Парк»','КП «Міськбуд»','ТОВ «Агро-Південь»','ФОП Коваленко']";
        $pickWord = "({$words})[1 + floor(random() * " . count(self::WORDS) . ')::int]';
        $base = SearchProjector::BENCH_REF_BASE;

        $this->db->executeStatement(
            "INSERT INTO search_document (id, doc_type, subject_type, subject_id, ref_id, title, body, client, search_text,
                author_id, manager_id, executor_ids, department_ids, status, visible_all, visible_to, capability, created_at, updated_at)
             SELECT nextval('search_document_id_seq'), x.doc_type, x.subject_type, 1 + (g % 5000), {$base} + g,
                    x.title, x.body, x.client, lower(x.title || ' ' || x.client || ' ' || x.body),
                    x.author, x.author, x.executors, x.deps, x.status,
                    x.doc_type NOT IN ('task', 'comment', 'file'), CASE WHEN x.doc_type IN ('task', 'comment', 'file') THEN x.executors || to_jsonb(x.author) ELSE '[]'::jsonb END,
                    CASE WHEN x.doc_type LIKE 'wh_%' THEN 'warehouse.view' END,
                    x.at, x.at + (random() * interval '20 days')
               FROM generate_series(1, :n) g,
               LATERAL (
                    SELECT CASE WHEN r < 0.15 THEN 'task' WHEN r < 0.55 THEN 'comment' WHEN r < 0.65 THEN 'file' WHEN r < 0.75 THEN 'supply'
                                WHEN r < 0.85 THEN 'supply_comment' WHEN r < 0.93 THEN 'wh_item' ELSE 'wh_movement' END AS doc_type,
                           CASE WHEN r < 0.65 THEN 'task' WHEN r < 0.85 THEN 'supply' WHEN r < 0.93 THEN 'wh_item' ELSE 'wh_movement' END AS subject_type,
                           r2
                      FROM (SELECT random() AS r, random() AS r2, g AS seed) s
               ) k,
               LATERAL (
                    SELECT k.doc_type, k.subject_type,
                           initcap(array_to_string(ARRAY(SELECT {$pickWord} FROM generate_series(1, 3 + (g % 3)) w WHERE w > 0 AND g > 0), ' ')) AS title,
                           array_to_string(ARRAY(SELECT {$pickWord} FROM generate_series(1, 12 + (g % 30)) w WHERE w > 0 AND g > 0), ' ')
                               || ' пк ' || (40 + g % 30) || '-' || (10 + g % 8) || ' №' || (g % 977) AS body,
                           ({$clients})[1 + (g % 10)] AS client,
                           ({$userArr})[1 + floor(random() * cardinality({$userArr}))::int] AS author,
                           (SELECT COALESCE(jsonb_agg(DISTINCT u), '[]') FROM (SELECT ({$userArr})[1 + floor(random() * cardinality({$userArr}))::int] AS u FROM generate_series(1, 1 + (g % 4)) z WHERE z > 0 AND g > 0) e) AS executors,
                           jsonb_build_array(({$depArr})[1 + (g % cardinality({$depArr}))]) AS deps,
                           CASE k.subject_type
                               WHEN 'task' THEN 'task:' || (ARRAY['new','in_work','approval','approved','waiting','done','rejected'])[1 + (g % 7)]
                               WHEN 'supply' THEN 'supply:' || (ARRAY['new','in_progress','approval','approved','paid','delivery','in_stock','rejected'])[1 + (g % 8)]
                               WHEN 'wh_item' THEN 'wh_item:' || (ARRAY['active','repair','written_off'])[1 + (g % 3)]
                               ELSE 'wh_movement:' || (ARRAY['receipt','shipment','return','transfer'])[1 + (g % 4)] END AS status,
                           LOCALTIMESTAMP(0) - (k.r2 * interval '730 days') AS at
               ) x",
            ['n' => $docs],
        );
    }

    private function clear(): void
    {
        $this->db->executeStatement('DELETE FROM search_document WHERE ref_id >= ?', [SearchProjector::BENCH_REF_BASE]);
    }

    /** @param list<float> $values */
    private static function pct(array $values, int $p): float
    {
        sort($values);
        $i = (int) ceil($p / 100 * count($values)) - 1;

        return round($values[max(0, $i)], 1);
    }
}
