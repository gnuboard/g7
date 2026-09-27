<?php

namespace App\Listeners\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequest;
use App\Contracts\Extension\HookListenerInterface;
use App\Services\Analytics\VisitService;

/**
 * 사이트 내부 검색어 기록 리스너
 *
 * 방문자가 사이트 안에서 무엇을 찾았는지는 서버만 압니다 — 결과 건수는 브라우저가
 * 알 수 없기 때문입니다. 검색 API 가 응답을 만들기 직전에 발화하는 훅을 받아 현재
 * 방문에 검색 액션을 붙입니다.
 *
 * 검색 요청은 같은 쿠키·IP·User-Agent 로 오므로 별도 토큰 없이 같은 방문에 묶입니다.
 *
 * **이 리스너는 큐를 타지 않습니다**(`sync => true`) — 형제인 수집 리스너와 같은 사유로,
 * 검색 1회가 잡 1개가 되면 얻는 것 없이 `jobs` 테이블만 부풉니다
 * (#682 S3 라운드 6, 설계 결정 「큐는 꼭 필요한 부분에만」).
 */
class RecordSiteSearchListener implements HookListenerInterface
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
            // sync — 위 클래스 주석의 사유. 검색 1회가 잡 1개가 되지 않도록 큐를 타지 않는다.
            'core.search.after_search' => ['method' => 'handle', 'priority' => 20, 'sync' => true],
        ];
    }

    /**
     * 검색 액션을 현재 방문에 기록합니다.
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

        // 검색어 수집을 끈 사이트에서는 훅이 와도 적재하지 않는다.
        if (! AnalyticsConfig::fromSettings()->siteSearchEnabled) {
            return;
        }

        $request = TrackingRequest::fromArray($payload);

        // 빈 검색어는 남길 것이 없다 — 목록을 그냥 연 것과 구분되지 않는다.
        if ($request->searchQuery === null || trim($request->searchQuery) === '') {
            return;
        }

        $this->visits->track($request);
    }
}
