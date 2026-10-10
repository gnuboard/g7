<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 회원 배송국가 컨트롤러 Feature 테스트 (MP08 후속)
 *
 * - 권한 보유 관리자가 특정 회원의 배송국가 변경 → 영속 저장 + 활동 로그
 * - 비활성 국가 → 422
 * - 권한 없는 사용자 → 403
 * - 회원 식별은 UUID (관리자 회원 URL 규약)
 */
class AdminUserShippingCountryControllerTest extends ModuleTestCase
{
    /**
     * 테스트 준비 — 필요한 서비스와 임시 자원을 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.international_shipping_enabled', true);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국'], 'is_active' => true],
            ['code' => 'JP', 'name' => ['ko' => '일본'], 'is_active' => false],
        ]);
    }

    /**
     * 회원별 변경 API 주소를 만든다.
     *
     * @param  string  $uuid  uuid 인자
     * @return string 문자열
     */
    private function url(string $uuid): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/users/{$uuid}/shipping-country";
    }

    /**
     * 권한 있는 관리자는 회원 배송국가를 바꿀 수 있다(폐기 예고 API 호환).
     *
     * @effects admin_with_permission_can_change_user_shipping_country
     *
     * @return void 반환값 없음
     */
    public function test_admin_with_permission_can_change_user_shipping_country(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-shipping-country.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['shipping_country' => 'US']);

        $response->assertOk();
        $this->assertSame(
            'US',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredShippingCountry($target->id)
        );
    }

    /**
     * 비활성 국가는 422 로 거부된다.
     *
     * @effects inactive_country_rejected_422
     *
     * @return void 반환값 없음
     */
    public function test_inactive_country_rejected_422(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-shipping-country.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['shipping_country' => 'JP']);

        $response->assertStatus(422);
    }

    /**
     * 권한 없는 관리자는 403 이다.
     *
     * @effects admin_without_permission_forbidden
     *
     * @return void 반환값 없음
     */
    public function test_admin_without_permission_forbidden(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['shipping_country' => 'US']);

        $response->assertStatus(403);
    }

    /**
     * 배송국가 변경이 활동 로그 훅을 발화한다.
     *
     * @effects shipping_country_change_fires_activity_log_hook
     *
     * @return void 반환값 없음
     */
    public function test_shipping_country_change_fires_activity_log_hook(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-shipping-country.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $fired = false;
        HookManager::addAction(
            'sirsoft-ecommerce.admin.user_shipping_country.changed',
            function () use (&$fired) {
                $fired = true;
            }
        );

        $this->patchJson($this->url($target->uuid), ['shipping_country' => 'US'])->assertOk();

        $this->assertTrue($fired, '배송국가 변경 활동 로그 훅이 발화하지 않았습니다.');
    }
}
