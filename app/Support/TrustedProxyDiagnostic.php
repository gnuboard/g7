<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * 신뢰 프록시 설정 진단 (#124)
 *
 * 리버스 프록시 뒤에서 신뢰 프록시가 설정되지 않으면 요청 객체가 프록시를 방문자로 인식한다.
 * HTTPS 종단 구성에서는 화면이 백지가 되어 곧바로 드러나지만, HTTP 전용 사이트가 프록시 뒤에
 * 있는 구성(Cloudflare flexible SSL, 사내 LB)에서는 **화면이 완전히 정상 렌더된다.** 그 상태로
 * webhook 403 · IP 기록 왜곡 · 로그인 제한 붕괴 · GeoIP 오판정이 계속되며, 사용자도 운영자도
 * 이상을 감지할 단서가 없다. 능동 경고가 없으면 영구 미발견이다.
 *
 * 그래서 판정식은 "HTTPS 인식 실패" 가 아니라 다음이다:
 *
 *     X-Forwarded-* 헤더를 수신 중  AND  config('trustedproxy.proxies') 가 비어 있음
 *
 * 이 판정은 여기 한 곳에서만 계산하고 네 노출면(대시보드 알림 · 환경설정 고급 탭 ·
 * 설치 마법사 · Artisan 커맨드)이 그것을 소비한다. 면마다 조건을 다시 쓰면 한 곳만
 * 어긋나도 서로 다른 답을 내놓는다.
 *
 * @since 7.0.10
 */
class TrustedProxyDiagnostic
{
    /** 프록시 헤더 수신 중인데 신뢰 설정이 없음 — 조치가 필요한 상태 */
    public const STATUS_WARNING = 'warning';

    /** 정상 — 설정이 되어 있거나, 프록시 헤더가 아예 없는 직접 노출 구성 */
    public const STATUS_OK = 'ok';

    /** 요청이 없는 맥락(콘솔 등) — "값 없음" 이 아니라 "판정 불가" 다 */
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    /**
     * Laravel 내장 TrustProxies 미들웨어가 신뢰하는 전달 헤더 목록.
     *
     * 미들웨어는 PROTO·FOR·HOST·PORT·PREFIX·AWS_ELB 를 함께 신뢰하므로, 그중 하나라도
     * 수신 중이면 앞단에 무언가가 있다는 신호다.
     *
     * @var array<int, string>
     */
    public const FORWARDED_HEADERS = [
        'X-Forwarded-For',
        'X-Forwarded-Proto',
        'X-Forwarded-Host',
        'X-Forwarded-Port',
        'X-Forwarded-Prefix',
        'X-Forwarded-Aws-Elb',
        'Forwarded',
    ];

    /**
     * 요청 1건에 대한 신뢰 프록시 진단 결과를 반환합니다.
     *
     * @param  Request|null  $request  진단 대상 요청 (콘솔 등 요청이 없는 맥락이면 null)
     * @return array{forwarded_headers: array<int, string>, trusted_configured: bool, configured_proxies: string|null, is_secure: bool|null, client_ip: string|null, remote_addr: string|null, status: string} 진단 결과
     */
    public static function forRequest(?Request $request): array
    {
        $configured = self::configuredProxies();
        $trustedConfigured = self::isConfigured();

        if ($request === null) {
            return [
                'forwarded_headers' => [],
                'trusted_configured' => $trustedConfigured,
                'configured_proxies' => $configured,
                'is_secure' => null,
                'client_ip' => null,
                'remote_addr' => null,
                'status' => self::STATUS_NOT_APPLICABLE,
            ];
        }

        $forwarded = self::forwardedHeaders($request);

        return [
            'forwarded_headers' => $forwarded,
            'trusted_configured' => $trustedConfigured,
            'configured_proxies' => $configured,
            'is_secure' => $request->isSecure(),
            'client_ip' => $request->ip(),
            'remote_addr' => $request->server('REMOTE_ADDR'),
            'status' => ($forwarded !== [] && ! $trustedConfigured)
                ? self::STATUS_WARNING
                : self::STATUS_OK,
        ];
    }

    /**
     * 요청이 수신 중인 전달 헤더의 이름 목록을 반환합니다.
     *
     * @param  Request  $request  대상 요청
     * @return array<int, string> 수신 중인 헤더 이름 목록
     */
    public static function forwardedHeaders(Request $request): array
    {
        $present = [];

        foreach (self::FORWARDED_HEADERS as $header) {
            if ($request->headers->has($header)) {
                $present[] = $header;
            }
        }

        return $present;
    }

    /**
     * 신뢰 프록시가 설정되어 있는지 반환합니다.
     *
     * 빈 문자열은 미설정과 같게 다룬다 — `.env` 에 `TRUSTED_PROXIES=` 만 남겨 둔 상태가
     * "설정됨" 으로 판정되면 경고가 조용히 사라지는데, 미들웨어는 여전히 아무것도 신뢰하지 않는다.
     *
     * @return bool 설정 여부
     */
    public static function isConfigured(): bool
    {
        return self::configuredProxies() !== null;
    }

    /**
     * 설정된 신뢰 프록시 값을 반환합니다 (미설정이면 null).
     *
     * @return string|null 설정값
     */
    public static function configuredProxies(): ?string
    {
        $proxies = config('trustedproxy.proxies');

        if (is_array($proxies)) {
            $proxies = implode(',', $proxies);
        }

        if (! is_string($proxies)) {
            return null;
        }

        $proxies = trim($proxies);

        return $proxies === '' ? null : $proxies;
    }
}
