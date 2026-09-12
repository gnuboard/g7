<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Support;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\ClaimResource;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 구매자 조작 가능 판정에 **출처 축**이 들어가는지 (#599 후속 F3)
 *
 * 서버는 `source_type !== DIRECT` 인 환불신청서의 철회·수정을 거절한다. 그런데 리소스의
 * `is_withdrawable`/`is_editable` 은 **상태만** 봤다 — 반품·교환에서 이어진 환불신청서와
 * 운영자가 대리 접수한 신청서에도 [수정]/[철회] 버튼이 뜨고, 누르면 **언제나 422** 였다.
 * 버튼은 서 있는데 언제나 실패하는 경로라 오류로 보고되지도 않는다.
 */
class ClaimResourceAbilityTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 주어진 출처의 접수 상태 환불신청서를 만들고 리소스 배열을 돌려줍니다.
     *
     * @param  RefundSourceTypeEnum  $source  출처
     * @return array<string, mixed> 리소스 배열
     */
    protected function resourceFor(RefundSourceTypeEnum $source): array
    {
        $order = $this->paidClaimOrder();

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => $source,
            'refund_source_id' => null,
            'refund_number' => 'RF'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
        ]);

        return (new ClaimResource($refund->fresh(['refundOptions'])))->toArray(Request::create('/'));
    }

    /**
     * 고객이 직접 낸 신청서는 접수 상태에서 수정·철회할 수 있다(비회귀).
     *
     * @scenario refund_source=direct
     *
     * @effects direct_refund_stays_buyer_editable
     */
    public function test_a_direct_refund_request_stays_buyer_editable(): void
    {
        $data = $this->resourceFor(RefundSourceTypeEnum::DIRECT);

        $this->assertTrue($data['is_withdrawable']);
        $this->assertTrue($data['is_editable']);
    }

    /**
     * 운영자가 대리 접수한 신청서는 구매자가 손댈 수 없다.
     *
     * 열어 두면 **고객이 운영자의 취소 조치를 철회해 버린다**.
     *
     * @scenario refund_source=admin
     *
     * @effects admin_filed_refund_is_not_buyer_editable
     */
    public function test_an_admin_filed_refund_is_not_buyer_editable(): void
    {
        $data = $this->resourceFor(RefundSourceTypeEnum::ADMIN);

        $this->assertFalse($data['is_withdrawable']);
        $this->assertFalse($data['is_editable']);
    }

    /**
     * 이어진 출처의 환불신청서가 구매자에게 잠겨 있는지 단언합니다.
     *
     * @param  RefundSourceTypeEnum  $source  환불 출처
     */
    protected function assertNotBuyerEditable(RefundSourceTypeEnum $source): void
    {
        $data = $this->resourceFor($source);

        $this->assertFalse($data['is_withdrawable'], $source->value.' 발 환불은 구매자가 철회할 수 없어야 한다');
        $this->assertFalse($data['is_editable'], $source->value.' 발 환불은 구매자가 수정할 수 없어야 한다');
    }

    /**
     * 반품에서 이어진 환불신청서는 구매자가 손댈 수 없다.
     *
     * 원 신청서(반품)가 그 조작의 주체다. 환불신청서만 따로 철회하면 **반품은 완료인데 환불만
     * 사라진** 어긋난 상태가 남고, 어느 화면도 그 상태를 설명하지 못한다.
     *
     * @scenario refund_source=return
     *
     * @effects return_and_exchange_originated_refunds_are_not_buyer_editable
     */
    public function test_a_return_originated_refund_is_not_buyer_editable(): void
    {
        $this->assertNotBuyerEditable(RefundSourceTypeEnum::RETURN);
    }

    /**
     * 교환 차액 환불신청서도 마찬가지다 — 조작 주체는 교환 신청서다.
     *
     * @scenario refund_source=exchange
     *
     * @effects return_and_exchange_originated_refunds_are_not_buyer_editable
     */
    public function test_an_exchange_originated_refund_is_not_buyer_editable(): void
    {
        $this->assertNotBuyerEditable(RefundSourceTypeEnum::EXCHANGE);
    }

    /**
     * 구형 취소 발 환불신청서도 잠겨 있다 — 원본은 취소신청서다.
     *
     * @scenario refund_source=cancel
     *
     * @effects return_and_exchange_originated_refunds_are_not_buyer_editable
     */
    public function test_a_cancel_originated_refund_is_not_buyer_editable(): void
    {
        $this->assertNotBuyerEditable(RefundSourceTypeEnum::CANCEL);
    }

    /**
     * 출처 Enum 의 두 축이 서로 어긋나지 않는다.
     *
     * `ownsOptionQuantities()`(수량 선점·회수)와 `isBuyerEditable()`(구매자 조작)은 서로 다른
     * 축이다 — 운영자 대리 접수는 수량을 선점하지만 구매자가 손댈 수는 없다.
     *
     * @scenario refund_source=direct
     *
     * @effects refund_source_type_axes_are_explicit
     */
    public function test_the_refund_source_axes_are_explicit(): void
    {
        $this->assertTrue(RefundSourceTypeEnum::DIRECT->ownsOptionQuantities());
        $this->assertTrue(RefundSourceTypeEnum::ADMIN->ownsOptionQuantities());
        $this->assertFalse(RefundSourceTypeEnum::RETURN->ownsOptionQuantities());
        $this->assertFalse(RefundSourceTypeEnum::EXCHANGE->ownsOptionQuantities());
        $this->assertFalse(RefundSourceTypeEnum::CANCEL->ownsOptionQuantities());

        $this->assertTrue(RefundSourceTypeEnum::DIRECT->isBuyerEditable());
        $this->assertFalse(RefundSourceTypeEnum::ADMIN->isBuyerEditable());

        $this->assertSame(
            ['direct', 'admin'],
            RefundSourceTypeEnum::quantityOwningValues(),
            '수량 잠금·사유 사용중 판정이 읽는 목록이다 — 빠지면 운영자가 건 취소 수량을 고객이 또 신청한다',
        );
    }

    /**
     * **비회원 여부는 주문의 주인이 정한다** — 신청자 컬럼이 아니다.
     *
     * `requested_by`(취소는 `cancelled_by`)는 비회원 신청·운영자 대리 접수·시스템 자동 취소가
     * 모두 null 이다(§4-3-1 이 권한 축에서 지적한 그 모호성). 그 값으로 가르면 **회원 주문의
     * 운영자 접수 건이 관리자 목록에 「비회원」으로 뜬다** — 운영자는 그 고객에게 연락할 방법을
     * 잘못 고른다(회원 알림 대신 비회원 조회 안내).
     *
     * 2026-09-12 실측에서 발견: 회원(`user_id=3345`) 주문의 운영자 대리 환불신청서가 목록에서
     * 「非会員」으로 표시됐다.
     *
     * 되돌리면 red — 판정을 `requested_by === null` 로 되돌리면 첫 단언이 깨진다.
     *
     * @scenario refund_source=admin
     *
     * @effects guest_flag_reads_the_order_owner_not_the_requester_column
     */
    public function test_the_guest_flag_reads_the_order_owner(): void
    {
        $order = $this->paidClaimOrder();

        $this->assertNotNull($order->user_id, '이 픽스처는 회원 주문이라야 축이 성립한다');

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_number' => 'RF-GUESTFLAG-1',
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::BANK,
            'refund_source_type' => RefundSourceTypeEnum::ADMIN,
            // 운영자 대리 접수는 신청자 컬럼이 비어 있다 — 비회원과 같은 모양이다.
            'requested_by' => null,
            'refund_amount' => 1000,
        ]);

        $this->assertFalse(
            $refund->fresh('order')->isGuestRequest(),
            '회원 주문의 운영자 대리 접수를 비회원으로 표시하면 운영자가 연락 수단을 잘못 고른다',
        );
    }
}
