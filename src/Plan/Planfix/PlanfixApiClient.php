<?php

namespace App\Plan\Planfix;

use RuntimeException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Мінімальний клієнт Planfix REST лише для читання: сторінки списків, один GET, завантаження
 * файла. Повтори на 429/5xx і обриви звʼязку, пауза між запитами (ліміти Planfix).
 * Токен іде заголовком Bearer; на переадресації до сховища файлів (інший хост) HttpClient
 * його не передає.
 */
final class PlanfixApiClient
{
    private const int TRIES = 6;

    private int $calls = 0;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $pauseMs = 250,
    ) {
    }

    public function calls(): int
    {
        return $this->calls;
    }

    /**
     * Гортати список; $visit повертає false — зупинитись.
     *
     * @param array<string, mixed> $body
     * @param callable(array<string, mixed>): bool $visit
     */
    public function each(string $path, array $body, string $key, callable $visit, int $page = 100): void
    {
        $offset = 0;

        while (true) {
            $items = $this->request('POST', $path, $body + ['offset' => $offset, 'pageSize' => $page])[$key] ?? [];

            foreach ($items as $item) {
                if (! $visit($item)) {
                    return;
                }
            }

            if (count($items) < $page) {
                return;
            }

            $offset += count($items);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    public function paged(string $path, array $body, string $key): array
    {
        $all = [];
        $this->each($path, $body, $key, static function (array $item) use (&$all): bool {
            $all[] = $item;

            return true;
        });

        return $all;
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function download(string $path, string $target): void
    {
        $tmp = $target . '.part';
        $this->retry(function () use ($path, $tmp): void {
            $response = $this->http->request('GET', $this->baseUrl . $path, ['auth_bearer' => $this->token, 'timeout' => 180]);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new PlanfixApiRetry($status, sprintf('файл: HTTP %d', $status));
            }

            $handle = fopen($tmp, 'wb') ?: throw new RuntimeException('Не вдалося записати ' . $tmp);

            foreach ($this->http->stream($response) as $chunk) {
                fwrite($handle, $chunk->getContent());
            }

            fclose($handle);
        });

        if (! is_file($tmp) || filesize($tmp) === 0) {
            @unlink($tmp);

            throw new RuntimeException('файл порожній');
        }

        chmod($tmp, 0660);
        rename($tmp, $target);
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        return $this->retry(function () use ($method, $path, $body): array {
            $options = ['auth_bearer' => $this->token, 'timeout' => 120, 'headers' => ['Accept' => 'application/json']];

            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $this->http->request($method, $this->baseUrl . $path, $options);
            $status = $response->getStatusCode();

            if ($status !== 200) {
                throw new PlanfixApiRetry($status, sprintf('%s %s: HTTP %d', $method, $path, $status));
            }

            $data = $response->toArray(false);

            if (($data['result'] ?? '') !== 'success') {
                throw new RuntimeException(sprintf('%s %s: %s', $method, $path, mb_substr((string) json_encode($data['error'] ?? $data, JSON_UNESCAPED_UNICODE), 0, 200)));
            }

            return $data;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function retry(callable $call): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            ++$this->calls;

            try {
                $result = $call();
                usleep($this->pauseMs * 1000);

                return $result;
            } catch (PlanfixApiRetry $e) {
                if (! in_array($e->status, [429, 500, 502, 503, 504], true) || $attempt >= self::TRIES) {
                    throw new RuntimeException($e->getMessage(), 0, $e);
                }
            } catch (ExceptionInterface $e) {
                if ($attempt >= self::TRIES) {
                    throw new RuntimeException('Planfix: ' . $e->getMessage(), 0, $e);
                }
            }

            usleep(min(30_000, 1_000 * 2 ** $attempt) * 1000);
        }
    }
}
