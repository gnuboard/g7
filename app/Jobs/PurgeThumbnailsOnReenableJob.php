<?php

// audit:allow job-generator-needs-scale-test reason: 지금 쓰는 썸네일 디스크의 thumbnails/ 트리를 지우는 단건 잡 — 페이로드가 없고 행·파일 목록을 적재하지 않는다. 디스크당 디렉터리 삭제 한 번이라 규모 의존 메모리가 없다

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
 * 썸네일을 다시 켤 때 지금 쓰는 썸네일 디스크의 파생본을 비우는 큐 작업
 *
 * 썸네일이 꺼져 있는 동안 저장소 계층은 원본 put 의 파생본 무효화를 건너뛴다(이미지 업로드마다 원격 확인 요청을
 * 내지 않게). 그 사이 덮어쓴 원본의 옛 파생본은 다시 켜는 순간 옛 그림으로 나갈 수 있고, 원격 원본은 수정 시각을
 * 보지 않아 영구히 남는다. 어느 원본이 바뀌었는지 기록하지 않았으므로 썸네일 디스크째 비운다(썸네일은 캐시다).
 * 디렉터리 삭제는 파일 수만큼 걸려 설정 저장 요청 안에서 돌리지 않는다.
 */
class PurgeThumbnailsOnReenableJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var int 최대 시도 횟수 */
    public int $tries = 1;

    /** @var int 타임아웃 (초) — 파일 수만큼 걸리는 삭제라 일반 작업보다 길게 둔다 */
    public int $timeout = 1800;

    /** @var int 중복 방지 잠금 유지 시간 (초) */
    public int $uniqueFor = 1800;

    /**
     * 비우기를 예약합니다 (큐가 있으면 큐로, 없으면 응답을 보낸 뒤에).
     *
     * @return void 반환값 없음
     */
    public static function schedule(): void
    {
        $job = new self;

        ThumbnailJobDispatcher::dispatch($job, static fn () => app()->call([$job, 'handle']));
    }

    /**
     * 작업을 실행합니다.
     *
     * 다시 끈 뒤에 돌아도 지운다 — 끈 동안 남은 파생본은 어차피 낡았을 수 있다.
     * 응답 뒤에 돌면 요청 끝의 퍼지 비우기가 이미 지났을 수 있어 여기서 직접 비운다.
     *
     * @param  ThumbnailStore  $store  파생본 저장소
     * @param  ThumbnailPurgeBuffer  $purgeBuffer  퍼지 묶음
     * @return void 반환값 없음
     */
    public function handle(ThumbnailStore $store, ThumbnailPurgeBuffer $purgeBuffer): void
    {
        $store->purgeCurrentDisks();
        $purgeBuffer->flush();
    }
}
