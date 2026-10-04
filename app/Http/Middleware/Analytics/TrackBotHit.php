<?php

namespace App\Http\Middleware\Analytics;

use App\Analytics\AnalyticsBotClassifier;
use App\Analytics\AnalyticsConfig;
use App\Services\Analytics\BotHitRecorder;
use App\Support\SiteUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 봇 요청 기록 미들웨어
 *
 * 봇 방문을 사람 방문과 섞지 않고 따로 셉니다. 크롤러가 하루에 수만 번 오는 사이트도
 * 있어, 섞으면 인기 페이지와 방문자 수가 통째로 뒤집힙니다.
 *
 * SEO 미들웨어 앞에 두고 응답 뒤(terminate)에 기록합니다 — 그래야 SEO 가 남긴 봇
 * 판정과 캐시 사용 여부를 함께 읽을 수 있습니다. SEO 안에 통계 코드를 넣지 않는 것은
 * 두 기능의 수명을 묶지 않기 위해서입니다.
 */
class TrackBotHit
{
    /**
     * 미들웨어를 생성합니다.
     *
     * @param  AnalyticsBotClassifier  $classifier  봇 판별기
     * @param  BotHitRecorder  $recorder  봇 요청 기록기
     */
    public function __construct(
        private readonly AnalyticsBotClassifier $classifier,
        private readonly BotHitRecorder $recorder,
    ) {}

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
     * 응답을 보낸 뒤 봇 요청을 기록합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Response  $response  응답
     * @return void
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $config = AnalyticsConfig::fromSettings();

            if (! $config->enabled || ! $config->botTrackingEnabled) {
                return;
            }

            if (! $this->isBot($request, $config)) {
                return;
            }

            $userAgent = $request->userAgent();

            $this->recorder->record(
                AnalyticsBotClassifier::botName($userAgent, $config->excludedUserAgents),
                $userAgent,
                // 보안: 요청 Host 가 아니라 사이트 주소 + 요청 경로
                SiteUrl::forPath($request->getRequestUri()),
                $request->ip(),
                $this->cacheStatus($response),
                $config->ipMaskOctets,
            );
        } catch (\Throwable $e) {
            // 응답은 이미 나갔다. 통계 때문에 요청 종료가 실패하면 안 된다.
        }
    }

    /**
     * 이 요청이 봇인지 판정합니다.
     *
     * SEO 가 이미 판정했으면 그 결과를 따르고, 아니면 통계가 직접 판정합니다 —
     * SEO 의 봇 감지를 끈 사이트에서도 봇이 사람으로 집계되지 않아야 합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  AnalyticsConfig  $config  설정
     * @return bool 봇 여부
     */
    private function isBot(Request $request, AnalyticsConfig $config): bool
    {
        if ($request->attributes->get('seo.is_bot') === true) {
            return true;
        }

        return $this->classifier->isBot($request, $config);
    }

    /**
     * SEO 캐시 사용 여부를 읽습니다.
     *
     * 캐시가 듣지 않으면 크롤러 하나가 서버를 계속 갈아 대므로 운영자가 봐야 합니다.
     *
     * @param  Response  $response  응답
     * @return string 캐시 상태 (hit, miss, bypass, none)
     */
    private function cacheStatus(Response $response): string
    {
        $header = strtolower((string) $response->headers->get('X-SEO-Cache', ''));

        return in_array($header, ['hit', 'miss', 'bypass'], true) ? $header : 'none';
    }
}
