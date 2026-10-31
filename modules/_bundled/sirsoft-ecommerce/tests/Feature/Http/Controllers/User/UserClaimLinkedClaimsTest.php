<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Support\ClaimUrl;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원·비회원 신청서 상세의 후속 환불·원 신청서 연결 (보완 5 · Q8)
 *
 * 주소는 서버가 회원/비회원을 갈라 준다 — 화면(partial)이 회원 여부를 스스로 판단하지 않는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/user-claim-list-detail-edit.yaml`
 */
class UserClaimLinkedClaimsTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const GUEST_PASSWORD = 'guest12';

    private const GUEST_PHONE = '010-1234-5678';

    protected User $user;

    /**
     * 통화·사유·회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->user = $this->createUser();
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 주문에 반품과 그 반품에서 생긴 환불신청서를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int|null  $requestedBy  신청자 회원 ID
     * @return array{0: OrderReturn, 1: OrderRefund} [반품, 후속 환불]
     */
    private function returnWithRefund(Order $order, ?int $requestedBy): array
    {
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $requestedBy,
        ]);
        $refund = OrderRefund::create([
            'order_id' => $order->id, 'refund_source_type' => RefundSourceTypeEnum::RETURN, 'refund_source_id' => $return->id,
            'refund_number' => 'RF-ULINK-'.$return->id, 'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG, 'refund_amount' => 26000, 'requested_by' => $requestedBy,
        ]);

        return [$return, $refund];
    }

    /**
     * 회원 반품 상세의 후속 환불은 회원 상세 주소로, 환불 상세의 원 신청서도 회원 주소로 이어진다.
     *
     * @scenario claim_type=return, screen=detail, entry=none
     *
     * @effects user_claim_detail_shows_follow_up_refunds
     *
     * @return void 반환값 없음
     */
    public function test_member_detail_links_use_member_paths(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => $this->user->id])->save();
        [$return, $refund] = $this->returnWithRefund($order->fresh(['options', 'shippingAddress']), $this->user->id);

        $linked = $this->actingAs($this->user)->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$return->return_number}")
            ->assertOk()->json('data.linked_claims');
        $this->assertSame(ClaimUrl::memberDetail($refund->refund_number), $linked['refunds'][0]['detail_url']);

        $source = $this->actingAs($this->user)->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$refund->refund_number}")
            ->assertOk()->json('data.linked_claims.source');
        $this->assertSame(ClaimUrl::memberDetail($return->return_number), $source['detail_url']);
    }

    /**
     * 남의 신청서는 연결 정보와 함께 열리지 않는다(404).
     *
     * @scenario claim_type=return, screen=detail, entry=none
     *
     * @effects user_claim_detail_shows_follow_up_refunds
     *
     * @return void 반환값 없음
     */
    public function test_other_member_cannot_open_the_detail(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => $this->user->id])->save();
        [$return] = $this->returnWithRefund($order->fresh(['options', 'shippingAddress']), $this->user->id);

        $this->actingAs($this->createUser())->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$return->return_number}")
            ->assertNotFound();
    }

    /**
     * 비회원 반품 상세의 후속 환불은 비회원 상세 주소로 이어진다.
     *
     * @scenario screen=detail, entry=with_token, claim_type=return
     *
     * @effects guest_claim_detail_shows_follow_up_refunds
     *
     * @return void 반환값 없음
     */
    public function test_guest_detail_links_use_guest_paths(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::GUEST_PHONE]);
        [$return, $refund] = $this->returnWithRefund($order->fresh(['options', 'shippingAddress']), null);

        $token = app(GuestOrderAuthService::class)->authenticate($order->order_number, self::GUEST_PHONE, self::GUEST_PASSWORD, '10.0.0.9')['token'];
        $linked = $this->getJson("/api/modules/sirsoft-ecommerce/guest/orders/{$order->order_number}/claims/{$return->return_number}", ['X-Guest-Order-Token' => $token])
            ->assertOk()->json('data.linked_claims');

        $this->assertSame(ClaimUrl::guestDetail($refund->refund_number), $linked['refunds'][0]['detail_url']);
    }
}
