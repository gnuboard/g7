<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use Modules\Sirsoft\Ecommerce\Support\ShopPathResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 상점 경로 해석기 단위 테스트
 *
 * 상점 주소는 운영자가 바꿀 수 있고(`basic_info.route_path`), 아예 주소 없이 루트에
 * 붙일 수도 있다(`basic_info.no_route`). 그런데 주문 완료 이동·알림 메일·검색 결과·
 * 사이트맵이 각자 `/shop` 을 문자열로 조립해 왔다. 기본값을 쓰지 않는 상점에서는
 * 그 주소가 전부 존재하지 않는 페이지를 가리킨다 — 예외도 404 로그도 남지 않고
 * 링크만 조용히 죽는다.
 *
 * 이 테스트는 해석 규칙 자체를 고정한다. 소비 지점의 반영 여부는 각 지점 테스트가 본다.
 *
 * 결제 플러그인 4종의 리다이렉트 주소도 이 해석기를 SSoT 로 삼는다
 * (plugins/_bundled/sirsoft-tosspayments/tests/scenarios/payment-redirect-shop-route-path.yaml).
 *
 * @scenario case=payment_redirect_follows_shop_route_path
 *
 * @effects payment_redirect_follows_route_path, payment_redirect_drops_segment_when_no_route,
 *          default_shop_redirect_unchanged
 */
class ShopPathResolverTest extends ModuleTestCase
{
    /**
     * 상점 기본 설정을 주입합니다.
     *
     * 해석기는 `g7_module_settings()` 를 통해 설정을 읽으므로 부팅 시 적재되는
     * `g7_settings.modules.*` 배열을 직접 주입해 파일 I/O 없이 축을 만든다.
     *
     * @param  array<string, mixed>  $basicInfo  basic_info 설정 값
     */
    private function setBasicInfo(array $basicInfo): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', $basicInfo);
    }

    #[Test]
    public function 설정이_없으면_기본_상점_주소로_폴백한다(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce', []);

        $this->assertSame('/shop', ShopPathResolver::base());
        $this->assertSame('/shop/products/A1', ShopPathResolver::path('products/A1'));
    }

    #[Test]
    public function 운영자가_바꾼_주소를_반영한다(): void
    {
        $this->setBasicInfo(['route_path' => 'store']);

        $this->assertSame('/store', ShopPathResolver::base());
        $this->assertSame('/store/orders/20260806-0001/complete', ShopPathResolver::path('orders/20260806-0001/complete'));
    }

    #[Test]
    public function 주소_없이_운영하면_루트에_붙는다(): void
    {
        $this->setBasicInfo(['route_path' => 'shop', 'no_route' => true]);

        $this->assertSame('', ShopPathResolver::base());
        $this->assertSame('/products/A1', ShopPathResolver::path('products/A1'));
        $this->assertSame('/', ShopPathResolver::path(''));
    }

    #[Test]
    public function 주소가_빈_문자열이면_기본값으로_폴백한다(): void
    {
        $this->setBasicInfo(['route_path' => '']);

        $this->assertSame('/shop', ShopPathResolver::base());
        $this->assertSame('/shop/guest/orders', ShopPathResolver::path('guest/orders'));
    }

    #[Test]
    public function 앞뒤_슬래시가_붙은_주소도_한_겹으로_정규화한다(): void
    {
        $this->setBasicInfo(['route_path' => '/store/']);

        $this->assertSame('/store', ShopPathResolver::base());
        $this->assertSame('/store/products', ShopPathResolver::path('/products'));
        $this->assertSame('/store/products', ShopPathResolver::path('products'));
    }

    #[Test]
    public function no_route_가_꺼져_있으면_주소를_유지한다(): void
    {
        $this->setBasicInfo(['route_path' => 'store', 'no_route' => false]);

        $this->assertSame('/store', ShopPathResolver::base());
        $this->assertSame('/store/category/tops', ShopPathResolver::path('category/tops'));
    }
}
