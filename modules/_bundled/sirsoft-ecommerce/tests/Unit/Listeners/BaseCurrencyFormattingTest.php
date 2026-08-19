<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Enums\TotalRelation;
use App\Support\Query\BoundedCount;
use App\Support\Query\BoundedPage;
use Illuminate\Support\Facades\Gate;
use Modules\Sirsoft\Ecommerce\Database\Factories\ProductFactory;
use Modules\Sirsoft\Ecommerce\Listeners\EcommerceNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Listeners\SearchProductsListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ProductService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 금액 표기가 기본 통화를 따르는지 검증하는 회귀 테스트
 *
 * 통화 기호/자릿수는 쇼핑몰 설정(`language_currency.default_currency`)이 정한다.
 * 그런데 일부 지점이 통화 코드를 'KRW' 로 못 박거나 문자열 '원' 을 직접 이어 붙여,
 * 기본 통화가 KRW 가 아닌 상점에서 값은 기준통화인데 단위만 원화로 표기됐다.
 *
 * 기본 통화가 KRW 인 상점에서는 우연히 맞으므로, 이 테스트는 기본 통화를 JPY 로
 * 두고 검증한다.
 */
class BaseCurrencyFormattingTest extends ModuleTestCase
{
    /**
     * 기본 통화를 JPY 로 설정합니다.
     */
    private function setJpyBase(): void
    {
        $languageCurrency = [
            'default_currency' => 'JPY',
            'currencies' => [
                [
                    'code' => 'JPY', 'name' => ['ja' => '円'], 'symbol' => '¥',
                    'is_default' => true, 'decimal_places' => 0, 'base_unit' => 1000,
                    'exchange_rate' => null,
                ],
                [
                    'code' => 'KRW', 'name' => ['ko' => '원'], 'symbol' => '₩',
                    'is_default' => false, 'decimal_places' => 0, 'base_unit' => 1,
                    'exchange_rate' => 950, 'rounding_unit' => '1', 'rounding_method' => 'floor',
                ],
            ],
        ];

        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);
        SearchProductsListener::clearCurrencySettingsCache();
    }

    /**
     * 통합 검색 결과의 가격 표기가 기본 통화를 따라야 한다.
     *
     * 회귀: `formatCurrencyPrice($price, 'KRW')` 로 통화 코드가 못 박혀 있어,
     * JPY 기준 상품가에 원화 접미(원)가 붙었다. 이 문자열은 검색 결과 레이아웃이
     * 표시 통화 환산값이 없을 때 쓰는 폴백이라 그대로 화면에 나간다.
     */
    public function test_search_result_price_uses_base_currency(): void
    {
        $this->setJpyBase();
        Gate::before(fn () => true);

        $product = ProductFactory::new()->create([
            'selling_price' => 10000,
            'list_price' => 12000,
        ]);

        $productService = $this->createMock(ProductService::class);
        $productService->method('searchByKeyword')->willReturn(
            new BoundedPage([$product], 1, 10, 1, TotalRelation::Exact, null, false)
        );
        $productService->method('countByKeyword')->willReturn(
            new BoundedCount(1, TotalRelation::Exact, null)
        );

        $listener = new SearchProductsListener($productService);
        $results = $listener->searchProducts([], ['q' => '테스트', 'type' => 'products']);

        $item = $results['products']['items'][0];

        $this->assertStringContainsString('¥', $item['selling_price_formatted']);
        $this->assertStringNotContainsString('원', $item['selling_price_formatted']);
        $this->assertStringContainsString('¥', $item['list_price_formatted']);
        $this->assertStringNotContainsString('원', $item['list_price_formatted']);
    }

    /**
     * 관리자 신규주문 알림의 금액이 결제 통화 포맷을 따라야 한다.
     *
     * 회귀: `number_format(...).'원'` 으로 원화가 못 박혀 있었다. 같은 리스너의
     * 구매자 알림은 `formatOrderChargeAmount()` 를 거치므로 관리자 경로만 어긋났다.
     */
    public function test_new_order_admin_amount_uses_order_currency(): void
    {
        $this->setJpyBase();

        $order = new Order([
            'total_amount' => 10000,
            'total_paid_amount' => 10000,
        ]);
        $order->currency_snapshot = ['base_currency' => 'JPY', 'order_currency' => 'JPY'];

        $listener = app(EcommerceNotificationDataListener::class);
        $result = $listener->extractData(
            ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []],
            'new_order_admin',
            [$order],
        );

        $this->assertStringContainsString('¥', $result['data']['total_amount']);
        $this->assertStringNotContainsString('원', $result['data']['total_amount']);
    }
}
