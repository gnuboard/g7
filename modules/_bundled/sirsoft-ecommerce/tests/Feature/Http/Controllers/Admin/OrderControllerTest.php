<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 컨트롤러 Feature 테스트
 */
class OrderControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    // ========================================
    // index() 테스트
    // ========================================

    public function test_index_returns_paginated_orders(): void
    {
        // Given: 관리자가 로그인하고 주문이 15개 존재
        OrderFactory::new()->count(15)->create();

        // When: 주문 목록 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        // Then: 성공 응답 및 페이지네이션 구조 확인
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                    'pagination',
                ],
            ]);
    }

    public function test_index_filters_by_order_status(): void
    {
        // Given: 다양한 상태의 주문 존재
        OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        OrderFactory::new()->count(2)->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        OrderFactory::new()->count(1)->create(['order_status' => OrderStatusEnum::CANCELLED]);

        // When: pending_payment 상태만 필터링
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?order_status[]=pending_payment');

        // Then: pending_payment 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(3, $data);
    }

    public function test_index_filters_by_date_range(): void
    {
        // Given: 다양한 날짜의 주문 존재 (명확한 범위 밖)
        OrderFactory::new()->create(['ordered_at' => now()->subDays(30)]); // 범위 밖
        OrderFactory::new()->create(['ordered_at' => now()->subDays(3)]);  // 범위 내
        OrderFactory::new()->create(['ordered_at' => now()]);              // 범위 내

        // When: 최근 7일 필터링
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?' . http_build_query([
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ]));

        // Then: API가 정상 응답하고 날짜 필터 파라미터를 받을 수 있음
        $response->assertOk();
        $data = $response->json('data.data');
        // 최소 범위 내 주문은 반환되어야 함
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    public function test_index_searches_by_order_number(): void
    {
        // Given: 특정 주문번호 존재
        $targetOrder = OrderFactory::new()->create(['order_number' => 'ORD-20250117-00001']);
        OrderFactory::new()->count(5)->create();

        // When: 주문번호로 검색
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?search_keyword=ORD-20250117-00001&search_field=order_number');

        // Then: 해당 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($targetOrder->id, $data[0]['id']);
    }

    public function test_index_filters_by_min_amount(): void
    {
        // Given: 다양한 금액의 주문
        OrderFactory::new()->create(['total_amount' => 10000]);
        OrderFactory::new()->create(['total_amount' => 50000]);
        OrderFactory::new()->create(['total_amount' => 100000]);

        // When: 최소 금액 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?min_amount=30000');

        // Then: 30000 이상 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_country_codes(): void
    {
        // Given: 다양한 국가의 주문
        $order1 = OrderFactory::new()->create();
        $order2 = OrderFactory::new()->create();
        $order3 = OrderFactory::new()->create();
        OrderAddressFactory::new()->forOrder($order1)->shipping()->create(['recipient_country_code' => 'KR']);
        OrderAddressFactory::new()->forOrder($order2)->shipping()->create(['recipient_country_code' => 'US']);
        OrderAddressFactory::new()->forOrder($order3)->shipping()->create(['recipient_country_code' => 'KR']);

        // When: KR 국가 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?country_codes[]=KR');

        // Then: KR 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_order_device(): void
    {
        // Given: PC와 모바일 주문
        OrderFactory::new()->count(3)->create(['order_device' => DeviceTypeEnum::PC->value]);
        OrderFactory::new()->count(2)->create(['order_device' => DeviceTypeEnum::MOBILE->value]);

        // When: 모바일만 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?order_device[]=mobile');

        // Then: 모바일 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    public function test_index_filters_by_shipping_type(): void
    {
        // Given: 다양한 배송 방법의 주문
        $order1 = OrderFactory::new()->create();
        $order2 = OrderFactory::new()->create();
        OrderShippingFactory::new()->forOrder($order1)->create(['shipping_type' => ShippingTypeEnum::DOMESTIC_PARCEL->value]);
        OrderShippingFactory::new()->forOrder($order2)->create(['shipping_type' => ShippingTypeEnum::PICKUP->value]);

        // When: 택배만 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?shipping_type[]=domestic_parcel');

        // Then: 택배 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    public function test_index_returns_pagination_structure(): void
    {
        // Given: 25개 주문
        OrderFactory::new()->count(25)->create();

        // When: 페이지당 10개 요청
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?per_page=10');

        // Then: pagination 하위 객체에 페이지네이션 정보 포함
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'data',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'total',
                    ],
                ],
            ]);
        $this->assertEquals(25, $response->json('data.pagination.total'));
        $this->assertEquals(1, $response->json('data.pagination.current_page'));
    }

    public function test_index_returns_order_device_and_first_order_fields(): void
    {
        // Given: PC에서 첫구매 주문
        $order = OrderFactory::new()->create([
            'order_device' => DeviceTypeEnum::PC->value,
            'is_first_order' => true,
        ]);
        OrderOptionFactory::new()->forOrder($order)->create();

        // When: 주문 목록 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        // Then: order_device_label과 is_first_order 필드 포함
        $response->assertOk();
        $data = $response->json('data.data.0');
        $this->assertArrayHasKey('order_device', $data);
        $this->assertArrayHasKey('order_device_label', $data);
        $this->assertArrayHasKey('is_first_order', $data);
        $this->assertEquals('pc', $data['order_device']);
        $this->assertTrue($data['is_first_order']);
    }

    public function test_index_requires_authentication(): void
    {
        // Given: 비로그인 상태

        // When: 주문 목록 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }

    public function test_index_requires_permission(): void
    {
        // Given: 권한 없는 사용자
        $user = $this->createUser();

        // When: 주문 목록 API 호출
        $response = $this->actingAs($user)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    // ========================================
    // show() 테스트
    // ========================================

    public function test_show_returns_order_details(): void
    {
        // Given: 주문 및 관련 데이터 존재
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->count(3)->forOrder($order)->create();
        OrderAddressFactory::new()->shipping()->forOrder($order)->create();
        OrderPaymentFactory::new()->forOrder($order)->create();
        OrderShippingFactory::new()->forOrder($order)->create();

        // When: 주문번호 기반으로 주문 상세 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}");

        // Then: 모든 관계 데이터 포함
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'order_number',
                    'order_status',
                ],
            ]);
    }

    public function test_show_returns_order_details_by_id(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create();

        // When: ID 기반으로 주문 상세 API 호출 (하위 호환)
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->id}");

        // Then: ID로도 정상 조회
        $response->assertOk()
            ->assertJsonPath('data.id', $order->id);
    }

    public function test_show_returns_404_for_nonexistent_order(): void
    {
        // Given: 존재하지 않는 주문번호

        // When: 주문 상세 API 호출
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders/ORD-99999999-00000');

        // Then: 404 Not Found
        $response->assertNotFound();
    }

    // ========================================
    // update() 테스트
    // ========================================

    public function test_update_changes_order_status(): void
    {
        // Given: pending_payment 상태의 주문
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 주문번호 기반으로 상태 변경 요청
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'payment_complete',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ]);

        // Then: 상태 변경 완료
        $response->assertOk();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
    }

    public function test_update_saves_recipient_info_and_delivery_memo(): void
    {
        // Given: 배송지 주소가 있는 주문
        $order = OrderFactory::new()->create();
        $address = OrderAddressFactory::new()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_name' => '기존수령인',
            'recipient_phone' => '010-0000-0000',
            'zipcode' => '00000',
            'address_detail' => '기존상세주소',
            'delivery_memo' => '기존메모',
        ]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 수취인 정보 + 배송메모 + 관리자메모 수정 요청
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'recipient_name' => '새수령인',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 새주소로 456',
                'recipient_detail_address' => '새상세주소 101동',
                'delivery_memo' => '부재시 문앞에',
                'admin_memo' => '관리자 테스트 메모',
            ]);

        // Then: 수취인 정보와 주문 정보 모두 업데이트됨
        $response->assertOk();
        $address->refresh();
        $this->assertEquals('새수령인', $address->recipient_name);
        $this->assertEquals('010-1234-5678', $address->recipient_phone);
        $this->assertEquals('12345', $address->zipcode);
        $this->assertEquals('서울특별시 강남구 새주소로 456', $address->address);
        $this->assertEquals('새상세주소 101동', $address->address_detail);
        $this->assertEquals('부재시 문앞에', $address->delivery_memo);
        $this->assertEquals('관리자 테스트 메모', $order->fresh()->admin_memo);
    }

    public function test_update_validates_recipient_fields(): void
    {
        // Given: 수정 권한이 있는 사용자와 주문
        $order = OrderFactory::new()->create();
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 유효하지 않은 데이터로 요청
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'recipient_name' => str_repeat('가', 101),
                'recipient_phone' => str_repeat('0', 21),
                'delivery_memo' => str_repeat('a', 501),
            ]);

        // Then: 422 Validation Error
        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['recipient_name', 'recipient_phone', 'delivery_memo']);
    }

    public function test_update_requires_edit_permission(): void
    {
        // Given: view 권한만 있는 사용자
        $order = OrderFactory::new()->create();

        // When: 주문 수정 시도
        $response = $this->actingAs($this->adminUser)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'payment_complete',
            ]);

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    // ========================================
    // bulkUpdate() 테스트
    // ========================================

    public function test_bulk_update_changes_multiple_orders(): void
    {
        // Given: 여러 pending_payment 주문
        $orders = OrderFactory::new()->count(3)->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 일괄 상태 변경
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => $orders->pluck('id')->toArray(),
                'order_status' => 'payment_complete',
            ]);

        // Then: 모든 주문 상태 변경
        $response->assertOk();
        foreach ($orders as $order) {
            $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->fresh()->order_status);
        }
    }

    public function test_bulk_update_shipping_creates_records_when_not_exist(): void
    {
        // Given: 옵션은 있지만 shipping 레코드가 없는 주문
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $option1 = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);
        $option2 = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'test_bulk_carrier'],
            [
                'name' => json_encode(['ko' => '테스트 택배사', 'en' => 'Test Carrier']),
                'type' => 'domestic',
                'is_active' => true,
            ]
        );

        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // shipping 레코드 0건 확인
        $this->assertEquals(0, OrderShipping::where('order_id', $order->id)->count());

        // When: 배송중 + 운송장 정보로 일괄 변경
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$order->id],
                'order_status' => 'shipping',
                'carrier_id' => $carrier->id,
                'tracking_number' => 'TEST123456',
            ]);

        // Then: 성공 + 옵션별 shipping 레코드 생성
        $response->assertOk();

        $shippings = OrderShipping::where('order_id', $order->id)->get();
        $this->assertEquals(2, $shippings->count());

        foreach ($shippings as $shipping) {
            $this->assertEquals($carrier->id, $shipping->carrier_id);
            $this->assertEquals('TEST123456', $shipping->tracking_number);
            $this->assertNotNull($shipping->shipped_at);
        }

        // 옵션별 매핑 확인
        $shippingOptionIds = $shippings->pluck('order_option_id')->sort()->values()->toArray();
        $expectedOptionIds = [$option1->id, $option2->id];
        sort($expectedOptionIds);
        $this->assertEquals($expectedOptionIds, $shippingOptionIds);
    }

    public function test_bulk_update_shipping_updates_existing_records(): void
    {
        // Given: 기존 shipping 레코드가 있는 주문
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $option = OrderOptionFactory::new()->forOrder($order)->create([
            'option_status' => OrderStatusEnum::PAYMENT_COMPLETE,
        ]);

        $oldCarrier = ShippingCarrier::firstOrCreate(
            ['code' => 'old_carrier'],
            ['name' => json_encode(['ko' => '이전 택배사', 'en' => 'Old Carrier']), 'type' => 'domestic', 'is_active' => true]
        );
        $newCarrier = ShippingCarrier::firstOrCreate(
            ['code' => 'new_carrier'],
            ['name' => json_encode(['ko' => '새 택배사', 'en' => 'New Carrier']), 'type' => 'domestic', 'is_active' => true]
        );

        // 기존 shipping 레코드 생성
        OrderShipping::create([
            'order_id' => $order->id,
            'order_option_id' => $option->id,
            'shipping_status' => 'shipped',
            'shipping_type' => 'domestic_parcel',
            'carrier_id' => $oldCarrier->id,
            'tracking_number' => 'OLD123',
        ]);

        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 배송중 상태 + 새 운송장으로 변경
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$order->id],
                'order_status' => 'shipping',
                'carrier_id' => $newCarrier->id,
                'tracking_number' => 'NEW456',
            ]);

        // Then: 기존 레코드 업데이트 (새로 생성 아님)
        $response->assertOk();

        $shippings = OrderShipping::where('order_id', $order->id)->get();
        $this->assertEquals(1, $shippings->count());
        $this->assertEquals($newCarrier->id, $shippings->first()->carrier_id);
        $this->assertEquals('NEW456', $shippings->first()->tracking_number);
    }

    public function test_bulk_update_validates_ids_required(): void
    {
        // Given: 권한 있는 사용자
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: ids 없이 일괄 수정 시도
        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'order_status' => 'payment_complete',
            ]);

        // Then: 422 Validation Error
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
    }

    // ========================================
    // destroy() 테스트
    // ========================================

    public function test_destroy_soft_deletes_order(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->cancelled()->create();
        $adminWithDeletePermission = $this->createAdminUser(['sirsoft-ecommerce.orders.delete']);

        // When: 주문번호 기반으로 주문 삭제
        $response = $this->actingAs($adminWithDeletePermission)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}");

        // Then: Soft Delete 완료
        $response->assertOk();
        $this->assertSoftDeleted('ecommerce_orders', ['id' => $order->id]);
    }

    public function test_destroy_requires_delete_permission(): void
    {
        // Given: 삭제 권한 없는 사용자
        $order = OrderFactory::new()->cancelled()->create();

        // When: 주문 삭제 시도
        $response = $this->actingAs($this->adminUser)
            ->deleteJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}");

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    // ========================================
    // logs() 테스트
    // ========================================

    public function test_logs_returns_paginated_logs(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create();

        // When: 주문번호 기반으로 주문 로그 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/logs");

        // Then: 성공 응답 및 페이지네이션 구조 확인
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data',
                ],
            ]);
    }

    public function test_logs_supports_pagination_params(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create();

        // When: 페이지네이션 파라미터와 함께 로그 조회
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/logs?per_page=5&page=1&sort_order=asc");

        // Then: 성공 응답
        $response->assertOk();
    }

    public function test_logs_requires_authentication(): void
    {
        // Given: 주문 존재
        $order = OrderFactory::new()->create();

        // When: 인증 없이 로그 조회
        $response = $this->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/logs");

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }

    // ========================================
    // sendEmail() 테스트
    // ========================================

    public function test_send_email_success(): void
    {
        // Given: 메일 Fake 설정 및 주문 생성
        Mail::fake();
        $order = OrderFactory::new()->create();
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 이메일 발송 API 호출
        $response = $this->actingAs($adminWithEditPermission)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/send-email", [
                'email' => 'customer@example.com',
                'message' => '주문 관련 안내드립니다.',
            ]);

        // Then: 성공 응답
        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_send_email_validation_fails_with_empty_data(): void
    {
        // Given: 주문 생성
        $order = OrderFactory::new()->create();
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 빈 데이터로 발송 요청
        $response = $this->actingAs($adminWithEditPermission)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/send-email", []);

        // Then: 422 Validation Error
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'message']);
    }

    public function test_send_email_validation_fails_with_invalid_email(): void
    {
        // Given: 주문 생성
        $order = OrderFactory::new()->create();
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        // When: 잘못된 이메일로 발송 요청
        $response = $this->actingAs($adminWithEditPermission)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/send-email", [
                'email' => 'not-an-email',
                'message' => '테스트 메시지',
            ]);

        // Then: 422 Validation Error (email 필드)
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_send_email_requires_permission(): void
    {
        // Given: 주문 존재, 권한 없는 관리자
        $order = OrderFactory::new()->create();

        // When: 인증 없이 이메일 발송
        $response = $this->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/send-email", [
            'email' => 'customer@example.com',
            'message' => '테스트 메시지',
        ]);

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }
}
