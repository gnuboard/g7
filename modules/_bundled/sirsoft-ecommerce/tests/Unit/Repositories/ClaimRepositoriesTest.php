<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use App\Models\User;
use Carbon\Carbon;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimImageFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ShippingPolicyClaimAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 리포지토리 7종 테스트
 *
 * 인터페이스 바인딩(컨테이너 해석)과 각 조회/집계 계약을 함께 고정한다.
 */
class ClaimRepositoriesTest extends ModuleTestCase
{
    /**
     * 클레임 저장소 인터페이스 7종이 컨테이너에서 모두 해당 계약의 구현체로 해석되는지 확인한다.
     *
     * @scenario area=binding
     *
     * @effects all_claim_repository_interfaces_are_bound
     *
     * @return void 반환값 없음
     */
    public function test_all_claim_repository_interfaces_are_bound(): void
    {
        $interfaces = [
            OrderExchangeRepositoryInterface::class,
            OrderExchangeOptionRepositoryInterface::class,
            OrderReturnRepositoryInterface::class,
            OrderReturnOptionRepositoryInterface::class,
            ClaimImageRepositoryInterface::class,
            ClaimPaymentRepositoryInterface::class,
            ShippingPolicyClaimAddressRepositoryInterface::class,
        ];

        foreach ($interfaces as $interface) {
            $this->assertInstanceOf($interface, app($interface), $interface.' 바인딩이 없습니다.');
        }
    }

    // ========================================
    // OrderExchangeRepository
    // ========================================

    /**
     * 교환 저장소가 생성·ID/번호 조회·수정을 지원하고, 없는 교환번호는 null 을 돌려주는지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_repository_crud_and_lookup
     *
     * @return void 반환값 없음
     */
    public function test_exchange_repository_crud_and_lookup(): void
    {
        $repo = app(OrderExchangeRepositoryInterface::class);
        $order = OrderFactory::new()->create();

        $exchange = $repo->create(
            OrderExchangeFactory::new()->make(['order_id' => $order->id])->getAttributes()
        );

        $this->assertNotNull($repo->findById($exchange->id));
        $this->assertNotNull($repo->findByNumber($exchange->exchange_number));
        $this->assertNull($repo->findByNumber('EX-NOT-EXIST'));

        $updated = $repo->update($exchange, ['admin_memo' => '검수 메모']);
        $this->assertSame('검수 메모', $updated->admin_memo);
    }

    /**
     * 주문별 교환 목록이 다른 주문의 신청서를 섞지 않고 최신 신청이 먼저 오도록 정렬되는지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_repository_lists_by_order_newest_first
     *
     * @return void 반환값 없음
     */
    public function test_exchange_repository_lists_by_order_newest_first(): void
    {
        $repo = app(OrderExchangeRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $first = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        $second = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        OrderExchangeFactory::new()->create(); // 다른 주문 — 섞이면 안 된다

        $list = $repo->listByOrderId($order->id);

        $this->assertCount(2, $list);
        $this->assertEquals($second->id, $list->first()->id);
        $this->assertEquals($first->id, $list->last()->id);
    }

    /**
     * 주문옵션별 진행 중 교환 수량 합계가 철회 등 종결 상태를 빼고 세며, 수정 경로에서는 자기 신청서를 제외하는지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_repository_active_quantity_excludes_terminal_states
     *
     * @return void 반환값 없음
     */
    public function test_exchange_repository_active_quantity_excludes_terminal_states(): void
    {
        $repo = app(OrderExchangeRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $orderOption = OrderOptionFactory::new()->forOrder($order)->create();

        $active = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $active->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ExchangeStatusEnum::APPROVED,
            'exchange_quantity' => 2,
        ]);

        $withdrawn = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $withdrawn->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ExchangeStatusEnum::WITHDRAWN,
            'exchange_quantity' => 5,
        ]);

        // 철회·반려·완료 건은 수량 잠금 대상이 아니다.
        $this->assertSame(2, $repo->sumActiveQuantityByOrderOption($orderOption->id));

        // 수정 경로는 자기 자신을 제외하고 센다.
        $this->assertSame(0, $repo->sumActiveQuantityByOrderOption($orderOption->id, $active->id));
    }

    /**
     * 교환 사유 코드별 사용 건수가 그 코드의 신청서만 세고, 쓰인 적 없는 코드는 0 인지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_repository_counts_by_reason_code
     *
     * @return void 반환값 없음
     */
    public function test_exchange_repository_counts_by_reason_code(): void
    {
        $repo = app(OrderExchangeRepositoryInterface::class);
        OrderExchangeFactory::new()->count(2)->create(['reason_code' => 'size_mismatch']);
        OrderExchangeFactory::new()->create(['reason_code' => 'etc']);

        $this->assertSame(2, $repo->countByReasonCode('size_mismatch'));
        $this->assertSame(0, $repo->countByReasonCode('never_used'));
    }

    /**
     * 환불 계수기는 **환불 카탈로그에서 고른 사유**만 센다.
     *
     * `ecommerce_order_refunds.reason_code` 에는 네 출처가 쓴다. 그런데 반품 발·교환 발
     * 환불행은 자기 신청서의 코드를 그대로 복사하므로 그 값의 출처는 **반품/교환 카탈로그**다.
     * 코드 유일성이 `(type, code)` 쌍이라 같은 문자열이 유형 간 공존하고(기본 시드만 해도
     * 환불∩반품 4종 `changed_mind`·`delayed_delivery`·`product_info_different`·`etc`),
     * 스코프 없이 세면 **반품 한 건이 환불 카탈로그의 동명 사유를 잠근다** — 그 사유는 쓰인
     * 적이 없는데 삭제도 코드 변경도 영구히 400 이 되고, 안내에 뜨는 "사용 횟수" 는 환불·취소
     * 어디에서도 찾을 수 없는 수치다. `modules/_bundled/sirsoft-ecommerce/docs/api/claim-reasons.md` 가 "그 사유의 유형에 맞는
     * 표 전수를 센다(환불 유형은 환불신청과 즉시취소 두 표를 합산)" 로 이미 이 계약을 적었고,
     * 같은 저장소의 형제 메서드도 `DIRECT` 로 좁혀 센다.
     *
     * 되돌리면(스코프 제거) 아래 두 단언이 붉어진다.
     *
     * @scenario area=refund
     *
     * @effects refund_repository_counts_only_direct_refunds_by_reason_code
     *
     * @return void 반환값 없음
     */
    public function test_refund_repository_counts_only_direct_refunds_by_reason_code(): void
    {
        $repo = app(OrderRefundRepositoryInterface::class);
        $order = OrderFactory::new()->create();

        $make = function (RefundSourceTypeEnum $source, string $code) use ($order) {
            return OrderRefund::create([
                'order_id' => $order->id,
                'refund_source_type' => $source,
                'refund_number' => 'RF-TEST-'.uniqid(),
                'refund_status' => RefundStatusEnum::REQUESTED,
                'refund_method' => RefundMethodEnum::PG,
                'reason_code' => $code,
                'refund_amount' => 0,
                'refund_points_amount' => 0,
                'refund_shipping_amount' => 0,
            ]);
        };

        // 환불 카탈로그에서 고른 사유 — 이것만 세어야 한다.
        $make(RefundSourceTypeEnum::DIRECT, 'etc');
        $make(RefundSourceTypeEnum::DIRECT, 'etc');

        // 반품·교환 카탈로그의 같은 코드가 복사돼 온 행 — 환불 사유의 사용량이 아니다.
        $make(RefundSourceTypeEnum::RETURN, 'etc');
        $make(RefundSourceTypeEnum::EXCHANGE, 'etc');

        $this->assertSame(2, $repo->countByReasonCode('etc'));

        // 반품 발 환불만 있는 코드는 환불 카탈로그에서 쓰인 적이 없다 — 잠기면 안 된다.
        $make(RefundSourceTypeEnum::RETURN, 'changed_mind');

        $this->assertSame(0, $repo->countByReasonCode('changed_mind'));
    }

    /**
     * 교환 상품 행 일괄 상태 변경이 지정한 교환 신청서의 행만 바꾸고, 같은 주문의 다른 신청서 행은 요청 상태로 남기는지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_option_repository_bulk_status_update_scopes_to_exchange
     *
     * @return void 반환값 없음
     */
    public function test_exchange_option_repository_bulk_status_update_scopes_to_exchange(): void
    {
        $repo = app(OrderExchangeOptionRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $target = OrderExchangeFactory::new()->create(['order_id' => $order->id]);
        $other = OrderExchangeFactory::new()->create(['order_id' => $order->id]);

        OrderExchangeOptionFactory::new()->count(2)->create([
            'order_exchange_id' => $target->id,
            'order_id' => $order->id,
        ]);
        $untouched = OrderExchangeOptionFactory::new()->create([
            'order_exchange_id' => $other->id,
            'order_id' => $order->id,
        ]);

        $affected = $repo->bulkUpdateStatus($target->id, ExchangeStatusEnum::APPROVED->value);

        $this->assertSame(2, $affected);
        foreach ($repo->listByExchangeId($target->id) as $option) {
            $this->assertSame(ExchangeStatusEnum::APPROVED, $option->option_status);
        }
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $untouched->refresh()->option_status);
    }

    /**
     * 행 ID 목록을 넘기면 같은 교환 신청서 안에서도 그 행만 상태가 바뀌는지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_option_repository_bulk_status_update_can_target_subset
     *
     * @return void 반환값 없음
     */
    public function test_exchange_option_repository_bulk_status_update_can_target_subset(): void
    {
        $repo = app(OrderExchangeOptionRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();
        $first = OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $exchange->id]);
        $second = OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $exchange->id]);

        $affected = $repo->bulkUpdateStatus($exchange->id, ExchangeStatusEnum::ON_HOLD->value, [$first->id]);

        $this->assertSame(1, $affected);
        $this->assertSame(ExchangeStatusEnum::ON_HOLD, $first->refresh()->option_status);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $second->refresh()->option_status);
    }

    // ========================================
    // OrderReturnRepository
    // ========================================

    /**
     * 반품 저장소가 생성·ID/번호 조회·수정(관리자 메모)을 지원하는지 확인한다.
     *
     * @scenario area=return
     *
     * @effects return_repository_crud_and_lookup
     *
     * @return void 반환값 없음
     */
    public function test_return_repository_crud_and_lookup(): void
    {
        $repo = app(OrderReturnRepositoryInterface::class);
        $order = OrderFactory::new()->create();

        $return = $repo->create(
            OrderReturnFactory::new()->make(['order_id' => $order->id])->getAttributes()
        );

        $this->assertNotNull($repo->findById($return->id));
        $this->assertNotNull($repo->findByNumber($return->return_number));
        $this->assertSame('보류 사유 확인', $repo->update($return, ['admin_memo' => '보류 사유 확인'])->admin_memo);
    }

    /**
     * 주문옵션별 진행 중 반품 수량 합계가 반려 등 종결 상태를 빼고 세며, 수정 경로에서는 자기 신청서를 제외하는지 확인한다.
     *
     * @scenario area=return
     *
     * @effects return_repository_active_quantity_excludes_terminal_states
     *
     * @return void 반환값 없음
     */
    public function test_return_repository_active_quantity_excludes_terminal_states(): void
    {
        $repo = app(OrderReturnRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $orderOption = OrderOptionFactory::new()->forOrder($order)->create();

        $active = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        OrderReturnOptionFactory::new()->create([
            'order_return_id' => $active->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ReturnStatusEnum::COLLECTING,
            'return_quantity' => 3,
        ]);

        $rejected = OrderReturnFactory::new()->create(['order_id' => $order->id]);
        OrderReturnOptionFactory::new()->create([
            'order_return_id' => $rejected->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => ReturnStatusEnum::REJECTED,
            'return_quantity' => 4,
        ]);

        $this->assertSame(3, $repo->sumActiveQuantityByOrderOption($orderOption->id));
        $this->assertSame(0, $repo->sumActiveQuantityByOrderOption($orderOption->id, $active->id));
    }

    /**
     * 반품 상품 행 일괄 상태 변경이 그 반품의 행 전부를 바꾸고 변경 건수를 돌려주는지 확인한다.
     *
     * @scenario area=return
     *
     * @effects return_option_repository_bulk_update
     *
     * @return void 반환값 없음
     */
    public function test_return_option_repository_bulk_update(): void
    {
        $repo = app(OrderReturnOptionRepositoryInterface::class);
        $return = OrderReturnFactory::new()->create();
        OrderReturnOptionFactory::new()->count(2)->create(['order_return_id' => $return->id]);

        $this->assertSame(2, $repo->bulkUpdateStatus($return->id, ReturnStatusEnum::APPROVED->value));
        foreach ($repo->listByReturnId($return->id) as $option) {
            $this->assertSame(ReturnStatusEnum::APPROVED, $option->option_status);
        }
    }

    // ========================================
    // ClaimImageRepository
    // ========================================

    /**
     * 첨부 이미지 목록·개수가 클레임 유형과 신청서 ID 로 좁혀져 다른 신청서의 이미지가 섞이지 않는지 확인한다.
     *
     * @scenario area=image
     *
     * @effects claim_image_repository_scopes_by_claimable
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_repository_scopes_by_claimable(): void
    {
        $repo = app(ClaimImageRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();
        $return = OrderReturnFactory::new()->create();

        ClaimImageFactory::new()->forExchange($exchange)->count(2)->create();
        ClaimImageFactory::new()->forReturn($return)->create();

        $this->assertCount(2, $repo->listByClaimable(ClaimTypeEnum::EXCHANGE->value, $exchange->id));
        $this->assertSame(2, $repo->countByClaimable(ClaimTypeEnum::EXCHANGE->value, $exchange->id));
        $this->assertSame(1, $repo->countByClaimable(ClaimTypeEnum::RETURN->value, $return->id));
    }

    /**
     * 해시로 첨부 이미지를 찾을 수 있고, 삭제 후에는 ID 로 더 이상 조회되지 않는지 확인한다.
     *
     * @scenario area=image
     *
     * @effects claim_image_repository_finds_by_hash_and_deletes
     *
     * @return void 반환값 없음
     */
    public function test_claim_image_repository_finds_by_hash_and_deletes(): void
    {
        $repo = app(ClaimImageRepositoryInterface::class);
        $image = ClaimImageFactory::new()->create();

        $this->assertNotNull($repo->findByHash($image->hash));
        $this->assertTrue($repo->delete($image));
        $this->assertNull($repo->findById($image->id));
    }

    // ========================================
    // ClaimPaymentRepository
    // ========================================

    /**
     * 클레임 결제를 결제번호·거래 ID 로 찾을 수 있고, 발급된 적 없는 거래 ID 는 null 인지 확인한다.
     *
     * @scenario area=payment
     *
     * @effects claim_payment_repository_lookup_by_number_and_transaction
     *
     * @return void 반환값 없음
     */
    public function test_claim_payment_repository_lookup_by_number_and_transaction(): void
    {
        $repo = app(ClaimPaymentRepositoryInterface::class);
        $payment = ClaimPaymentFactory::new()->paid()->create();

        $this->assertNotNull($repo->findByPaymentNumber($payment->payment_number));
        $this->assertNotNull($repo->findByTransactionId($payment->transaction_id));
        $this->assertNull($repo->findByTransactionId('never-issued'));
    }

    /**
     * 신청서별 결제 합계가 수납 완료 건의 금액과 포인트만 더하고 미수납 건은 빼는지 확인한다.
     *
     * @scenario area=payment
     *
     * @effects claim_payment_repository_sums_only_paid_amounts
     *
     * @return void 반환값 없음
     */
    public function test_claim_payment_repository_sums_only_paid_amounts(): void
    {
        $repo = app(ClaimPaymentRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();

        ClaimPaymentFactory::new()->paid()->create([
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
            'amount' => 3000,
            'points_amount' => 1000,
        ]);
        // 미수납 건은 합계에 들어가면 안 된다.
        ClaimPaymentFactory::new()->create([
            'payable_id' => $exchange->id,
            'order_id' => $exchange->order_id,
            'amount' => 9999,
        ]);

        $this->assertSame(
            4000.0,
            $repo->sumPaidAmount(ClaimTypeEnum::EXCHANGE->value, $exchange->id)
        );
    }

    /**
     * 수납된 결제가 하나도 없으면 결제 합계가 0 인지 확인한다.
     *
     * @scenario area=payment
     *
     * @effects claim_payment_repository_sum_is_zero_when_none_paid
     *
     * @return void 반환값 없음
     */
    public function test_claim_payment_repository_sum_is_zero_when_none_paid(): void
    {
        $repo = app(ClaimPaymentRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();

        $this->assertSame(
            0.0,
            $repo->sumPaidAmount(ClaimTypeEnum::EXCHANGE->value, $exchange->id)
        );
    }

    // ========================================
    // ShippingPolicyClaimAddressRepository
    // ========================================

    /**
     * 배송정책 회수지 upsert 를 같은 유형으로 반복해도 행이 하나로 유지되고 마지막 값으로 갱신되는지 확인한다.
     *
     * @scenario area=claim_address
     *
     * @effects claim_address_repository_upsert_is_idempotent_per_type
     *
     * @return void 반환값 없음
     */
    public function test_claim_address_repository_upsert_is_idempotent_per_type(): void
    {
        $repo = app(ShippingPolicyClaimAddressRepositoryInterface::class);
        $seed = ShippingPolicyClaimAddressFactory::new()->forReturn()->create();
        $policyId = $seed->shipping_policy_id;

        $repo->upsert($policyId, ClaimTypeEnum::RETURN->value, ['recipient_name' => '수정된 담당자']);
        $repo->upsert($policyId, ClaimTypeEnum::RETURN->value, ['recipient_name' => '다시 수정']);

        $this->assertCount(1, $repo->listByPolicy($policyId));
        $this->assertSame(
            '다시 수정',
            $repo->findByPolicyAndType($policyId, ClaimTypeEnum::RETURN->value)->recipient_name
        );
    }

    /**
     * 유형별 회수지 삭제가 지정 유형의 행만 지우고 같은 정책의 다른 유형 행은 남기는지 확인한다.
     *
     * @scenario area=claim_address
     *
     * @effects claim_address_repository_delete_scopes_to_type
     *
     * @return void 반환값 없음
     */
    public function test_claim_address_repository_delete_scopes_to_type(): void
    {
        $repo = app(ShippingPolicyClaimAddressRepositoryInterface::class);
        $seed = ShippingPolicyClaimAddressFactory::new()->forReturn()->create();
        $policyId = $seed->shipping_policy_id;
        ShippingPolicyClaimAddressFactory::new()->forExchange()->create(['shipping_policy_id' => $policyId]);

        $this->assertSame(1, $repo->deleteByPolicyAndType($policyId, ClaimTypeEnum::RETURN->value));
        $this->assertNull($repo->findByPolicyAndType($policyId, ClaimTypeEnum::RETURN->value));
        $this->assertNotNull($repo->findByPolicyAndType($policyId, ClaimTypeEnum::EXCHANGE->value));
    }

    // ========================================
    // 취소·환불 옵션 저장소 (S6b 신설 — 저장소 우회 해소분)
    // ========================================

    /**
     * 취소 상품 행 일괄 상태 변경이 대상 취소 신청서의 행에만 완료 상태·처리자·완료 시각을 기록하고, 다른 취소의 행은 건드리지 않는지 확인한다.
     *
     * @scenario area=cancel
     *
     * @effects cancel_option_repository_bulk_status_update_records_completion
     *
     * @return void 반환값 없음
     */
    public function test_cancel_option_repository_bulk_status_update_records_completion(): void
    {
        $repo = app(OrderCancelOptionRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $orderOption = OrderOptionFactory::new()->create(['order_id' => $order->id]);

        $cancel = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_reason_type' => 'etc',
            'items_snapshot' => [],
            'shipping_snapshot' => [],
            'cancel_status' => CancelStatusEnum::REQUESTED,
        ]);
        $other = OrderCancel::create([
            'order_id' => $order->id,
            'cancel_number' => 'CN-TEST-'.uniqid(),
            'cancel_type' => CancelTypeEnum::PARTIAL,
            'cancel_reason_type' => 'etc',
            'items_snapshot' => [],
            'shipping_snapshot' => [],
            'cancel_status' => CancelStatusEnum::REQUESTED,
        ]);

        $target = OrderCancelOption::create([
            'order_cancel_id' => $cancel->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => CancelOptionStatusEnum::REQUESTED,
            'cancel_quantity' => 1,
            'original_quantity' => 1,
            'unit_price' => 1000,
            'subtotal_amount' => 1000,
        ]);
        $untouched = OrderCancelOption::create([
            'order_cancel_id' => $other->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => CancelOptionStatusEnum::REQUESTED,
            'cancel_quantity' => 1,
            'original_quantity' => 1,
            'unit_price' => 1000,
            'subtotal_amount' => 1000,
        ]);

        $operator = User::factory()->create();

        $completedAt = Carbon::now();
        $affected = $repo->bulkUpdateStatus(
            $cancel->id,
            CancelOptionStatusEnum::COMPLETED->value,
            $operator->id,
            $completedAt,
        );

        $this->assertSame(1, $affected);

        $target->refresh();
        $this->assertSame((int) $operator->id, (int) $target->processed_by);
        $this->assertSame(CancelOptionStatusEnum::COMPLETED, $target->option_status);
        $this->assertNotNull($target->completed_at);

        // 다른 취소의 상세 행은 건드리지 않는다 — WHERE 키가 헤더 관계와 같아야 한다.
        $this->assertSame(CancelOptionStatusEnum::REQUESTED, $untouched->refresh()->option_status);
    }

    /**
     * 환불 상품 행 일괄 상태 변경이 처리자·완료 시각을 받지 않는 반려 경로에서는 기존 처리자를 지우지 않고, 값을 받는 실행 경로에서만 처리자·완료 시각을 기입하는지 확인한다.
     *
     * @scenario area=refund
     *
     * @effects refund_option_repository_bulk_status_update_sets_completed_at_only_when_given
     *
     * @return void 반환값 없음
     */
    public function test_refund_option_repository_bulk_status_update_sets_completed_at_only_when_given(): void
    {
        $repo = app(OrderRefundOptionRepositoryInterface::class);
        $order = OrderFactory::new()->create();
        $orderOption = OrderOptionFactory::new()->create(['order_id' => $order->id]);

        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::DIRECT,
            'refund_number' => 'RF-TEST-'.uniqid(),
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 0,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        $option = OrderRefundOption::create([
            'order_refund_id' => $refund->id,
            'order_id' => $order->id,
            'order_option_id' => $orderOption->id,
            'option_status' => RefundStatusEnum::REQUESTED,
            'quantity' => 1,
            'unit_price' => 1000,
            'subtotal_amount' => 1000,
            'discount_amount' => 0,
            'refund_amount' => 0,
        ]);

        $operator = User::factory()->create();
        $option->update(['processed_by' => $operator->id]);

        // 처리자·완료 시각 미전달(반려·철회 경로) — 기존 값을 지우지 않는다.
        $this->assertSame(1, $repo->bulkUpdateStatus($refund->id, RefundStatusEnum::REJECTED->value));
        $option->refresh();
        $this->assertNull($option->completed_at);
        $this->assertSame((int) $operator->id, (int) $option->processed_by);

        // 처리자·완료 시각 전달(환불 실행 경로) — 그때만 기입한다.
        $another = User::factory()->create();
        $this->assertSame(1, $repo->bulkUpdateStatus(
            $refund->id,
            RefundStatusEnum::COMPLETED->value,
            $another->id,
            Carbon::now(),
        ));

        $option->refresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $option->option_status);
        $this->assertSame((int) $another->id, (int) $option->processed_by);
        $this->assertNotNull($option->completed_at);
    }

    // ========================================
    // 완료 미리보기 다건 스코프용 묶음 조회 (S6b 3차 보강)
    // ========================================

    /**
     * 교환 ID 여러 개로 상품 행 ID 를 조회하면 신청서별로 묶어 돌려주고, 요청하지 않은 신청서는 빠지며 빈 입력은 빈 배열인지 확인한다.
     *
     * @scenario area=exchange
     *
     * @effects exchange_option_repository_lists_option_ids_grouped_by_claim
     *
     * @return void 반환값 없음
     */
    public function test_exchange_option_repository_lists_option_ids_grouped_by_claim(): void
    {
        $repo = app(OrderExchangeOptionRepositoryInterface::class);
        $first = OrderExchangeFactory::new()->create();
        $second = OrderExchangeFactory::new()->create();
        $orphan = OrderExchangeFactory::new()->create();

        $a = OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $first->id]);
        $b = OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $first->id]);
        $c = OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $second->id]);
        OrderExchangeOptionFactory::new()->create(['order_exchange_id' => $orphan->id]);

        $map = $repo->listOptionIdsByExchangeIds([$first->id, $second->id]);

        // 신청서별로 묶여야 한다 — 합집합으로 돌려주면 교차 배치가 검증을 통과한다.
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $map[$first->id]);
        $this->assertSame([$c->id], $map[$second->id]);
        $this->assertArrayNotHasKey($orphan->id, $map);

        $this->assertSame([], $repo->listOptionIdsByExchangeIds([]));
    }

    /**
     * 반품 ID 여러 개로 상품 행 ID 를 조회하면 신청서별로 묶어 돌려주고, 빈 입력은 빈 배열인지 확인한다.
     *
     * @scenario area=return
     *
     * @effects return_option_repository_lists_option_ids_grouped_by_claim
     *
     * @return void 반환값 없음
     */
    public function test_return_option_repository_lists_option_ids_grouped_by_claim(): void
    {
        $repo = app(OrderReturnOptionRepositoryInterface::class);
        $first = OrderReturnFactory::new()->create();
        $second = OrderReturnFactory::new()->create();

        $a = OrderReturnOptionFactory::new()->create(['order_return_id' => $first->id]);
        $b = OrderReturnOptionFactory::new()->create(['order_return_id' => $second->id]);

        $map = $repo->listOptionIdsByReturnIds([$first->id, $second->id]);

        $this->assertSame([$a->id], $map[$first->id]);
        $this->assertSame([$b->id], $map[$second->id]);

        $this->assertSame([], $repo->listOptionIdsByReturnIds([]));
    }
}
