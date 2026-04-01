<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use App\Services\ActivityLogService;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 서비스 Unit 테스트
 */
class OrderServiceTest extends ModuleTestCase
{
    protected OrderService $service;

    protected $mockRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = Mockery::mock(OrderRepositoryInterface::class);
        $mockUserAddressRepository = Mockery::mock(UserAddressRepositoryInterface::class);
        $mockActivityLogService = Mockery::mock(ActivityLogService::class);
        $mockActivityLogService->shouldReceive('logAdmin')->byDefault();

        $this->service = new OrderService(
            $this->mockRepository,
            $mockUserAddressRepository,
            $mockActivityLogService
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // getList() 테스트
    // ========================================

    public function test_get_list_returns_paginated_orders(): void
    {
        // Given: Repository가 페이지네이션 결과 반환
        $filters = ['order_status' => ['pending_payment'], 'per_page' => 10];
        $mockPaginator = Mockery::mock(LengthAwarePaginator::class);

        $this->mockRepository
            ->shouldReceive('getListWithFilters')
            ->with($filters, 10)
            ->once()
            ->andReturn($mockPaginator);

        // When: getList 호출
        $result = $this->service->getList($filters);

        // Then: 결과 반환
        $this->assertSame($mockPaginator, $result);
    }

    public function test_get_list_uses_default_per_page(): void
    {
        // Given: per_page 미지정
        $filters = ['order_status' => ['pending_payment']];
        $mockPaginator = Mockery::mock(LengthAwarePaginator::class);

        $this->mockRepository
            ->shouldReceive('getListWithFilters')
            ->with($filters, 20) // 기본값 20
            ->once()
            ->andReturn($mockPaginator);

        // When: getList 호출
        $result = $this->service->getList($filters);

        // Then: 기본 per_page 적용
        $this->assertSame($mockPaginator, $result);
    }

    // ========================================
    // getDetail() 테스트
    // ========================================

    public function test_get_detail_returns_order_with_relations(): void
    {
        // Given: Repository가 주문 반환
        $order = new Order(['id' => 1, 'order_number' => 'ORD-001']);

        $this->mockRepository
            ->shouldReceive('findWithRelations')
            ->with(1)
            ->once()
            ->andReturn($order);

        // When: getDetail 호출
        $result = $this->service->getDetail(1);

        // Then: 주문 반환
        $this->assertEquals($order, $result);
    }

    public function test_get_detail_returns_null_for_nonexistent_order(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('findWithRelations')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // When: getDetail 호출
        $result = $this->service->getDetail(99999);

        // Then: null 반환
        $this->assertNull($result);
    }

    // ========================================
    // getStatistics() 테스트
    // ========================================

    public function test_get_statistics_returns_statistics_array(): void
    {
        // Given: Repository가 통계 반환
        $statistics = [
            'total_orders' => 100,
            'pending_payment' => 10,
            'payment_complete' => 30,
        ];

        $this->mockRepository
            ->shouldReceive('getStatistics')
            ->once()
            ->andReturn($statistics);

        // When: getStatistics 호출
        $result = $this->service->getStatistics();

        // Then: 통계 반환
        $this->assertEquals($statistics, $result);
    }

    // ========================================
    // update() 테스트
    // ========================================

    public function test_update_modifies_order(): void
    {
        // Given: 주문 존재
        $order = new Order(['id' => 1, 'order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        $data = ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value];

        $updatedOrder = new Order([
            'id' => 1,
            'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $this->mockRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($updatedOrder);

        // When: update 호출
        $result = $this->service->update($order, $data);

        // Then: 수정된 주문 반환
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    public function test_delete_removes_order(): void
    {
        // Given: 주문 존재 (관계 메서드 mock)
        $order = Mockery::mock(Order::class)->makePartial();
        $order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $order->shouldReceive('getAttribute')->with('order_number')->andReturn('ORD-001');

        // 관계 삭제 mock (반환 타입 일치 필수)
        $mockTaxInvoices = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockTaxInvoices->shouldReceive('delete')->once();
        $order->shouldReceive('taxInvoices')->once()->andReturn($mockTaxInvoices);

        $mockShippings = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockShippings->shouldReceive('delete')->once();
        $order->shouldReceive('shippings')->once()->andReturn($mockShippings);

        $mockAddresses = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockAddresses->shouldReceive('delete')->once();
        $order->shouldReceive('addresses')->once()->andReturn($mockAddresses);

        $mockPayment = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasOne::class);
        $mockPayment->shouldReceive('delete')->once();
        $order->shouldReceive('payment')->once()->andReturn($mockPayment);

        $mockOptions = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockOptions->shouldReceive('delete')->once();
        $order->shouldReceive('options')->once()->andReturn($mockOptions);

        $this->mockRepository
            ->shouldReceive('delete')
            ->with($order)
            ->once()
            ->andReturn(true);

        // When: delete 호출
        $result = $this->service->delete($order);

        // Then: true 반환 + 모든 관계 삭제 호출됨
        $this->assertTrue($result);
    }

    // ========================================
    // bulkUpdate() 테스트
    // ========================================

    public function test_bulk_update_processes_multiple_orders(): void
    {
        // Given: 여러 주문 ID와 상태
        $ids = [1, 2, 3];
        $data = [
            'ids' => $ids,
            'order_status' => 'payment_complete',
        ];

        $this->mockRepository
            ->shouldReceive('bulkUpdateStatus')
            ->with($ids, 'payment_complete')
            ->once()
            ->andReturn(3);

        // 주문상품옵션 상태도 동일하게 일괄 변경
        $this->mockRepository
            ->shouldReceive('bulkUpdateOptionStatus')
            ->with($ids, 'payment_complete')
            ->once()
            ->andReturn(5);

        // When: bulkUpdate 호출
        $result = $this->service->bulkUpdate($data);

        // Then: 처리된 개수 반환
        $this->assertEquals(3, $result['updated_count']);
        $this->assertEquals(3, $result['requested_count']);
    }

    public function test_bulk_update_with_shipping_info(): void
    {
        // Given: 배송 정보 변경
        $ids = [1, 2];
        $data = [
            'ids' => $ids,
            'carrier_id' => 1,
            'tracking_number' => '123456789012',
        ];

        $this->mockRepository
            ->shouldReceive('bulkUpdateShipping')
            ->with($ids, 1, '123456789012')
            ->once()
            ->andReturn(2);

        // When: bulkUpdate 호출
        $result = $this->service->bulkUpdate($data);

        // Then: 처리된 개수 반환
        $this->assertEquals(2, $result['updated_count']);
    }

    // ========================================
    // getByOrderNumber() 테스트
    // ========================================

    public function test_get_by_order_number_returns_order(): void
    {
        // Given: 주문번호로 조회
        $order = new Order(['order_number' => 'ORD-20250117-00001']);

        $this->mockRepository
            ->shouldReceive('findByOrderNumber')
            ->with('ORD-20250117-00001')
            ->once()
            ->andReturn($order);

        // When: getByOrderNumber 호출
        $result = $this->service->getByOrderNumber('ORD-20250117-00001');

        // Then: 주문 반환
        $this->assertEquals('ORD-20250117-00001', $result->order_number);
    }
}
