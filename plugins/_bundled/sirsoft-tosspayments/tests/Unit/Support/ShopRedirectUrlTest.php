<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Support;

use Illuminate\Support\Facades\Config;
use Plugins\Sirsoft\Tosspayments\Support\ShopRedirectUrl;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 결제 리다이렉트 주소가 상점 주소 설정을 따르는지 검증한다 (공개 #85).
 *
 * 상점 주소는 운영자 설정이다. 기본값을 `/shop/...` 리터럴로 두면 주소를 바꾼 상점에서
 * 결제를 마친 구매자가 존재하지 않는 페이지로 떨어지는데, 리다이렉트라 예외도 로그도
 * 남지 않아 증상이 드러나지 않는다.
 *
 * @scenario case=payment_redirect_follows_shop_route_path
 *
 * @effects payment_redirect_follows_route_path, payment_redirect_drops_segment_when_no_route,
 *          operator_supplied_redirect_url_is_preserved
 *
 * @group payment
 */
class ShopRedirectUrlTest extends PluginTestCase
{
    /**
     * 상점 주소 설정을 주입합니다.
     *
     * @param  array<string, mixed>  $basicInfo  `basic_info` 하위 설정
     */
    private function setShopSettings(array $basicInfo): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.basic_info', $basicInfo);
    }

    public function test_설정이_없으면_기본_상점_주소를_쓴다(): void
    {
        $this->setShopSettings([]);

        $this->assertSame(
            '/shop/orders/20260807-0001/complete',
            ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_SUCCESS_URL, ['{orderId}' => '20260807-0001'])
        );
        $this->assertSame('/shop/checkout', ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_FAIL_URL));
    }

    public function test_운영자가_바꾼_상점_주소를_따른다(): void
    {
        $this->setShopSettings(['route_path' => 'store']);

        $this->assertSame(
            '/store/orders/20260807-0001/complete',
            ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_SUCCESS_URL, ['{orderId}' => '20260807-0001'])
        );
        $this->assertSame('/store/checkout', ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_FAIL_URL));
    }

    public function test_주소_없이_운영하면_루트에_붙는다(): void
    {
        $this->setShopSettings(['no_route' => true]);

        $this->assertSame(
            '/orders/20260807-0001/complete',
            ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_SUCCESS_URL, ['{orderId}' => '20260807-0001'])
        );
        $this->assertSame('/checkout', ShopRedirectUrl::resolve(ShopRedirectUrl::DEFAULT_FAIL_URL));
    }

    public function test_운영자가_직접_넣은_주소는_그대로_쓴다(): void
    {
        $this->setShopSettings(['route_path' => 'store']);

        // 자리표시자가 없으면 손대지 않는다 — 절대 URL 의 '//' 도 보존되어야 한다.
        $this->assertSame(
            'https://pay.example.com/done/20260807-0001',
            ShopRedirectUrl::resolve('https://pay.example.com/done/{orderId}', ['{orderId}' => '20260807-0001'])
        );
        $this->assertSame('/my/custom/checkout', ShopRedirectUrl::resolve('/my/custom/checkout'));
    }
}
