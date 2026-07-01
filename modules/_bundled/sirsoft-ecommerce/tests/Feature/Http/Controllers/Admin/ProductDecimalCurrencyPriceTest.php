<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 다통화(소수 통화) 상품 가격 등록/수정 검증 테스트
 *
 * 기본통화가 KRW(소수 0자리)가 아닌 USD(소수 2자리) 등일 때, 3.3 같은 소수 가격이
 * 정수 검증에 막혀 저장되지 않던 회귀를 다룹니다. 케이스 축:
 *   기본통화(KRW/USD) × 가격형태(정수/소수2/소수초과) × 위치(상품/옵션) × 결과(통과/422).
 */
class ProductDecimalCurrencyPriceTest extends ModuleTestCase
{
    private $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultConfig = SequenceType::PRODUCT->getDefaultConfig();
        Sequence::firstOrCreate(
            ['type' => SequenceType::PRODUCT->value],
            [
                'algorithm' => $defaultConfig['algorithm']->value,
                'prefix' => $defaultConfig['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $defaultConfig['max_value'],
                'cycle' => false,
                'pad_length' => $defaultConfig['pad_length'],
                'max_history_count' => $defaultConfig['max_history_count'],
            ]
        );

        $this->user = $this->createAdminUser([
            'sirsoft-ecommerce.products.read',
            'sirsoft-ecommerce.products.create',
            'sirsoft-ecommerce.products.update',
        ]);

        $this->category = new Category([
            'name' => ['ko' => '테스트 카테고리', 'en' => 'Test Category'],
            'slug' => 'decimal-price-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $this->category->path = 'temp';
        $this->category->save();
        $this->category->generatePath();
        $this->category->save();
    }

    /**
     * 기본통화를 설정합니다 (default_currency + 해당 통화의 decimal_places).
     *
     * @param  string  $code  기본통화 코드
     */
    private function setBaseCurrency(string $code): void
    {
        $languageCurrency = [
            'default_currency' => $code,
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => '원'], 'is_default' => $code === 'KRW', 'decimal_places' => 0, 'exchange_rate' => $code === 'KRW' ? null : 1176470],
                ['code' => 'USD', 'name' => ['en' => 'Dollar'], 'is_default' => $code === 'USD', 'decimal_places' => 2, 'exchange_rate' => $code === 'USD' ? null : 0.00085],
            ],
        ];

        // DB 저장(설정 SSoT)
        app(EcommerceSettingsService::class)->setSetting('language_currency', $languageCurrency);

        // g7_module_settings() 가 읽는 런타임 config 캐시에도 즉시 반영
        // (FormRequest::baseCurrencyDecimalPlaces() 가 g7_module_settings 를 사용)
        config(['g7_settings.modules.sirsoft-ecommerce.language_currency' => $languageCurrency]);
    }

    /**
     * 기본 상품 등록 데이터 (가격은 호출 측에서 덮어씀).
     *
     * @param  string  $productCode  상품코드
     */
    private function baseProductData(string $productCode): array
    {
        return [
            'name' => ['ko' => '소수가격 상품', 'en' => 'Decimal Price Product'],
            'product_code' => $productCode,
            'category_ids' => [$this->category->id],
            'list_price' => 10,
            'selling_price' => 8,
            'stock_quantity' => 100,
            'sales_status' => 'on_sale',
            'display_status' => 'visible',
            'tax_status' => 'taxable',
            'options' => [
                [
                    'option_code' => 'OPT-001',
                    'option_name' => ['ko' => '기본 옵션', 'en' => 'Default Option'],
                    'option_values' => [
                        ['key' => ['ko' => '기본', 'en' => 'Default'], 'value' => ['ko' => '기본', 'en' => 'Default']],
                    ],
                    'list_price' => 10,
                    'selling_price' => 8,
                    'stock_quantity' => 100,
                ],
            ],
        ];
    }

    /** 케이스 1: KRW 기본 + 정수 상품가 → 정상 등록. */
    public function test_krw_base_integer_price_passes(): void
    {
        $this->setBaseCurrency('KRW');
        $data = $this->baseProductData('DEC-KRW-INT');
        $data['list_price'] = 10000;
        $data['selling_price'] = 8000;
        $data['options'][0]['list_price'] = 10000;
        $data['options'][0]['selling_price'] = 8000;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();
    }

    /** 케이스 2: KRW 기본 + 소수 상품가 → 422 (KRW 는 소수 0자리). */
    public function test_krw_base_decimal_price_blocked(): void
    {
        $this->setBaseCurrency('KRW');
        $data = $this->baseProductData('DEC-KRW-DEC');
        $data['list_price'] = 10000.5;
        $data['selling_price'] = 8000.3;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['list_price', 'selling_price']);
    }

    /** 케이스 3: USD 기본 + 정수 상품가 → 정상 등록. */
    public function test_usd_base_integer_price_passes(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-USD-INT');
        $data['list_price'] = 5;
        $data['selling_price'] = 4;
        $data['options'][0]['list_price'] = 5;
        $data['options'][0]['selling_price'] = 4;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();
    }

    /** 케이스 4: USD 기본 + 소수2자리 상품가 → 정상 등록 (핵심 회귀). */
    public function test_usd_base_decimal2_price_passes(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-USD-DEC2');
        $data['list_price'] = 3.5;
        $data['selling_price'] = 3.3;
        $data['options'][0]['list_price'] = 3.5;
        $data['options'][0]['selling_price'] = 3.3;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();
    }

    /** 케이스 5: USD 기본 + 소수3자리 상품가 → 422 (decimal_places 초과). */
    public function test_usd_base_decimal3_price_blocked(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-USD-DEC3');
        $data['list_price'] = 3.555;
        $data['selling_price'] = 3.333;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['list_price', 'selling_price']);
    }

    /** 케이스 6: USD 기본 + 소수2자리 옵션가 → 422 아님(통과). */
    public function test_usd_base_decimal2_option_price_passes(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-USD-OPT');
        $data['list_price'] = 9.99;
        $data['selling_price'] = 7.5;
        $data['options'][0]['list_price'] = 9.99;
        $data['options'][0]['selling_price'] = 7.5;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();
    }

    /** 케이스 7: USD 소수 가격이 DB 에 소수 그대로 영속된다 (3.30 보존). */
    public function test_usd_decimal_price_persists_in_db(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-USD-PERSIST');
        $data['list_price'] = 12.34;
        $data['selling_price'] = 9.9;
        $data['options'][0]['list_price'] = 12.34;
        $data['options'][0]['selling_price'] = 9.9;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();

        $product = Product::where('product_code', 'DEC-USD-PERSIST')->first();
        $this->assertNotNull($product);
        $this->assertEquals(12.34, (float) $product->list_price);
        $this->assertEquals(9.9, (float) $product->selling_price);
    }

    /** 케이스 8: 에러 메시지에 raw 경로 대신 한글 필드명이 노출된다 (상품/옵션 양쪽). */
    public function test_error_message_uses_attribute_label_not_raw_path(): void
    {
        $this->setBaseCurrency('KRW');
        $data = $this->baseProductData('DEC-ATTR');
        $data['selling_price'] = 8000.3;            // KRW 소수 → 상품 판매가 차단
        $data['options'][0]['selling_price'] = 3.3; // KRW 소수 → 옵션 판매가 차단

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data, ['Accept-Language' => 'ko']);

        $response->assertStatus(422);

        // errors 의 키는 평평한 dot-path(`options.0.selling_price`)라 중첩 탐색 대신 직접 인덱싱
        $errors = $response->json('errors');

        // 상품 판매가: 라벨(판매가) 포함 + raw 키명 미포함
        $sellingMessages = $errors['selling_price'] ?? null;
        $this->assertNotEmpty($sellingMessages);
        $this->assertStringContainsString('판매가', $sellingMessages[0]);
        $this->assertStringNotContainsString('selling_price', $sellingMessages[0]);

        // 옵션 판매가: 라벨(옵션 판매가) 포함 + raw 경로(options.0.selling_price) 미포함
        $optionMessages = $errors['options.0.selling_price'] ?? null;
        $this->assertNotEmpty($optionMessages);
        $this->assertStringContainsString('옵션 판매가', $optionMessages[0]);
        $this->assertStringNotContainsString('options.0.selling_price', $optionMessages[0]);
    }

    /** 케이스 9: 수정 폼도 소수 검증을 상속 — USD 소수2자리 수정 통과. */
    public function test_update_inherits_decimal_validation(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-UPD');
        $data['list_price'] = 5;
        $data['selling_price'] = 4;
        $data['options'][0]['list_price'] = 5;
        $data['options'][0]['selling_price'] = 4;

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertCreated();

        $product = Product::where('product_code', 'DEC-UPD')->with('options')->first();

        $update = $this->baseProductData('DEC-UPD');
        $update['list_price'] = 6.75;
        $update['selling_price'] = 6.5;
        // 기존 옵션 id 를 전달해 in-place 수정(신규 insert 로 인한 unique 충돌 회피)
        $update['options'][0]['id'] = $product->options->first()->id;
        $update['options'][0]['list_price'] = 6.75;
        $update['options'][0]['selling_price'] = 6.5;

        $this->actingAs($this->user)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", $update)
            ->assertOk();

        $product->refresh();
        $this->assertEquals(6.5, (float) $product->selling_price);
    }

    /** 케이스 10: 소수 가격에서도 판매가>정가 차단(lte) 유지. */
    public function test_selling_greater_than_list_still_blocked_with_decimals(): void
    {
        $this->setBaseCurrency('USD');
        $data = $this->baseProductData('DEC-LTE');
        $data['list_price'] = 3.0;
        $data['selling_price'] = 3.3; // 판매가 > 정가

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data)
            ->assertStatus(422)
            ->assertJsonValidationErrors('selling_price');
    }
}
