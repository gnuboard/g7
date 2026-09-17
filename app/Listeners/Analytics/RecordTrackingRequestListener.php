<?php

namespace App\Listeners\Analytics;

use App\Analytics\TrackingRequest;
use App\Contracts\Extension\HookListenerInterface;
use App\Services\Analytics\VisitService;

/**
 * 수집 요청 적재 리스너
 *
 * 큐 방식에서 비콘·서버 폴백이 넘긴 요청을 워커가 받아 적재합니다. 코어 훅 정책상
 * 액션 리스너는 기본으로 큐를 타므로, 이 클래스가 곧 「적재를 요청 밖으로 미루는」
 * 지점입니다.
 *
 * 워커가 돌지 않는 설치에서는 이 리스너가 영영 실행되지 않아 방문이 0 으로 남습니다.
 * 그 상태를 운영자가 알 수 있도록 진단 화면이 큐 대기 건수를 보여 주고, 설정에서
 * 즉시 적재(sync)로 바꿀 수 있습니다.
 */
class RecordTrackingRequestListener implements HookListenerInterface
{
    /**
     * 리스너를 생성합니다.
     *
     * @param  VisitService  $visits  방문 적재 서비스
     */
    public function __construct(private readonly VisitService $visits) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 구독 정의
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.analytics.tracking_request_received' => ['method' => 'handle', 'priority' => 10],
        ];
    }

    /**
     * 수집 요청을 적재합니다.
     *
     * @param  mixed  ...$args  훅 인자 (첫 인자가 직렬화된 수집 요청)
     * @return void
     */
    public function handle(...$args): void
    {
        $payload = $args[0] ?? null;

        if (! is_array($payload)) {
            return;
        }

        $this->visits->track(TrackingRequest::fromArray($payload));
    }
}
