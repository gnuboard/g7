<?php

namespace App\Listeners\Analytics;

use App\Analytics\TrackingRequest;
use App\Contracts\Extension\HookListenerInterface;
use App\Services\Analytics\VisitService;

/**
 * 수집 요청 적재 리스너
 *
 * 비콘·서버 폴백이 넘긴 요청을 받아 방문 원장에 적재합니다. 훅을 거치는 것은
 * 확장이 같은 자리에 끼어들 수 있게 하기 위해서이고, 적재 자체는 요청 안에서
 * 끝냅니다.
 *
 * **이 리스너는 큐를 타지 않습니다**(`sync => true`). 코어 훅 정책상 액션 리스너는
 * 기본으로 큐를 타는데, 방문 수집에 그 기본을 그대로 두면 방문 1건마다 잡이 1개씩
 * 쌓입니다 — 10만 방문이면 잡 10만 개이고, `jobs` 테이블의 INSERT·SELECT·DELETE 가
 * 원장 INSERT 한 번보다 비싸므로 얻는 것 없이 비용만 늡니다. 게다가 워커가 없는
 * 설치에서는 방문이 통째로 유실됩니다. 큐는 집계·익명화처럼 무거운 배치에만 씁니다
 * (#682 S3 라운드 6, 설계 결정 「큐는 꼭 필요한 부분에만」).
 *
 * 비콘은 `sendBeacon` 으로 오고 서버 폴백은 `terminate` 단계에서 돌므로, 동기로
 * 적재해도 방문자가 기다리는 시간은 늘지 않습니다.
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
            // sync — 위 클래스 주석의 사유. 방문 1건이 잡 1개가 되지 않도록 큐를 타지 않는다.
            'core.analytics.tracking_request_received' => ['method' => 'handle', 'priority' => 10, 'sync' => true],
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
