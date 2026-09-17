<?php

namespace App\Http\Controllers\Api\Public;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\CollectFailureReason;
use App\Enums\Analytics\DispatchMode;
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

            // 큐로 넘기면 워커가 적재한다. 워커가 없는 설치에서는 sync 로 바꿔야
            // 방문이 쌓이므로, 진단 화면이 큐 대기 건수를 함께 보여 준다.
            if ($config->dispatchMode === DispatchMode::Queue) {
                // 쿠키만은 여기서 심는다. 워커는 응답 헤더를 쓸 수 없어 적재와 함께
                // 미루면 쿠키가 영영 나가지 않고, 그러면 매 비콘이 새 방문으로 잡혀
                // 재방문이 항상 0 이 된다. 화면에는 숫자가 보이므로 증상이 없다.
                $identity = $this->visits->identify($dto, $config);

                HookManager::doAction(
                    'core.analytics.tracking_request_received',
                    // 워커가 같은 방문자로 적재하도록 여기서 정한 식별값을 함께 넘긴다 —
                    // 워커가 다시 만들면 응답에 심은 쿠키와 어긋난다.
                    $dto->withVisitorKey($identity['key'])->toArray(),
                );

                if ($identity['new_cookie'] !== null) {
                    $response = $this->attachVisitorCookie($response, $identity['new_cookie'], $config);
                }

                return $response;
            }

            $result = $this->visits->track($dto);

            if ($result['new_cookie'] !== null) {
                $response = $this->attachVisitorCookie($response, $result['new_cookie'], $config);
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
