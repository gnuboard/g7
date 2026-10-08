<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\GuestOrderResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 상세 「적용 배송정책」 배송비는 보는 사람의 언어로 표기된다 (S3-D12, 2026-10-07 실측)
 *
 * 상품별 배송정책 줄(`partials/mypage/orders/_items.json`)은 스냅샷의
 * `policy.standalone_shipping_amount_formatted` 를 그대로 그린다. 그 문자열은 **주문을 만든 요청의 언어**로
 * 포맷되어 저장되므로, 영어 화면에서 주문한 비회원 주문을 한국어로 열면 같은 화면의 다른 금액은 「2,500원」인데
 * 이 줄만 「₩2,500」로 보였다(실측: 비회원 주문 20261006-1425494404, 회원 주문은 「2,500원」). 반대로 한국어로
 * 주문한 회원이 영어로 보면 「2,500원」이 남는다. 응답을 만들 때 금액 숫자로 다시 포맷한다 — 저장된 스냅샷은
 * 재계산의 입력이라 건드리지 않는다.
 */
class ShippingPolicyAmountLocaleTest extends ModuleTestCase
{
    /**
     * 영어 표기 문자열이 저장된 주문을 만듭니다.
     *
     * @return Order 스냅샷이 채워진 주문
     */
    private function makeOrderFormattedInEnglish(): Order
    {
        return OrderFactory::new()->create([
            'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::make(
                [[
                    'product_option_id' => 481,
                    'policy' => [
                        'policy_id' => 7,
                        'policy_name' => '5만원 이상 무료',
                        'standalone_shipping_amount' => 2500,
                        'standalone_shipping_amount_formatted' => '₩2,500',
                    ],
                ]],
                ['country_code' => 'KR', 'zipcode' => '06236'],
            ),
        ]);
    }

    /**
     * 응답의 첫 줄 배송비 표기를 꺼냅니다.
     *
     * @param  array  $result  리소스 배열
     * @return string|null 표기 문자열
     */
    private function formattedOf(array $result): ?string
    {
        return $result['shipping_policy_applied_snapshot']['items'][0]['policy']['standalone_shipping_amount_formatted'] ?? null;
    }

    /**
     * 회원 응답 — 한국어로 보면 한국어 표기
     *
     * @scenario response=member
     *
     * @effects shipping_policy_amount_follows_viewer_locale
     *
     * @return void 반환값 없음
     */
    public function test_member_resource_formats_the_amount_in_the_viewer_locale(): void
    {
        $order = $this->makeOrderFormattedInEnglish();
        app()->setLocale('ko');

        $formatted = $this->formattedOf((new OrderResource($order))->toArray(request()));

        $this->assertSame(ecommerce_format_price(2500), $formatted);
        $this->assertStringNotContainsString('₩', (string) $formatted, '한국어 화면에 주문 시점의 영어 표기가 남았다');
    }

    /**
     * 비회원 응답 — 한국어로 보면 한국어 표기, 영어로 보면 영어 표기
     *
     * @scenario response=guest
     *
     * @effects shipping_policy_amount_follows_viewer_locale
     *
     * @return void 반환값 없음
     */
    public function test_guest_resource_formats_the_amount_in_the_viewer_locale(): void
    {
        $order = $this->makeOrderFormattedInEnglish();

        app()->setLocale('ko');
        $ko = $this->formattedOf((new GuestOrderResource($order))->toArray(request()));
        $this->assertSame(ecommerce_format_price(2500), $ko);
        $this->assertStringNotContainsString('₩', (string) $ko);

        app()->setLocale('en');
        $en = $this->formattedOf((new GuestOrderResource($order->fresh()))->toArray(request()));
        $this->assertSame(ecommerce_format_price(2500), $en);
        $this->assertNotSame($ko, $en, '언어에 따라 표기가 달라야 한다');
    }

    /**
     * 저장된 스냅샷(재계산 입력)은 바꾸지 않는다
     *
     * @scenario response=stored
     *
     * @effects stored_shipping_snapshot_stays_untouched
     *
     * @return void 반환값 없음
     */
    public function test_stored_snapshot_is_left_untouched(): void
    {
        $order = $this->makeOrderFormattedInEnglish();
        app()->setLocale('ko');

        (new OrderResource($order))->toArray(request());

        $stored = $order->fresh()->shipping_policy_applied_snapshot;
        $this->assertSame('₩2,500', $stored['items'][0]['policy']['standalone_shipping_amount_formatted']);
    }
}
