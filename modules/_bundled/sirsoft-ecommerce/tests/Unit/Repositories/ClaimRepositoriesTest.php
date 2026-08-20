<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimImageFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\ShippingPolicyClaimAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ClaimPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
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

    public function test_exchange_repository_counts_by_reason_code(): void
    {
        $repo = app(OrderExchangeRepositoryInterface::class);
        OrderExchangeFactory::new()->count(2)->create(['reason_code' => 'size_mismatch']);
        OrderExchangeFactory::new()->create(['reason_code' => 'etc']);

        $this->assertSame(2, $repo->countByReasonCode('size_mismatch'));
        $this->assertSame(0, $repo->countByReasonCode('never_used'));
    }

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

    public function test_exchange_option_repository_deletes_by_exchange(): void
    {
        $repo = app(OrderExchangeOptionRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();
        OrderExchangeOptionFactory::new()->count(3)->create(['order_exchange_id' => $exchange->id]);

        $this->assertSame(3, $repo->deleteByExchangeId($exchange->id));
        $this->assertCount(0, $repo->listByExchangeId($exchange->id));
    }

    // ========================================
    // OrderReturnRepository
    // ========================================

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

    public function test_return_option_repository_bulk_update_and_delete(): void
    {
        $repo = app(OrderReturnOptionRepositoryInterface::class);
        $return = OrderReturnFactory::new()->create();
        OrderReturnOptionFactory::new()->count(2)->create(['order_return_id' => $return->id]);

        $this->assertSame(2, $repo->bulkUpdateStatus($return->id, ReturnStatusEnum::APPROVED->value));
        foreach ($repo->listByReturnId($return->id) as $option) {
            $this->assertSame(ReturnStatusEnum::APPROVED, $option->option_status);
        }

        $this->assertSame(2, $repo->deleteByReturnId($return->id));
    }

    // ========================================
    // ClaimImageRepository
    // ========================================

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

    public function test_claim_image_repository_finds_by_hash_and_deletes(): void
    {
        $repo = app(ClaimImageRepositoryInterface::class);
        $image = ClaimImageFactory::new()->create();

        $this->assertNotNull($repo->findByHash($image->hash));
        $this->assertTrue($repo->delete($image));
        $this->assertNull($repo->findById($image->id));
    }

    public function test_claim_image_repository_delete_by_claimable_leaves_others(): void
    {
        $repo = app(ClaimImageRepositoryInterface::class);
        $exchange = OrderExchangeFactory::new()->create();
        $other = OrderExchangeFactory::new()->create();
        ClaimImageFactory::new()->forExchange($exchange)->count(2)->create();
        ClaimImageFactory::new()->forExchange($other)->create();

        $this->assertSame(2, $repo->deleteByClaimable(ClaimTypeEnum::EXCHANGE->value, $exchange->id));
        $this->assertSame(1, $repo->countByClaimable(ClaimTypeEnum::EXCHANGE->value, $other->id));
    }

    // ========================================
    // ClaimPaymentRepository
    // ========================================

    public function test_claim_payment_repository_lookup_by_number_and_transaction(): void
    {
        $repo = app(ClaimPaymentRepositoryInterface::class);
        $payment = ClaimPaymentFactory::new()->paid()->create();

        $this->assertNotNull($repo->findByPaymentNumber($payment->payment_number));
        $this->assertNotNull($repo->findByTransactionId($payment->transaction_id));
        $this->assertNull($repo->findByTransactionId('never-issued'));
    }

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
}
