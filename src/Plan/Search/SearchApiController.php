<?php

namespace App\Plan\Search;

use App\Entity\TelegramUser;
use App\Plan\Search\Entity\SavedSearch;
use App\Plan\Subject\SubjectRef;
use App\Plan\Subject\SubjectRegistry;
use App\Plan\Subject\SubjectType;
use App\Repository\TelegramUserRepository;
use App\Tasks\Controller\TasksController;
use App\Tasks\Search\Snippet;
use App\Tasks\Service\TaskPresenter;
use DateTime;
use DateTimeInterface;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Розумний пошук «Плану»: `GET /api/plan/search` (повний текст + фільтри + фасети),
 * `GET /api/plan/search/meta` (люди, відділи, типи, статуси для фільтрів),
 * збережені пошуки `GET|POST /api/plan/search/saved`, `DELETE /api/plan/search/saved/{id}`.
 */
#[Route('/api/plan/search')]
final class SearchApiController extends TasksController
{
    public function __construct(
        private readonly PlanSearch $search,
        private readonly TelegramUserRepository $users,
        private readonly TaskPresenter $presenter,
        private readonly SubjectRegistry $subjects,
        private readonly Connection $db,
    ) {
    }

    #[Route('', name: 'api_plan_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $me = $this->me();
        $query = SearchQuery::fromRequest($request);
        $terms = $query->terms();
        $started = hrtime(true);
        $result = $this->search->search($me, $query);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $facets = $result['facets'];
        $peopleIds = [...array_column($result['hits'], 'author_id'), ...array_keys($facets['author']), ...array_keys($facets['executor'])];

        foreach ([$query->person, $query->author, $query->executor, $query->manager] as $id) {
            if ($id !== null) {
                $peopleIds[] = $id;
            }
        }

        $people = $this->people($peopleIds);
        $departments = $this->departments();
        $thumbs = $this->thumbs($result['hits']);

        $response = $this->json([
            'q' => $query->q,
            'terms' => $terms,
            'total' => $result['total'],
            'approximate' => $result['approximate'],
            'offset' => $query->offset,
            'more' => $result['more'],
            'hits' => array_map(fn (array $row) => $this->hit($row, $terms, $people, $departments, $thumbs), $result['hits']),
            'facets' => [
                'type' => array_map(
                    static fn (string $key, int $n) => ['key' => $key, 'label' => SearchCatalog::GROUP_LABELS[$key] ?? $key, 'count' => $n],
                    array_keys($facets['type']),
                    $facets['type'],
                ),
                'status' => array_map(
                    static fn (string $key, int $n) => [
                        'key' => $key,
                        'label' => SearchCatalog::statusLabel($key),
                        'kind' => strstr($key, ':', true) ?: $key,
                        'kindLabel' => SearchCatalog::STATUS_KINDS[strstr($key, ':', true) ?: ''] ?? '',
                        'count' => $n,
                    ],
                    array_keys($facets['status']),
                    $facets['status'],
                ),
                'author' => $this->personFacet($facets['author'], $people),
                'executor' => $this->personFacet($facets['executor'], $people),
                'department' => array_values(array_filter(array_map(
                    static fn (int $id, int $n) => isset($departments[$id]) ? ['id' => $id, 'name' => $departments[$id], 'count' => $n] : null,
                    array_keys($facets['department']),
                    $facets['department'],
                ))),
                'client' => array_map(
                    static fn (string|int $name, int $n) => ['name' => (string) $name, 'count' => $n],
                    array_keys($facets['client']),
                    $facets['client'],
                ),
            ],
            // Обрані люди — щоб чипи фільтрів мали імена, навіть коли збігів нуль.
            'people' => array_values(array_filter(array_map(
                static fn (?int $id) => $id !== null ? ($people[$id] ?? null) : null,
                array_values(array_unique(array_filter([$query->person, $query->author, $query->executor, $query->manager]))),
            ))),
            'tookMs' => round($elapsed, 1),
        ]);
        $response->headers->set('Server-Timing', sprintf('search;dur=%.1f', $elapsed));

        return $response;
    }

    /** Довідники для фільтрів: усі, кого можна обрати, навіть без збігів. */
    #[Route('/meta', name: 'api_plan_search_meta', methods: ['GET'])]
    public function meta(): JsonResponse
    {
        $me = $this->me();
        $groups = $this->search->groupsFor($me);
        $people = [];

        foreach ($this->users->findBy([], ['first_name' => 'ASC']) as $user) {
            if ($user->isApproved() && ! $user->isArchived()) {
                $people[] = $this->presenter->person($user);
            }
        }

        usort($people, static fn (array $a, array $b) => strcmp((string) $a['name'], (string) $b['name']));
        $departments = [];

        foreach ($this->departments(activeOnly: true) as $id => $name) {
            $departments[] = ['id' => $id, 'name' => $name];
        }

        $statuses = array_values(array_filter(
            SearchCatalog::statuses(),
            static fn (array $s) => in_array($s['kind'], ['task', 'supply'], true) || in_array('warehouse', $groups, true),
        ));

        return $this->json([
            'types' => array_map(static fn (string $g) => ['key' => $g, 'label' => SearchCatalog::GROUP_LABELS[$g]], $groups),
            'statuses' => array_map(static fn (array $s) => [...$s, 'kindLabel' => SearchCatalog::STATUS_KINDS[$s['kind']] ?? ''], $statuses),
            'people' => $people,
            'departments' => $departments,
            'saved' => $this->savedList($me),
        ]);
    }

    #[Route('/saved', name: 'api_plan_search_saved', methods: ['GET'])]
    public function saved(): JsonResponse
    {
        return $this->json($this->savedList($this->me()));
    }

    #[Route('/saved', name: 'api_plan_search_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $me = $this->me();
        $data = $this->payload($request);
        $name = trim((string) ($data['name'] ?? ''));
        $query = ltrim(trim((string) ($data['query'] ?? '')), '?');

        if ($name === '' || mb_strlen($name) > 80) {
            return $this->error('Назва — від 1 до 80 символів.');
        }

        if ($query === '' || strlen($query) > 1000) {
            return $this->error('Немає що зберігати: задайте запит або фільтр.');
        }

        // Лише параметри пошуку — жодних сторонніх ключів у збереженому рядку.
        parse_str($query, $parts);
        $allowed = array_intersect_key($parts, array_flip(['q', 'type', 'person', 'author', 'executor', 'manager', 'department', 'status', 'client', 'from', 'to', 'starred', 'sort']));
        $clean = http_build_query($allowed, '', '&', PHP_QUERY_RFC3986);

        if ($clean === '') {
            return $this->error('Немає що зберігати: задайте запит або фільтр.');
        }

        $count = (int) $this->db->fetchOne('SELECT COUNT(*) FROM plan_saved_search WHERE user_id = ?', [$me->getId()]);

        if ($count >= SavedSearch::MAX_PER_USER) {
            return $this->error(sprintf('Збережено вже %d пошуків — видаліть зайві.', SavedSearch::MAX_PER_USER));
        }

        $saved = new SavedSearch($me, mb_substr($name, 0, 80), $clean);
        $this->em->persist($saved);
        $this->em->flush();

        return $this->json(self::savedItem($saved), Response::HTTP_CREATED);
    }

    #[Route('/saved/{id}', name: 'api_plan_search_unsave', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function unsave(int $id): JsonResponse
    {
        $me = $this->me();
        $saved = $this->em->find(SavedSearch::class, $id);

        // Чужий збережений пошук — так само «не знайдено», як неіснуючий.
        if ($saved === null || $saved->getUser()->getId() !== $me->getId()) {
            return $this->error('Збережений пошук не знайдено.', Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($saved);
        $this->em->flush();

        return $this->json($this->savedList($me));
    }

    /** @return list<array<string, mixed>> */
    private function savedList(TelegramUser $me): array
    {
        return array_map(
            self::savedItem(...),
            $this->em->getRepository(SavedSearch::class)->findBy(['user' => $me], ['id' => 'DESC']),
        );
    }

    /** @return array<string, mixed> */
    private static function savedItem(SavedSearch $saved): array
    {
        return ['id' => $saved->getId(), 'name' => $saved->getName(), 'query' => $saved->getQuery()];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $terms
     * @param array<int, array<string, mixed>> $people
     * @param array<int, string> $departments
     * @param array<int, string> $thumbs
     *
     * @return array<string, mixed>
     */
    private function hit(array $row, array $terms, array $people, array $departments, array $thumbs): array
    {
        $docType = (string) $row['doc_type'];
        $ref = SubjectRef::of((string) $row['subject_type'], (int) $row['subject_id']);
        $refId = (int) $row['ref_id'];
        $status = $row['status'] !== null ? (string) $row['status'] : null;
        $deps = json_decode((string) $row['department_ids'], true);

        return [
            'id' => (int) $row['id'],
            'docType' => $docType,
            'group' => SearchCatalog::groupOf($docType),
            'docLabel' => SearchCatalog::DOC_LABELS[$docType] ?? $docType,
            'subject' => $ref->toArray(),
            'key' => $ref->key(),
            'refId' => $refId,
            'title' => Snippet::mark((string) $row['title'], $terms),
            'snippet' => Snippet::around((string) $row['body'], $terms),
            'status' => $status,
            'statusLabel' => $status !== null ? SearchCatalog::statusLabel($status) : null,
            'closed' => SearchCatalog::isClosed($status),
            'client' => (string) $row['client'] !== '' ? (string) $row['client'] : null,
            'author' => $row['author_id'] !== null ? ($people[(int) $row['author_id']] ?? null) : null,
            'departments' => array_values(array_filter(array_map(static fn ($id) => $departments[(int) $id] ?? null, is_array($deps) ? $deps : []))),
            'thumb' => $docType === 'file' ? ($thumbs[$refId] ?? null) : null,
            'url' => $this->url($ref, $docType, $refId),
            'at' => self::atom((string) $row['updated_at']),
            'createdAt' => self::atom((string) $row['created_at']),
        ];
    }

    /** Адреса в CRM: задача — у розділеному екрані пошуку (з ?m= / ?f=), інше — сторінка модуля. */
    private function url(SubjectRef $ref, string $docType, int $refId): string
    {
        return match (true) {
            $ref->type === SubjectType::Task => '/crm/tasks/search/' . $ref->id . match ($docType) {
                'comment' => '?m=' . $refId,
                'file' => '?f=' . $refId,
                default => '',
            },
            $ref->type === SubjectType::User => '/crm/staff',
            default => $this->subjects->url($ref),
        };
    }

    /**
     * @param array<int, int> $facet
     * @param array<int, array<string, mixed>> $people
     *
     * @return list<array<string, mixed>>
     */
    private function personFacet(array $facet, array $people): array
    {
        $out = [];

        foreach ($facet as $id => $n) {
            if (isset($people[$id])) {
                $out[] = ['id' => $id, 'name' => $people[$id]['name'], 'count' => $n, 'person' => $people[$id]];
            }
        }

        return $out;
    }

    /**
     * @param array<int, mixed> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    private function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];

        foreach ($ids === [] ? [] : $this->users->findBy(['id' => $ids]) as $user) {
            $out[(int) $user->getId()] = $this->presenter->person($user);
        }

        return $out;
    }

    /** @return array<int, string> */
    private function departments(bool $activeOnly = false): array
    {
        $out = [];

        foreach ($this->db->fetchAllAssociative('SELECT id, name FROM supply_department' . ($activeOnly ? ' WHERE active' : '') . ' ORDER BY name') as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Мініатюри файлів задач, у яких уже є превʼю.
     *
     * @param list<array<string, mixed>> $hits
     *
     * @return array<int, string>
     */
    private function thumbs(array $hits): array
    {
        $ids = [];

        foreach ($hits as $row) {
            if ($row['doc_type'] === 'file') {
                $ids[] = (int) $row['ref_id'];
            }
        }

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($this->db->fetchFirstColumn("SELECT id FROM task_attachment WHERE id IN (?) AND preview_state = 'ready'", [$ids], [ArrayParameterType::INTEGER]) as $id) {
            $out[(int) $id] = '/api/tasks/files/' . (int) $id . '/thumb';
        }

        return $out;
    }

    private static function atom(string $value): string
    {
        return (new DateTime($value, new DateTimeZone('Europe/Kyiv')))->format(DateTimeInterface::ATOM);
    }
}
