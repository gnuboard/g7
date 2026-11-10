<?php

namespace App\Thumbnails;

use App\Services\Queue\QueueWorkerRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * 썸네일 후속 작업을 요청 밖으로 넘깁니다 — 큐가 있으면 큐로, 없으면(`sync`) 응답을 보낸 뒤에.
 */
final class ThumbnailJobDispatcher
{
    /**
     * 작업을 예약합니다.
     *
     * @param  ShouldQueue  $job  큐 작업
     * @param  \Closure(): void  $inline  큐가 없을 때 응답 뒤에 돌릴 실행
     * @return void 반환값 없음
     */
    public static function dispatch(ShouldQueue $job, \Closure $inline): void
    {
        if (config('queue.default') === 'sync') {
            app()->terminating($inline);

            return;
        }

        // 운영자가 등록한 연결·큐에만 넣는다 — 미등록이면 기본 연결·큐로 폴백(워커가 없는 큐에 쌓이지 않게)
        $resolved = app(QueueWorkerRegistry::class)->resolve(null, null);

        if ($resolved['connection'] !== null) {
            $job->onConnection($resolved['connection']);
        }

        if ($resolved['queue'] !== null) {
            $job->onQueue($resolved['queue']);
        }

        dispatch($job);
    }
}
