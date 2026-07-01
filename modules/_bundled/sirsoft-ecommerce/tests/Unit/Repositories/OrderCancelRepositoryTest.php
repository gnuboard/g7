<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Repositories\OrderCancelRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 취소 Repository 테스트
 *
 * latestByOrderId 는 알림 페이로드(취소 사유) 추출 시 주문의 최신 취소 이력을 조회한다.
 * (EcommerceNotificationDataListener 가 직접 Eloquent 대신 본 메서드를 위임 호출 — repository 패턴)
 */
class OrderCancelRepositoryTest extends ModuleTestCase
{
    protected OrderCancelRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OrderCancelRepository(new OrderCancel);
    }

    /**
     * 취소 이력 한 건을 주어진 주문에 생성합니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  string  $cancelNumber  고유 취소번호
     * @param  string  $reason  취소 사유 상세
     * @return OrderCancel 생성된 취소 이력
     */
    private function makeCancel(int $orderId, string $cancelNumber, string $reason): OrderCancel
    {
        return OrderCancel::create([
            'order_id' => $orderId,
            'cancel_number' => $cancelNumber,
            'cancel_type' => CancelTypeEnum::FULL,
            'cancel_status' => CancelStatusEnum::REQUESTED,
            'cancel_reason_type' => 'change_of_mind',
            'cancel_reason' => $reason,
            'items_snapshot' => [],
        ]);
    }

    /**
     * 취소 이력이 없으면 null 을 반환한다.
     */
    public function test_latest_by_order_id_returns_null_when_no_cancel_exists(): void
    {
        $order = OrderFactory::new()->create();

        $this->assertNull($this->repository->latestByOrderId($order->id));
    }

    /**
     * 취소 이력이 여러 건이면 id 가 가장 큰(가장 최근) 건을 반환한다.
     */
    public function test_latest_by_order_id_returns_most_recent_by_id(): void
    {
        $order = OrderFactory::new()->create();
        $this->makeCancel($order->id, 'CXL-OLD', '오래된 사유');
        $latest = $this->makeCancel($order->id, 'CXL-NEW', '최신 사유');

        $result = $this->repository->latestByOrderId($order->id);

        $this->assertNotNull($result);
        $this->assertSame($latest->id, $result->id);
        $this->assertSame('최신 사유', $result->cancel_reason);
    }

    /**
     * 다른 주문의 취소 이력은 반환하지 않는다 (order_id 스코프).
     */
    public function test_latest_by_order_id_is_scoped_to_the_given_order(): void
    {
        $orderA = OrderFactory::new()->create();
        $orderB = OrderFactory::new()->create();
        $this->makeCancel($orderA->id, 'CXL-A', 'A 사유');

        $this->assertNull($this->repository->latestByOrderId($orderB->id));
    }
}
