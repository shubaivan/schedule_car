<?php

namespace App\Plan\Drive;

/**
 * Диск без Google — для тестів і локального стенда (PLAN_DRIVE_FAKE_DIR).
 *
 * Тримає теки й файли в пам'яті; з $dir — ще й на диску ($dir/index.json і
 * $dir/<шлях тек>/<файл>), щоб дерево можна було роздивитись руками.
 * failNext() — підставити збій наступним викликам (для тестів backoff/auth/quota).
 */
final class FakeDriveClient implements DriveClient
{
    /** @var array<string, array{name: string, parent: string}> id → тека */
    private array $folders = [];

    /** @var array<string, array{name: string, parent: string, key: string, mime: string, size: int}> id → файл */
    private array $files = [];

    /** @var list<DriveException> */
    private array $failures = [];

    private int $seq = 0;

    public int $uploads = 0;

    public function __construct(private readonly ?string $dir = null)
    {
        if ($this->dir !== null && is_file($this->dir . '/index.json')) {
            $state = json_decode((string) file_get_contents($this->dir . '/index.json'), true);

            if (is_array($state)) {
                $this->folders = $state['folders'] ?? [];
                $this->files = $state['files'] ?? [];
                $this->seq = (int) ($state['seq'] ?? 0);
            }
        }
    }

    public function failNext(DriveException $error, int $times = 1): self
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->failures[] = $error;
        }

        return $this;
    }

    public function rootId(): string
    {
        return 'root';
    }

    public function folder(string $name, string $parentId): string
    {
        $this->maybeFail();

        foreach ($this->folders as $id => $folder) {
            if ($folder['name'] === $name && $folder['parent'] === $parentId) {
                return $id;
            }
        }

        $id = 'fold' . ++$this->seq;
        $this->folders[$id] = ['name' => $name, 'parent' => $parentId];
        $this->save();

        return $id;
    }

    public function findByKey(string $planKey): ?DriveUpload
    {
        $this->maybeFail();

        foreach ($this->files as $id => $file) {
            if ($file['key'] === $planKey) {
                return new DriveUpload($id, self::url($id));
            }
        }

        return null;
    }

    public function nameTaken(string $name, string $parentId): bool
    {
        $this->maybeFail();

        foreach ($this->files as $file) {
            if ($file['name'] === $name && $file['parent'] === $parentId) {
                return true;
            }
        }

        return false;
    }

    public function upload(string $name, string $mime, string $contents, string $parentId, string $planKey): DriveUpload
    {
        $this->maybeFail();

        $id = 'file' . ++$this->seq;
        $this->files[$id] = ['name' => $name, 'parent' => $parentId, 'key' => $planKey, 'mime' => $mime, 'size' => strlen($contents)];
        ++$this->uploads;

        if ($this->dir !== null) {
            $folder = $this->dir . '/' . implode('/', $this->pathOf($parentId));

            if (! is_dir($folder)) {
                mkdir($folder, 0o775, true);
            }

            file_put_contents($folder . '/' . $name, $contents);
        }

        $this->save();

        return new DriveUpload($id, self::url($id));
    }

    /** «План/2026/Задачі/№0012 …/файл.pdf» для кожного файлу. @return list<string> */
    public function paths(): array
    {
        $out = [];

        foreach ($this->files as $file) {
            $out[] = implode('/', [...$this->pathOf($file['parent']), $file['name']]);
        }

        return $out;
    }

    /** @return list<string> */
    private function pathOf(string $folderId): array
    {
        $path = [];

        while (isset($this->folders[$folderId])) {
            array_unshift($path, $this->folders[$folderId]['name']);
            $folderId = $this->folders[$folderId]['parent'];
        }

        return $path;
    }

    private function maybeFail(): void
    {
        if ($this->failures !== []) {
            throw array_shift($this->failures);
        }
    }

    private function save(): void
    {
        if ($this->dir === null) {
            return;
        }

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0o775, true);
        }

        file_put_contents($this->dir . '/index.json', json_encode(['seq' => $this->seq, 'folders' => $this->folders, 'files' => $this->files], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private static function url(string $id): string
    {
        return 'https://drive.example.test/file/d/' . $id . '/view';
    }
}
