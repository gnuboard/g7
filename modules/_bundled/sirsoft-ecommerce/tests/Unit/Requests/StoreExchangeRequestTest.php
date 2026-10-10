<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreExchangeRequest;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Rules\ExchangeRedeliveryCountryUnchanged;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 교환 신청 요청 검증 테스트 (S4 — D14 ⓒ 재발송지 국가 변경 차단)
 *
 * 교환은 해외배송을 지원하지 않는다. 재발송지는 우편번호·상세주소만 바꿀 수 있고 국가가
 * 바뀌면 FormRequest 가 422 로 거절한다 — 서비스의 같은 가드(`requestExchange`)와 **같은
 * 기준 국가**(`ExchangeRedeliveryCountryUnchanged::orderShippingCountry`)를 읽는다.
 *
 * 신청 상품의 주문 소속(배열 항목의 상위 스코프)은 소유권 404 뒤에 422 로 판정한다 —
 * 순서가 바뀌면 남의 주문에 어떤 상품이 있는지가 새어 나간다.
 */
class StoreExchangeRequestTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ClaimReason::firstOrCreate(
            ['type' => 'exchange', 'code' => 'size_mismatch'],
            [
                'name' => ['ko' => '사이즈', 'en' => 'Size'],
                'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => 0,
            ]
        );
    }

    /**
     * 배송지가 붙은 주문을 만듭니다.
     *
     * @param  string  $countryCode  배송지 국가
     * @return Order 주문
     */
    protected function orderShippedTo(string $countryCode = 'KR'): Order
    {
        $order = Order::factory()->create();

        OrderAddress::factory()->create([
            'order_id' => $order->id,
            'address_type' => 'shipping',
            'recipient_country_code' => $countryCode,
            'zipcode' => '06236',
        ]);

        return $order->fresh(['shippingAddress']);
    }

    /**
     * 주문을 라우트에 묶은 요청의 규칙으로 검증합니다.
     *
     * @param  Order|null  $order  라우트 주문
     * @param  array  $data  요청 본문
     * @return \Illuminate\Validation\Validator 검증기
     */
    protected function validate(?Order $order, array $data): \Illuminate\Validation\Validator
    {
        $request = new StoreExchangeRequest;
        $request->setRouteResolver(function () use ($order) {
            $route = new Route('POST', 'user/orders/{id}/exchanges', []);
            $route->parameters = ['id' => $order?->id ?? 0];

            return $route;
        });

        return Validator::make($data, $request->rules(), $request->messages());
    }

    /**
     * 최소 유효 본문을 만듭니다.
     *
     * @param  array  $overrides  덮어쓸 필드
     * @return array 본문
     */
    protected function validBody(array $overrides = []): array
    {
        return array_merge([
            'items' => [['order_option_id' => 1, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
        ], $overrides);
    }

    public function test_minimal_request_passes(): void
    {
        $validator = $this->validate($this->orderShippedTo(), $this->validBody());

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_redelivery_in_the_same_country_passes_in_any_letter_case(): void
    {
        $order = $this->orderShippedTo();

        foreach (['KR', 'kr'] as $code) {
            $validator = $this->validate($order, $this->validBody([
                'redelivery_address' => ['country_code' => $code, 'zipcode' => '63000'],
            ]));

            $this->assertFalse($validator->fails(), $code);
        }
    }

    public function test_redelivery_to_another_country_is_rejected(): void
    {
        $validator = $this->validate($this->orderShippedTo(), $this->validBody([
            'redelivery_address' => ['country_code' => 'JP', 'zipcode' => '100-0001'],
        ]));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            __('sirsoft-ecommerce::validation.claims.redelivery_address.country_code_unchanged'),
            $validator->errors()->first('redelivery_address.country_code')
        );
    }

    public function test_redelivery_country_is_judged_against_the_order_shipping_country(): void
    {
        // 해외 주문이라면 그 국가가 기준이다 — KR 리터럴이 아니다.
        $validator = $this->validate($this->orderShippedTo('JP'), $this->validBody([
            'redelivery_address' => ['country_code' => 'JP'],
        ]));

        $this->assertFalse($validator->fails());
        $this->assertSame('JP', ExchangeRedeliveryCountryUnchanged::orderShippingCountry($this->orderShippedTo('JP')));
    }

    public function test_redelivery_without_country_keeps_the_order_country(): void
    {
        $validator = $this->validate($this->orderShippedTo(), $this->validBody([
            'redelivery_address' => ['zipcode' => '63000', 'changed_by_user' => true],
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_rule_passes_when_the_order_is_missing_so_existence_is_judged_elsewhere(): void
    {
        // 주문 부재는 withValidator 가 404 로 답한다 — 국가 규칙이 먼저 422 를 내면 존재 여부가 새어 나간다.
        $validator = $this->validate(null, $this->validBody([
            'redelivery_address' => ['country_code' => 'JP'],
        ]));

        $this->assertFalse($validator->fails());
    }

    public function test_shape_rules_reject_invalid_inputs(): void
    {
        $order = $this->orderShippedTo();

        $this->assertArrayHasKey('items', $this->validate($order, ['reason_code' => 'size_mismatch'])->errors()->toArray());
        $this->assertArrayHasKey('items.0.quantity', $this->validate($order, $this->validBody(['items' => [['order_option_id' => 1, 'quantity' => 0]]]))->errors()->toArray());
        $this->assertArrayHasKey('reason_code', $this->validate($order, $this->validBody(['reason_code' => 'unknown']))->errors()->toArray());
        $this->assertArrayHasKey('charge_method', $this->validate($order, $this->validBody(['charge_method' => 'cash']))->errors()->toArray());
        $this->assertArrayHasKey('redelivery_address.country_code', $this->validate($order, $this->validBody(['redelivery_address' => ['country_code' => 'KOR']]))->errors()->toArray());
        $this->assertArrayHasKey('items.0.new_product_option_id', $this->validate($order, $this->validBody(['items' => [['order_option_id' => 1, 'quantity' => 1, 'new_product_option_id' => 'abc']]]))->errors()->toArray());
    }

    /**
     * 요청 전체(규칙 + `withValidator`)를 실제 검증 절차로 돌립니다.
     *
     * @param  Order|null  $order  라우트 주문
     * @param  array  $data  요청 본문
     * @return StoreExchangeRequest 검증을 마친 요청
     *
     * @throws ValidationException 422
     * @throws HttpResponseException 404 (주문 부재·소유권)
     */
    protected function resolve(?Order $order, array $data): StoreExchangeRequest
    {
        $request = StoreExchangeRequest::create('/user/orders/'.($order?->id ?? 0).'/exchanges', 'POST', $data);
        $request->setRouteResolver(function () use ($order) {
            $route = new Route('POST', 'user/orders/{id}/exchanges', []);
            $route->parameters = ['id' => $order?->id ?? 0];

            return $route;
        });
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    /**
     * 주문 상품 한 건이 붙은 배송지 주문을 만들고 그 소유자로 로그인합니다.
     *
     * @return array{0: Order, 1: OrderOption} [주문, 주문 상품]
     */
    protected function ownedOrderWithOption(): array
    {
        $order = $this->orderShippedTo();
        $option = OrderOption::factory()->create(['order_id' => $order->id]);
        $this->actingAs(User::findOrFail($order->user_id));

        return [$order, $option];
    }

    /**
     * 다른 주문의 상품 ID 는 이 요청 맥락에서 유효하지 않다 — 422 (배열 항목의 상위 스코프).
     *
     * 서비스도 같은 판정을 하지만, FormRequest 가 1차 방어다(취소 요청의 `ValidatesCancelItems` 와 같은 강도).
     */
    public function test_items_of_another_order_are_rejected_with_422(): void
    {
        [$order] = $this->ownedOrderWithOption();
        $foreign = OrderOption::factory()->create(['order_id' => Order::factory()->create()->id]);

        try {
            $this->resolve($order, $this->validBody(['items' => [['order_option_id' => $foreign->id, 'quantity' => 1]]]));
            $this->fail('다른 주문의 상품이 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('sirsoft-ecommerce::exceptions.claim_option_not_found'),
                $e->validator->errors()->first('items.0.order_option_id')
            );
        }
    }

    public function test_items_of_the_own_order_pass_the_full_request(): void
    {
        [$order, $option] = $this->ownedOrderWithOption();

        $request = $this->resolve($order, $this->validBody(['items' => [['order_option_id' => $option->id, 'quantity' => 1]]]));

        $this->assertSame($order->id, $request->getOrder()->id);
        $this->assertSame($option->id, $request->toServicePayload()['items'][0]['order_option_id']);
        $this->assertSame($order->user_id, $request->toServicePayload()['requested_by']);
    }

    /**
     * 소유권 404 가 상품 소속 422 보다 먼저다 — 앞뒤가 바뀌면 남의 주문에 어떤 상품이 있는지가 새어 나간다.
     */
    public function test_ownership_404_precedes_the_item_scope_check(): void
    {
        [$order] = $this->ownedOrderWithOption();
        $someoneElses = $this->orderShippedTo();

        try {
            $this->resolve($someoneElses, $this->validBody(['items' => [['order_option_id' => 999999, 'quantity' => 1]]]));
            $this->fail('다른 회원의 주문이 통과했습니다.');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }
    }

    public function test_charge_methods_and_custom_options_are_accepted(): void
    {
        $validator = $this->validate($this->orderShippedTo(), $this->validBody([
            'items' => [['order_option_id' => 1, 'quantity' => 2, 'new_product_option_id' => 7, 'custom_options' => ['engraving' => 'G7']]],
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST->value,
            'reason_detail' => '사이즈가 작습니다.',
        ]));

        $this->assertFalse($validator->fails(), json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE));
    }
}
