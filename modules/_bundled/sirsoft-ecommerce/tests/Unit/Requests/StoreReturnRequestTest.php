<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Requests;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreRefundRequestRequest;
use Modules\Sirsoft\Ecommerce\Http\Requests\User\StoreReturnRequest;
use Modules\Sirsoft\Ecommerce\Models\ClaimReason;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 반품·취소/환불 신청 요청 검증 테스트 (S5)
 *
 * 교환 요청과 같은 강도 — 소유권 404 가 상품 소속 422 보다 먼저이고, 비회원 경로는 토큰이 확정한
 * 주문(`guest_order` attribute)만 쓴다.
 */
class StoreReturnRequestTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['return', 'refund'] as $type) {
            ClaimReason::firstOrCreate(
                ['type' => $type, 'code' => 'changed_mind'],
                [
                    'name' => ['ko' => '변심', 'en' => 'Changed mind'],
                    'fault_type' => ClaimReasonFaultTypeEnum::CUSTOMER,
                    'is_user_selectable' => true,
                    'is_active' => true,
                    'sort_order' => 0,
                ]
            );
        }
    }

    /**
     * 배송지가 붙은 회원 주문과 상품 한 건을 만듭니다.
     *
     * @return array{0: Order, 1: OrderOption} [주문, 상품]
     */
    protected function orderWithOption(): array
    {
        $order = Order::factory()->create();
        OrderAddress::factory()->create(['order_id' => $order->id, 'address_type' => 'shipping']);
        $option = OrderOption::factory()->create(['order_id' => $order->id]);

        return [$order->fresh(['shippingAddress']), $option];
    }

    /**
     * 요청 전체(규칙 + withValidator)를 실제 검증 절차로 돌립니다.
     *
     * @param  class-string  $class  요청 클래스
     * @param  Order|null  $order  라우트 주문 (회원 경로)
     * @param  array  $data  본문
     * @param  Order|null  $guestOrder  토큰 검증 주문 (비회원 경로)
     * @return object 검증을 마친 요청
     */
    protected function resolve(string $class, ?Order $order, array $data, ?Order $guestOrder = null): object
    {
        $request = $class::create('/user/orders/'.($order?->id ?? 0).'/returns', 'POST', $data);
        $request->setRouteResolver(function () use ($order) {
            $route = new Route('POST', 'user/orders/{id}/returns', []);
            $route->parameters = ['id' => $order?->id ?? 0];

            return $route;
        });

        if ($guestOrder !== null) {
            $request->attributes->set('guest_order', $guestOrder);
        }

        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    protected function validBody(OrderOption $option, array $overrides = []): array
    {
        return array_merge([
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ], $overrides);
    }

    public function test_return_request_passes_for_the_owner_and_builds_the_service_payload(): void
    {
        [$order, $option] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));

        $request = $this->resolve(StoreReturnRequest::class, $order, $this->validBody($option, [
            'charge_method' => 'pay_on_request',
            'payment_method' => 'card',
            'image_ids' => [3, 4],
        ]));

        $payload = $request->toServicePayload();
        $this->assertSame($order->id, $request->getOrder()->id);
        $this->assertSame($option->id, $payload['items'][0]['order_option_id']);
        $this->assertSame('pay_on_request', $payload['charge_method']);
        $this->assertSame($order->user_id, $payload['requested_by']);
        $this->assertSame('card', $request->paymentMethod());
        $this->assertSame([3, 4], $request->imageIds());
    }

    public function test_return_request_ownership_404_precedes_the_item_scope_422(): void
    {
        [$order] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));
        [$someoneElses] = $this->orderWithOption();

        try {
            $this->resolve(StoreReturnRequest::class, $someoneElses, $this->validBody(OrderOption::factory()->make(['id' => 999999])));
            $this->fail('다른 회원의 주문이 통과했습니다.');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }
    }

    public function test_return_request_rejects_items_of_another_order_with_422(): void
    {
        [$order] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));
        [, $foreign] = $this->orderWithOption();

        try {
            $this->resolve(StoreReturnRequest::class, $order, $this->validBody($foreign));
            $this->fail('다른 주문의 상품이 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('sirsoft-ecommerce::exceptions.claim_option_not_found'),
                $e->validator->errors()->first('items.0.order_option_id')
            );
        }
    }

    public function test_return_request_rejects_a_payment_method_outside_the_setting(): void
    {
        [$order, $option] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));

        try {
            $this->resolve(StoreReturnRequest::class, $order, $this->validBody($option, ['payment_method' => 'vbank']));
            $this->fail('허용되지 않은 결제 수단이 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payment_method', $e->validator->errors()->toArray());
        }
    }

    public function test_guest_route_uses_the_token_order_and_a_null_requester(): void
    {
        $order = Order::factory()->forGuest()->create();
        OrderAddress::factory()->create(['order_id' => $order->id, 'address_type' => 'shipping']);
        $option = OrderOption::factory()->create(['order_id' => $order->id]);

        $request = $this->resolve(StoreReturnRequest::class, null, $this->validBody($option), $order->fresh(['shippingAddress']));

        $this->assertSame($order->id, $request->getOrder()->id);
        $this->assertNull($request->toServicePayload()['requested_by']);
    }

    public function test_refund_request_requires_a_complete_refund_bank_when_any_field_is_given(): void
    {
        [$order, $option] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));

        try {
            $this->resolve(StoreRefundRequestRequest::class, $order, $this->validBody($option, [
                'refund_bank' => ['bank_code' => '004'],
            ]));
            $this->fail('불완전한 환불 계좌가 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund_bank', $e->validator->errors()->toArray());
        }

        $request = $this->resolve(StoreRefundRequestRequest::class, $order, $this->validBody($option, [
            'refund_bank' => ['bank_code' => '004', 'account_number' => '1', 'holder' => 'h'],
        ]));

        $this->assertSame('004', $request->toServicePayload()['refund_bank']['bank_code']);
    }

    public function test_refund_request_ownership_404_precedes_the_item_scope_422(): void
    {
        [$order] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));
        [$someoneElses] = $this->orderWithOption();

        try {
            $this->resolve(StoreRefundRequestRequest::class, $someoneElses, $this->validBody(OrderOption::factory()->make(['id' => 999999])));
            $this->fail('다른 회원의 주문이 통과했습니다.');
        } catch (HttpResponseException $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }
    }

    public function test_refund_request_without_a_bank_yields_a_null_bank(): void
    {
        [$order, $option] = $this->orderWithOption();
        $this->actingAs(User::findOrFail($order->user_id));

        $request = $this->resolve(StoreRefundRequestRequest::class, $order, $this->validBody($option));

        $this->assertNull($request->toServicePayload()['refund_bank']);
        $this->assertSame([], $request->imageIds());
    }
}
