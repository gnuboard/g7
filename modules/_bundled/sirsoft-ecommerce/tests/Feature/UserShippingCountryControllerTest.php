<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 유저 배송국가 컨트롤러 Feature 테스트 (MP08 후속)
 *
 * - 인증 유저가 활성 국가로 변경 → 영속 저장 (golden path)
 * - 비활성 국가 → 422 (유효성 실패)
 * - 비인증 → 401 (권한 경계)
 * - GET 으로 현재 배송국가 조회
 */
class UserShippingCountryControllerTest extends ModuleTestCase
{
    private const UPDATE_URL = '/api/modules/sirsoft-ecommerce/user/shipping-country';

    protected function setUp(): void
    {
        parent::setUp();
        $this->enableInternationalShipping(['KR', 'US']);
    }

    private function enableInternationalShipping(array $activeCodes): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => in_array('KR', $activeCodes, true)],
            ['code' => 'US', 'name' => ['ko' => '미국', 'en' => 'United States'], 'is_active' => in_array('US', $activeCodes, true)],
            ['code' => 'JP', 'name' => ['ko' => '일본', 'en' => 'Japan'], 'is_active' => in_array('JP', $activeCodes, true)],
        ]);
    }

    public function test_authenticated_user_can_update_to_active_country(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::UPDATE_URL, ['shipping_country' => 'US']);

        $response->assertOk();
        $this->assertSame(
            'US',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_lowercase_country_is_normalized_to_uppercase(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson(self::UPDATE_URL, ['shipping_country' => 'us']);

        $response->assertOk();
        $this->assertSame(
            'US',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_inactive_country_is_rejected_422(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // JP 는 활성 목록에 없음 → 422
        $response = $this->putJson(self::UPDATE_URL, ['shipping_country' => 'JP']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('shipping_country');
        $this->assertNull(
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($user->id)
        );
    }

    public function test_guest_cannot_update_shipping_country(): void
    {
        $response = $this->putJson(self::UPDATE_URL, ['shipping_country' => 'US']);

        $response->assertStatus(401);
    }

    public function test_show_returns_current_shipping_country(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        app(EcommerceUserProfileRepositoryInterface::class)->setPreferredShippingCountry($user->id, 'US');

        $response = $this->getJson(self::UPDATE_URL);

        $response->assertOk();
        $response->assertJsonPath('data.preferred_shipping_country', 'US');
    }
}
