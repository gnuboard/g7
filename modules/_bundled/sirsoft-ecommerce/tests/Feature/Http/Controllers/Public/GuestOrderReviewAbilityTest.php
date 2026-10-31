<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Public;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 주문 상세의 리뷰 작성 버튼 노출 회귀 테스트 (#703)
 *
 * 회귀 배경: 리뷰 작성 가능 여부(can_write_review)가 권한만 보고 판정돼, 비회원(guest 역할에
 * 리뷰 작성 권한이 기본 부여)이 구매확정한 품목에도 true 로 내려갔다. 비회원 주문 상세에는 리뷰 작성
 * 창이 없고 리뷰 저장 API 는 로그인을 요구하므로 버튼이 눌러도 아무 일도 없는 죽은 버튼이 됐다.
 */
class GuestOrderReviewAbilityTest extends ModuleTestCase
{
    private const PASSWORD = 'guest1234';

    private const PHONE = '010-2222-3333';

    /**
     * 구매확정된 품목 1건을 가진 주문을 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @return void 없음
     */
    private function attachConfirmedOption(Order $order): void
    {
        OrderAddress::factory()->shipping()->forOrder($order)->create([
            'orderer_phone' => self::PHONE,
        ]);

        OrderOption::factory()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::CONFIRMED,
            'confirmed_at' => now()->subDay(),
        ]);
    }

    /**
     * 비회원 주문 상세 응답은 구매확정 품목이라도 리뷰 작성 가능으로 표시하지 않는다.
     *
     * @scenario order_owner=guest, option_status=confirmed
     *
     * @effects guest_order_hides_review_button
     *
     * @return void 없음
     */
    public function test_guest_order_detail_does_not_offer_review(): void
    {
        $order = Order::factory()->forGuest()->create([
            'order_number' => 'ORD-REVIEW-GUEST-1',
            'order_status' => OrderStatusEnum::CONFIRMED,
            'guest_lookup_password_hash' => Hash::make(self::PASSWORD),
        ]);
        $this->attachConfirmedOption($order);

        $token = app(GuestOrderAuthService::class)
            ->authenticate($order->order_number, self::PHONE, self::PASSWORD, '10.0.0.7')['token'];

        $response = $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $token]
        );

        $response->assertOk();
        $this->assertFalse((bool) $response->json('data.options.0.can_write_review'));
    }

    /**
     * 회원 주문 상세는 같은 상태에서 리뷰 작성 가능으로 표시된다 (회원 경로 보존).
     *
     * @scenario order_owner=member, option_status=confirmed
     *
     * @effects member_order_keeps_review_button
     *
     * @return void 없음
     */
    public function test_member_order_detail_still_offers_review(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->forUser($user)->create([
            'order_status' => OrderStatusEnum::CONFIRMED,
        ]);
        $this->attachConfirmedOption($order);

        $response = $this->actingAs($user)->getJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}");

        $response->assertOk();
        $this->assertTrue((bool) $response->json('data.options.0.can_write_review'));
    }
}
