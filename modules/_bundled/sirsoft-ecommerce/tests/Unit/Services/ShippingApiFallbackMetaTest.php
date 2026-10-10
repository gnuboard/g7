<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Seeders\ShippingTypeSeeder;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Services\OrderCalculationService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 외부 배송비 API 가 실패해 기본 배송비로 계산한 주문은 그 사실을 남긴다 (B9)
 *
 * 폴백 자체는 설계다(주문을 막지 않는다). 그러나 흔적이 경고 로그뿐이라, 실제 배송비보다 적게 받은 주문을 운영자가 찾을 수
 * 없었다. 계산 결과 metadata 에 폴백 정책을 싣고, 주문 생성이 `order_meta.shipping_fee_fallback` 으로 남긴다.
 *
 * 시나리오 매니페스트: `tests/scenarios/shipping-api-fallback-meta.yaml`
 */
class ShippingApiFallbackMetaTest extends ModuleTestCase
{
    /**
     * 배송 유형 시드를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ShippingTypeSeeder::class);
    }

    /**
     * API 가 실패하면 기본 배송비를 쓰고, 계산 서비스가 폴백한 정책을 기억한다.
     *
     * @scenario stage=calculation
     *
     * @effects shipping_api_fallback_is_recorded_on_the_order
     *
     * @return void 반환값 없음
     */
    public function test_api_failure_falls_back_and_is_recorded(): void
    {
        Http::fake(['*' => Http::response('server error', 500)]);
        $policy = ShippingPolicy::create(['name' => ['ko' => 'API 정책', 'en' => 'API Policy'], 'is_default' => false, 'is_active' => true]);
        $policy->countrySettings()->create([
            'country_code' => 'KR', 'shipping_method' => 'parcel', 'currency_code' => 'KRW',
            'charge_policy' => ChargePolicyEnum::API, 'base_fee' => 5000,
            'api_endpoint' => 'https://shipping.example.com/calc', 'api_config' => [],
            'extra_fee_enabled' => false, 'is_active' => true,
        ]);
        $service = app(OrderCalculationService::class);
        $ref = new \ReflectionMethod($service, 'calculateApiShippingFee');
        $ref->setAccessible(true);

        $fee = $ref->invoke($service, $policy->countrySettings()->first(), [
            'items' => [['product_option_id' => 1, 'quantity' => 1, 'subtotal' => 10000, 'weight' => 1, 'volume' => 1]],
            'total_amount' => 10000, 'total_quantity' => 1,
        ]);

        $this->assertSame(5000, $fee);
        $fallbacks = $service->shippingApiFallbacks();
        $this->assertCount(1, $fallbacks, '폴백이 기록되지 않았다');
        $this->assertSame($policy->id, $fallbacks[0]['policy_id']);
    }

    /**
     * 계산 결과에 폴백이 있으면 주문 메타에 `shipping_fee_fallback` 이 실린다.
     *
     * @scenario stage=order_meta
     *
     * @effects shipping_api_fallback_is_recorded_on_the_order
     *
     * @return void 반환값 없음
     */
    public function test_order_meta_carries_the_fallback(): void
    {
        $result = new OrderCalculationResult(metadata: ['shipping_fee_fallback' => [['policy_id' => 7, 'country_code' => 'KR', 'reason' => 'http_error']]]);
        $service = app(OrderProcessingService::class);
        $ref = new \ReflectionMethod($service, 'shippingFallbackMeta');
        $ref->setAccessible(true);

        $meta = $ref->invoke($service, $result);

        $this->assertTrue($meta['shipping_fee_fallback']);
        $this->assertSame(7, $meta['shipping_fee_fallback_policies'][0]['policy_id']);
        $this->assertSame([], $ref->invoke($service, new OrderCalculationResult));
    }
}
