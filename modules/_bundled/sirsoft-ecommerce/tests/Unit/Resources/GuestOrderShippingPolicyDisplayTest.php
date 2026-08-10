<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\GuestOrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 비회원 주문 상세의 배송정책 표시 필드 정합성 테스트
 *
 * 비회원 주문 상세 화면(`shop/guest_order_show.json`)은 회원 마이페이지와 **같은
 * partial**(`partials/mypage/orders/_items.json`)을 써서 상품별 배송정책명과 개별
 * 배송비를 그린다. 그 partial 은 `shipping_policy_applied_snapshot.items[]` 에서
 * `policy.policy_name` · `policy.standalone_shipping_amount(_formatted)` 를 읽는다.
 *
 * 그런데 `GuestOrderResource` 는 그 필드를 "내부 계산 스냅샷" 으로 분류해 통째로
 * 제외했다. 결과는 오류가 아니라 **조용한 누락** — 옵셔널 체이닝이 빈 배열로 떨어져
 * `if` 가 false 가 되고, 비회원 주문 상세에서만 배송정책 줄이 항상 사라진다.
 * (브라우저 실측으로 발견: 회원 경로는 표시, 비회원 경로는 미표시)
 *
 * 내부 계산 근거(정책 id·계산 파라미터)를 비회원에게 노출하지 않는다는 기존 원칙은
 * 유지하고, 화면이 실제로 읽는 **표시용 필드만** 노출하는 것으로 해소한다.
 */
class GuestOrderShippingPolicyDisplayTest extends ModuleTestCase
{
    /**
     * 표시용 스냅샷을 담은 주문을 만듭니다.
     *
     * @return Order 스냅샷이 채워진 주문
     */
    private function makeOrderWithSnapshot(): Order
    {
        return OrderFactory::new()->make([
            'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make(
                [[
                    'product_option_id' => 481,
                    'policy' => [
                        'policy_id' => 7,
                        'policy_name' => '국내 무료배송',
                        'charge_policy' => 'free',
                        'standalone_shipping_amount' => 0,
                        'calculation_basis' => ['internal' => 'do-not-expose'],
                    ],
                ]],
                ['country_code' => 'KR', 'zipcode' => '06236'],
            ),
        ]);
    }

    /**
     * 비회원 응답이 화면이 읽는 배송정책 표시 필드를 노출하는지 확인
     */
    public function test_guest_resource_exposes_shipping_policy_display_fields(): void
    {
        // Given: 배송정책 스냅샷이 있는 비회원 주문
        $order = $this->makeOrderWithSnapshot();

        // When: 비회원 리소스로 직렬화
        $result = (new GuestOrderResource($order))->toArray(request());

        // Then: 화면이 읽는 경로가 살아 있다
        $this->assertArrayHasKey('shipping_policy_applied_snapshot', $result);
        $items = $result['shipping_policy_applied_snapshot']['items'] ?? null;
        $this->assertIsArray($items, 'items 는 프론트 .find() 대상이므로 배열이어야 한다');
        $this->assertCount(1, $items);
        $this->assertSame(481, $items[0]['product_option_id']);
        $this->assertSame('국내 무료배송', $items[0]['policy']['policy_name']);
        $this->assertArrayHasKey('standalone_shipping_amount', $items[0]['policy']);
    }

    /**
     * 표시에 쓰이지 않는 내부 계산 값은 비회원에게 노출되지 않는지 확인
     */
    public function test_guest_resource_hides_internal_calculation_fields(): void
    {
        // Given: 내부 계산 근거가 섞인 스냅샷
        $order = $this->makeOrderWithSnapshot();

        // When: 비회원 리소스로 직렬화
        $result = (new GuestOrderResource($order))->toArray(request());

        // Then: 표시용 외 내부 키는 빠진다
        $policy = $result['shipping_policy_applied_snapshot']['items'][0]['policy'];
        $this->assertArrayNotHasKey('calculation_basis', $policy);
        $this->assertArrayNotHasKey('policy_id', $policy);

        // 배송지 메타도 비회원 표시 대상이 아니다
        $this->assertArrayNotHasKey('address', $result['shipping_policy_applied_snapshot']);
    }

    /**
     * 스냅샷이 없어도 화면이 안전하게 빈 목록을 받는지 확인
     */
    public function test_guest_resource_returns_empty_items_when_snapshot_missing(): void
    {
        // Given: 스냅샷이 비어 있는 주문
        $order = OrderFactory::new()->make(['shipping_policy_applied_snapshot' => null]);

        // When: 비회원 리소스로 직렬화
        $result = (new GuestOrderResource($order))->toArray(request());

        // Then: `.find()` 가 죽지 않도록 배열이 유지된다
        $this->assertSame([], $result['shipping_policy_applied_snapshot']['items']);
    }
}
