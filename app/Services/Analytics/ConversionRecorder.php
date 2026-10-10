<?php

namespace App\Services\Analytics;

use App\Analytics\TrackingRequestFactory;
use App\Analytics\UrlNormalizer;
use App\Contracts\Analytics\ConversionRecorderInterface;
use App\Enums\Analytics\ActionType;
use App\Support\SiteUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 서버 측 전환 기록기
 *
 * 현재 HTTP 요청으로 수집 요청을 만들어 방문 적재 서비스에 넘깁니다. 같은 쿠키·IP·
 * User-Agent 로 오므로 브라우저 비콘이 연 방문에 그대로 붙습니다. 등록부 조회·속성 검증·
 * 중복 판정은 적재 서비스가 비콘과 같은 규칙으로 합니다 — 입구가 둘이어도 판정은 한곳입니다.
 *
 * 전환이 일어난 주소는 API 주소가 아니라 사용자가 보던 화면이어야 하므로, 같은 사이트의
 * `Referer` 가 있으면 그것을 씁니다.
 */
class ConversionRecorder implements ConversionRecorderInterface
{
    /**
     * 기록기를 생성합니다.
     *
     * @param  VisitService  $visits  방문 적재 서비스
     */
    public function __construct(private readonly VisitService $visits) {}

    /**
     * {@inheritDoc}
     */
    public function record(string $goalKey, array $attributes = [], array $dimensions = []): void
    {
        try {
            $request = app()->bound('request') ? app('request') : null;

            if (! $request instanceof Request) {
                return;
            }

            $this->visits->track(TrackingRequestFactory::fromHttp($request, [
                't' => ActionType::Goal->value,
                'url' => $this->pageUrl($request),
                'goal' => $goalKey,
                'attrs' => $attributes,
                'dims' => $dimensions,
            ], 'server'));
        } catch (\Throwable $e) {
            // 적재 서비스는 스스로 예외를 삼키지만, 요청 조립에서 던질 수도 있다. 전환 기록 때문에
            // 가입·주문이 실패하면 안 된다.
            Log::warning('서버 측 전환 기록 실패', ['goal' => $goalKey, 'error' => $e->getMessage()]);
        }
    }

    /**
     * 전환이 일어난 화면 주소를 정합니다.
     *
     * 보안: 같은 사이트 판정은 요청 Host 가 아니라 사이트 호스트(포트 포함)와 비교한다 — 요청 Host 와 같은
     * Referer 를 믿으면 위조 Host 요청이 임의 주소를 통계에 심는다. 폴백도 사이트 주소 + 요청 경로다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 같은 사이트 Referer, 없으면 사이트 주소 기준 요청 주소
     */
    private function pageUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        $siteHost = $this->siteHostWithPort();

        if ($referer !== '' && $siteHost !== '' && $this->refererHostWithPort($referer) === $siteHost) {
            return $referer;
        }

        return SiteUrl::forPath($request->getRequestUri());
    }

    /**
     * 사이트 호스트(소문자·www 제거)와 사이트 주소에 명시된 포트를 반환합니다.
     *
     * @return string 예: `example.com`, `example.com:8443` (사이트 주소가 없으면 빈 문자열)
     */
    private function siteHostWithPort(): string
    {
        $host = UrlNormalizer::siteHost();

        if ($host === '') {
            return '';
        }

        $port = parse_url(SiteUrl::hostWithPort(), PHP_URL_PORT);

        return is_int($port) ? $host.':'.$port : $host;
    }

    /**
     * Referer 의 호스트를 사이트 호스트와 같은 형태(소문자·www 제거·비표준 포트만 표기)로 반환합니다.
     *
     * @param  string  $referer  Referer 헤더 값
     * @return string 호스트 (해석 불가 시 빈 문자열)
     */
    private function refererHostWithPort(string $referer): string
    {
        $parts = parse_url($referer);

        if (! is_array($parts) || ! isset($parts['host']) || $parts['host'] === '') {
            return '';
        }

        $host = strtolower($parts['host']);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? null;

        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $host;
        }

        return $host.':'.$port;
    }
}
