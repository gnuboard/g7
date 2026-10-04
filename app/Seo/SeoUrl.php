<?php

namespace App\Seo;

use App\Support\SiteUrl;

/**
 * 봇 화면이 쓰는 절대 URL 의 단일 출처
 *
 * Laravel `url()` 은 **현재 요청의 Host 헤더**로 절대 URL 을 만든다. Host 는 방문자가 마음대로
 * 적어 보낼 수 있는 값이라, 봇 화면이 `url()` 로 canonical·og:url·hreflang·이미지 주소를 만들면
 * 위조 Host 로 그린 화면이 캐시에 남아 이후 정상 방문자에게 그대로 나갔다. 같은 값으로 내부 API
 * 주소를 만들던 데이터소스 호출은 서버가 공격자가 고른 호스트·포트로 요청을 보내는 통로였다.
 *
 * 그래서 봇 화면의 절대 URL 은 요청이 아니라 운영자가 정한 사이트 주소(`config('app.url')`)로
 * 만든다. 같은 사이트를 여러 도메인으로 서빙해도 canonical 은 사이트 주소 하나로 모인다(의도).
 *
 * 실제 계산은 코어 공용 {@see SiteUrl} 에 위임한다 — SEO 호출부가 쓰는 이름만 남긴다.
 */
final class SeoUrl
{
    /**
     * 사이트 주소(끝 슬래시 제거)를 반환합니다.
     *
     * 비어 있으면 빈 문자열을 돌려준다 — 그때 `absolute()` 는 상대 경로를 만든다. 요청 Host 로
     * 되돌아가면 이 클래스를 둔 이유가 사라지므로 폴백하지 않는다.
     *
     * @return string 사이트 주소 (예: `https://example.com`)
     */
    public static function base(): string
    {
        return SiteUrl::base();
    }

    /**
     * 경로를 사이트 주소 기준 절대 URL 로 바꿉니다.
     *
     * `url()` 과 같은 모양을 낸다 — 이미 완전한 URL(`https://`·`//`·`mailto:` 등)은 그대로 두고,
     * 끝 슬래시를 떼며, 쿼리 문자열은 보존한다. 다른 점은 기준 주소가 요청 Host 가 아니라는 것뿐이다.
     *
     * @param  string  $path  경로 (예: `/products/1?page=2`)
     * @return string 절대 URL
     */
    public static function absolute(string $path): string
    {
        return SiteUrl::absolute($path);
    }

    /**
     * 사이트 주소의 origin(scheme://host[:port])을 반환합니다.
     *
     * 캐시 키의 이름공간으로 쓴다. 요청 origin 이 아니라 사이트 주소에서 뽑는 이유는
     * `SeoCacheManager::buildKey()` 에 적었다.
     *
     * @return string 정규화된 origin (사이트 주소가 없으면 빈 문자열)
     */
    public static function origin(): string
    {
        return SiteUrl::origin();
    }
}
