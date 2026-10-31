<?php

namespace App\Http\Controllers\Api\Public;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\CollectFailureReason;
use App\Extension\HookManager;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Analytics\CollectRequest;
use App\Services\Analytics\CollectFailureLogger;
use App\Services\Analytics\VisitService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;

/**
 * 방문자 통계 비콘 수집 API
 *
 * 브라우저가 페이지 이동·내려받기·외부 링크 클릭을 알려 오는 공개 경로입니다.
 *
 * **응답은 언제나 204 입니다.** 검증에 실패했든 적재가 깨졌든 마찬가지입니다.
 * 비콘에 4xx·5xx 를 돌려주면 방문자 브라우저 콘솔에 오류가 뜨고, 그것을 본 운영자는
 * 사이트가 고장 났다고 판단합니다. 통계를 못 남긴 것이 사이트 장애로 보여선 안 됩니다.
 * 실패 사유는 전용 로그로 남아 진단 화면에서 볼 수 있습니다.
 */
class AnalyticsCollectController extends PublicBaseController
{
    /**
     * 수집 컨트롤러를 생성합니다.
     *
     * @param  VisitService  $visits  방문 적재 서비스
     * @param  CollectFailureLogger  $failures  실패 기록기
     */
    public function __construct(
        private readonly VisitService $visits,
        private readonly CollectFailureLogger $failures,
    ) {}

    /**
     * 비콘 페이로드를 받아 방문을 적재합니다.
     *
     * 검증 실패는 여기까지 오지 않습니다 — FormRequest 가 204 로 끊고 사유를 남깁니다.
     *
     * @param  CollectRequest  $request  검증된 비콘 요청
     * @return Response 본문 없는 204 응답 (필요 시 방문자 쿠키 동반)
     */
    public function store(CollectRequest $request): Response
    {
        $response = response()->noContent();

        try {
            $config = AnalyticsConfig::fromSettings();

            if (! $config->enabled) {
                return $response;
            }

            $dto = TrackingRequestFactory::fromHttp($request, $request->validated());

            // 적재는 훅을 거친다 — 확장이 같은 자리에 끼어들 수 있게 하기 위해서다.
            // 구독 리스너(`RecordTrackingRequestListener`)가 `sync => true` 라 큐를 타지
            // 않고 이 요청 안에서 끝난다. 방문 1건이 잡 1개가 되던 예전 큐 경로는
            // 지웠다(#682 S3 라운드 6, 설계 결정 「큐는 꼭 필요한 부분에만」).
            //
            // 식별은 훅보다 먼저 한다. 쿠키는 응답 헤더로만 나갈 수 있는데, 구독자가
            // 방문자 키를 새로 만들면 응답에 심은 쿠키와 어긋나 매 비콘이 새 방문으로
            // 잡히고 재방문이 항상 0 이 된다. 화면에는 숫자가 보이므로 증상이 없다.
            $identity = $this->visits->identify($dto, $config);

            HookManager::doAction(
                'core.analytics.tracking_request_received',
                $dto->withVisitorKey($identity['key'])->toArray(),
            );

            if ($identity['new_cookie'] !== null) {
                $response = $this->attachVisitorCookie($response, $identity['new_cookie'], $config);
            }
        } catch (\Throwable $e) {
            // 여기까지 예외가 올라왔다면 적재 서비스 밖의 문제다. 그래도 응답은 204 다.
            $this->failures->log(CollectFailureReason::DbError, $e->getMessage());
        }

        return $response;
    }

    /**
     * 방문자 쿠키를 응답에 심습니다.
     *
     * 서버가 심습니다 — 브라우저에서 `document.cookie` 로 쓰면 동의 관리 플러그인의
     * 쿠키 차단기가 조용히 버립니다.
     *
     * @param  Response  $response  응답
     * @param  string  $value  쿠키 값
     * @param  AnalyticsConfig  $config  설정
     * @return Response 쿠키가 실린 응답
     */
    private function attachVisitorCookie(Response $response, string $value, AnalyticsConfig $config): Response
    {
        $cookie = Cookie::make(
            name: (string) config('analytics.cookies.visitor', 'g7_vid'),
            value: $value,
            minutes: $config->visitorCookieDays * 24 * 60,
            path: '/',
            domain: null,
            secure: request()->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );

        return $response->withCookie($cookie);
    }
}
