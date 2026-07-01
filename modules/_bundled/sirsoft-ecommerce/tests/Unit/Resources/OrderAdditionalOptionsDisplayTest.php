<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Resources;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderListResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderOptionResource;
use Modules\Sirsoft\Ecommerce\Http\Resources\UserOrderListResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 추가옵션 표시(스냅샷 기반) 회귀 테스트
 *
 * - OrderOptionResource: 스냅샷의 custom_text(직접입력) 를 노출 (E3)
 * - OrderListResource(관리자) / UserOrderListResource(마이페이지):
 *   목록 first_option/items 에 추가옵션 요약(첫 1건 + "외 N건", custom_text 병기) 노출 (E6/Q-E2)
 */
class OrderAdditionalOptionsDisplayTest extends ModuleTestCase
{
    /**
     * 추가옵션 스냅샷을 가진 주문옵션 1건을 포함한 주문을 생성합니다.
     *
     * @param  array  $snapshot  additional_options_snapshot 배열
     * @return Order 옵션이 로드된 주문
     */
    private function makeOrderWithSnapshot(array $snapshot): Order
    {
        $order = OrderFactory::new()->create();
        OrderOptionFactory::new()->forOrder($order)->create([
            'product_name' => ['ko' => 'PW추가옵션상품', 'en' => 'PW Product'],
            'product_option_name' => ['ko' => '빨강', 'en' => 'Red'],
            'quantity' => 5,
            'additional_options_snapshot' => $snapshot,
        ]);

        return $order->fresh(['options']);
    }

    public function test_order_option_resource_exposes_custom_text_from_snapshot(): void
    {
        $order = $this->makeOrderWithSnapshot([
            ['additional_option_id' => 1, 'value_id' => 10, 'name' => ['ko' => '각인 추가'], 'price_adjustment' => 5000, 'custom_text' => '홍길동'],
        ]);

        $resource = (new OrderOptionResource($order->options->first()))->toArray(Request::create('/'));

        $this->assertSame('홍길동', $resource['additional_options'][0]['custom_text']);
    }

    public function test_order_option_resource_custom_text_empty_when_absent(): void
    {
        $order = $this->makeOrderWithSnapshot([
            ['additional_option_id' => 1, 'value_id' => 10, 'name' => ['ko' => '기본포장'], 'price_adjustment' => 3000],
        ]);

        $resource = (new OrderOptionResource($order->options->first()))->toArray(Request::create('/'));

        $this->assertSame('', $resource['additional_options'][0]['custom_text']);
    }

    public function test_admin_order_list_summarizes_additional_options_with_extra_count(): void
    {
        // 2건 추가옵션 → 첫 1건 라벨 + 외 1건
        $order = $this->makeOrderWithSnapshot([
            ['additional_option_id' => 1, 'value_id' => 10, 'name' => ['ko' => '각인 추가'], 'price_adjustment' => 5000, 'custom_text' => '홍길동'],
            ['additional_option_id' => 2, 'value_id' => 20, 'name' => ['ko' => '기본포장'], 'price_adjustment' => 3000],
        ]);

        $resource = (new OrderListResource($order))->toArray(Request::create('/'));
        $summary = $resource['first_option']['additional_options_summary'];

        // custom_text 병기
        $this->assertSame('각인 추가: 홍길동', $summary['label']);
        $this->assertSame(1, $summary['extra_count']);
        $this->assertSame(2, $summary['total_count']);
    }

    public function test_user_order_list_summarizes_additional_options(): void
    {
        $order = $this->makeOrderWithSnapshot([
            ['additional_option_id' => 1, 'value_id' => 10, 'name' => ['ko' => '각인 추가'], 'price_adjustment' => 5000],
        ]);

        $resource = (new UserOrderListResource($order))->toArray(Request::create('/'));
        $summary = $resource['items'][0]['additional_options_summary'];

        $this->assertSame('각인 추가', $summary['label']);
        $this->assertSame(0, $summary['extra_count']);
    }

    public function test_order_list_summary_null_when_no_additional_options(): void
    {
        $order = $this->makeOrderWithSnapshot([]);

        $resource = (new OrderListResource($order))->toArray(Request::create('/'));

        $this->assertNull($resource['first_option']['additional_options_summary']);
    }
}
