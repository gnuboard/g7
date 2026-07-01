<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Database\Factories\TempOrderFactory;
use Modules\Sirsoft\Ecommerce\DTO\OrderCalculationResult;
use Modules\Sirsoft\Ecommerce\DTO\PromotionsSummary;
use Modules\Sirsoft\Ecommerce\DTO\Summary;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CheckoutDataService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * B6(알림 배송지 변수) + B7(무료배송 결제통화 환산) 테스트 (MP08 후속)
 */
class ShippingCountryNotificationAndFreeShippingTest extends ModuleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.available_countries', [
            ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => true],
            ['code' => 'US', 'name' => ['ko' => '미국', 'en' => 'United States'], 'is_active' => true],
        ]);
    }

    public function test_b6_order_notification_includes_shipping_country_variables(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $user->id]);
        $order->addresses()->create([
            'address_type' => 'shipping',
            'orderer_name' => 'John Doe',
            'orderer_phone' => '010-1111-2222',
            'orderer_email' => 'john@example.com',
            'recipient_name' => 'John Doe',
            'recipient_phone' => '010-1111-2222',
            'recipient_country_code' => 'US',
            'zipcode' => '',
            'address' => '',
            'address_detail' => '',
            'address_line_1' => '123 Main St',
            'intl_city' => 'New York',
            'intl_postal_code' => '10001',
        ]);
        $order->load('shippingAddress');

        $listener = app(EcommerceNotificationDataListener::class);
        $result = $listener->extractData([], 'order_confirmed', [$order]);

        $data = $result['data'] ?? [];
        $this->assertSame('John Doe', $data['shipping_recipient_name']);
        $this->assertSame('US', $data['shipping_country_code']);
        // config('countries.localized_names') 에 US 가 있으면 현지화명, 없으면 코드 폴백
        $this->assertNotSame('', $data['shipping_country_name']);
        $this->assertStringContainsString('123 Main St', $data['shipping_address']);
    }

    public function test_b7_checkout_response_exposes_free_shipping_conversion(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('shipping.free_shipping_enabled', true);
        $settings->setSetting('shipping.free_shipping_threshold', 50000);

        $user = User::factory()->create();
        $tempOrder = TempOrderFactory::new()->forUser($user)->create();

        $summary = new Summary(subtotal: 30000, finalAmount: 30000);
        $calc = new OrderCalculationResult(
            items: [],
            summary: $summary,
            promotions: new PromotionsSummary,
        );

        $response = app(CheckoutDataService::class)->buildResponseData($tempOrder, $calc, $user->id);

        $this->assertArrayHasKey('free_shipping', $response);
        $free = $response['free_shipping'];
        $this->assertTrue($free['enabled']);
        $this->assertSame(50000, $free['threshold_base']);
        // 결제통화 환산값 노출 (기본통화 KRW 는 항상 포함)
        $this->assertArrayHasKey('KRW', $free['threshold_multi_currency']);
        // 잔여 = 50000 - 30000 = 20000
        $this->assertSame(20000, $free['remaining_base']);
    }
}
