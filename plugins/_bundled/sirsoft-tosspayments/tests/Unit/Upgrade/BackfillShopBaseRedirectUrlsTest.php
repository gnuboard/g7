<?php

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Upgrade;

use App\Upgrades\Data\Ext\Plugins\SirsoftTosspayments\V1_0_0\Migrations\BackfillShopBaseRedirectUrls;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 기설치본의 결제 리다이렉트 주소 백필 규칙 (공개 #85).
 *
 * 코드 기본값이 `{shopBase}` 자리표시자로 바뀌어도, 이미 설치를 마친 사이트는 예전
 * 리터럴이 설정 파일에 저장돼 있어 그대로 깨진 채로 남는다. 백필이 그 저장값만 바꾸되
 * **운영자가 직접 넣은 주소는 건드리지 않아야** 한다.
 *
 * 판단 규칙만 순수 함수로 검증한다 — 실제 설정 파일(storage/app/plugins/...)은 개발
 * 환경의 실데이터이므로 테스트가 건드리지 않는다.
 *
 * @scenario case=payment_redirect_follows_shop_route_path
 *
 * @effects legacy_default_redirect_is_backfilled, operator_custom_redirect_survives_backfill,
 *          redirect_backfill_is_idempotent
 *
 * @group payment
 * @group upgrade
 */
class BackfillShopBaseRedirectUrlsTest extends PluginTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 은 AbstractUpgradeStep 이 동적으로 require 하므로 일반 autoload 대상이 아니다.
        require_once base_path('plugins/_bundled/sirsoft-tosspayments/upgrades/data/1.0.0/migrations/BackfillShopBaseRedirectUrls.php');
    }

    public function test_예전_기본값이_저장돼_있으면_자리표시자로_바꾼다(): void
    {
        [$settings, $updated] = BackfillShopBaseRedirectUrls::apply([
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);

        $this->assertSame('{shopBase}/orders/{orderId}/complete', $settings['redirect_success_url']);
        $this->assertSame('{shopBase}/checkout', $settings['redirect_fail_url']);
        $this->assertSame(['redirect_success_url', 'redirect_fail_url'], $updated);
    }

    public function test_운영자가_직접_넣은_주소는_건드리지_않는다(): void
    {
        $custom = [
            'redirect_success_url' => 'https://pay.example.com/done/{orderId}',
            'redirect_fail_url' => '/my/custom/checkout',
        ];

        [$settings, $updated] = BackfillShopBaseRedirectUrls::apply($custom);

        $this->assertSame($custom, $settings);
        $this->assertSame([], $updated);
    }

    public function test_한쪽만_예전_기본값이면_그쪽만_바꾼다(): void
    {
        [$settings, $updated] = BackfillShopBaseRedirectUrls::apply([
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => 'https://pay.example.com/fail',
        ]);

        $this->assertSame('{shopBase}/orders/{orderId}/complete', $settings['redirect_success_url']);
        $this->assertSame('https://pay.example.com/fail', $settings['redirect_fail_url']);
        $this->assertSame(['redirect_success_url'], $updated);
    }

    public function test_재실행해도_변화가_없다(): void
    {
        [$once] = BackfillShopBaseRedirectUrls::apply([
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ]);

        [$twice, $updated] = BackfillShopBaseRedirectUrls::apply($once);

        $this->assertSame($once, $twice);
        $this->assertSame([], $updated);
    }

    public function test_키가_없으면_추가하지_않는다(): void
    {
        [$settings, $updated] = BackfillShopBaseRedirectUrls::apply(['is_test_mode' => true]);

        $this->assertSame(['is_test_mode' => true], $settings);
        $this->assertSame([], $updated);
    }
}
