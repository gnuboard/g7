<?php

namespace Plugins\Sirsoft\Tosspayments\Support;

use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;

/**
 * 결제 완료·실패 후 돌아갈 상점 화면 주소를 만듭니다.
 *
 * 상점 주소는 운영자 설정이다 — 쇼핑몰 모듈의 `basic_info.route_path` 로 바꿀 수 있고
 * `basic_info.no_route` 를 켜면 세그먼트 없이 루트에 붙는다. 그런데 이 플러그인의
 * 리다이렉트 기본값은 `/shop/...` 리터럴이라, 주소를 바꾼 상점에서는 결제를 마친
 * 구매자가 존재하지 않는 페이지로 떨어졌다. 리다이렉트라 예외도 로그도 남지 않는다.
 *
 * 그래서 기본값에 `{shopBase}` 자리표시자를 두고, 실제 이동 직전에 쇼핑몰 모듈의
 * 해석기(SSoT)가 계산한 기준 경로로 바꾼다. 운영자가 절대 URL(`https://...`)이나
 * 자기 경로를 직접 넣었다면 자리표시자가 없으므로 입력값이 그대로 쓰인다.
 *
 * @since 1.0.0
 */
final class ShopRedirectUrl
{
    /** 결제 성공 후 이동할 기본 주소 */
    public const DEFAULT_SUCCESS_URL = '{shopBase}/orders/{orderId}/complete';

    /** 결제 실패 후 이동할 기본 주소 */
    public const DEFAULT_FAIL_URL = '{shopBase}/checkout';

    /**
     * 자리표시자를 실제 값으로 바꾼 주소를 반환합니다.
     *
     * `{shopBase}` 는 항상 치환한다 — 주소 없이 운영하는 상점(`no_route`)에서는 빈
     * 문자열이 되어 `{shopBase}/checkout` 이 `/checkout` 으로 접힌다.
     *
     * 슬래시 중복 정규화는 하지 않는다. 운영자가 절대 URL 을 넣었을 때 `https://` 의
     * 이중 슬래시까지 접어 버리기 때문이다.
     *
     * @param  string  $template  설정에 저장된 주소 템플릿
     * @param  array<string, string>  $tokens  추가 치환 토큰 (예: `['{orderId}' => '20260807-0001']`)
     * @return string 치환이 끝난 주소
     */
    public static function resolve(string $template, array $tokens = []): string
    {
        return strtr($template, ['{shopBase}' => ShopPathResolver::base()] + $tokens);
    }
}
