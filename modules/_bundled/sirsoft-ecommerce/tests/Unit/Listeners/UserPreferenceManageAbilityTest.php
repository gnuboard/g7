<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Listeners\UserCurrencyInfoListener;
use Modules\Sirsoft\Ecommerce\Listeners\UserShippingCountryInfoListener;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 응답의 결제 통화·배송국가 변경 권한 키 테스트 (7.1.0 abilities 게이트 ecom-backend#9)
 *
 * 관리자 회원 상세의 결제 통화·배송국가 칸을 잠그는 키다. 저장 검증(`UserPreferenceCoreSaveListener`)과 같은 권한이다.
 */
class UserPreferenceManageAbilityTest extends ModuleTestCase
{
    /**
     * 결제 통화 칸 잠금 키가 `user-currency.manage` 를 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=missing
     *
     * @effects user_currency_field_follows_the_manage_permission
     */
    public function test_currency_manage_ability_follows_the_permission(): void
    {
        $user = User::factory()->create();
        $listener = app(UserCurrencyInfoListener::class);

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.user-currency.manage']));
        $this->assertTrue($listener->injectPreferredCurrency([], $user)['ecommerce_currency_can_manage']);

        $this->actingAs($this->createAdminUser([]));
        $this->assertFalse($listener->injectPreferredCurrency([], $user)['ecommerce_currency_can_manage']);
    }

    /**
     * 배송국가 칸 잠금 키가 `user-shipping-country.manage` 를 따른다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=admin, permission=granted
     *
     * @effects user_shipping_country_field_follows_the_manage_permission
     */
    public function test_shipping_country_manage_ability_follows_the_permission(): void
    {
        $user = User::factory()->create();
        $listener = app(UserShippingCountryInfoListener::class);

        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.user-shipping-country.manage']));
        $this->assertTrue($listener->injectPreferredShippingCountry([], $user)['ecommerce_shipping_country_can_manage']);

        $this->actingAs($this->createAdminUser([]));
        $this->assertFalse($listener->injectPreferredShippingCountry([], $user)['ecommerce_shipping_country_can_manage']);
    }
}
