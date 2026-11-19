<?php

namespace App\Extension\Support;

use App\Extension\Helpers\ExtensionPendingHelper;
use App\Support\AtomicBootstrapCache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 확장 교체가 남겨 둔 옛 클래스 파일의 「정리 대기 목록」.
 *
 * 새 판이 지운 리스너 클래스 파일은 교체 때 새 트리에 함께 실어 둔다({@see ExtensionPendingHelper::carryOverRemovedClassFiles()}).
 * 훅 캐시를 다시 구운 **직후** 지우면, 그 전에 부팅해 옛 훅 목록·옛 소스 classmap 을 쥔 요청(부하 시 수 초)이 지운 뒤에 훅을
 * 발화해 같은 include 실패가 났다. 그래서 바로 지우지 않고 이 목록에 적어 두고, 최장 요청 시간보다 충분히 뒤
 * ({@see MIN_AGE_SECONDS})의 안전 지점에서 지운다 — 예약 작업 `storage:prune-leftovers`(크론이 없는 호스팅은 웹 크론·방문 트리거로
 * 같은 예약이 돈다)와 다음 확장 교체 기록 때. 남아 있는 동안의 파일은 새 판 선언(`getHookListeners()`·서비스 공급자)이 부르지
 * 않아 동작에 영향이 없다(7.2.0 전환이 남긴 옛 클래스 파일과 같은 상태).
 *
 * 원칙은 「확실하지 않으면 지우지 않는다」이다.
 * - 같은 확장을 다시 교체하면 그 확장의 항목을 새 목록으로 바꾸고, 들어오는 새 트리가 출하하는 경로는 먼저 뺀다.
 * - 지울 때는 실을 때의 내용 지문과 같은 파일만 지운다. 지문이 없으면(실을 때 읽지 못함) 지우지 않는다.
 * - 목록 쓰기는 잠금 안에서 원자 교체로 한다. 잠금을 얻지 못하면 상대 기록을 덮지 않고 넘긴다(남는 파일이 생길 뿐이다).
 * - 백업을 만들 때 그 확장의 항목을 사본으로 두고, 백업으로 되돌리면 그 사본으로 항목을 되살린다.
 *
 * @since 7.2.0
 */
class CarriedClassFileLedger
{
    /** 정리까지 기다리는 최소 시간(초) — PHP 최장 실행 시간·느린 요청보다 넉넉히 */
    public const MIN_AGE_SECONDS = 600;

    /** 백업 사본을 담는 예약 키 */
    private const SNAPSHOTS_KEY = '__snapshots';

    /** 잠금을 기다리는 최대 시간(초) */
    protected int $lockWaitSeconds = 5;

    /**
     * 대기 목록 파일 경로를 돌려줍니다.
     *
     * @return string 절대 경로
     */
    public function path(): string
    {
        return storage_path('app/extension-carried-class-files.json');
    }

    /**
     * 한 확장의 남겨 둔 파일을 적습니다 (같은 확장의 앞 항목은 바꾼다).
     *
     * @param  string  $activePath  확장 활성 디렉토리
     * @param  array<int, string>  $relativePaths  남겨 둔 파일의 상대 경로
     * @param  int|null  $now  기록 시각 (null 이면 지금)
     * @return void 반환값 없음
     */
    public function record(string $activePath, array $relativePaths, ?int $now = null): void
    {
        $files = [];
        foreach ($relativePaths as $relative) {
            $hash = @md5_file($activePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));
            $files[$relative] = is_string($hash) ? $hash : null;
        }

        $this->mutate(function (array $data) use ($activePath, $files, $now): array {
            $key = $this->key($activePath);
            if ($files === []) {
                unset($data[$key]);
            } else {
                $data[$key] = ['active' => $activePath, 'recorded_at' => $now ?? time(), 'files' => $files];
            }

            return $data;
        });
    }

    /**
     * 들어오는 새 트리가 출하하는 경로를 그 확장의 대기 목록에서 뺍니다 — 교체가 시작될 때 부른다.
     *
     * 대기 목록에 오른 파일을 다음 판이 (같은 내용으로라도) 다시 출하하면 그 파일은 더는 「남긴 파일」이 아니다. 빼지 않으면
     * 다음 정리(예약 정리·다음 교체 앞머리)가 새 판이 막 설치한 파일을 지워, 선언·훅 캐시에는 있는 리스너가 조용히 건너뛰어졌다.
     * 내용 해시가 같다는 것만으로는 「남긴 파일」과 「다시 출하한 파일」을 가를 수 없어 출하 여부(새 트리에 그 경로가 있는가)로 가른다.
     *
     * @param  string  $activePath  교체되는 활성 디렉토리
     * @param  string  $incomingTree  들어오는 새 트리(원본·스테이징)
     * @return void 반환값 없음
     */
    public function forgetShippedBy(string $activePath, string $incomingTree): void
    {
        $key = $this->key($activePath);
        if (! isset($this->entries()[$key])) {
            return;
        }

        $this->mutate(function (array $data) use ($key, $incomingTree): array {
            if (! isset($data[$key])) {
                return $data;
            }

            $files = (array) ($data[$key]['files'] ?? []);
            foreach (array_keys($files) as $relative) {
                if (File::exists($incomingTree.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $relative))) {
                    unset($files[$relative]);
                }
            }

            if ($files === []) {
                unset($data[$key]);
            } else {
                $data[$key]['files'] = $files;
            }

            return $data;
        });
    }

    /**
     * 백업을 만들 때 그 확장의 대기 항목을 사본으로 둡니다 (항목이 없으면 하지 않는다).
     *
     * @param  string  $activePath  백업한 활성 디렉토리
     * @param  string  $backupPath  백업 디렉토리
     * @return void 반환값 없음
     */
    public function snapshotFor(string $activePath, string $backupPath): void
    {
        $key = $this->key($activePath);
        if (! isset($this->entries()[$key])) {
            return;
        }

        $this->mutate(function (array $data) use ($key, $backupPath): array {
            if (isset($data[$key])) {
                $data[self::SNAPSHOTS_KEY][$this->key($backupPath)] = $data[$key];
            }

            return $data;
        });
    }

    /**
     * 백업으로 되돌린 뒤 그 백업의 사본으로 대기 항목을 되살립니다 — 교체가 먼저 뺀 항목이 복원 폴더에 영구히 남지 않게.
     *
     * @param  string  $activePath  되돌린 활성 디렉토리
     * @param  string  $backupPath  백업 디렉토리
     * @return void 반환값 없음
     */
    public function restoreSnapshot(string $activePath, string $backupPath): void
    {
        $backupKey = $this->key($backupPath);
        if (! isset($this->snapshots()[$backupKey])) {
            return;
        }

        $this->mutate(function (array $data) use ($activePath, $backupKey): array {
            $snapshot = $data[self::SNAPSHOTS_KEY][$backupKey] ?? null;
            if (is_array($snapshot)) {
                $data[$this->key($activePath)] = $snapshot;
            }

            return $data;
        });
    }

    /**
     * 백업을 지울 때 그 사본을 지웁니다.
     *
     * @param  string  $backupPath  백업 디렉토리
     * @return void 반환값 없음
     */
    public function dropSnapshot(string $backupPath): void
    {
        $backupKey = $this->key($backupPath);
        if (! isset($this->snapshots()[$backupKey])) {
            return;
        }

        $this->mutate(function (array $data) use ($backupKey): array {
            unset($data[self::SNAPSHOTS_KEY][$backupKey]);

            return $data;
        });
    }

    /**
     * 기록한 지 {@see MIN_AGE_SECONDS} 가 지난 항목의 파일을 지웁니다.
     *
     * 실을 때와 내용 지문이 같은 파일만 지운다. 지문이 없거나 다르면 남긴다. 백업이 이미 사라진 사본도 함께 걷는다.
     *
     * @param  int|null  $now  기준 시각 (null 이면 지금)
     * @param  int  $minAge  최소 경과 초
     * @return array<string, array<int, string>> 활성 디렉토리별 지운 파일 상대 경로
     */
    public function sweep(?int $now = null, int $minAge = self::MIN_AGE_SECONDS): array
    {
        $now ??= time();
        $removed = [];

        $this->mutate(function (array $data) use ($now, $minAge, &$removed): array {
            foreach ($data as $key => $entry) {
                if ($key === self::SNAPSHOTS_KEY || ($now - (int) ($entry['recorded_at'] ?? 0)) < $minAge) {
                    continue;
                }

                $active = (string) ($entry['active'] ?? '');
                $targets = [];
                foreach ((array) ($entry['files'] ?? []) as $relative => $hash) {
                    $path = $active.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $relative);
                    // 실을 때 지문이 있고 지금 내용과 같을 때만 지운다 — 지문이 없으면 내용을 확인할 수 없어 남긴다
                    if (is_string($hash) && @md5_file($path) === $hash) {
                        $targets[] = (string) $relative;
                    }
                }

                $deleted = $active !== '' ? ExtensionPendingHelper::removeCarriedOverClassFiles($active, $targets) : [];
                if ($deleted !== []) {
                    $removed[$active] = $deleted;
                }
                unset($data[$key]);
            }

            foreach (array_keys((array) ($data[self::SNAPSHOTS_KEY] ?? [])) as $backupKey) {
                if (! File::isDirectory((string) $backupKey)) {
                    unset($data[self::SNAPSHOTS_KEY][$backupKey]);
                }
            }

            return $data;
        });

        if ($removed !== []) {
            Log::info('확장 교체가 남긴 옛 클래스 파일 정리(대기 목록)', ['files' => $removed]);
        }

        return $removed;
    }

    /**
     * 대기 항목을 읽습니다 (백업 사본 제외).
     *
     * @return array<string, array{active: string, recorded_at: int, files: array<string, string|null>}> 항목
     */
    public function read(): array
    {
        return $this->entries();
    }

    /**
     * 백업 사본을 읽습니다.
     *
     * @return array<string, array<string, mixed>> 백업 경로 → 백업 시점 항목
     */
    public function snapshots(): array
    {
        $data = $this->load();

        return is_array($data[self::SNAPSHOTS_KEY] ?? null) ? $data[self::SNAPSHOTS_KEY] : [];
    }

    /**
     * 대기 항목만 돌려줍니다.
     *
     * @return array<string, array<string, mixed>> 항목
     */
    private function entries(): array
    {
        $data = $this->load();
        unset($data[self::SNAPSHOTS_KEY]);

        return $data;
    }

    /**
     * 목록 파일 전체를 읽습니다.
     *
     * @return array<string, mixed> 내용
     */
    private function load(): array
    {
        $path = $this->path();
        if (! File::exists($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 잠금 안에서 목록을 읽고 바꿔 원자 교체로 씁니다 — 잠금을 얻지 못하면 바꾸지 않는다.
     *
     * 예약 정리와 확장 교체가 겹치면 한쪽 기록이 다른 쪽에 덮여 사라졌다. 잠금을 못 얻으면 넘긴다 — 놓친 기록은 그 파일이 남는
     * 쪽(지우지 않는 쪽)으로만 어긋난다.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $change  내용을 받아 바꾼 내용을 돌려주는 함수
     * @return bool 썼으면 true
     */
    private function mutate(callable $change): bool
    {
        $path = $this->path();
        File::ensureDirectoryExists(dirname($path));

        $handle = @fopen($path.'.lock', 'c');
        if ($handle === false) {
            Log::warning('정리 대기 목록 잠금 파일을 열지 못해 기록을 건너뜁니다', ['path' => $path]);

            return false;
        }

        try {
            $deadline = microtime(true) + $this->lockWaitSeconds;
            while (! flock($handle, LOCK_EX | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    Log::warning('정리 대기 목록 잠금을 얻지 못해 기록을 건너뜁니다 — 남은 파일은 지우지 않는 쪽으로만 어긋납니다', ['path' => $path]);

                    return false;
                }
                usleep(50_000);
            }

            $data = $change($this->load());
            if (($data[self::SNAPSHOTS_KEY] ?? null) === []) {
                unset($data[self::SNAPSHOTS_KEY]);
            }

            if ($data === []) {
                File::delete($path);
            } else {
                AtomicBootstrapCache::write($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * 경로를 목록 키로 바꿉니다.
     *
     * @param  string  $path  활성·백업 디렉토리
     * @return string 키
     */
    private function key(string $path): string
    {
        return str_replace('\\', '/', rtrim($path, '/\\'));
    }
}
