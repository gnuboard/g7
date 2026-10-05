<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Models\User;
use App\Services\NotificationDefinitionService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Models\ShippingType;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 컨트롤러 Feature 테스트
 */
class OrderControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /**
     * 모든 테스트의 기본 호출자로 주문 조회 권한(orders.read)만 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);
    }

    // ========================================
    // index() 테스트
    // ========================================

    /**
     * 조회 권한 관리자가 목록을 열면 success 와 data.data · data.pagination 봉투가 돌아온다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_returns_success_envelope_with_pagination
     *
     * @return void 반환값 없음
     */
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

    /**
     * order_status[]=pending_payment 필터는 결제대기 주문 3건만 돌려주고 결제완료·취소 주문을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_order_status
     *
     * @return void 반환값 없음
     */
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

    /**
     * 최근 7일 start_date·end_date 필터를 받아 정상 응답하고, 범위 안 주문 2건 이상을 돌려준다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_date_range
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_date_range(): void
    {
        // Given: 다양한 날짜의 주문 존재 (명확한 범위 밖)
        OrderFactory::new()->create(['ordered_at' => now()->subDays(30)]); // 범위 밖
        OrderFactory::new()->create(['ordered_at' => now()->subDays(3)]);  // 범위 내
        OrderFactory::new()->create(['ordered_at' => now()]);              // 범위 내

        // When: 최근 7일 필터링
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?'.http_build_query([
                'start_date' => now()->subDays(7)->toDateString(),
                'end_date' => now()->toDateString(),
            ]));

        // Then: API가 정상 응답하고 날짜 필터 파라미터를 받을 수 있음
        $response->assertOk();
        $data = $response->json('data.data');
        // 최소 범위 내 주문은 반환되어야 함
        $this->assertGreaterThanOrEqual(2, count($data));
    }

    /**
     * search_field=order_number 검색은 주문번호가 일치하는 주문 1건만 돌려준다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_searches_by_order_number
     *
     * @return void 반환값 없음
     */
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

    /**
     * min_amount=30000 필터는 총액 30000 이상 주문 2건만 돌려준다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_min_amount
     *
     * @return void 반환값 없음
     */
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

    /**
     * country_codes[]=KR 필터는 배송지 국가가 KR 인 주문 2건만 돌려주고 US 주문을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_shipping_country
     *
     * @return void 반환값 없음
     */
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

    /**
     * order_device[]=mobile 필터는 모바일 주문 2건만 돌려주고 PC 주문을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_order_device
     *
     * @return void 반환값 없음
     */
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

    /**
     * shipping_type[]=parcel 필터는 택배 배송 주문 1건만 돌려주고 매장수령 주문을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_filters_by_shipping_type
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_by_shipping_type(): void
    {
        // Given: 배송유형 DB 데이터 생성 (검증 통과용)
        ShippingType::firstOrCreate(['code' => 'parcel'], [
            'name' => ['ko' => '택배', 'en' => 'Parcel'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 1,
        ]);
        ShippingType::firstOrCreate(['code' => 'pickup'], [
            'name' => ['ko' => '매장수령', 'en' => 'Store Pickup'], 'category' => 'domestic', 'is_active' => true, 'sort_order' => 5,
        ]);

        // Given: 다양한 배송 방법의 주문
        $order1 = OrderFactory::new()->create();
        $order2 = OrderFactory::new()->create();
        OrderShippingFactory::new()->forOrder($order1)->create(['shipping_type' => 'parcel']);
        OrderShippingFactory::new()->forOrder($order2)->create(['shipping_type' => 'pickup']);

        // When: 택배만 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?shipping_type[]=parcel');

        // Then: 택배 주문만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
    }

    /**
     * per_page=10 으로 25건을 조회하면 pagination 에 current_page·last_page·total 이 실리고 total=25 · current_page=1 이다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_honors_per_page_and_reports_totals
     *
     * @return void 반환값 없음
     */
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

    /**
     * 목록 행에 order_device(pc)·order_device_label·is_first_order(true) 필드가 실린다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_carries_device_and_first_order_fields
     *
     * @return void 반환값 없음
     */
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

    /**
     * 현재 로케일(en) 키가 없는 다국어 옵션명을 가진 주문도 500 없이 직렬화되고, 대표 옵션명이 남은 로케일 값(ko)으로 실린다.
     *
     * 오류 핸들러로 E_NOTICE 를 ErrorException 으로 승격해 운영의 overloaded 속성 참조 회귀를 결정적으로 재현한다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_index_serializes_multilingual_option_name_without_500
     *
     * @return void 반환값 없음
     */
    public function test_index_serializes_array_product_option_name_without_error(): void
    {
        // Given: 다국어(array) product_option_name 을 가진 옵션이 포함된 주문
        // (운영에서 OrderListResource:78 의 reset($firstOption->product_option_name) 가
        //  overloaded 속성을 참조로 수정하려다 ErrorException 을 던져 500 발생한 회귀)
        // 현재 로케일(en) 키가 없는 옵션 → line 78 의 reset() fallback 경로로 진입
        app()->setLocale('en');
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_option_name' => ['ko' => '레드 / L'],
        ]);

        // 운영 환경처럼 E_NOTICE("Indirect modification of overloaded property") 를
        // ErrorException 으로 승격시켜 회귀를 결정적으로 재현
        $previous = set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
            if (! (error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            // When: 주문 목록 조회
            $response = $this->actingAs($this->adminUser)
                ->getJson('/api/modules/sirsoft-ecommerce/admin/orders');
        } finally {
            set_error_handler($previous);
        }

        // Then: 500 없이 정상 응답 + 현재 로케일 옵션명 직렬화
        $response->assertOk();
        $firstOption = $response->json('data.data.0.first_option');
        $this->assertSame('레드 / L', $firstOption['product_option_name']);
    }

    /**
     * 비로그인 요청은 주문 목록에서 401 을 받는다.
     *
     * @scenario caller=unauthenticated
     *
     * @effects admin_order_index_requires_authentication
     *
     * @return void 반환값 없음
     */
    public function test_index_requires_authentication(): void
    {
        // Given: 비로그인 상태

        // When: 주문 목록 API 호출
        $response = $this->getJson('/api/modules/sirsoft-ecommerce/admin/orders');

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }

    /**
     * 주문 권한이 없는 일반 사용자는 주문 목록에서 403 을 받는다.
     *
     * @scenario caller=no_permission_user
     *
     * @effects admin_order_index_requires_orders_read_permission
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문번호로 상세를 조회하면 옵션·배송지·결제·배송이 딸린 주문의 id·order_number·order_status 가 실린 봉투가 돌아온다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_show_resolves_by_order_number
     *
     * @return void 반환값 없음
     */
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

    /**
     * 하위 호환으로 숫자 id 를 넣어도 같은 주문 상세(data.id 일치)가 돌아온다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_show_resolves_by_numeric_id
     *
     * @return void 반환값 없음
     */
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

    /**
     * 존재하지 않는 주문번호의 상세 조회는 404 를 받는다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_show_is_404_for_unknown_order
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수정 권한 관리자가 주문번호로 PATCH 하면 결제대기 → 결제완료(인접 전진) 전이가 DB 에 반영된다.
     *
     * @scenario transition_path=single_update, from_status=pending_payment, to_status=payment_complete, classification=forward_adjacent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수정 요청의 수취인·연락처·우편번호·주소·상세주소·배송메모는 배송지 주소 행에, 관리자 메모는 주문 행에 저장된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_update_saves_recipient_address_and_memos
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수취인명 101자·연락처 21자·배송메모 501자는 각 필드 검증 오류(422)로 거절된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_update_validates_recipient_field_lengths
     *
     * @return void 반환값 없음
     */
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

    /**
     * 조회 권한만 가진 관리자의 주문 수정은 403 을 받는다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_update_requires_orders_update_permission
     *
     * @return void 반환값 없음
     */
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
    // update() 상태 전이 규칙 (A30)
    // ========================================

    /**
     * 단건 역방향 전이(구매확정 → 결제완료)는 422 로 차단되고 DB 가 무변경이어야 한다.
     *
     * @scenario transition_path=single_update, from_status=confirmed, to_status=payment_complete, classification=reverse_not_whitelisted
     *
     * @effects single_reverse_transition_blocked_and_db_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_update_blocks_reverse_status_transition(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::CONFIRMED]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'payment_complete',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['order_status']);
        // DB 무변경
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $order->fresh()->order_status);
    }

    /**
     * 단건 역방향 차단 시 상태 전이 알림 훅(after_status_change)이 미발화여야 한다.
     *
     * @scenario transition_path=single_update, from_status=confirmed, to_status=payment_complete, verify_stage=hook_fired
     *
     * @effects blocked_transition_does_not_fire_status_change_hook
     *
     * @return void 반환값 없음
     */
    public function test_blocked_transition_does_not_fire_status_change_hook(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::CONFIRMED]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $fired = [];
        $cb = function ($o, $prev = null, $current = null) use (&$fired) {
            $fired[] = $current;
        };
        HookManager::addAction('sirsoft-ecommerce.order.after_status_change', $cb, 1);

        try {
            $response = $this->actingAs($adminWithEditPermission)
                ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                    'order_status' => 'payment_complete',
                    'recipient_name' => '홍길동',
                    'recipient_phone' => '010-1234-5678',
                    'recipient_zipcode' => '12345',
                    'recipient_address' => '서울특별시 강남구 테헤란로 123',
                    'recipient_detail_address' => '101동 202호',
                ]);
        } finally {
            HookManager::removeAction('sirsoft-ecommerce.order.after_status_change', $cb);
        }

        $response->assertUnprocessable();
        // 차단되었으므로 DB 미반영 → 전이 알림 훅 미발화
        $this->assertSame([], $fired, '차단된 전이는 after_status_change 를 발화하지 않아야 함');
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $order->fresh()->order_status);
    }

    /**
     * 단건 forward 점프(결제완료 → 배송중, 중간 단계 건너뛰기)는 허용되어야 한다.
     *
     * @scenario transition_path=single_update, from_status=payment_complete, to_status=shipping, classification=forward_jump
     *
     * @effects single_forward_jump_allowed
     *
     * @return void 반환값 없음
     */
    public function test_update_allows_forward_jump_transition(): void
    {
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PAYMENT_COMPLETE]);
        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'fwd_jump_carrier'],
            ['name' => json_encode(['ko' => '점프 택배사', 'en' => 'Jump Carrier']), 'type' => 'domestic', 'is_active' => true]
        );
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'shipping',
                'carrier_id' => $carrier->id,
                'tracking_number' => 'FWD-JUMP-001',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ]);

        $response->assertOk();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $order->fresh()->order_status);
    }

    /**
     * 단건 self-transition(동일 상태)은 통과(no-op)되어야 한다.
     *
     * @scenario transition_path=single_update, from_status=shipping, to_status=shipping, classification=self
     *
     * @effects single_self_transition_allowed
     *
     * @return void 반환값 없음
     */
    public function test_update_allows_self_transition(): void
    {
        $carrier = ShippingCarrier::firstOrCreate(
            ['code' => 'self_carrier'],
            ['name' => json_encode(['ko' => '셀프 택배사', 'en' => 'Self Carrier']), 'type' => 'domestic', 'is_active' => true]
        );
        $order = OrderFactory::new()->create(['order_status' => OrderStatusEnum::SHIPPING]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}", [
                'order_status' => 'shipping',
                'carrier_id' => $carrier->id,
                'tracking_number' => 'SELF-001',
                'recipient_name' => '홍길동',
                'recipient_phone' => '010-1234-5678',
                'recipient_zipcode' => '12345',
                'recipient_address' => '서울특별시 강남구 테헤란로 123',
                'recipient_detail_address' => '101동 202호',
            ]);

        $response->assertOk();
        $this->assertEquals(OrderStatusEnum::SHIPPING, $order->fresh()->order_status);
    }

    // ========================================
    // bulkUpdate() 테스트
    // ========================================

    /**
     * 일괄 수정으로 결제대기 주문 3건을 결제완료로 바꾸면 3건 모두 DB 에 반영된다.
     *
     * @scenario transition_path=bulk_update, from_status=pending_payment, to_status=payment_complete, classification=forward_adjacent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 배송 행이 없는 주문을 일괄로 배송중 + 택배사·송장번호로 바꾸면 옵션마다 배송 행이 하나씩 생기고 택배사·송장·출고시각이 기록된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_bulk_shipping_creates_per_option_shipping_records
     *
     * @return void 반환값 없음
     */
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

    /**
     * 배송 행이 이미 있는 옵션은 일괄 송장 입력 시 새 행을 만들지 않고 기존 행의 택배사·송장번호를 갱신한다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_bulk_shipping_updates_existing_shipping_record
     *
     * @return void 반환값 없음
     */
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
            'shipping_type' => 'parcel',
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

    /**
     * ids 없이 보낸 일괄 수정은 ids 검증 오류(422)로 거절된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_bulk_update_requires_ids
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 일괄 변경: forward 가능 2건 + 역방향 위반 1건 혼합 시 전체 422 + 3건 모두 미변경(all-or-nothing).
     *
     * @scenario transition_path=bulk_update, mix=2_forward_1_reverse, to_status=payment_complete, classification=all_or_nothing
     *
     * @effects bulk_update_all_or_nothing_blocks_and_db_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_bulk_update_blocks_all_when_one_reverse_transition(): void
    {
        // forward 가능 2건(주문대기 → 결제완료) + 역방향 위반 1건(구매확정 → 결제완료)
        $ok1 = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $ok2 = OrderFactory::new()->create(['order_status' => OrderStatusEnum::PENDING_PAYMENT]);
        $violator = OrderFactory::new()->create(['order_status' => OrderStatusEnum::CONFIRMED]);
        $adminWithEditPermission = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $response = $this->actingAs($adminWithEditPermission)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/orders/bulk', [
                'ids' => [$ok1->id, $ok2->id, $violator->id],
                'order_status' => 'payment_complete',
            ]);

        // Then: 전체 422 + 위반 현재상태 라벨 포함
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['order_status']);

        // all-or-nothing: 정상 2건도 미변경
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $ok1->fresh()->order_status);
        $this->assertEquals(OrderStatusEnum::PENDING_PAYMENT, $ok2->fresh()->order_status);
        $this->assertEquals(OrderStatusEnum::CONFIRMED, $violator->fresh()->order_status);
    }

    // ========================================
    // destroy() 테스트
    // ========================================

    /**
     * 삭제 권한 관리자가 주문번호로 취소 주문을 삭제하면 소프트 삭제된다.
     *
     * @scenario caller=orders_delete_admin
     *
     * @effects admin_order_destroy_soft_deletes
     *
     * @return void 반환값 없음
     */
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

    /**
     * 조회 권한만 가진 관리자의 주문 삭제는 403 을 받는다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_destroy_requires_orders_delete_permission
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문번호로 주문 로그를 조회하면 success 와 data.data 봉투가 돌아온다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_logs_returns_success_envelope
     *
     * @return void 반환값 없음
     */
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

    /**
     * 주문 로그 조회는 per_page·page·sort_order=asc 파라미터를 받아 정상 응답한다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_logs_accepts_pagination_and_sort_params
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비로그인 요청은 주문 로그 조회에서 401 을 받는다.
     *
     * @scenario caller=unauthenticated
     *
     * @effects admin_order_logs_requires_authentication
     *
     * @return void 반환값 없음
     */
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

    /**
     * 메일 채널을 준비하고 order_custom_email 알림 정의를 동기화한 상태에서 수정 권한 관리자의 메일 발송은 success=true 로 응답한다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_send_email_succeeds_when_mail_channel_ready
     *
     * @return void 반환값 없음
     */
    public function test_send_email_success(): void
    {
        // Given: 메일 Fake 설정 및 주문 생성
        // 주문 메일은 7.1.0 부터 알림 정의(order_custom_email)·메일 채널 준비 상태를 거친다(#699).
        Mail::fake();
        $this->enableMailChannelReadiness();
        (new \ReflectionMethod(ModuleManager::class, 'syncModuleNotificationDefinitions'))
            ->invoke(app(ModuleManager::class), new Module('sirsoft-ecommerce', $this->getModuleBasePath()));
        app(NotificationDefinitionService::class)->invalidateAllCache();
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

    /**
     * 빈 본문으로 메일을 보내면 email·message 검증 오류(422)로 거절된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_send_email_requires_email_and_message
     *
     * @return void 반환값 없음
     */
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

    /**
     * 형식이 틀린 이메일 주소로 메일을 보내면 email 검증 오류(422)로 거절된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_order_send_email_rejects_invalid_email
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비로그인 요청은 주문 메일 발송에서 401 을 받는다.
     *
     * @scenario caller=unauthenticated
     *
     * @effects admin_order_send_email_requires_authentication
     *
     * @return void 반환값 없음
     */
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

    // ========================================
    // 회원/비회원 구분 필터 (member_type)
    // ========================================

    /**
     * member_type=member 필터는 회원 주문 2건만 돌려주고 비회원 주문 3건을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_list_filters_by_member_type_member_when_user_id_is_present
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_member_orders(): void
    {
        // Given: 회원 주문 2건, 비회원 주문 3건
        $member = User::factory()->create();
        OrderFactory::new()->count(2)->forUser($member)->create();
        OrderFactory::new()->count(3)->forGuest()->create();

        // When: member_type=member 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?member_type=member');

        // Then: 회원 주문 2건만 반환 (user_id 모두 존재)
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(2, $data);
    }

    /**
     * member_type=guest 필터는 비회원 주문 3건만 돌려주고 회원 주문 2건을 뺀다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_list_filters_by_member_type_guest_when_user_id_is_null
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_guest_orders(): void
    {
        // Given: 회원 주문 2건, 비회원 주문 3건
        $member = User::factory()->create();
        OrderFactory::new()->count(2)->forUser($member)->create();
        OrderFactory::new()->count(3)->forGuest()->create();

        // When: member_type=guest 필터
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?member_type=guest');

        // Then: 비회원 주문 3건만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(3, $data);
    }

    /**
     * 허용 값 밖의 member_type 은 member_type 검증 오류(422)로 거절된다.
     *
     * @scenario caller=orders_read_admin
     *
     * @effects admin_order_list_rejects_unknown_member_type_with_422
     *
     * @return void 반환값 없음
     */
    public function test_index_rejects_invalid_member_type(): void
    {
        // When: 허용되지 않은 member_type 값
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/modules/sirsoft-ecommerce/admin/orders?member_type=invalid');

        // Then: 422 Validation Error
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['member_type']);
    }

    // ========================================
    // 비회원 조회 비밀번호 재설정
    // ========================================

    /**
     * 비회원 주문의 조회 비밀번호를 재설정하면 해시가 새 비밀번호로 바뀌어 옛 비밀번호가 더는 맞지 않고, 응답에 평문이 실리지 않는다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_reset_guest_lookup_password_updates_hash_for_guest_order
     *
     * @return void 반환값 없음
     */
    public function test_reset_guest_lookup_password_succeeds_for_guest_order(): void
    {
        // Given: 조회 비밀번호 hash 가 설정된 비회원 주문, update 권한 관리자
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = OrderFactory::new()->forGuest()->create();
        $oldHash = $order->guest_lookup_password_hash;

        // When: 새 비밀번호로 재설정
        $response = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
                'guest_lookup_password' => 'newpass1',
                'guest_lookup_password_confirmation' => 'newpass1',
            ]);

        // Then: 성공 + hash 변경 + 평문은 응답에 미노출
        $response->assertOk();
        $order->refresh();
        $this->assertNotSame($oldHash, $order->guest_lookup_password_hash);
        $this->assertTrue(Hash::check('newpass1', $order->guest_lookup_password_hash));
        // 기존 비밀번호(guest12)로는 더 이상 조회 불가
        $this->assertFalse(Hash::check('guest1234', $order->guest_lookup_password_hash));
        $this->assertStringNotContainsString('newpass1', $response->getContent());
    }

    /**
     * 회원 주문에 조회 비밀번호 재설정을 시도하면 422 로 거절된다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_reset_guest_lookup_password_rejects_member_order_with_422
     *
     * @return void 반환값 없음
     */
    public function test_reset_guest_lookup_password_rejected_for_member_order(): void
    {
        // Given: 회원 주문, update 권한 관리자
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $member = User::factory()->create();
        $order = OrderFactory::new()->forUser($member)->create();

        // When: 회원 주문에 재설정 시도
        $response = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
                'guest_lookup_password' => 'newpass1',
                'guest_lookup_password_confirmation' => 'newpass1',
            ]);

        // Then: 422 거부
        $response->assertStatus(422);
    }

    /**
     * 8자 미만 비밀번호(abc12)는 guest_lookup_password 검증 오류(422)로 거절된다 — 회원가입 정책 min:8 과 같다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_reset_guest_lookup_password_enforces_min_length_8
     *
     * @return void 반환값 없음
     */
    public function test_reset_guest_lookup_password_validates_min_length(): void
    {
        // Given: 비회원 주문, update 권한 관리자
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = OrderFactory::new()->forGuest()->create();

        // When: 8자 미만 비밀번호 (G7 회원가입 정책 min:8 과 일치)
        $response = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
                'guest_lookup_password' => 'abc12',
                'guest_lookup_password_confirmation' => 'abc12',
            ]);

        // Then: 422 Validation Error (min)
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['guest_lookup_password']);
    }

    /**
     * 숫자 없이 영문 8자(abcdefgh)만으로도 재설정이 성공한다 — 영문+숫자 조합을 강제하지 않는다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_reset_guest_lookup_password_accepts_letters_only
     *
     * @return void 반환값 없음
     */
    public function test_reset_guest_lookup_password_allows_letters_only(): void
    {
        // Given: 비회원 주문, update 권한 관리자
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $order = OrderFactory::new()->forGuest()->create();

        // When: 영문만 8자 이상 (숫자 없음) — 정책상 허용
        $response = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
                'guest_lookup_password' => 'abcdefgh',
                'guest_lookup_password_confirmation' => 'abcdefgh',
            ]);

        // Then: 성공 (영문+숫자 강제 없음)
        $response->assertOk();
    }

    /**
     * 비로그인 요청은 비회원 조회 비밀번호 재설정에서 401 을 받는다.
     *
     * @scenario caller=unauthenticated
     *
     * @effects admin_reset_guest_lookup_password_requires_authentication
     *
     * @return void 반환값 없음
     */
    public function test_reset_guest_lookup_password_requires_authentication(): void
    {
        // Given: 비회원 주문
        $order = OrderFactory::new()->forGuest()->create();

        // When: 인증 없이 재설정 시도
        $response = $this->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
            'guest_lookup_password' => 'newpass1',
            'guest_lookup_password_confirmation' => 'newpass1',
        ]);

        // Then: 401 Unauthorized
        $response->assertUnauthorized();
    }

    /**
     * 관리자가 비회원 조회 비밀번호를 재설정하면 기존 verify 토큰이 즉시 무효화된다.
     *
     * GuestOrderAuthService 의 HMAC 입력에 비밀번호 해시 suffix 가 포함되어 있어
     * 비밀번호 변경 시 토큰 서명이 자동으로 불일치하는 stateless revocation 이 동작한다.
     * 이 invariant 가 회귀되면 (캐싱 추가/HMAC 입력 순서 변경 등) 본 테스트가 실패한다.
     *
     * @scenario caller=orders_update_admin
     *
     * @effects admin_reset_guest_lookup_password_revokes_existing_guest_token
     *
     * @return void 반환값 없음
     */
    public function test_admin_password_reset_invalidates_existing_guest_token(): void
    {
        // Given: 비회원 주문 + shippingAddress (verify 의 전화번호 매칭에 필요)
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);
        $phone = '010-1234-5678';
        $oldPassword = 'guest1234'; // 정책 min:8 통과
        $order = OrderFactory::new()
            ->forGuest()
            ->state(['guest_lookup_password_hash' => Hash::make($oldPassword)])
            ->create();
        OrderAddressFactory::new()->forOrder($order)->create([
            'address_type' => 'shipping',
            'orderer_phone' => $phone,
        ]);

        // 기존 비밀번호로 verify 통과 → 토큰 발급
        $verify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $order->order_number,
            'orderer_phone' => $phone,
            'guest_lookup_password' => $oldPassword,
        ]);
        $verify->assertStatus(200);
        $existingToken = $verify->json('data.guest_order_token');

        // 발급된 토큰으로 상세 조회 성공 (baseline)
        $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $existingToken]
        )->assertStatus(200);

        // When: 관리자가 새 비밀번호로 재설정
        $resetResponse = $this->actingAs($admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/reset-guest-lookup-password", [
                'guest_lookup_password' => 'newpass1',
                'guest_lookup_password_confirmation' => 'newpass1',
            ]);
        $resetResponse->assertOk();

        // 후속 비회원 호출에서 admin 세션이 이어지지 않도록 인증 상태 리셋
        // (테스트 인스턴스의 actingAs 가 후속 요청까지 유효해 user/orders/{N} 가 회원 분기로 빠지면
        //  baseline 200 검증이 404 redirect_to /mypage/orders 로 잘못 평가됨)
        // Sanctum RequestGuard 는 logout() 미지원 → forgetGuards() 로 모든 guard 해제.
        app('auth')->forgetGuards();

        // Then: 기존 토큰으로 상세 조회 시 404 (자동 무효화 — stateless revocation)
        $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $existingToken]
        )->assertStatus(404);

        // Then(보강): 새 비밀번호로는 verify + 상세 조회 모두 성공
        $newVerify = $this->postJson('/api/modules/sirsoft-ecommerce/guest/orders/verify', [
            'order_number' => $order->order_number,
            'orderer_phone' => $phone,
            'guest_lookup_password' => 'newpass1',
        ]);
        $newVerify->assertStatus(200);

        $this->getJson(
            "/api/modules/sirsoft-ecommerce/user/orders/{$order->order_number}",
            ['X-Guest-Order-Token' => $newVerify->json('data.guest_order_token')]
        )->assertStatus(200);
    }

    /**
     * 관리자 주문 상세에 진행단계 배열과 상태 아이콘 토큰이 실린다 — 회원·비회원 상세와 같은 배열.
     *
     * @scenario surface=admin_detail, order_stage=in_progress
     *
     * @effects order_progress_steps_seven_stages, order_status_badge_carries_icon
     *
     * @return void 반환값 없음
     */
    public function test_show_carries_progress_steps_and_status_icons(): void
    {
        $order = OrderFactory::new()->delivered()->create();
        OrderOptionFactory::new()->count(2)->forOrder($order)->create();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}");

        $response->assertOk()
            ->assertJsonPath('data.order_status_icon', 'status-delivered')
            ->assertJsonCount(7, 'data.progress_steps')
            ->assertJsonPath('data.progress_steps.5.value', 'delivered')
            ->assertJsonPath('data.progress_steps.5.state', 'current');

        foreach ($response->json('data.options') as $option) {
            $this->assertStringStartsWith('status-', $option['option_status_icon']);
        }
    }
}
