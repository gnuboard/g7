<?php

namespace App\Thumbnails;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 썸네일 퍼지 묶음 — 지운 접두를 모아 액션 `core.thumbnail.after_purge` 를 한 번에 발화합니다.
 *
 * 게시글 하나를 지우면 첨부 열 개의 파생본 디렉터리가 지워진다. 그때마다 CDN 무효화 요청을 보내면
 * 요금·호출 상한에 걸리므로 묶어서 한 번 보낸다. 비우는 시점은 세 곳이다:
 *  - HTTP: 응답 뒤 `app()->terminating()`
 *  - 큐: 작업 하나가 끝날 때마다 (`JobProcessed`·`JobFailed`)
 *  - 콘솔 명령·테스트: 즉시 (단 `batch()` 안에서는 끝날 때 한 번)
 *
 * 페이로드: `list<array{source_disk: string, prefix: string, thumbnail_disk: string, public_url_prefix: ?string, original_public_url: ?string, original_is_directory: bool}>`
 */
class ThumbnailPurgeBuffer
{
    /** @var array<string, array<string, mixed>> 모은 항목 (중복 제거 키 → 항목) */
    private array $items = [];

    /** 큐 작업을 처리하는 중인지 */
    private bool $processingJob = false;

    /** batch() 깊이 */
    private int $batchDepth = 0;

    /**
     * 지운 접두 하나를 모읍니다.
     *
     * @param  array{source_disk: string, prefix: string, thumbnail_disk: string, public_url_prefix: ?string, original_public_url: ?string, original_is_directory: bool}  $item  항목
     * @return void 반환값 없음
     */
    public function add(array $item): void
    {
        $this->items[md5(serialize($item))] = $item;

        if ($this->shouldFlushImmediately()) {
            $this->flush();
        }
    }

    /**
     * 콜백 안에서 생긴 항목을 끝에 한 번만 발화합니다.
     *
     * @template T
     *
     * @param  callable(): T  $callback  콜백
     * @return T 콜백 반환값
     */
    public function batch(callable $callback): mixed
    {
        $this->batchDepth++;

        try {
            return $callback();
        } finally {
            $this->batchDepth--;

            if ($this->batchDepth === 0 && $this->shouldFlushImmediately()) {
                $this->flush();
            }
        }
    }

    /**
     * 모은 항목을 발화하고 비웁니다.
     *
     * @return int 발화한 항목 수
     */
    public function flush(): int
    {
        if ($this->items === []) {
            return 0;
        }

        $items = array_values($this->items);
        $this->items = [];

        try {
            HookManager::doAction('core.thumbnail.after_purge', $items);
        } catch (\Throwable $e) {
            // 퍼지 리스너 실패가 삭제 요청을 실패로 만들지 않는다 — 재시도는 리스너(플러그인) 몫이다.
            Log::warning('썸네일 퍼지 훅 처리 실패', ['count' => count($items), 'error' => $e->getMessage()]);
        }

        return count($items);
    }

    /**
     * 큐 작업 시작·끝 표시 (서비스 프로바이더가 큐 이벤트에 건다)
     *
     * @param  bool  $processing  작업 처리 중이면 true
     * @return void 반환값 없음
     */
    public function setProcessingJob(bool $processing): void
    {
        $this->processingJob = $processing;
    }

    /**
     * 아직 발화하지 않은 항목 (테스트·진단용)
     *
     * @return list<array<string, mixed>> 항목
     */
    public function pending(): array
    {
        return array_values($this->items);
    }

    /**
     * 즉시 발화해야 하는지 — 콘솔(큐 작업 밖)·테스트이고 batch() 밖일 때.
     *
     * @return bool 즉시면 true
     */
    private function shouldFlushImmediately(): bool
    {
        if ($this->batchDepth > 0 || $this->processingJob) {
            return false;
        }

        return app()->runningUnitTests() || app()->runningInConsole();
    }
}
