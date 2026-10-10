<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\GuestRoleResolver;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원·비회원 [취소·환불 신청]의 즉시 취소 분기 권한 테스트 (7.1.0 abilities 게이트 R3)
 *
 * 취소·환불 신청은 상품 상태로 자동 분기한다. 즉시 취소 분기(`auto` — 미결제·결제완료 상품)는
 * 종전 [주문 취소]와 같은 일이라, 신청 권한(`user-claims.request`)에 더해 주문 취소 권한
 * (`user-orders.cancel`)도 있어야 한다. 심사 분기(`request` — 상품준비~배송중)는 신청 권한만 본다.
 * 화면이 버튼을 잠글 수 있게 신청 가능 상품 응답은 두 권한을 `abilities` 로 싣는다.
 */
class InstantCancelPermissionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const GUEST_PASSWORD = 'guest1234';

    private User $member;

    /**
     * 통화 설정·사유 카탈로그·회원을 세웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->member = $this->createUser();

        // 즉시 취소 분기는 PG 환불까지 자동으로 간다 — PG 응답을 성공으로 고정한다.
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'TEST_TXN', 'error_code' => null, 'error_message' => null,
        ]);
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 역할에서 주문 취소 권한을 거둡니다.
     *
     * @param  string  $roleIdentifier  역할 식별자 (user | guest)
     * @return void 반환값 없음
     */
    private function revokeCancel(string $roleIdentifier): void
    {
        $permission = Permission::where('identifier', 'sirsoft-ecommerce.user-orders.cancel')->first();
        Role::where('identifier', $roleIdentifier)->first()?->permissions()->detach($permission?->id);
        GuestRoleResolver::flush();
    }

    /**
     * 회원 소유의 결제완료 주문을 만듭니다. (즉시 취소 분기)
     *
     * @param  OrderStatusEnum  $status  주문·상품 상태
     * @return Order 주문
     */
    private function memberOrder(OrderStatusEnum $status = OrderStatusEnum::PAYMENT_COMPLETE): Order
    {
        $order = $this->paidClaimOrder();

        if ($status !== OrderStatusEnum::PAYMENT_COMPLETE) {
            $order = $this->moveOrderTo($order, $status);
        }

        $order->forceFill(['user_id' => $this->member->id])->save();

        return $order->fresh(['options']);
    }

    /**
     * 신청 본문을 만듭니다.
     *
     * @param  Order  $order  주문
     * @return array<string, mixed> 본문
     */
    private function body(Order $order): array
    {
        return [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ];
    }

    /**
     * 주문 취소 권한이 없으면 즉시 취소 분기 신청은 403 이고 환불신청서도 생기지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_instant_cancel_requires_user_orders_cancel
     */
    public function test_member_instant_cancel_requires_user_orders_cancel(): void
    {
        $this->revokeCancel('user');
        $order = $this->memberOrder();

        $response = $this->actingAs($this->member)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/refund-requests", $this->body($order));

        $this->assertSame(403, $response->status(), (string) $response->getContent());

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    /**
     * 심사 분기(상품준비중)는 주문 취소 권한 없이도 신청 권한만으로 접수됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_review_branch_needs_only_request_permission
     */
    public function test_member_review_branch_needs_only_the_request_permission(): void
    {
        $this->revokeCancel('user');
        $order = $this->memberOrder(OrderStatusEnum::PREPARING);

        $response = $this->actingAs($this->member)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/refund-requests", $this->body($order));

        $this->assertSame(201, $response->status(), (string) $response->getContent());
        $response->assertJsonPath('data.branch', 'request');
    }

    /**
     * 신청 가능 상품 응답은 버튼을 잠글 두 권한을 싣습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects user_claimable_options_expose_abilities
     */
    public function test_claimable_options_expose_cancel_and_request_abilities(): void
    {
        $order = $this->memberOrder();

        $this->actingAs($this->member)
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.abilities.can_cancel', true)
            ->assertJsonPath('data.abilities.can_request', true)
            ->assertJsonPath('data.options.0.refund_branch', 'auto');

        $this->revokeCancel('user');

        $this->actingAs($this->member->fresh())
            ->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/claimable-options")
            ->assertOk()
            ->assertJsonPath('data.abilities.can_cancel', false)
            ->assertJsonPath('data.abilities.can_request', true);
    }

    /**
     * 비회원도 같은 기준이다 — guest 역할의 주문 취소 권한이 없으면 즉시 취소 분기 신청이 403 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_instant_cancel_requires_user_orders_cancel
     */
    public function test_guest_instant_cancel_requires_user_orders_cancel(): void
    {
        $order = $this->paidClaimOrder();
        $order->forceFill([
            'user_id' => null,
            'guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD),
        ])->save();
        $phone = '010-7777-8888';
        OrderAddress::query()->where('order_id', $order->id)->update(['orderer_phone' => $phone]);
        if (! OrderAddress::query()->where('order_id', $order->id)->exists()) {
            OrderAddress::factory()->shipping()->forOrder($order)->create(['orderer_phone' => $phone]);
        }

        $token = app(GuestOrderAuthService::class)
            ->authenticate($order->order_number, $phone, self::GUEST_PASSWORD, '10.0.0.9')['token'];

        $this->revokeCancel('guest');

        $response = $this->postJson(
            "/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/refund-requests",
            $this->body($order),
            ['X-Guest-Order-Token' => $token],
        );

        $this->assertSame(403, $response->status(), (string) $response->getContent());

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
    }
}
