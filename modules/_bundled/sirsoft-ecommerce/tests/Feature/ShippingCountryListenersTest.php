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
    /**
     * 해외배송을 켜고 배송 가능 국가(KR, US 활성)를 준비합니다.
     *
     * @return void 반환값 없음
     */
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

    /**
     * 회원 응답에 저장된 배송국가와 현지화한 국가명이 실리는지 확인한다.
     *
     * @scenario actor=member, setting_location=profile_view, module_state=intl_on
     *
     * @effects mypage_view_shows_saved_country
     *
     * @return void 반환값 없음
     */
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

    /**
     * 배송국가를 정한 적이 없는 회원의 응답에는 두 키가 null 로 실리는지 확인한다.
     *
     * @scenario actor=member, setting_location=profile_view, module_state=intl_on
     *
     * @effects mypage_view_shows_saved_country
     *
     * @return void 반환값 없음
     */
    public function test_info_listener_injects_null_when_unset(): void
    {
        $user = User::factory()->create();

        $listener = app(UserShippingCountryInfoListener::class);
        $result = $listener->injectPreferredShippingCountry([], $user);

        $this->assertNull($result['ecommerce_preferred_shipping_country']);
        $this->assertNull($result['ecommerce_preferred_shipping_country_name']);
    }

    /**
     * 가입 데이터에 실린 배송국가가 활성 국가면 그 값으로 정해지는지 확인한다 (요청 검증을 거치지 않는 내부 가입 호출의 경로).
     *
     * @scenario actor=member, setting_location=register, module_state=intl_on
     *
     * @effects register_field_prefill_and_persist
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가입 데이터의 배송국가가 비활성이면 기본 국가로 정해지는지 확인한다.
     *
     * @scenario actor=member, setting_location=register, module_state=intl_on
     *
     * @effects resolve_priority_saved_geoip_default
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 배송국가가 정해진 회원은 기본값 지정이 덮어쓰지 않는지 확인한다 (가입 트랜잭션에서 회원 항목 핸들러가 먼저 저장한 값 보존).
     *
     * @scenario actor=member, setting_location=register, module_state=intl_on
     *
     * @effects register_field_prefill_and_persist
     *
     * @return void 반환값 없음
     */
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

    /**
     * 가입 요청에서 비활성 국가는 옛 키로 보내도 회원 항목 키의 검증 오류로 거절된다.
     *
     * 가입 검증은 리스너의 규칙 필터가 아니라 회원 항목 선언(선택지 = 활성 국가)이 한다.
     *
     * @scenario actor=member, setting_location=register, module_state=intl_on
     *
     * @effects inactive_country_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_register_rejects_inactive_country_on_field_key(): void
    {
        $this->syncMemberFieldDeclarations();

        $this->assertArrayNotHasKey(
            'core.auth.register_validation_rules',
            AssignDefaultShippingCountryOnRegisterListener::getSubscribedHooks(),
        );

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'reg_'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'agree_terms' => '1',
            'agree_privacy' => '1',
            'preferred_shipping_country' => 'JP',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('ecommerce_preferred_shipping_country');
    }
}
