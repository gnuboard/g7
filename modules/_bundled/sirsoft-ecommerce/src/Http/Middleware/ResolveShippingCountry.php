<?php

namespace Modules\Sirsoft\Ecommerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * 요청별 배송국가 결정 미들웨어 (MP08 후속 — D2/D11)
 *
 * SetTimezone 패턴(App::instance + static accessor)을 미러한다. 커머스 스토어프론트
 * 라우트 그룹(cart/checkout/products 등)에 적용해 요청마다 배송국가를 결정하고 컨테이너에
 * 보관한다. CartItemResource/CheckoutItemResource 등이 static accessor 로 읽는다.
 *
 * 우선순위:
 *   1) X-Shipping-Country 헤더(D11) — 활성 국가일 때만 즉시 채택(비로그인 헤더 즉시반영)
 *   2) ShippingCountryResolver::resolve(저장 → GeoIP → default)
 */
class ResolveShippingCountry
{
    /**
     * 애플리케이션 컨테이너에 저장되는 배송국가 키
     */
    public const SHIPPING_COUNTRY_KEY = 'resolved_shipping_country';

    public function __construct(
        private ShippingCountryResolver $resolver
    ) {}

    /**
     * 들어오는 요청을 처리하고 배송국가를 결정해 컨테이너에 보관합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @param  Closure  $next  다음 미들웨어 또는 요청 핸들러
     * @return Response HTTP 응답
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::instance(self::SHIPPING_COUNTRY_KEY, $this->determineCountry($request));

        return $next($request);
    }

    /**
     * 요청의 배송국가를 결정합니다.
     *
     * @param  Request  $request  HTTP 요청
     * @return string 결정된 배송국가 코드 (항상 활성 국가)
     */
    private function determineCountry(Request $request): string
    {
        // 1. X-Shipping-Country 헤더 (비로그인 즉시 반영, 활성 국가만 채택)
        $header = $request->header('X-Shipping-Country');
        if (is_string($header) && $this->resolver->isAllowed($header)) {
            return strtoupper($header);
        }

        // 2. 저장 → GeoIP → default
        return $this->resolver->resolve(
            Auth::check() ? (int) Auth::id() : null,
            $request->ip()
        );
    }

    /**
     * 현재 요청에 결정된 배송국가를 가져옵니다.
     *
     * 미들웨어가 실행되지 않은 경우 default_country 로 폴백합니다.
     *
     * @return string 현재 배송국가 코드
     */
    public static function getCountry(): string
    {
        if (App::bound(self::SHIPPING_COUNTRY_KEY)) {
            return App::make(self::SHIPPING_COUNTRY_KEY);
        }

        return App::make(ShippingCountryResolver::class)->defaultCountry();
    }
}
