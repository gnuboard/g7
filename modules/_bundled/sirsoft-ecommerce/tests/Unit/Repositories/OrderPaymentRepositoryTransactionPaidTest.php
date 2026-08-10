<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderPaymentRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * OrderPaymentRepository::isTransactionPaid() 단위 테스트.
 *
 * PG 웹훅/콜백 리플레이 멱등 처리의 근거가 되는 조회를 검증한다.
 */
class OrderPaymentRepositoryTransactionPaidTest extends ModuleTestCase
{
    private OrderPaymentRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(OrderPaymentRepositoryInterface::class);
    }

    /**
     * 지정한 transaction_id / 상태로 결제 레코드를 만든다.
     */
    private function makePayment(string $transactionId, PaymentStatusEnum $status): void
    {
        $order = Order::factory()->create();
        OrderPayment::factory()->forOrder($order)->create([
            'transaction_id' => $transactionId,
            'payment_status' => $status,
        ]);
    }

    public function test_returns_true_when_transaction_is_paid(): void
    {
        $this->makePayment('txn_paid', PaymentStatusEnum::PAID);

        $this->assertTrue($this->repository->isTransactionPaid('txn_paid'));
    }

    public function test_returns_false_when_transaction_not_paid(): void
    {
        $this->makePayment('txn_waiting', PaymentStatusEnum::WAITING_DEPOSIT);

        $this->assertFalse($this->repository->isTransactionPaid('txn_waiting'));
    }

    public function test_returns_false_for_unknown_transaction(): void
    {
        $this->assertFalse($this->repository->isTransactionPaid('does_not_exist'));
    }

    public function test_returns_false_for_null_or_empty(): void
    {
        $this->assertFalse($this->repository->isTransactionPaid(null));
        $this->assertFalse($this->repository->isTransactionPaid(''));
    }
}
