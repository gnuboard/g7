<?php

namespace App\Console\Commands;

use App\Console\Commands\Traits\LocalizesDescription;
use App\Console\Commands\Traits\ParsesDaysOption;
use App\Exceptions\UnsafeStoragePathException;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ImageProcessorResolver;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPurgeBuffer;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;

/**
 * 썸네일 파생본 정리 커맨드
 *
 * 파생본 파일명에는 규격 지문만 들어가므로(원본이 바뀌면 저장소 계층이 바로 지운다) 규격 값을 고치거나
 * 엔진을 바꾸면 옛 지문 파일이 디스크에 남는다. 서빙은 새 지문 키만 찾으므로 옛 파일은 다시 쓰이지 않는다.
 * 그런 파일과 아래 경우를 썸네일 디스크 전부(`ThumbnailStore::disks()`)의 `thumbnails/` 아래에서 지운다.
 *
 *  - 규격이 바뀜: 같은 이름 규격의 현재 지문(현재 엔진 기준)과 다른 파일
 *  - 없는 규격: 정의되지 않은 규격 이름의 파일 (운영자 규격 삭제·확장 제거)
 *  - 원본 없음: 키(`thumbnails/{원본 disk}/{원본 경로}/파일`)에서 복원한 원본이 원본 디스크에 없는 파일
 *    — 드라이버 밖에서 원본을 지운 경우(수동 삭제·복원 실패 등)
 *  - 오래됨: `--days` 를 주면 그 일수보다 오래 다시 만들어지지 않은 파일 (파일 수정 시각 기준)
 *
 * 썸네일은 다시 만들 수 있는 캐시라 정리 스케줄은 기본 켬이다. 스케줄 호출(`--scheduled`)은
 * `upload.thumbnail_prune_enabled` 를 다시 확인한다.
 *
 * @example php artisan thumbnails:prune --dry-run
 * @example php artisan thumbnails:prune --days=90
 */
class PruneThumbnailsCommand extends Command
{
    use LocalizesDescription;
    use ParsesDaysOption;

    /** 정리 사유 — 판정 순서대로 */
    private const REASONS = ['stale', 'unknown', 'orphan', 'expired'];

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'thumbnails:prune
                            {--dry-run : 실제 삭제 없이 대상 개수만 확인}
                            {--days= : 이 일수보다 오래 다시 만들어지지 않은 썸네일도 지움 (미지정 시 기간 정리 안 함)}
                            {--scheduled : 스케줄러 호출 표시 — 자동 정리 토글이 꺼져 있으면 조기 종료}';

    /** @var array<string, bool> 원본 존재 확인 결과 (원본 디스크|경로 → 있음) */
    private array $sourceExists = [];

    /**
     * @param  ThumbnailStore  $store  파생본 저장소
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     * @param  ImageProcessorResolver  $processors  이미지 처리 엔진 해석기
     * @param  ThumbnailPurgeBuffer  $purgeBuffer  퍼지 묶음
     */
    public function __construct(
        private readonly ThumbnailStore $store,
        private readonly ThumbnailPresetRegistry $registry,
        private readonly ImageProcessorResolver $processors,
        private readonly ThumbnailPurgeBuffer $purgeBuffer,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        if ($this->option('scheduled') && ! (bool) config('core.thumbnail.prune_enabled', true)) {
            $this->info(__('thumbnail.console.prune.disabled'));

            return Command::SUCCESS;
        }

        // 0일은 「전부 지움」이 되어 캐시를 통째로 비우는 것과 같다 — 그 일은 관리자 「비우기」가 맡으므로 1일부터 받는다.
        $days = $this->parseDaysOption('days', 1);

        if ($days === false) {
            return Command::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $engine = $this->processors->resolve();

        if ($engine === null) {
            $this->warn(__('thumbnail.console.prune.no_engine'));
        }

        $fingerprints = $this->currentFingerprints($engine?->id());
        $cutoff = $days !== null ? now()->subDays($days)->getTimestamp() : null;
        $counts = array_fill_keys(self::REASONS, 0);
        $failed = 0;

        $this->purgeBuffer->batch(function () use ($fingerprints, $cutoff, $isDryRun, &$counts, &$failed) {
            foreach ($this->store->disks() as $disk) {
                try {
                    $result = $this->pruneDisk($disk, $fingerprints, $cutoff, $isDryRun);
                } catch (\Throwable $e) {
                    $this->warn(__('thumbnail.console.prune.disk_failed', ['disk' => $disk, 'error' => $e->getMessage()]));
                    Log::warning('썸네일 정리: 디스크 읽기 실패', ['disk' => $disk, 'error' => $e->getMessage()]);

                    continue;
                }

                foreach (self::REASONS as $reason) {
                    $counts[$reason] += $result['counts'][$reason];
                }
                $failed += $result['failed'];
            }
        });

        $replace = [
            'total' => array_sum($counts),
            'stale' => $counts['stale'],
            'unknown' => $counts['unknown'],
            'orphan' => $counts['orphan'],
            'expired' => $counts['expired'],
            'days' => $days ?? __('thumbnail.console.prune.days_unset'),
        ];

        if ($isDryRun) {
            $this->info('[DRY RUN] '.__('thumbnail.console.prune.dry_run', $replace));

            return Command::SUCCESS;
        }

        $this->info(__('thumbnail.console.prune.done', $replace + ['failed' => $failed]));

        Log::info('PruneThumbnailsCommand: 썸네일 정리 완료', ['days' => $days, 'failed' => $failed] + $counts);

        return Command::SUCCESS;
    }

    /**
     * 규격 이름 → 현재 지문 목록 (엔진이 없으면 null — 규격 변경 판정을 하지 않는다)
     *
     * 정지 이미지 지문과 움직이는 원본의 첫 프레임 썸네일 지문 둘 다 현재 지문이다.
     *
     * @param  string|null  $engineId  현재 엔진 id
     * @return array<string, ?list<string>> 규격 이름 → 지문 목록
     */
    private function currentFingerprints(?string $engineId): array
    {
        $fingerprints = [];

        foreach ($this->registry->all() as $spec) {
            /** @var ThumbnailSpec $spec */
            $fingerprints[$spec->name] = $engineId !== null ? [$spec->fingerprint($engineId), $spec->fingerprint($engineId, true)] : null;
        }

        return $fingerprints;
    }

    /**
     * 썸네일 디스크 하나를 정리합니다.
     *
     * @param  string  $disk  썸네일 디스크
     * @param  array<string, ?list<string>>  $fingerprints  규격 이름 → 현재 지문 목록
     * @param  int|null  $cutoff  이 시각(유닉스 초)보다 오래된 파일을 지움 (null 이면 기간 정리 안 함)
     * @param  bool  $isDryRun  세기만 하는지
     * @return array{counts: array<string, int>, failed: int} 사유별 대상 수·삭제 실패 수
     */
    private function pruneDisk(string $disk, array $fingerprints, ?int $cutoff, bool $isDryRun): array
    {
        // 디스크 직접 접근은 ThumbnailStore::filesystem() 한 지점을 지난다
        $filesystem = $this->store->filesystem($disk);
        $counts = array_fill_keys(self::REASONS, 0);
        $failed = 0;
        $directories = [];

        foreach ($filesystem->allFiles(ThumbnailStore::ROOT) as $file) {
            $reason = $this->reasonFor($filesystem, $file, $fingerprints, $cutoff);

            if ($reason === null) {
                continue;
            }

            $counts[$reason]++;

            if ($isDryRun) {
                continue;
            }

            try {
                $filesystem->delete($file);
                $directories[dirname($file)] ??= $this->store->classifyFile($file, $disk);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('썸네일 정리: 파일 삭제 실패', ['disk' => $disk, 'file' => $file, 'error' => $e->getMessage()]);
            }
        }

        foreach ($directories as $directory => $kind) {
            $parts = explode('/', $directory, 3);

            // CDN 무효화는 원본 하나의 파생본 디렉터리 단위 와일드카드다 — 지운 파일만 무효화할 방법이 없어 같은 원본의 다른
            // 규격도 한 번 다시 받는다(캐시 재적재일 뿐 결과는 같다). 권한 이미지 썸네일은 CDN 에 두지 않으므로(스트림만)
            // CDN 대상 경로를 싣지 않는다 — 권한 위치를 s3 로 골라도 직접 URL 이 퍼지 대상으로 새지 않게.
            $this->purgeBuffer->add([
                'source_disk' => $parts[1] ?? '*',
                'prefix' => $parts[2] ?? '',
                'thumbnail_disk' => $disk,
                'public_url_prefix' => $kind === ThumbnailStore::KIND_PUBLIC ? $this->store->storage($disk)->url('', $directory) : null,
                'original_public_url' => null,
                'original_is_directory' => false,
            ]);
        }

        if ($directories !== [] && $this->store->isLocalDisk($disk)) {
            $this->removeEmptyDirectories($filesystem, array_map('strval', array_keys($directories)));
        }

        return ['counts' => $counts, 'failed' => $failed];
    }

    /**
     * 파생본 파일 하나를 지울 사유 (남길 파일이면 null)
     *
     * @param  Filesystem  $filesystem  썸네일 디스크
     * @param  string  $file  파생본 키
     * @param  array<string, ?list<string>>  $fingerprints  규격 이름 → 현재 지문 목록
     * @param  int|null  $cutoff  기간 정리 기준 시각
     * @return string|null stale·unknown·orphan·expired
     */
    private function reasonFor(Filesystem $filesystem, string $file, array $fingerprints, ?int $cutoff): ?string
    {
        $preset = ThumbnailStore::presetOfFile($file);

        if (array_key_exists($preset, $fingerprints)) {
            $current = $fingerprints[$preset];

            if ($current !== null && ! in_array(ThumbnailStore::fingerprintOfFile($file), $current, true)) {
                return 'stale';
            }
        } else {
            return 'unknown';
        }

        $parts = explode('/', $file, 3);

        // 키 형식이 아닌 파일(`thumbnails/{disk}/{경로}/{파일}` 이 아님)은 원본을 복원할 수 없다 — 어떤 원본의 파생본도 아니다.
        if (count($parts) < 3 || dirname($parts[2]) === '.') {
            return 'orphan';
        }

        $exists = $this->sourceExists($parts[1], dirname($parts[2]));

        if ($exists === false) {
            return 'orphan';
        }

        if ($cutoff !== null && $filesystem->lastModified($file) < $cutoff) {
            return 'expired';
        }

        return null;
    }

    /**
     * 키에서 복원한 원본이 원본 디스크에 있는지 (원본 하나에 한 번만 묻는다)
     *
     * 원본 디스크 설정 자체가 없으면 없음으로 본다. 원격 디스크 오류(네트워크 등)는 있음으로 본다 —
     * 일시 장애로 멀쩡한 파생본을 지우지 않게.
     *
     * @param  string  $sourceDisk  원본 디스크
     * @param  string  $sourcePath  원본 전체 경로
     * @return bool 있으면 true
     */
    private function sourceExists(string $sourceDisk, string $sourcePath): bool
    {
        $cacheKey = $sourceDisk.'|'.$sourcePath;

        if (array_key_exists($cacheKey, $this->sourceExists)) {
            return $this->sourceExists[$cacheKey];
        }

        if (config("filesystems.disks.{$sourceDisk}") === null) {
            return $this->sourceExists[$cacheKey] = false;
        }

        try {
            $exists = $this->store->storage($sourceDisk)->exists('', $sourcePath);
        } catch (UnsafeStoragePathException) {
            // 안전하지 않은 경로(`..` 등)로 복원되는 키는 정상 경로로 만들어진 파생본이 아니다.
            $exists = false;
        } catch (\Throwable $e) {
            Log::warning('썸네일 정리: 원본 존재 확인 실패 — 남겨 둡니다', ['disk' => $sourceDisk, 'path' => $sourcePath, 'error' => $e->getMessage()]);
            $exists = true;
        }

        return $this->sourceExists[$cacheKey] = $exists;
    }

    /**
     * 파일을 지워 빈 디렉터리를 `thumbnails/` 아래까지 거슬러 올라가며 지웁니다 (로컬 디스크만 — 원격은 디렉터리가 없다).
     *
     * @param  Filesystem  $filesystem  썸네일 디스크
     * @param  list<string>  $directories  파일을 지운 디렉터리
     * @return void 반환값 없음
     */
    private function removeEmptyDirectories(Filesystem $filesystem, array $directories): void
    {
        foreach ($directories as $directory) {
            while ($directory !== ThumbnailStore::ROOT && str_starts_with($directory, ThumbnailStore::ROOT.'/')) {
                if ($filesystem->files($directory) !== [] || $filesystem->directories($directory) !== []) {
                    break;
                }

                $filesystem->deleteDirectory($directory);
                $directory = dirname($directory);
            }
        }
    }
}
