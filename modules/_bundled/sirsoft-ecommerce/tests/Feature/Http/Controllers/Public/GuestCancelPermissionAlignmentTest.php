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
 * 비회원 주문 취소의 권한 정합 회귀 테스트 (#703)
 *
 * 회귀 배경: 비회원 주문 상세의 취소 가능 여부(can_cancel)는 주문 상태만 보고, 취소 창이 여는 사유 목록
 * API 는 guest 역할의 「주문 취소」 권한을 요구했다. 운영자가 비회원의 주문 취소 권한을 거두면 취소 버튼은
 * 그대로 보이는데 창이 사유를 불러오지 못해 막혔고, 반대로 취소 API 자체는 권한 검사 없이 열려 있었다.
 * 회원 경로와 같은 권한 하나로 버튼·사유 목록·취소 API 가 함께 판정되어야 한다.
 */
class GuestCancelPermissionAlignmentTest extends ModuleTestCase
{
    private const PASSWORD = 'guest1234';

    private const PHONE = '010-4444-5555';

    /**
     * 결제완료 상태 비회원 주문과 조회 토큰을 만듭니다.
     *
     * @return array{0: Order, 1: string} 주문과 조회 토큰
     */
    private function makeGuestOrder(): array
    {
        $order = Order::factory()->forGuest()->create([
            'order_number' => 'ORD-CANCEL-PERM-1',
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
        ]);
        OrderAddress::factory()->shipping()->forOrder($order)->create(['orderer_phone' => self::PHONE]);
        OrderOption::factory()->forOrder($order)->create(['option_status' => OrderStatusEnum::PAYMENT_COMPLETE]);

        $token = app(GuestOrderAuthService::class)
            ->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.8')['token'];

        return [$order, $token];
    }

    /**
     * guest 역할에서 주문 취소 권한을 거둡니다.
     *
     * @return void 없음
     */
    private function revokeGuestCancelPermission(): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.user-orders.cancel')->first();
        Role::where('identifier', 'guest')->first()?->permissions()->detach($permission?->id);
        GuestRoleResolver::flush();
    }

    /**
     * 권한이 있으면 비회원 주문 상세가 취소 가능으로 표시된다 (기본 상태 보존).
     *
     * @scenario guest_cancel_permission=granted, surface=order_detail_abilities
     *
     * @effects guest_can_cancel_follows_permission
     *
     * @return void 없음
     */
    public function test_guest_with_cancel_permission_sees_cancel_ability(): void
    {
        [$order, $token] = $this->makeGuestOrder();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.abilities.can_cancel', true);
    }

    /**
     * 권한을 거두면 상세의 취소 버튼 판정이 꺼진다.
     *
     * @scenario guest_cancel_permission=revoked, surface=order_detail_abilities
     *
     * @effects guest_can_cancel_follows_permission
     *
     * @return void 없음
     */
    public function test_guest_without_cancel_permission_does_not_see_cancel_ability(): void
    {
        [$order, $token] = $this->makeGuestOrder();
        $this->revokeGuestCancelPermission();

        $this->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.abilities.can_cancel', false);
    }

    /**
     * 권한을 거두면 비회원 취소 API 도 막힌다 (회원 취소 라우트와 같은 권한).
     *
     * @scenario guest_cancel_permission=revoked, surface=cancel_api
     *
     * @effects guest_cancel_api_requires_permission
     *
     * @return void 없음
     */
    public function test_guest_cancel_api_requires_cancel_permission(): void
    {
        [$order, $token] = $this->makeGuestOrder();
        $this->revokeGuestCancelPermission();

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/cancel",
            ['reason' => 'changed_mind'],
            ['X-Guest-Order-Token' => $token]
        );

        // 비로그인 권한 거부는 permission 미들웨어가 401 로 돌려준다(사유 목록 API 와 같은 응답)
        $this->assertContains($response->status(), [401, 403], $response->getContent());

        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }
}
