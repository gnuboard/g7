<?php

// audit:allow job-generator-needs-scale-test reason: 디스크 하나의 thumbnails/ 트리를 지우는 단건 잡 — 페이로드는 디스크 이름뿐이고 행·파일 목록을 적재하지 않는다. 디렉터리 삭제 한 번이라 규모 의존 메모리가 없다

namespace App\Jobs;

use App\Thumbnails\ThumbnailJobDispatcher;
use App\Thumbnails\ThumbnailPurgeBuffer;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * 더는 쓰지 않는 썸네일 디스크의 파생본을 비우는 큐 작업
 *
 * 저장 위치를 바꾼 뒤 옛 디스크의 `thumbnails/` 를 지운다. 디렉터리 삭제는 파일 수만큼 걸려 설정 저장 요청 안에서
 * 돌리지 않는다. 지금 쓰는 디스크로 되돌렸으면 `ThumbnailStore::purgeRetiredDisk()` 가 건너뛴다.
 */
class PurgeRetiredThumbnailDiskJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 최대 시도 횟수 */
    public int $tries = 1;

    /** @var int 타임아웃 (초) — 파일 수만큼 걸리는 삭제라 일반 작업보다 길게 둔다 */
    public int $timeout = 1800;

    /** @var int 중복 방지 잠금 유지 시간 (초) */
    public int $uniqueFor = 1800;

    /**
     * PurgeRetiredThumbnailDiskJob 생성자
     *
     * @param  string  $disk  옛 썸네일 디스크
     */
    public function __construct(
        public readonly string $disk,
    ) {}

    /**
     * 옛 디스크 비우기를 예약합니다.
     *
     * @param  string  $disk  옛 썸네일 디스크
     * @return void 반환값 없음
     */
    public static function schedule(string $disk): void
    {
        $job = new self($disk);

        ThumbnailJobDispatcher::dispatch($job, static fn () => app()->call([$job, 'handle']));
    }

    /**
     * 중복 방지 키
     *
     * @return string 디스크
     */
    public function uniqueId(): string
    {
        return $this->disk;
    }

    /**
     * 작업을 실행합니다.
     *
     * 응답 뒤에 돌면 요청 끝의 퍼지 비우기가 이미 지났을 수 있어 여기서 직접 비운다.
     *
     * @param  ThumbnailStore  $store  파생본 저장소
     * @param  ThumbnailPurgeBuffer  $purgeBuffer  퍼지 묶음
     * @return void 반환값 없음
     */
    public function handle(ThumbnailStore $store, ThumbnailPurgeBuffer $purgeBuffer): void
    {
        $store->purgeRetiredDisk($this->disk);
        $purgeBuffer->flush();
    }
}
