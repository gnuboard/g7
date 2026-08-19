<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\User;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderModificationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 배송지 변경 API 테스트
 */
class OrderShippingAddressTest extends ModuleTestCase
{
    private User $user;

    private Order $order;

    private OrderAddress $shippingAddress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->order = Order::factory()->paid()->forUser($this->user)->create();
        $this->shippingAddress = OrderAddress::factory()->shipping()->forOrder($this->order)->create();
    }

    /**
     * 배송지 URL 생성 헬퍼
     *
     * @param  int  $orderId  주문 ID
     * @return string
     */
    private function url(int $orderId): string
    {
        return "/api/modules/sirsoft-ecommerce/user/orders/{$orderId}/shipping-address";
    }

    /**
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_uses_user_endpoint_without_token_header_for_member
     */
    public function test_update_shipping_address_with_manual_input(): void
    {
        $data = [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구 서초대로 123',
            'address_detail' => '456동 789호',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('김철수', $this->shippingAddress->recipient_name);
        $this->assertEquals('010-9999-8888', $this->shippingAddress->recipient_phone);
        $this->assertEquals('54321', $this->shippingAddress->zipcode);
        $this->assertEquals('서울시 서초구 서초대로 123', $this->shippingAddress->address);
    }

    /**
     * @scenario actor=member, change_mode=saved, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_saved_mode_sends_address_id_manual_sends_full_object
     */
    public function test_update_shipping_address_with_saved_address(): void
    {
        $savedAddress = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'recipient_name' => '박영희',
            'recipient_phone' => '010-1111-2222',
            'zipcode' => '11111',
            'address' => '부산시 해운대구',
            'address_detail' => '마린시티',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $savedAddress->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('박영희', $this->shippingAddress->recipient_name);
        $this->assertEquals('부산시 해운대구', $this->shippingAddress->address);
    }

    /**
     * 저장된 해외 배송지를 선택해도 해외 주소가 보존되어야 한다.
     *
     * 종전에는 address_id 분기가 요청 데이터를 국내 6필드로만 재구성해, 해외 주소를
     * 고르면 해외 컬럼이 전부 null 로 덮이고 국내 컬럼도 빈 문자열이라 배송지가
     * 통째로 사라졌다. 응답은 200 이고 오류도 남지 않는다.
     *
     * @scenario actor=member, change_mode=saved, address_region=intl, e2e_browser=chromium
     *
     * @effects shipping_address_saved_intl_address_preserves_intl_columns
     */
    public function test_update_shipping_address_with_saved_intl_address_preserves_intl_columns(): void
    {
        $savedAddress = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'recipient_name' => 'John Doe',
            'recipient_phone' => '+1-202-555-0143',
            'country_code' => 'US',
            'zipcode' => null,
            'address' => null,
            'address_detail' => null,
            'address_line_1' => '1600 Pennsylvania Ave NW',
            'address_line_2' => 'Suite 200',
            'city' => 'Washington',
            'state' => 'DC',
            'postal_code' => '20500',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $savedAddress->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('US', $this->shippingAddress->recipient_country_code);
        $this->assertEquals('1600 Pennsylvania Ave NW', $this->shippingAddress->address_line_1);
        $this->assertEquals('Suite 200', $this->shippingAddress->address_line_2);
        $this->assertEquals('Washington', $this->shippingAddress->intl_city);
        $this->assertEquals('DC', $this->shippingAddress->intl_state);
        $this->assertEquals('20500', $this->shippingAddress->intl_postal_code);
        // 국내 컬럼은 비활성 측이므로 초기화된다 (NOT NULL → 빈 문자열)
        $this->assertSame('', $this->shippingAddress->zipcode);
        $this->assertSame('', $this->shippingAddress->address);
    }

    /**
     * 저장된 배송지를 선택하면서 함께 보낸 배송 메모가 반영되어야 한다.
     *
     * address_id 분기가 요청 데이터를 통째로 갈아치우면서 delivery_memo 가 조용히
     * 버려졌다 — 검증은 통과하고 응답도 200 이라 입력이 사라진 것이 드러나지 않는다.
     *
     * @scenario actor=member, change_mode=saved, address_region=domestic, e2e_browser=chromium
     *
     * @effects shipping_address_saved_mode_preserves_delivery_memo_from_request
     */
    public function test_update_shipping_address_with_saved_address_keeps_delivery_memo(): void
    {
        $savedAddress = UserAddress::factory()->create([
            'user_id' => $this->user->id,
            'recipient_name' => '박영희',
            'recipient_phone' => '010-1111-2222',
            'country_code' => 'KR',
            'zipcode' => '11111',
            'address' => '부산시 해운대구',
            'address_detail' => '마린시티',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $savedAddress->id,
                'delivery_memo' => '부재 시 문 앞에 놓아주세요',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('부산시 해운대구', $this->shippingAddress->address);
        $this->assertEquals('부재 시 문 앞에 놓아주세요', $this->shippingAddress->delivery_memo);
    }

    public function test_update_shipping_address_fails_after_shipping(): void
    {
        $order = Order::factory()->shipping()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_requires_authentication(): void
    {
        $response = $this->putJson($this->url($this->order->id), [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구',
        ]);

        $response->assertUnauthorized();
    }

    public function test_update_shipping_address_denied_for_other_user(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertNotFound();
    }

    public function test_update_shipping_address_validates_required_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'recipient_phone']);
    }

    public function test_update_shipping_address_with_saved_address_rejects_other_user_address(): void
    {
        $otherUser = User::factory()->create();
        $otherAddress = UserAddress::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => $otherAddress->id,
            ]);

        // address_id는 DB에 존재하지만 다른 유저 소유 → FormRequest에서 검증 실패
        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id']);
    }

    public function test_update_shipping_address_with_delivery_memo(): void
    {
        $data = [
            'recipient_name' => '김철수',
            'recipient_phone' => '010-9999-8888',
            'zipcode' => '54321',
            'address' => '서울시 서초구 서초대로 123',
            'address_detail' => '456동 789호',
            'delivery_memo' => '부재 시 경비실에 맡겨주세요',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->shippingAddress->refresh();
        $this->assertEquals('부재 시 경비실에 맡겨주세요', $this->shippingAddress->delivery_memo);
    }

    public function test_update_shipping_address_fails_when_delivered(): void
    {
        $order = Order::factory()->delivered()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_fails_when_cancelled(): void
    {
        $order = Order::factory()->cancelled()->forUser($this->user)->create();
        OrderAddress::factory()->shipping()->forOrder($order)->create();

        $response = $this->actingAs($this->user)
            ->putJson($this->url($order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_shipping_address_rejects_nonexistent_address_id(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'address_id' => 99999,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['address_id']);
    }

    public function test_update_shipping_address_response_contains_order_data(): void
    {
        $data = [
            'recipient_name' => '이응답',
            'recipient_phone' => '010-5555-6666',
            'zipcode' => '12345',
            'address' => '서울시 강남구',
        ];

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), $data);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => ['order'],
            ]);
    }

    // ================================================================
    // 예외 → 상태코드 분리 (#104)
    //
    // 종전에는 모든 예외를 422 + "배송 전에만 변경 가능" 으로 뭉갰다. 인프라 장애까지
    // 입력 오류로 위장되어 장애 인지가 늦어진다.
    // ================================================================

    /**
     * 도메인 예외(배송 전 상태 아님)는 422 + 기존 안내 메시지를 유지한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects update_shipping_address_domain_exception_returns_422_with_cannot_modify_address
     */
    public function test_domain_exception_returns_422_with_cannot_modify_address(): void
    {
        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('getDetail')->andReturn($this->order);
            $mock->shouldReceive('updateShippingAddress')
                ->andThrow(new OrderModificationException('배송 전 상태에서만 변경할 수 있습니다.'));
        });

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구 서초대로 123',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', __('sirsoft-ecommerce::messages.orders.cannot_modify_address'));
    }

    /**
     * 도메인 예외가 아닌 예외(인프라/코드 결함)는 500 으로 구분되어야 한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects update_shipping_address_infrastructure_exception_returns_500_not_422, update_shipping_address_500_response_excludes_exception_message
     */
    public function test_infrastructure_exception_returns_500_not_422(): void
    {
        $this->mock(OrderService::class, function ($mock) {
            $mock->shouldReceive('getDetail')->andReturn($this->order);
            $mock->shouldReceive('updateShippingAddress')
                ->andThrow(new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation'));
        });

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김철수',
                'recipient_phone' => '010-9999-8888',
                'zipcode' => '54321',
                'address' => '서울시 서초구 서초대로 123',
            ]);

        $response->assertStatus(500);

        // 예외 원문이 응답에 실려서는 안 된다
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
    }

    // ================================================================
    // 국내/해외 주소 조합 정합성 (F3)
    //
    // country_code 가 국내/해외를 가르는 기준축인데 nullable 이라, 해외 필드만 채우고
    // country_code 를 생략하면 검증을 통과한 뒤 서비스가 국내 분기로 떨어져
    // NOT NULL 컬럼(zipcode/address)에 null 을 쓰려다 SQL 무결성 위반이 났다.
    // ================================================================

    /**
     * country_code 없이 해외 주소 조합만 보내면 국내 필수값 검증으로 차단되어야 한다.
     *
     * 수정 전에는 검증을 통과해 SQL 23000(zipcode cannot be null)이 발생했고,
     * 그 인프라 예외가 "배송 전에만 변경 가능" 이라는 엉뚱한 도메인 메시지로 위장됐다.
     *
     * @scenario actor=member, change_mode=manual, address_region=intl_without_country_code, e2e_browser=chromium
     *
     * @effects shipping_address_country_code_is_the_axis_for_domestic_vs_intl_validation, shipping_address_intl_combination_without_country_code_returns_422_validation_errors
     */
    public function test_intl_address_without_country_code_is_blocked_by_validation(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'intl_city' => 'Mountain View',
                'intl_postal_code' => '94043',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode', 'address']);
    }

    /**
     * country_code=KR 을 명시하면서 zipcode 를 생략하면 검증으로 차단되어야 한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects shipping_address_domestic_country_code_requires_zipcode_and_address
     */
    public function test_domestic_country_code_requires_zipcode_and_address(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김국내',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'KR',
                'address_line_1' => '1600 Amphitheatre Parkway',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['zipcode', 'address']);
    }

    /**
     * country_code 가 해외인데 해외 필수값이 비면 검증으로 차단되어야 한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects shipping_address_foreign_country_code_requires_address_line_1_intl_city_intl_postal_code
     */
    public function test_foreign_country_code_requires_intl_fields(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'US',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address_line_1', 'intl_city', 'intl_postal_code']);
    }

    /**
     * 해외 주소로 전환하면 해외 컬럼이 기록되고 국내 컬럼은 초기화되어야 한다.
     *
     * 초기화하지 않으면 구 국내 주소가 그대로 남아 배송지가 두 벌이 된다.
     *
     * @scenario actor=member, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects shipping_address_switching_to_intl_clears_domestic_columns_with_empty_string
     */
    public function test_switching_to_intl_address_clears_domestic_columns(): void
    {
        $this->shippingAddress->update([
            'recipient_country_code' => 'KR',
            'zipcode' => '54321',
            'address' => '서울시 서초구 서초대로 123',
            'address_detail' => '456동 789호',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'US',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'intl_city' => 'Mountain View',
                'intl_state' => 'CA',
                'intl_postal_code' => '94043',
            ]);

        $response->assertOk();

        $this->shippingAddress->refresh();
        $this->assertSame('US', $this->shippingAddress->recipient_country_code);
        $this->assertSame('1600 Amphitheatre Parkway', $this->shippingAddress->address_line_1);
        $this->assertSame('Mountain View', $this->shippingAddress->intl_city);
        $this->assertSame('94043', $this->shippingAddress->intl_postal_code);

        // 국내 컬럼은 NOT NULL 이므로 빈 문자열로 초기화
        $this->assertSame('', $this->shippingAddress->zipcode);
        $this->assertSame('', $this->shippingAddress->address);
        $this->assertNull($this->shippingAddress->address_detail);
    }

    /**
     * 국내 → 해외 → 국내 왕복 시 매번 반대편 컬럼이 초기화되어야 한다.
     *
     * @scenario actor=member, change_mode=manual, address_region=intl, e2e_browser=chromium
     *
     * @effects shipping_address_switching_to_domestic_clears_intl_columns_with_null
     */
    public function test_round_trip_between_domestic_and_intl_clears_opposite_columns(): void
    {
        // 1) 해외로 전환
        $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김해외',
                'recipient_phone' => '010-9999-8888',
                'country_code' => 'US',
                'address_line_1' => '1600 Amphitheatre Parkway',
                'intl_city' => 'Mountain View',
                'intl_postal_code' => '94043',
            ])->assertOk();

        // 2) 다시 국내로 전환
        $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김국내',
                'recipient_phone' => '010-1111-2222',
                'country_code' => 'KR',
                'zipcode' => '06236',
                'address' => '서울 강남구 테헤란로 1',
                'address_detail' => '10층',
            ])->assertOk();

        $this->shippingAddress->refresh();
        $this->assertSame('KR', $this->shippingAddress->recipient_country_code);
        $this->assertSame('06236', $this->shippingAddress->zipcode);
        $this->assertSame('서울 강남구 테헤란로 1', $this->shippingAddress->address);

        // 해외 컬럼은 nullable 이므로 null 로 초기화
        $this->assertNull($this->shippingAddress->address_line_1);
        $this->assertNull($this->shippingAddress->intl_city);
        $this->assertNull($this->shippingAddress->intl_state);
        $this->assertNull($this->shippingAddress->intl_postal_code);
    }

    /**
     * 해외 배송지 주문에서 국내 주소를 직접 입력하면 그 주소가 저장되어야 한다.
     *
     * 배송지 변경 모달의 직접 입력 폼은 국내 필드만 제공하므로 country_code 를 보내지
     * 않는다. FormRequest 게이트는 "국가 미전송 = 국내" 로 판정해 zipcode/address 를
     * 필수로 검증하는데, 서비스가 기존 주소의 국가(US)를 승계해 해외 분기로 떨어지면
     * 방금 검증한 국내 입력을 통째로 버리고 빈 문자열을 쓴다 — 응답은 200 이고
     * 토스트도 성공이라 주소가 사라진 사실이 화면 재조회 전까지 드러나지 않는다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects shipping_address_manual_domestic_input_on_intl_order_is_saved_not_discarded
     */
    public function test_manual_domestic_input_on_intl_order_saves_domestic_address(): void
    {
        // 주문 배송지를 해외 상태로 만든다
        $this->shippingAddress->update([
            'recipient_country_code' => 'US',
            'zipcode' => '',
            'address' => '',
            'address_detail' => null,
            'address_line_1' => '1600 Amphitheatre Parkway',
            'address_line_2' => 'Building 42',
            'intl_city' => 'Mountain View',
            'intl_state' => 'CA',
            'intl_postal_code' => '94043',
        ]);

        // 직접 입력 폼과 동일한 페이로드 — country_code 를 보내지 않는다
        $response = $this->actingAs($this->user)
            ->putJson($this->url($this->order->id), [
                'recipient_name' => '김국내',
                'recipient_phone' => '010-1111-2222',
                'zipcode' => '06134',
                'address' => '서울특별시 강남구 테헤란로 152',
                'address_detail' => '10층',
            ]);

        $response->assertOk();

        $this->shippingAddress->refresh();
        $this->assertSame('KR', $this->shippingAddress->recipient_country_code);
        $this->assertSame('06134', $this->shippingAddress->zipcode);
        $this->assertSame('서울특별시 강남구 테헤란로 152', $this->shippingAddress->address);
        $this->assertSame('10층', $this->shippingAddress->address_detail);

        // 해외 컬럼은 초기화된다
        $this->assertNull($this->shippingAddress->address_line_1);
        $this->assertNull($this->shippingAddress->address_line_2);
        $this->assertNull($this->shippingAddress->intl_city);
        $this->assertNull($this->shippingAddress->intl_state);
        $this->assertNull($this->shippingAddress->intl_postal_code);
    }
}
