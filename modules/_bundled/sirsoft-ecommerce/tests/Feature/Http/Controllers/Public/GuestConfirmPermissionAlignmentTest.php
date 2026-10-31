<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use App\Models\Permission;
use App\Models\Role;
use App\Support\GuestRoleResolver;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 구매확정의 권한 정합 회귀 테스트 (#703)
 *
 * 회귀 배경: 비회원 주문 상세의 구매확정 버튼(can_confirm)은 guest 역할의 「구매확정」 권한을 보는데,
 * 비회원 구매확정 API 는 권한 검사 없이 열려 있었다. 운영자가 권한을 거두면 버튼은 사라져도 요청을 직접
 * 보내면 구매확정이 처리됐다. 비회원 취소(GuestCancelPermissionAlignmentTest)와 같은 부류 — 회원 구매확정
 * 라우트와 같은 권한 하나로 버튼·API 가 함께 판정되어야 한다.
 */
class GuestConfirmPermissionAlignmentTest extends ModuleTestCase
{
    private const PASSWORD = 'guest1234';

    private const PHONE = '010-4444-6666';

    /**
     * 배송중 옵션을 가진 비회원 주문과 조회 토큰을 만듭니다.
     *
     * @return array{0: Order, 1: OrderOption, 2: string} 주문·옵션·조회 토큰
     */
    private function makeGuestOrder(): array
    {
        $order = Order::factory()->forGuest()->create([
            'order_number' => 'ORD-CONFIRM-PERM-1',
            'order_status' => OrderStatusEnum::SHIPPING,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
        ]);
        OrderAddress::factory()->shipping()->forOrder($order)->create(['orderer_phone' => self::PHONE]);
        $option = OrderOption::factory()->forOrder($order)->create(['option_status' => OrderStatusEnum::SHIPPING]);

        $token = app(GuestOrderAuthService::class)
            ->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.9')['token'];

        return [$order, $option, $token];
    }

    /**
     * guest 역할에서 구매확정 권한을 거둡니다.
     *
     * @return void 없음
     */
    private function revokeGuestConfirmPermission(): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.user-orders.confirm')->first();
        Role::where('identifier', 'guest')->first()?->permissions()->detach($permission?->id);
        GuestRoleResolver::flush();
    }

    /**
     * 권한이 있으면 비회원 구매확정이 처리된다 (기본 상태 보존).
     *
     * @scenario guest_confirm_permission=granted, surface=confirm_api
     *
     * @effects guest_confirm_api_requires_permission
     *
     * @return void 없음
     */
    public function test_guest_with_confirm_permission_can_confirm(): void
    {
        [$order, $option, $token] = $this->makeGuestOrder();

        $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/options/{$option->id}/confirm",
            [],
            ['X-Guest-Order-Token' => $token]
        )->assertOk();

        $this->assertSame(OrderStatusEnum::CONFIRMED, $option->fresh()->option_status);
    }

    /**
     * 권한을 거두면 상세의 구매확정 판정과 API 가 함께 닫힌다 (회원 구매확정 라우트와 같은 권한).
     *
     * @scenario guest_confirm_permission=revoked, surface=confirm_api
     *
     * @effects guest_confirm_api_requires_permission
     *
     * @return void 없음
     */
    public function test_guest_confirm_api_requires_confirm_permission(): void
    {
        [$order, $option, $token] = $this->makeGuestOrder();
        $this->revokeGuestConfirmPermission();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.options.0.can_confirm', false);

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/options/{$option->id}/confirm",
            [],
            ['X-Guest-Order-Token' => $token]
        );

        // 비로그인 권한 거부는 permission 미들웨어가 401 로 돌려준다(비회원 취소와 같은 응답)
        $this->assertContains($response->status(), [401, 403], $response->getContent());
        $this->assertSame(OrderStatusEnum::SHIPPING, $option->fresh()->option_status);
    }
}
