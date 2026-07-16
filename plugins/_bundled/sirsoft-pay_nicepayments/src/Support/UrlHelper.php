<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Support;

/**
 * 결제 콜백 redirect URL 빌더
 *
 * Laravel 의 `redirect('/path')` 는 내부적으로 `URL::to('/path')` 로 절대 URL 을 만드는데,
 * reverse proxy 뒤 PHP-FPM 환경에서 `Request::root()` 가 `http://localhost` 로
 * 떨어지는 경우가 있다 (TrustProxies 미구성, mod_proxy_fcgi 의 Host 미전달 등).
 *
 * 결제 콜백은 외부 요청 헤더를 신뢰하면 redirect origin 이 흔들릴 수 있으므로,
 * 모든 상대 경로는 운영자가 관리하는 config('app.url') 기준으로 절대화한다.
 */
final class UrlHelper
{
    /**
     * 상대 경로를 절대 URL 로 변환
     *
     * 입력이 이미 `http://` / `https://` 로 시작하면 그대로 반환한다.
     * 상대 경로는 신뢰 가능한 설정값인 config('app.url') 기준으로 절대화한다.
     *
     * @param  string  $urlOrPath  상대 경로 또는 절대 URL
     * @return string 절대 URL
     */
    public static function toAbsolute(string $urlOrPath): string
    {
        if (preg_match('#^https?://#i', $urlOrPath) === 1) {
            return $urlOrPath;
        }

        $origin = rtrim((string) config('app.url', 'http://localhost'), '/');
        if ($origin === '') {
            $origin = 'http://localhost';
        }

        return rtrim($origin, '/') . '/' . ltrim($urlOrPath, '/');
    }
}
