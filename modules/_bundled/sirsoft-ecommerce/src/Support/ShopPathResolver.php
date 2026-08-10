<?php

namespace Modules\Sirsoft\Ecommerce\Support;

/**
 * 상점 화면 경로의 SSoT.
 *
 * 상점 주소는 운영자 설정이다 — `basic_info.route_path` 로 바꿀 수 있고,
 * `basic_info.no_route` 를 켜면 주소 세그먼트 없이 루트에 붙는다. 그런데 서버가
 * 만들어 내보내는 상점 주소(주문 완료 이동 · 비회원 주문 조회 안내 · 알림 메일의
 * 주문 조회 링크 · 통합검색 결과 링크 · 사이트맵/SEO 캐시 URL)는 각 지점에서
 * `/shop` 리터럴이나 `"/{$routePath}/..."` 조립으로 흩어져 있었다.
 *
 * 기본값이 아닌 상점에서는 그 주소가 존재하지 않는 페이지를 가리키는데, 서버는
 * 문자열을 만들어 내려보냈을 뿐이라 예외도 로그도 남지 않는다. 링크만 조용히 죽는다.
 *
 * 설정 접근은 `g7_module_settings()` 로 고정한다. 매 요청 부팅 시 적재된 배열을 읽으므로
 * 파일 I/O 가 없고, 테스트에서 `Config::set('g7_settings.modules.*')` 로 축을 주입할 수 있다.
 *
 * ## 공개 계약 (다른 확장이 소비한다)
 *
 * 상점 주소가 필요한 확장은 자체 계산을 두지 말고 이 클래스를 거친다. 결제 플러그인
 * 4종(tosspayments / pay_kginicis / pay_nhnkcp / pay_nicepayments)이 결제 완료·실패
 * 리다이렉트 주소를 만들 때 `base()` 를 호출한다. 같은 계산이 여러 벌로 흩어지면
 * 주소 규칙이 바뀔 때 한 곳을 빠뜨리게 되고, 그 확장에서만 링크가 조용히 죽는다.
 *
 * 소비 확장은 `plugin.json` / `module.json` 의 `dependencies.modules.sirsoft-ecommerce`
 * 를 이 계약이 도입된 `>=1.1.0` 이상으로 선언해야 한다.
 *
 * 시그니처를 바꾸거나 제거할 때는 위 소비 확장을 전수 확인하고 각 확장의 최소 버전
 * 제약을 함께 올린다 (CLAUDE.md "버전 동기화 의무" ②).
 *
 * @since 1.1.0
 */
class ShopPathResolver
{
    /** 상점 주소 설정 키 */
    public const ROUTE_PATH_KEY = 'basic_info.route_path';

    /** 주소 없이 운영 여부 설정 키 */
    public const NO_ROUTE_KEY = 'basic_info.no_route';

    /** 기본 상점 주소 (설정 미지정·빈 값 시 폴백) */
    public const DEFAULT_ROUTE_PATH = 'shop';

    /**
     * 상점 화면의 기준 경로를 반환합니다.
     *
     * `no_route` 가 켜져 있으면 빈 문자열이다 — 상점이 루트에 붙어 있어 앞에 붙일
     * 세그먼트가 없다는 뜻이며, `path()` 가 이를 이중 슬래시 없이 이어 붙인다.
     *
     * @return string 앞에만 슬래시가 붙은 기준 경로 (예: `/shop`), 주소 미사용 시 빈 문자열
     */
    public static function base(): string
    {
        if (g7_module_settings('sirsoft-ecommerce', self::NO_ROUTE_KEY)) {
            return '';
        }

        $routePath = trim((string) g7_module_settings('sirsoft-ecommerce', self::ROUTE_PATH_KEY, self::DEFAULT_ROUTE_PATH), '/');

        return '/'.($routePath !== '' ? $routePath : self::DEFAULT_ROUTE_PATH);
    }

    /**
     * 기준 경로에 하위 경로를 이어 붙인 상점 화면 경로를 반환합니다.
     *
     * 슬래시 중복은 한 겹으로 정규화한다 (`no_route` + 앞 슬래시 붙은 하위 경로 조합에서
     * `//products` 가 만들어지는 것을 막는다). 코어 라우트 해석기가 같은 이유로 같은
     * 정규화를 수행한다 (`app/Seo/TemplateRouteResolver.php`).
     *
     * @param  string  $suffix  기준 경로 하위의 화면 경로 (앞 슬래시 유무 무관)
     * @return string 정규화된 상점 화면 경로
     */
    public static function path(string $suffix): string
    {
        return (string) preg_replace('#/+#', '/', self::base().'/'.ltrim($suffix, '/'));
    }
}
