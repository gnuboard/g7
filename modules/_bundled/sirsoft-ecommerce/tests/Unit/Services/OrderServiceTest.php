<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidEffectsReport;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidOptions;
use Modules\Sirsoft\Ecommerce\DTO\ManualPaidTransition;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\UserAddressRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ClaimAttachmentService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 서비스 Unit 테스트
 */
class OrderServiceTest extends ModuleTestCase
{
    protected OrderService $service;

    protected $mockRepository;

    /**
     * 결제 처리 서비스 대역 (수동 결제 처리 효과)
     *
     * @var MockInterface
     */
    protected $mockProcessing;

    /**
     * 주문 리포지토리 목과 실제 재고·첨부 서비스를 주입해 주문 서비스를 준비하고, 옵션 상태 동기화 전후 조회와 신청 상품 행 정리 호출은 기본 허용으로 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Hook listener job 차단 (mock 모델 deserialize 시 null TypeError 방지)
        Queue::fake();

        $this->mockRepository = Mockery::mock(OrderRepositoryInterface::class);
        // 상품 상태 동기화의 전/후 읽기와 주문 삭제의 신청 상품 행 정리는 각 테스트가 따로
        // 단언하지 않는 한 허용만 한다(전/후 없음 → 동기화 훅 미발행).
        $this->mockRepository->shouldReceive('collectOptionStatusSyncChanges')->andReturn([])->byDefault();
        $this->mockRepository->shouldReceive('deleteClaimItemRows')->andReturn(0)->byDefault();
        $mockUserAddressRepository = Mockery::mock(UserAddressRepositoryInterface::class);

        // 수동 결제 처리 세 항목(Q13)은 잠근 주문 행을 DB 에서 다시 읽는다 — 저장소를 목으로 둔 이 단위
        // 테스트에서는 그 단계를 대역으로 바꾼다(그 동작은 ManualPaidTransitionEffectsTest 가 실제 경로로 잰다).
        $this->mockProcessing = Mockery::mock(OrderProcessingService::class);
        $this->mockProcessing->shouldReceive('applyManualPaidTransitionEffects')
            ->andReturnUsing(fn ($order, ManualPaidTransition $t, ManualPaidOptions $o) => ManualPaidEffectsReport::none($t, $o))
            ->byDefault();

        $this->service = new OrderService(
            $this->mockRepository,
            $mockUserAddressRepository,
            app(StockService::class),
            app(ClaimAttachmentService::class),
            $this->mockProcessing,
        );
    }

    /**
     * Mockery 기대값을 검증·정리한 뒤 부모 정리를 수행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // getList() 테스트
    // ========================================

    /**
     * 주문 목록 조회가 필터와 요청한 per_page 를 리포지토리에 그대로 넘기고 페이지네이터를 가공 없이 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_list_passes_filters_and_per_page
     *
     * @return void 반환값 없음
     */
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

    /**
     * per_page 를 주지 않으면 기본값 20 으로 주문 목록을 조회하는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_list_defaults_per_page_to_20
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 상세 조회가 관계를 함께 적재하는 findWithRelations 로 주문을 찾아 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_detail_loads_with_relations
     *
     * @return void 반환값 없음
     */
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

    /**
     * 없는 주문 ID 를 조회하면 예외 없이 null 을 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_detail_returns_null_when_missing
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 통계 조회가 리포지토리 집계 배열을 가공 없이 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_statistics_passthrough
     *
     * @return void 반환값 없음
     */
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

    /**
     * 결제대기 주문을 결제완료로 수정하면 수정된 주문을 돌려주고, 주문상품옵션 상태도 같은 상태로 한 번 일괄 동기화하는지 확인한다.
     *
     * @scenario area=status_sync
     *
     * @effects order_update_returns_updated_order, status_change_syncs_option_status
     *
     * @return void 반환값 없음
     */
    public function test_update_modifies_order(): void
    {
        // Given: 주문 존재
        $order = new Order(['id' => 1, 'order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        $data = ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value];

        $updatedOrder = new Order(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updatedOrder->id = 1; // id 는 guarded 라 mass assignment 로 들어가지 않음

        $this->mockRepository
            ->shouldReceive('update')
            ->once()
            ->andReturn($updatedOrder);

        // 주문 상태가 변경되면 옵션 상태도 동기화되어야 한다.
        $this->mockRepository
            ->shouldReceive('bulkUpdateOptionStatus')
            ->once()
            ->with([1], OrderStatusEnum::PAYMENT_COMPLETE->value)
            ->andReturn(1);

        // When: update 호출
        $result = $this->service->update($order, $data);

        // Then: 수정된 주문 반환
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    /**
     * D7 — 해외(US) 주소 수정 시 intl 컬럼이 채워지고 국내 컬럼은 초기화되어야 한다.
     *
     * @scenario area=address
     *
     * @effects intl_address_update_clears_kr_columns
     *
     * @return void 반환값 없음
     */
    public function test_update_maps_intl_address_and_clears_kr_columns(): void
    {
        $order = new Order(['id' => 11, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        // shippingAddress 모킹 — update($addressData) 호출 내용 캡처
        $captured = null;
        $shippingAddress = Mockery::mock();
        $shippingAddress->recipient_country_code = 'KR';
        $shippingAddress->shouldReceive('update')->once()->andReturnUsing(function ($d) use (&$captured) {
            $captured = $d;

            return true;
        });
        $shippingAddress->shouldReceive('fresh')->andReturnNull();

        $orderMock = Mockery::mock($order)->makePartial();
        $orderMock->shouldReceive('getAttribute')->with('shippingAddress')->andReturn($shippingAddress);

        $this->mockRepository->shouldReceive('update')->once()->andReturn($orderMock);

        $this->service->update($orderMock, [
            'recipient_name' => 'John Smith',
            'recipient_country_code' => 'US',
            'address_line_1' => '1600 Amphitheatre Parkway',
            'address_line_2' => 'Bldg 40',
            'intl_city' => 'Mountain View',
            'intl_state' => 'CA',
            'intl_postal_code' => '94043',
        ]);

        $this->assertNotNull($captured);
        $this->assertSame('1600 Amphitheatre Parkway', $captured['address_line_1']);
        $this->assertSame('Mountain View', $captured['intl_city']);
        $this->assertSame('94043', $captured['intl_postal_code']);
        // 국내 컬럼 초기화 — zipcode/address 는 NOT NULL 이므로 빈 문자열 (DB 무결성 위반 방지)
        $this->assertSame('', $captured['zipcode']);
        $this->assertSame('', $captured['address']);
        $this->assertNull($captured['address_detail']);
    }

    /**
     * D7 — 국내(KR) 주소 수정 시 KR 컬럼이 채워지고 해외 컬럼은 초기화되어야 한다.
     *
     * @scenario area=address
     *
     * @effects kr_address_update_clears_intl_columns
     *
     * @return void 반환값 없음
     */
    public function test_update_maps_kr_address_and_clears_intl_columns(): void
    {
        $order = new Order(['id' => 12, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);

        $captured = null;
        $shippingAddress = Mockery::mock();
        $shippingAddress->recipient_country_code = 'US';
        $shippingAddress->shouldReceive('update')->once()->andReturnUsing(function ($d) use (&$captured) {
            $captured = $d;

            return true;
        });
        $shippingAddress->shouldReceive('fresh')->andReturnNull();

        $orderMock = Mockery::mock($order)->makePartial();
        $orderMock->shouldReceive('getAttribute')->with('shippingAddress')->andReturn($shippingAddress);

        $this->mockRepository->shouldReceive('update')->once()->andReturn($orderMock);

        $this->service->update($orderMock, [
            'recipient_name' => '홍길동',
            'recipient_country_code' => 'KR',
            'recipient_zipcode' => '06236',
            'recipient_address' => '서울특별시 강남구 테헤란로 152',
            'recipient_detail_address' => '강남빌딩 10층',
        ]);

        $this->assertNotNull($captured);
        $this->assertSame('06236', $captured['zipcode']);
        $this->assertSame('서울특별시 강남구 테헤란로 152', $captured['address']);
        // 해외 컬럼은 초기화(null)
        $this->assertNull($captured['address_line_1']);
        $this->assertNull($captured['intl_city']);
        $this->assertNull($captured['intl_postal_code']);
    }

    /**
     * 주문 상태가 바뀌면 그 주문의 옵션 상태를 같은 값으로 일괄 변경하도록 리포지토리를 한 번 호출하는지 확인한다.
     *
     * @scenario area=status_sync
     *
     * @effects status_change_syncs_option_status
     *
     * @return void 반환값 없음
     */
    public function test_update_syncs_option_status_when_order_status_changes(): void
    {
        // Given: 결제대기 주문을 결제완료로 변경
        $order = new Order(['id' => 7, 'order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        $data = ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value];

        $updatedOrder = new Order(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updatedOrder->id = 7;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updatedOrder);

        // Then: 주문 옵션 상태도 동일 상태로 일괄 동기화되어야 한다.
        $this->mockRepository
            ->shouldReceive('bulkUpdateOptionStatus')
            ->once()
            ->with([7], OrderStatusEnum::PAYMENT_COMPLETE->value)
            ->andReturn(2);

        $result = $this->service->update($order, $data);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    /**
     * 요청에 order_status 가 없는 수정(메모만 변경)은 옵션 상태 일괄 변경을 호출하지 않는지 확인한다.
     *
     * @scenario area=status_sync
     *
     * @effects status_unchanged_update_skips_option_sync
     *
     * @return void 반환값 없음
     */
    public function test_update_does_not_sync_options_when_status_unchanged(): void
    {
        // Given: 상태 변경 없이 배송 메모만 수정
        $order = new Order(['id' => 9, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $data = ['admin_memo' => '메모 수정'];

        $updatedOrder = new Order(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updatedOrder->id = 9;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updatedOrder);

        // Then: order_status 가 data 에 없으므로 옵션 동기화는 호출되지 않아야 한다.
        $this->mockRepository->shouldNotReceive('bulkUpdateOptionStatus');

        $result = $this->service->update($order, $data);

        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $result->order_status);
    }

    /**
     * 취소 상태로의 수정은 옵션 라이프사이클을 취소 서비스에 맡기므로 옵션 상태 일괄 변경을 호출하지 않는지 확인한다.
     *
     * @scenario area=status_sync
     *
     * @effects cancelled_update_skips_option_sync
     *
     * @return void 반환값 없음
     */
    public function test_update_does_not_sync_options_for_cancelled_status(): void
    {
        // Given: 주문을 취소 상태로 변경 (옵션 라이프사이클은 취소 서비스가 담당)
        $order = new Order(['id' => 11, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $data = ['order_status' => OrderStatusEnum::CANCELLED->value];

        $updatedOrder = new Order(['order_status' => OrderStatusEnum::CANCELLED->value]);
        $updatedOrder->id = 11;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updatedOrder);

        // Then: CANCELLED 는 동기화 제외 상태이므로 옵션 일괄 변경은 호출되지 않아야 한다.
        $this->mockRepository->shouldNotReceive('bulkUpdateOptionStatus');

        $result = $this->service->update($order, $data);

        $this->assertEquals(OrderStatusEnum::CANCELLED, $result->order_status);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    /**
     * 주문 삭제가 세금계산서·배송·주소·결제·주문옵션 관계를 각각 한 번씩 지운 뒤 리포지토리 삭제 결과(true)를 돌려주는지 확인한다.
     *
     * @scenario area=delete
     *
     * @effects order_delete_removes_all_relations
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_order(): void
    {
        // Given: 주문 존재 (관계 메서드 mock)
        $order = Mockery::mock(Order::class)->makePartial();
        $order->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $order->shouldReceive('getAttribute')->with('order_number')->andReturn('ORD-001');

        // 관계 삭제 mock (반환 타입 일치 필수)
        $mockTaxInvoices = Mockery::mock(HasMany::class);
        $mockTaxInvoices->shouldReceive('delete')->once();
        $order->shouldReceive('taxInvoices')->once()->andReturn($mockTaxInvoices);

        $mockShippings = Mockery::mock(HasMany::class);
        $mockShippings->shouldReceive('delete')->once();
        $order->shouldReceive('shippings')->once()->andReturn($mockShippings);

        $mockAddresses = Mockery::mock(HasMany::class);
        $mockAddresses->shouldReceive('delete')->once();
        $order->shouldReceive('addresses')->once()->andReturn($mockAddresses);

        $mockPayment = Mockery::mock(HasOne::class);
        $mockPayment->shouldReceive('delete')->once();
        $order->shouldReceive('payment')->once()->andReturn($mockPayment);

        $mockOptions = Mockery::mock(HasMany::class);
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

    /**
     * 일괄 상태 변경이 스냅샷을 잡고 주문·옵션 상태를 함께 바꾸며, 스코프 재적용과 전이 알림용 조회를 정확히 두 번만 하고 요청·처리 건수를 돌려주는지 확인한다.
     *
     * @scenario area=bulk
     *
     * @effects bulk_status_update_syncs_options_and_counts
     *
     * @return void 반환값 없음
     */
    public function test_bulk_update_processes_multiple_orders(): void
    {
        // Given: 여러 주문 ID와 상태
        $ids = [1, 2, 3];
        $data = [
            'ids' => $ids,
            'order_status' => 'payment_complete',
        ];

        // ChangeDetector용 스냅샷 조회 (Repository 위임)
        $this->mockRepository
            ->shouldReceive('getSnapshotsByIds')
            ->with($ids)
            ->once()
            ->andReturn([]);

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

        // 전이 알림 발화용(fresh 조회) + 서비스 진입부의 스코프 재적용 조회 두 곳에서 호출된다.
        // 일괄 라우트는 라우트 모델이 없어 미들웨어 스코프 검사가 스킵되므로 서비스가 재적용한다.
        // 상한을 없애지 않고 2회로 좁힌다 — 상한을 지우면 중복 조회 회귀를 못 잡는다.
        $this->mockRepository
            ->shouldReceive('findByIdsKeyed')
            ->with($ids)
            ->twice()
            ->andReturn(new Collection);

        // When: bulkUpdate 호출
        $result = $this->service->bulkUpdate($data);

        // Then: 처리된 개수 반환
        $this->assertEquals(3, $result['updated_count']);
        $this->assertEquals(3, $result['requested_count']);
    }

    /**
     * 일괄 배송정보 변경이 택배사·송장번호를 리포지토리에 넘기고 스코프 재적용 조회를 한 번만 한 뒤 처리 건수를 돌려주는지 확인한다.
     *
     * @scenario area=bulk
     *
     * @effects bulk_shipping_update_delegates_carrier_and_tracking
     *
     * @return void 반환값 없음
     */
    public function test_bulk_update_with_shipping_info(): void
    {
        // Given: 배송 정보 변경
        $ids = [1, 2];
        $data = [
            'ids' => $ids,
            'carrier_id' => 1,
            'tracking_number' => '123456789012',
        ];

        // ChangeDetector용 스냅샷 조회 (Repository 위임)
        $this->mockRepository
            ->shouldReceive('getSnapshotsByIds')
            ->with($ids)
            ->once()
            ->andReturn([]);

        $this->mockRepository
            ->shouldReceive('bulkUpdateShipping')
            ->with($ids, 1, '123456789012')
            ->once()
            ->andReturn(2);

        // 서비스 진입부의 스코프 재적용 조회 — 일괄 라우트는 라우트 모델이 없어
        // PermissionMiddleware 의 스코프 검사가 스킵되므로 서비스가 재적용한다.
        $this->mockRepository
            ->shouldReceive('findByIdsKeyed')
            ->once()
            ->andReturn(new Collection);

        // When: bulkUpdate 호출
        $result = $this->service->bulkUpdate($data);

        // Then: 처리된 개수 반환
        $this->assertEquals(2, $result['updated_count']);
    }

    // ========================================
    // getByOrderNumber() 테스트
    // ========================================

    /**
     * 주문번호로 조회하면 리포지토리 findByOrderNumber 결과를 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects order_lookup_by_order_number
     *
     * @return void 반환값 없음
     */
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

    // ========================================
    // 상태 전이 알림 훅 (A35/A36/D9) + IDV 가드 (A8)
    // ========================================

    /**
     * update 가 상태 전이 시 order.after_status_change 훅을 1회 발화한다.
     *
     * @scenario transition_path=update, target_status=shipping, previous_status=different, order_count=single
     *
     * @effects update_fires_after_status_change_on_transition
     *
     * @return void 반환값 없음
     */
    public function test_update_fires_after_status_change_on_transition(): void
    {
        $order = new Order(['id' => 21, 'order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updated = new Order(['order_status' => OrderStatusEnum::SHIPPING->value]);
        $updated->id = 21;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updated);
        $this->mockRepository->shouldReceive('bulkUpdateOptionStatus')->once()->andReturn(1);

        $fired = [];
        $cb = function ($o, $prev = null) use (&$fired) {
            $fired[] = $prev;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_change', $cb, 1);

        try {
            $this->service->update($order, ['order_status' => OrderStatusEnum::SHIPPING->value]);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.order.after_status_change', $cb);
        }

        $this->assertCount(1, $fired);
        $this->assertSame(OrderStatusEnum::PAYMENT_COMPLETE->value, $fired[0]);
    }

    /**
     * update 가 상태 미전이(동일 상태) 시 after_status_change 를 발화하지 않는다.
     *
     * @scenario transition_path=update, target_status=shipping, previous_status=same_as_target, order_count=single
     *
     * @effects no_fire_when_status_unchanged_in_bulk
     *
     * @return void 반환값 없음
     */
    public function test_update_does_not_fire_when_status_unchanged(): void
    {
        $order = new Order(['id' => 22, 'order_status' => OrderStatusEnum::SHIPPING->value]);
        $updated = new Order(['order_status' => OrderStatusEnum::SHIPPING->value]);
        $updated->id = 22;

        // order_status 가 data 에 없음 → 동기화/전이 없음
        $this->mockRepository->shouldReceive('update')->once()->andReturn($updated);

        $fired = 0;
        $cb = function () use (&$fired) {
            $fired++;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_change', $cb, 1);

        try {
            $this->service->update($order, ['admin_memo' => '메모만 수정']);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.order.after_status_change', $cb);
        }

        $this->assertSame(0, $fired);
    }

    /**
     * update 로 payment_complete 전이 시 비-DBANK 주문은 IDV approve 훅을 발화한다 (결정 7).
     *
     * @scenario policy=approve, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects admin_update_pg_fires_approve
     *
     * @return void 반환값 없음
     */
    public function test_update_to_payment_complete_fires_idv_approve_for_non_dbank(): void
    {
        $order = new Order(['id' => 23, 'order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        $order->setRelation('payment', new OrderPayment(['payment_method' => 'card']));
        $updated = new Order(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updated->id = 23;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updated);
        $this->mockRepository->shouldReceive('bulkUpdateOptionStatus')->once()->andReturn(1);

        $approveFired = false;
        $depositFired = false;
        $cbA = function () use (&$approveFired) {
            $approveFired = true;
        };
        $cbD = function () use (&$depositFired) {
            $depositFired = true;
        };
        HookManager::addAction('sirsoft-ecommerce.payment.before_approve', $cbA, 1);
        HookManager::addAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cbD, 1);

        try {
            $this->service->update($order, ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.payment.before_approve', $cbA);
            HookManager::removeAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cbD);
        }

        $this->assertTrue($approveFired, 'non-DBANK payment_complete 전이는 approve 훅을 발화해야 함');
        $this->assertFalse($depositFired, 'non-DBANK 는 confirm_deposit 훅을 발화하지 않아야 함');
    }

    /**
     * update 로 payment_complete 전이 시 DBANK 주문은 confirm_deposit 훅을 발화한다 (결정 7).
     *
     * @scenario policy=confirm_deposit, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects admin_update_dbank_fires_confirm_deposit, dbank_update_does_not_fire_approve
     *
     * @return void 반환값 없음
     */
    public function test_update_to_payment_complete_fires_confirm_deposit_for_dbank(): void
    {
        $order = new Order(['id' => 24, 'order_status' => OrderStatusEnum::PENDING_PAYMENT->value]);
        $order->setRelation('payment', new OrderPayment(['payment_method' => 'dbank']));
        $updated = new Order(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        $updated->id = 24;

        $this->mockRepository->shouldReceive('update')->once()->andReturn($updated);
        $this->mockRepository->shouldReceive('bulkUpdateOptionStatus')->once()->andReturn(1);

        $approveFired = false;
        $depositFired = false;
        $cbA = function () use (&$approveFired) {
            $approveFired = true;
        };
        $cbD = function () use (&$depositFired) {
            $depositFired = true;
        };
        HookManager::addAction('sirsoft-ecommerce.payment.before_approve', $cbA, 1);
        HookManager::addAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cbD, 1);

        try {
            $this->service->update($order, ['order_status' => OrderStatusEnum::PAYMENT_COMPLETE->value]);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.payment.before_approve', $cbA);
            HookManager::removeAction('sirsoft-ecommerce.payment.before_confirm_deposit', $cbD);
        }

        $this->assertTrue($depositFired, 'DBANK payment_complete 전이는 confirm_deposit 훅을 발화해야 함');
        $this->assertFalse($approveFired, 'DBANK 는 approve 훅을 발화하지 않아야 함 (분기 역검증)');
    }

    /**
     * bulkUpdate 가 payment_complete 일괄 전이 시 IDV approve 를 배치당 1회만 발화한다 (결정 4).
     *
     * @scenario policy=approve, enabled=on, actor=admin, verified_state=unverified
     *
     * @effects bulk_update_fires_approve_once
     *
     * @return void 반환값 없음
     */
    public function test_bulk_update_fires_idv_approve_once(): void
    {
        $ids = [31, 32, 33];
        $this->mockRepository->shouldReceive('getSnapshotsByIds')->with($ids)->once()->andReturn([]);
        $this->mockRepository->shouldReceive('bulkUpdateStatus')->once()->andReturn(3);
        $this->mockRepository->shouldReceive('bulkUpdateOptionStatus')->once()->andReturn(3);
        // 스코프 재적용 조회 + 전이 알림 fresh 조회 = 2회 (상한 유지)
        $this->mockRepository->shouldReceive('findByIdsKeyed')->twice()->andReturn(new Collection);

        $approveCount = 0;
        $cb = function () use (&$approveCount) {
            $approveCount++;
        };
        HookManager::addAction('sirsoft-ecommerce.payment.before_approve', $cb, 1);

        try {
            $this->service->bulkUpdate(['ids' => $ids, 'order_status' => 'payment_complete']);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.payment.before_approve', $cb);
        }

        $this->assertSame(1, $approveCount, 'approve enforce 는 배치당 1회만 발화해야 함');
    }
}
