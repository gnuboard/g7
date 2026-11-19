<?php

namespace App\Thumbnails;

use App\Extension\HookManager;
use App\Jobs\GenerateThumbnailsJob;
use App\Thumbnails\DTO\ThumbnailSource;
use App\Thumbnails\DTO\ThumbnailSpec;
use Illuminate\Support\Facades\Log;

/**
 * 썸네일 미리 만들기 — 등록 시 생성(저장소 계층)과 「기존 이미지 썸네일 만들기」(명령·관리자 버튼) 공용.
 */
class ThumbnailPregenerator
{
    /**
     * ThumbnailPregenerator 생성자
     *
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     * @param  ThumbnailSourceProviders  $providers  원본 제공자
     * @param  ThumbnailGenerator  $generator  파생본 준비
     */
    public function __construct(
        private readonly ThumbnailPresetRegistry $registry,
        private readonly ThumbnailSourceProviders $providers,
        private readonly ThumbnailGenerator $generator,
    ) {}

    /**
     * 방금 저장한 파일에 미리 생성 규격이 있으면 예약합니다.
     *
     * 큐가 있으면 큐 작업으로, `sync` 면 응답이 나간 뒤(`app()->terminating()`) 처리한다 — 업로드 응답을
     * 늦추지 않는다. 어느 제공자의 원본인지 모르면(임시 업로드·알 수 없는 경로) 만들지 않는다.
     *
     * @param  string  $disk  디스크
     * @param  string  $path  디스크 위 전체 경로
     * @return bool 예약했으면 true
     */
    public function scheduleForStoredFile(string $disk, string $path): bool
    {
        if (! (bool) config('core.thumbnail.enabled', true) || $this->registry->pregenerated() === []) {
            return false;
        }

        $provider = $this->providers->classify($disk, $path);

        if ($provider === null) {
            return false;
        }

        $mime = self::mimeFromPath($path);

        if ($mime === null) {
            return false;
        }

        $job = new GenerateThumbnailsJob($disk, $path, $mime, $provider->gated());

        ThumbnailJobDispatcher::dispatch($job, static fn () => $job->handle(app(self::class)));

        return true;
    }

    /**
     * 원본 하나의 규격들을 만듭니다.
     *
     * @param  ThumbnailSource  $source  원본
     * @param  list<ThumbnailSpec>|null  $specs  규격 (null 이면 미리 생성 규격)
     * @return array{generated: int, skipped: int} 만든 수·건너뛴 수(이미 있음·원본으로 돌려보냄)
     */
    public function generateFor(ThumbnailSource $source, ?array $specs = null): array
    {
        $generated = 0;
        $skipped = 0;

        foreach ($specs ?? $this->registry->pregenerated() as $spec) {
            try {
                $result = $this->generator->prepare($source, $spec);
            } catch (\Throwable $e) {
                Log::warning('썸네일 미리 만들기 실패', ['disk' => $source->disk, 'path' => $source->path, 'preset' => $spec->name, 'error' => $e->getMessage()]);
                $skipped++;

                continue;
            }

            $result->status === 'miss' ? $generated++ : $skipped++;
        }

        return ['generated' => $generated, 'skipped' => $skipped];
    }

    /**
     * 「기존 이미지 썸네일 만들기」 한 번분 — 제공자를 차례로 돌며 `limit` 건을 처리합니다.
     *
     * 커서 형식: `{제공자 순번}:{제공자 커서}`. 이미 있는 파생본은 `prepare()` 가 hit 으로 건너뛰므로
     * 처음부터 다시 돌려도 이어서 하는 것과 같다.
     *
     * 관리 화면과 명령 두 진입점이 모두 이 메서드를 지나므로 앞뒤 액션(`core.thumbnail.before_pregenerate`·
     * `after_pregenerate`)을 여기서 한 번분마다 한 번 발화한다. dry-run 은 아무것도 만들지 않아 발화하지 않는다.
     *
     * `timeBudget` 을 주면 원본을 하나씩 읽어, 예산을 넘긴 순간 처리한 자리의 커서를 돌려준다. 제공자 커서는
     * 한 묶음의 끝에서만 나오므로 묶음 중간에서는 이어 갈 자리를 만들 수 없다. 원본 하나를 처리한 뒤에만 재므로 실제
     * 시간은 예산에 원본 하나분을 더한 값까지 간다.
     *
     * @param  string|null  $cursor  이전 호출의 next_cursor
     * @param  list<string>  $sourceKeys  제공자 키 (빈 목록이면 전부)
     * @param  list<string>  $presetNames  규격 이름 (빈 목록이면 켜진 규격 전부)
     * @param  int  $limit  처리할 원본 수
     * @param  bool  $dryRun  만들지 않고 세기만
     * @param  float|null  $timeBudget  이번 한 번분의 시간 예산(초, null 이면 상한 없음 — 명령은 넘기지 않는다)
     * @return array{processed: int, generated: int, next_cursor: ?string, total: int} 진행 결과
     */
    public function batch(?string $cursor, array $sourceKeys, array $presetNames, int $limit, bool $dryRun = false, ?float $timeBudget = null): array
    {
        if (! $dryRun) {
            HookManager::doAction('core.thumbnail.before_pregenerate', $cursor, $sourceKeys, $presetNames);
        }

        $startedAt = microtime(true);
        $providers = array_values($this->providers->only($sourceKeys));
        $specs = $this->specs($presetNames);
        $total = array_sum(array_map(fn ($provider) => $provider->count(), $providers));

        [$index, $inner] = $this->parseCursor($cursor);
        $processed = 0;
        $generated = 0;

        while ($index < count($providers) && $processed < $limit) {
            $chunk = $providers[$index]->chunk($inner, $timeBudget === null ? $limit - $processed : 1);

            foreach ($chunk['items'] as $source) {
                $processed++;

                if (! $dryRun && $specs !== []) {
                    $generated += $this->generateFor($source, $specs)['generated'];
                }
            }

            if ($chunk['next'] === null) {
                $index++;
                $inner = null;
            } else {
                $inner = $chunk['next'];
            }

            if ($timeBudget !== null && microtime(true) - $startedAt >= $timeBudget) {
                break;
            }
        }

        $next = $index < count($providers) ? $index.':'.($inner ?? '') : null;
        $result = ['processed' => $processed, 'generated' => $generated, 'next_cursor' => $next, 'total' => $total];

        if (! $dryRun) {
            HookManager::doAction('core.thumbnail.after_pregenerate', $result, $sourceKeys, $presetNames);
        }

        return $result;
    }

    /**
     * 규격 이름 → 켜진 규격 (빈 목록이면 켜진 규격 전부, og 제외)
     *
     * @param  list<string>  $names  이름
     * @return list<ThumbnailSpec> 규격
     */
    private function specs(array $names): array
    {
        $enabled = array_filter(
            $this->registry->enabled(),
            fn (ThumbnailSpec $spec) => $spec->name !== ThumbnailPresetRegistry::OG
        );

        if ($names !== []) {
            $enabled = array_filter($this->registry->enabled(), fn (ThumbnailSpec $spec) => in_array($spec->name, $names, true));
        }

        return array_values($enabled);
    }

    /**
     * 커서를 [제공자 순번, 제공자 커서] 로 풉니다.
     *
     * @param  string|null  $cursor  커서
     * @return array{0: int, 1: ?string} 풀린 값
     */
    private function parseCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '' || ! str_contains($cursor, ':')) {
            return [0, null];
        }

        [$index, $inner] = explode(':', $cursor, 2);

        return [max(0, (int) $index), $inner === '' ? null : $inner];
    }

    /**
     * 확장자로 래스터 MIME 을 정합니다.
     *
     * @param  string  $path  경로
     * @return string|null MIME (래스터가 아니면 null)
     */
    public static function mimeFromPath(string $path): ?string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
            default => null,
        };
    }
}
