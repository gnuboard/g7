<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Extension\HookManager;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\EcommerceUserProfileRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 회원 결제 통화 컨트롤러 Feature 테스트 (A3·A5)
 *
 * - 권한 보유 관리자가 특정 회원의 통화 변경 → 영속 저장 + 활동 로그
 * - 미등록 통화 → 422
 * - 권한 없는 사용자 → 403
 *
 * 회귀(D11): 관리자 회원편집 화면은 회원을 UUID(getRouteKeyName='uuid')로 식별한다.
 * 통화 변경 라우트도 동일하게 UUID 로 바인딩되어야 한다(종전 numeric whereNumber 라
 * 실제 UI 의 UUID URL 이 404 였음). URL 은 회원 UUID 를 사용한다.
 */
class AdminUserCurrencyControllerTest extends ModuleTestCase
{
    private function url(string $uuid): string
    {
        return "/api/modules/sirsoft-ecommerce/admin/users/{$uuid}/currency";
    }

    public function test_admin_with_permission_can_change_user_currency(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-currency.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['currency' => 'USD']);

        $response->assertOk();
        $this->assertSame(
            'USD',
            app(EcommerceUserProfileRepositoryInterface::class)->getPreferredCurrency($target->id)
        );
    }

    public function test_unregistered_currency_rejected_422(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-currency.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['currency' => 'XYZ']);

        $response->assertStatus(422);
    }

    public function test_admin_without_permission_forbidden(): void
    {
        $this->createDefaultRoles();
        // 권한 없는 일반 관리자(admin.access 만, user-currency.manage 없음)
        $admin = $this->createAdminUser();
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->patchJson($this->url($target->uuid), ['currency' => 'USD']);

        $response->assertStatus(403);
    }

    public function test_currency_change_fires_activity_log_hook(): void
    {
        $this->createDefaultRoles();
        $admin = $this->createAdminUser(['sirsoft-ecommerce.user-currency.manage']);
        $target = User::factory()->create();
        Sanctum::actingAs($admin);

        $fired = false;
        HookManager::addAction(
            'sirsoft-ecommerce.admin.user_currency.changed',
            function () use (&$fired) {
                $fired = true;
            }
        );

        $this->patchJson($this->url($target->uuid), ['currency' => 'JPY'])->assertOk();

        $this->assertTrue($fired, '통화 변경 활동 로그 훅이 발화하지 않았습니다.');
    }
}
