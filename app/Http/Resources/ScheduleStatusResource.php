<?php

namespace App\Http\Resources;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * 스케줄 화면 상단 상태 카드용 응답 리소스
 *
 * 스케줄러 하트비트(마지막 틱)·큐 요약·Shell 사용 여부를 한 응답으로 묶는다.
 * 원본은 `['last_tick' => ?CarbonInterface, 'queue' => array, 'shell_enabled' => bool]` 배열이다.
 */
class ScheduleStatusResource extends BaseApiResource
{
    /**
     * 마지막 틱이 이 분 수 이내면 스케줄러가 정상으로 돌고 있다고 본다.
     */
    public const HEALTHY_WITHIN_MINUTES = 3;

    /**
     * 상태 요약을 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, mixed> heartbeat·queue 요약·shell_enabled
     */
    public function toArray(Request $request): array
    {
        $lastTick = $this->getValue('last_tick');
        $lastTick = $lastTick instanceof CarbonInterface ? Carbon::instance($lastTick) : null;

        $secondsAgo = $lastTick ? (int) $lastTick->diffInSeconds(now(), true) : null;

        return [
            'heartbeat' => [
                'last_tick_at' => $this->formatDateTimeForUser($lastTick),
                'minutes_ago' => $secondsAgo === null ? null : intdiv($secondsAgo, 60),
                // 틱이 한 번도 없었으면 크론이 안 걸린 것이라 비정상으로 본다
                'healthy' => $secondsAgo !== null && $secondsAgo <= self::HEALTHY_WITHIN_MINUTES * 60,
            ],
            'queue' => $this->getValue('queue') ?? [],
            // Shell 유형을 쓸 수 있는지(.env 게이트 on + 허용 실행 파일 1개 이상) — 등록·수정 창의 Shell 선택지 잠금용
            'shell_enabled' => (bool) $this->getValue('shell_enabled', false),
        ];
    }
}
