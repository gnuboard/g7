<?php

namespace App\Http\Middleware\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\DispatchMode;
use App\Enums\Analytics\VisitSource;
use App\Extension\HookManager;
use App\Services\Analytics\VisitService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 서버 폴백 수집 미들웨어
 *
 * 브라우저가 비콘을 보내지 못하는 환경(자바스크립트 차단·확장 프로그램·구형 브라우저)
 * 에서도 방문을 셉니다. 비콘만 믿으면 그런 방문자가 통계에서 통째로 사라집니다.
 *
 * 응답을 보낸 뒤(terminate)에 기록하므로 방문자가 기다리는 시간에 영향을 주지 않습니다.
 * 같은 페이지를 비콘이 다시 알리면 적재 서비스가 두 기록을 하나로 합칩니다.
 */
class TrackServerVisit
{
    /**
     * 미들웨어를 생성합니다.
     *
     * @param  VisitService  $visits  방문 적재 서비스
     */
    public function __construct(private readonly VisitService $visits) {}

    /**
     * 요청을 그대로 통과시킵니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어
     * @return Response 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * 응답을 보낸 뒤 방문을 기록합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Response  $response  응답
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $this->shouldTrack($request, $response)) {
                return;
            }

            $config = AnalyticsConfig::fromSettings();

            if (! $config->enabled) {
                return;
            }

            $dto = TrackingRequestFactory::fromHttp($request, [], VisitSource::Server->value);

            if ($config->dispatchMode === DispatchMode::Queue) {
                HookManager::doAction('core.analytics.tracking_request_received', $dto->toArray());

                return;
            }

            $this->visits->track($dto);
        } catch (\Throwable $e) {
            // 응답은 이미 나갔다. 통계 때문에 요청 종료가 실패하면 안 된다.
        }
    }

    /**
     * 이 요청을 서버 폴백으로 셀지 판정합니다.
     *
     * 사람이 실제로 화면을 받은 요청만 셉니다 — 리다이렉트·오류 응답·내려받기는
     * 페이지 열람이 아니고, 봇은 별도 원장으로 갑니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Response  $response  응답
     * @return bool 기록 대상 여부
     */
    private function shouldTrack(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // 봇 판정은 SEO 미들웨어가 앞서 남긴다. 봇은 봇 원장으로 간다.
        if ($request->attributes->get('seo.is_bot') === true) {
            return false;
        }

        $contentType = $response->headers->get('Content-Type', '');

        return str_contains(strtolower($contentType), 'text/html');
    }
}
