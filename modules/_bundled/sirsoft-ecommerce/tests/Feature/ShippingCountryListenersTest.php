<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Listeners\AssignDefaultShippingCountryOnRegisterListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserShippingCountryInfoListener;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송국가 리스너 테스트 (MP08 후속)
 *
 * - UserShippingCountryInfoListener: UserResource 응답에 배송국가+현지화명 주입(filter)
 * - AssignDefaultShippingCountryOnRegisterListener: 가입 시 제출값 우선/resolve 폴백 부여
 */
class ShippingCountryListenersTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.default_country', 'KR');
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국', 'en' => 'United States'], 'is_active' => true],
        ]);
    }

    public function test_info_listener_injects_country_and_localized_name(): void
    {
        app()->setLocale('ko');
        $user = User::factory()->create();
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredShippingCountry($user->id, 'US');

        $listener = app(UserShippingCountryInfoListener::class);
        $result = $listener->injectPreferredShippingCountry([], $user);

        $this->assertSame('US', $result['ecommerce_preferred_shipping_country']);
        // 현재 로케일(ko) 의 available_countries 국가명
        $this->assertSame('미국', $result['ecommerce_preferred_shipping_country_name']);
    }

    public function test_info_listener_injects_null_when_unset(): void
    {
        $user = User::factory()->create();

        $listener = app(UserShippingCountryInfoListener::class);
        $result = $listener->injectPreferredShippingCountry([], $user);

        $this->assertNull($result['ecommerce_preferred_shipping_country']);
        $this->assertNull($result['ecommerce_preferred_shipping_country_name']);
    }

    public function test_register_listener_uses_submitted_country_when_active(): void
    {
        $user = User::factory()->create();

        $listener = app(AssignDefaultShippingCountryOnRegisterListener::class);
        $listener->handleRegister($user, [
            'registration_data' => ['preferred_shipping_country' => 'US'],
        ]);

        $this->assertSame(
            'US',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_register_listener_falls_back_to_default_when_submitted_inactive(): void
    {
        $user = User::factory()->create();

        $listener = app(AssignDefaultShippingCountryOnRegisterListener::class);
        // JP 는 비활성 → resolve(null, ip) → default(KR)
        $listener->handleRegister($user, [
            'registration_data' => ['preferred_shipping_country' => 'JP'],
        ]);

        $this->assertSame(
            'KR',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_register_listener_does_not_overwrite_existing(): void
    {
        $user = User::factory()->create();
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredShippingCountry($user->id, 'US');

        $listener = app(AssignDefaultShippingCountryOnRegisterListener::class);
        $listener->handleRegister($user, [
            'registration_data' => ['preferred_shipping_country' => 'KR'],
        ]);

        // 이미 설정돼 있으면 보존
        $this->assertSame(
            'US',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_register_validation_rule_rejects_inactive_country(): void
    {
        $listener = app(AssignDefaultShippingCountryOnRegisterListener::class);
        $rules = $listener->addShippingCountryRule([]);

        $this->assertArrayHasKey('preferred_shipping_country', $rules);

        // 비활성 국가(JP)는 closure 규칙에서 실패해야 함
        $failed = false;
        $closure = collect($rules['preferred_shipping_country'])->first(fn ($r) => $r instanceof \Closure);
        $closure('preferred_shipping_country', 'JP', function () use (&$failed) {
            $failed = true;
        });
        $this->assertTrue($failed, '비활성 국가는 가입 검증에서 거부되어야 합니다.');
    }
}
