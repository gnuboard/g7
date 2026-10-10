<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ProductOptionController Feature 테스트
 *
 * 상품 옵션 일괄 가격/재고 변경 API 테스트
 */
class ProductOptionControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    protected Product $product;

    protected ProductOption $option1;

    protected ProductOption $option2;

    protected ProductOption $option3;

    /**
     * 테스트 환경 설정 — 관리자·상품·옵션 3개를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성
        $this->adminUser = $this->createAdminUser(['sirsoft-ecommerce.products.update']);

        // 테스트 상품 생성
        $this->product = Product::create([
            'name' => ['ko' => '테스트 상품', 'en' => 'Test Product'],
            'product_code' => 'TEST-001',
            'selling_price' => 10000,
            'list_price' => 12000,
            'stock_quantity' => 100,
            'has_options' => true,
        ]);

        // 테스트 옵션 생성
        $this->option1 = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-001',
            'option_values' => ['색상' => '빨강'],
            'option_name' => '빨강',
            'price_adjustment' => 0,
            'stock_quantity' => 50,
            'is_active' => true,
        ]);

        $this->option2 = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-002',
            'option_values' => ['색상' => '파랑'],
            'option_name' => '파랑',
            'price_adjustment' => 1000,
            'stock_quantity' => 30,
            'is_active' => true,
        ]);

        $this->option3 = ProductOption::create([
            'product_id' => $this->product->id,
            'option_code' => 'OPT-003',
            'option_values' => ['색상' => '초록'],
            'option_name' => '초록',
            'price_adjustment' => 2000,
            'stock_quantity' => 20,
            'is_active' => true,
        ]);
    }

    // ==================== 가격 일괄 변경 테스트 ====================

    /**
     * 금액 단위 인상은 선택한 옵션의 조정액을 그만큼 올린다.
     *
     * @scenario endpoint=bulk_price, case=apply
     *
     * @effects price_won_increase_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_increase_by_won(): void
    {
        // Given: option_ids로 옵션 선택
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option2->id}",
            ],
            'method' => 'increase',
            'value' => 500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 2);

        $this->option1->refresh();
        $this->option2->refresh();

        $this->assertEquals(500, $this->option1->price_adjustment); // 0 + 500
        $this->assertEquals(1500, $this->option2->price_adjustment); // 1000 + 500
    }

    /**
     * 금액 단위 인하는 선택한 옵션의 조정액을 그만큼 내린다.
     *
     * @scenario endpoint=bulk_price, case=apply
     *
     * @effects price_won_decrease_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_decrease_by_won(): void
    {
        // Given: option_ids로 옵션 선택
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option2->id}",
                "{$this->product->id}-{$this->option3->id}",
            ],
            'method' => 'decrease',
            'value' => 500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 2);

        $this->option2->refresh();
        $this->option3->refresh();

        $this->assertEquals(500, $this->option2->price_adjustment); // 1000 - 500
        $this->assertEquals(1500, $this->option3->price_adjustment); // 2000 - 500
    }

    /**
     * 금액 고정은 선택한 옵션의 조정액을 그 값으로 바꾼다.
     *
     * @scenario endpoint=bulk_price, case=apply
     *
     * @effects price_fixed_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_fixed_by_won(): void
    {
        // Given: product_ids로 상품의 모든 옵션 선택
        $data = [
            'product_ids' => [$this->product->id],
            'method' => 'fixed',
            'value' => 3000,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 3);

        $this->option1->refresh();
        $this->option2->refresh();
        $this->option3->refresh();

        $this->assertEquals(3000, $this->option1->price_adjustment);
        $this->assertEquals(3000, $this->option2->price_adjustment);
        $this->assertEquals(3000, $this->option3->price_adjustment);
    }

    /**
     * 비율 인상은 옵션 판매가(상품 판매가 + 조정액)의 비율만큼 올리고 결과를 조정액으로 저장한다.
     *
     * @scenario endpoint=bulk_price, case=apply
     *
     * @effects price_percent_based_on_option_selling_price
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_increase_by_percent(): void
    {
        // Given: 초기 price_adjustment 값이 있는 옵션만 선택
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option2->id}", // 1000
                "{$this->product->id}-{$this->option3->id}", // 2000
            ],
            'method' => 'increase',
            'value' => 10, // 10% 증가
            'unit' => 'percent',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 2);

        $this->option2->refresh();
        $this->option3->refresh();

        // 옵션 판매가 = 상품 판매가(10,000) + 조정액 → 판매가의 10% 를 올린 뒤 조정액으로 저장
        $this->assertEquals(2100, $this->option2->price_adjustment); // (10,000 + 1,000) × 1.1 − 10,000
        $this->assertEquals(3200, $this->option3->price_adjustment); // (10,000 + 2,000) × 1.1 − 10,000
    }

    /**
     * 상품 ID 와 「상품-옵션」 ID 를 섞어 보내도 대상 옵션을 모두 찾아 바꾼다.
     *
     * @scenario endpoint=bulk_price, case=apply
     *
     * @effects mixed_ids_resolved_to_options
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_with_mixed_ids(): void
    {
        // Given: 두 번째 상품 생성
        $product2 = Product::create([
            'name' => ['ko' => '테스트 상품 2', 'en' => 'Test Product 2'],
            'product_code' => 'TEST-002',
            'selling_price' => 20000,
            'list_price' => 25000,
            'stock_quantity' => 50,
            'has_options' => true,
        ]);

        $option4 = ProductOption::create([
            'product_id' => $product2->id,
            'option_code' => 'OPT-004',
            'option_values' => ['사이즈' => 'L'],
            'option_name' => 'L',
            'price_adjustment' => 500,
            'stock_quantity' => 25,
            'is_active' => true,
        ]);

        // product_ids와 option_ids 함께 사용
        $data = [
            'product_ids' => [$this->product->id], // 3개 옵션
            'option_ids' => ["{$product2->id}-{$option4->id}"], // 1개 옵션
            'method' => 'fixed',
            'value' => 1500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 성공 응답 (4개 옵션 모두 업데이트)
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 4);

        $this->option1->refresh();
        $option4->refresh();

        $this->assertEquals(1500, $this->option1->price_adjustment);
        $this->assertEquals(1500, $option4->price_adjustment);
    }

    // ==================== 재고 일괄 변경 테스트 ====================

    /**
     * 재고 증가는 선택한 옵션의 재고를 그만큼 늘린다.
     *
     * @scenario endpoint=bulk_stock, case=apply
     *
     * @effects stock_increase_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_stock_increase(): void
    {
        // Given: option_ids로 옵션 선택
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option2->id}",
            ],
            'method' => 'increase',
            'value' => 10,
        ];

        // When: 재고 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-stock', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 2);

        $this->option1->refresh();
        $this->option2->refresh();

        $this->assertEquals(60, $this->option1->stock_quantity); // 50 + 10
        $this->assertEquals(40, $this->option2->stock_quantity); // 30 + 10
    }

    /**
     * 재고 감소는 선택한 옵션의 재고를 그만큼 줄인다.
     *
     * @scenario endpoint=bulk_stock, case=apply
     *
     * @effects stock_decrease_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_stock_decrease(): void
    {
        // Given: option_ids로 옵션 선택
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option3->id}",
            ],
            'method' => 'decrease',
            'value' => 15,
        ];

        // When: 재고 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-stock', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 2);

        $this->option1->refresh();
        $this->option3->refresh();

        $this->assertEquals(35, $this->option1->stock_quantity); // 50 - 15
        $this->assertEquals(5, $this->option3->stock_quantity); // 20 - 15
    }

    /**
     * 재고 감소가 현재 재고보다 커도 0 아래로 내려가지 않는다.
     *
     * @scenario endpoint=bulk_stock, case=apply
     *
     * @effects stock_never_negative
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_stock_decrease_does_not_go_below_zero(): void
    {
        // Given: 재고보다 큰 값으로 감소 시도
        $data = [
            'option_ids' => [
                "{$this->product->id}-{$this->option3->id}", // 재고 20
            ],
            'method' => 'decrease',
            'value' => 100, // 재고보다 큼
        ];

        // When: 재고 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-stock', $data);

        // Then: 성공 응답 및 0으로 설정됨
        $response->assertStatus(200);

        $this->option3->refresh();
        $this->assertEquals(0, $this->option3->stock_quantity); // max(0, 20 - 100)
    }

    /**
     * 재고 고정은 선택한 옵션의 재고를 그 값으로 바꾼다.
     *
     * @scenario endpoint=bulk_stock, case=apply
     *
     * @effects stock_set_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_stock_set(): void
    {
        // Given: product_ids로 상품의 모든 옵션 선택
        $data = [
            'product_ids' => [$this->product->id],
            'method' => 'set',
            'value' => 100,
        ];

        // When: 재고 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-stock', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.updated_count', 3);

        $this->option1->refresh();
        $this->option2->refresh();
        $this->option3->refresh();

        $this->assertEquals(100, $this->option1->stock_quantity);
        $this->assertEquals(100, $this->option2->stock_quantity);
        $this->assertEquals(100, $this->option3->stock_quantity);
    }

    // ==================== 유효성 검증 테스트 ====================

    /**
     * 상품 ID·옵션 ID 가 모두 없으면 422 로 거절한다.
     *
     * @scenario endpoint=bulk_price, case=validation
     *
     * @effects invalid_request_rejected_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_requires_at_least_one_id_type(): void
    {
        // Given: product_ids와 option_ids 모두 없음
        $data = [
            'method' => 'increase',
            'value' => 500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
    }

    /**
     * 「상품-옵션」 형식이 아닌 옵션 ID 는 422 로 거절한다.
     *
     * @scenario endpoint=bulk_price, case=validation
     *
     * @effects invalid_request_rejected_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_price_validates_option_id_format(): void
    {
        // Given: 잘못된 option_id 형식
        $data = [
            'option_ids' => ['invalid-format', '123'], // 올바른 형식: "productId-optionId"
            'method' => 'increase',
            'value' => 500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['option_ids.0', 'option_ids.1']);
    }

    /**
     * 허용되지 않은 재고 변경 방식은 422 로 거절한다.
     *
     * @scenario endpoint=bulk_stock, case=validation
     *
     * @effects invalid_request_rejected_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_stock_validates_method(): void
    {
        // Given: 잘못된 method
        $data = [
            'product_ids' => [$this->product->id],
            'method' => 'invalid_method',
            'value' => 10,
        ];

        // When: 재고 일괄 변경 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-stock', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['method']);
    }

    /**
     * 로그인하지 않은 요청은 401 로 거절한다.
     *
     * @scenario endpoint=bulk_price, case=auth
     *
     * @effects unauthenticated_rejected_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        // Given: 인증 없이 요청
        $data = [
            'product_ids' => [$this->product->id],
            'method' => 'increase',
            'value' => 500,
            'unit' => 'won',
        ];

        // When: 가격 일괄 변경 API 호출
        $response = $this->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-price', $data);

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }

    // ==================== 통합 일괄 업데이트 테스트 (bulk-update) ====================

    /**
     * 통합 일괄 수정의 일괄 조건만 보내면 모든 대상 옵션에 적용한다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects bulk_changes_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_with_bulk_changes_only(): void
    {
        // Given: bulk_changes로 가격 조정액 일괄 변경
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option2->id}",
            ],
            'bulk_changes' => [
                'price_adjustment' => [
                    'method' => 'set',
                    'value' => 5000,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);
        $response->assertJsonPath('data.options_updated', 2);

        $this->option1->refresh();
        $this->option2->refresh();

        $this->assertEquals(5000, $this->option1->price_adjustment);
        $this->assertEquals(5000, $this->option2->price_adjustment);
    }

    /**
     * 통합 일괄 수정의 개별 행만 보내면 행마다 따로 적용한다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects items_applied_individually
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_with_items_only(): void
    {
        // Given: items로 개별 옵션 수정
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option2->id}",
            ],
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'option_id' => $this->option1->id,
                    'option_name' => ['ko' => '수정된 빨강', 'en' => 'Modified Red'],
                    'sku' => 'RED-SKU-001',
                ],
                [
                    'product_id' => $this->product->id,
                    'option_id' => $this->option2->id,
                    'safe_stock_quantity' => 10,
                    'is_active' => false,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->option1->refresh();
        $this->option2->refresh();

        $this->assertEquals(['ko' => '수정된 빨강', 'en' => 'Modified Red'], $this->option1->option_name);
        $this->assertEquals('RED-SKU-001', $this->option1->sku);
        $this->assertEquals(10, $this->option2->safe_stock_quantity);
        $this->assertEquals(false, $this->option2->is_active);
    }

    /**
     * 일괄 조건과 개별 행이 같은 칸을 바꾸면 일괄 조건이 우선한다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects bulk_changes_override_items
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_bulk_changes_overrides_items(): void
    {
        // Given: bulk_changes와 items 모두 설정
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option1->id}",
                "{$this->product->id}-{$this->option2->id}",
            ],
            'bulk_changes' => [
                'stock_quantity' => [
                    'method' => 'set',
                    'value' => 999,
                ],
            ],
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'option_id' => $this->option1->id,
                    'stock_quantity' => 100, // bulk_changes에 의해 무시됨
                    'option_name' => ['ko' => '이름은 적용됨', 'en' => 'Name Applied'],
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답
        $response->assertStatus(200);

        $this->option1->refresh();
        $this->option2->refresh();

        // bulk_changes의 stock_quantity가 적용됨 (items의 stock_quantity는 무시)
        $this->assertEquals(999, $this->option1->stock_quantity);
        $this->assertEquals(999, $this->option2->stock_quantity);

        // items의 다른 필드는 적용됨
        $this->assertEquals(['ko' => '이름은 적용됨', 'en' => 'Name Applied'], $this->option1->option_name);
    }

    /**
     * 옵션명의 일부 언어 값이 비어(null) 있어도 저장한다.
     *
     * 인라인으로 한국어 옵션명만 수정하면 프론트엔드가 저장된 다국어 객체
     * { ko: "...", en: null, ja: null } 를 그대로 전송한다. 비필수 로케일의 null 값이
     * per-locale 규칙(`option_name.*`)에서 거부되어 422 가 발생하던 회귀.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects option_name_null_locales_allowed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_option_name_allows_null_locales(): void
    {
        // Given: 한국어만 입력하고 en/ja 는 null 인 옵션명 (실제 저장 형태)
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option1->id}",
            ],
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'option_id' => $this->option1->id,
                    'option_name' => ['ko' => '레드11', 'en' => null, 'ja' => null],
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 검증 통과 및 한국어 옵션명 반영
        $response->assertStatus(200);

        $this->option1->refresh();
        $this->assertEquals('레드11', $this->option1->option_name['ko']);
    }

    /**
     * 통합 일괄 수정의 금액 가산은 조정액을 그만큼 올린다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects price_won_increase_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_price_adjustment_add_method(): void
    {
        // Given: add 메서드로 가격 증가
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option2->id}", // 현재 1000
                "{$this->product->id}-{$this->option3->id}", // 현재 2000
            ],
            'bulk_changes' => [
                'price_adjustment' => [
                    'method' => 'add',
                    'value' => 500,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->option2->refresh();
        $this->option3->refresh();

        $this->assertEquals(1500, $this->option2->price_adjustment); // 1000 + 500
        $this->assertEquals(2500, $this->option3->price_adjustment); // 2000 + 500
    }

    /**
     * 통합 일괄 수정의 재고 감소는 재고를 그만큼 줄인다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects stock_decrease_applied
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_stock_subtract_method(): void
    {
        // Given: subtract 메서드로 재고 감소
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option1->id}", // 현재 50
                "{$this->product->id}-{$this->option3->id}", // 현재 20
            ],
            'bulk_changes' => [
                'stock_quantity' => [
                    'method' => 'subtract',
                    'value' => 10,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답 및 DB 반영 확인
        $response->assertStatus(200);

        $this->option1->refresh();
        $this->option3->refresh();

        $this->assertEquals(40, $this->option1->stock_quantity); // 50 - 10
        $this->assertEquals(10, $this->option3->stock_quantity); // 20 - 10
    }

    /**
     * 통합 일괄 수정의 재고 감소도 0 아래로 내려가지 않는다.
     *
     * @scenario endpoint=bulk_update, case=apply
     *
     * @effects stock_never_negative
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_stock_subtract_does_not_go_below_zero(): void
    {
        // Given: 재고보다 큰 값으로 감소 시도
        $data = [
            'ids' => [
                "{$this->product->id}-{$this->option3->id}", // 현재 20
            ],
            'bulk_changes' => [
                'stock_quantity' => [
                    'method' => 'subtract',
                    'value' => 100, // 재고보다 큼
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 성공 응답 및 0으로 설정됨
        $response->assertStatus(200);

        $this->option3->refresh();
        $this->assertEquals(0, $this->option3->stock_quantity);
    }

    /**
     * 대상 ID 없이 보내면 422 로 거절한다.
     *
     * @scenario endpoint=bulk_update, case=validation
     *
     * @effects invalid_request_rejected_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_requires_ids(): void
    {
        // Given: ids 없음
        $data = [
            'bulk_changes' => [
                'price_adjustment' => [
                    'method' => 'set',
                    'value' => 1000,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ids']);
    }

    /**
     * ID 형식이 맞지 않으면 422 로 거절한다.
     *
     * @scenario endpoint=bulk_update, case=validation
     *
     * @effects invalid_request_rejected_422
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_validates_id_format(): void
    {
        // Given: 잘못된 ID 형식
        $data = [
            'ids' => ['invalid', '123'], // 올바른 형식: "productId-optionId"
            'bulk_changes' => [
                'price_adjustment' => [
                    'method' => 'set',
                    'value' => 1000,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->actingAs($this->adminUser)
            ->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 검증 실패
        $response->assertStatus(422);
    }

    /**
     * 로그인하지 않은 통합 일괄 수정은 401 로 거절한다.
     *
     * @scenario endpoint=bulk_update, case=auth
     *
     * @effects unauthenticated_rejected_401
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_bulk_update_requires_authentication(): void
    {
        // Given: 인증 없이 요청
        $data = [
            'ids' => ["{$this->product->id}-{$this->option1->id}"],
            'bulk_changes' => [
                'stock_quantity' => [
                    'method' => 'set',
                    'value' => 100,
                ],
            ],
        ];

        // When: 통합 일괄 업데이트 API 호출
        $response = $this->patchJson('/api/modules/sirsoft-ecommerce/admin/options/bulk-update', $data);

        // Then: 인증 필요 에러
        $response->assertStatus(401);
    }
}
