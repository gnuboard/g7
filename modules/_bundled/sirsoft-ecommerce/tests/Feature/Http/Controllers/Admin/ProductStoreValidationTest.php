<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\Role;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\Sequence;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 등록 cross-field 검증 통합 테스트 (A33)
 *
 * 구매 대상 제한(restricted) 선택 시 허용 역할 필수 검증을 다룹니다.
 */
class ProductStoreValidationTest extends ModuleTestCase
{
    private $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        // 상품 시퀀스 레코드 생성
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
            'slug' => 'a33-category',
            'is_active' => true,
            'depth' => 0,
        ]);
        $this->category->path = 'temp';
        $this->category->save();
        $this->category->generatePath();
        $this->category->save();
    }

    /**
     * 유효한 역할 ID 를 반환합니다 (없으면 생성).
     *
     * @return int 역할 ID
     */
    private function someRoleId(): int
    {
        $role = Role::query()->first();

        if (! $role) {
            $role = Role::create([
                'identifier' => 'a33-test-role',
                'name' => ['ko' => '테스트 역할', 'en' => 'Test Role'],
                'is_active' => true,
            ]);
        }

        return $role->id;
    }

    /**
     * 기본 상품 등록 데이터.
     *
     * @param  string  $productCode  상품코드
     */
    private function baseProductData(string $productCode = 'A33-001'): array
    {
        return [
            'name' => ['ko' => 'A33 상품', 'en' => 'A33 Product'],
            'product_code' => $productCode,
            'category_ids' => [$this->category->id],
            'list_price' => 10000,
            'selling_price' => 8000,
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
                    'list_price' => 10000,
                    'selling_price' => 8000,
                    'stock_quantity' => 100,
                ],
            ],
        ];
    }

    /**
     * restricted 선택 + 빈 역할 배열로 저장하면 422 로 차단됩니다.
     */
    public function test_store_blocks_restricted_with_empty_roles(): void
    {
        $data = $this->baseProductData('A33-001');
        $data['purchase_restriction'] = 'restricted';
        $data['allowed_roles'] = [];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('allowed_roles');
        $this->assertDatabaseMissing('ecommerce_products', ['product_code' => 'A33-001']);
    }

    /**
     * restricted 선택 + 역할 미전송 시에도 422 로 차단됩니다.
     */
    public function test_store_blocks_restricted_with_missing_roles(): void
    {
        $data = $this->baseProductData('A33-002');
        $data['purchase_restriction'] = 'restricted';
        // allowed_roles 미전송

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('allowed_roles');
    }

    /**
     * restricted 선택 + 유효 역할 1개면 정상 등록됩니다.
     */
    public function test_store_allows_restricted_with_valid_role(): void
    {
        $data = $this->baseProductData('A33-003');
        $data['purchase_restriction'] = 'restricted';
        $data['allowed_roles'] = [$this->someRoleId()];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();
        $this->assertDatabaseHas('ecommerce_products', ['product_code' => 'A33-003']);
    }

    /**
     * none(제한 없음) + 빈 역할 배열은 정상 등록됩니다 (무관 케이스).
     */
    public function test_store_allows_none_with_empty_roles(): void
    {
        $data = $this->baseProductData('A33-004');
        $data['purchase_restriction'] = 'none';
        $data['allowed_roles'] = [];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertCreated();
        $this->assertDatabaseHas('ecommerce_products', ['product_code' => 'A33-004']);
    }

    /**
     * restricted + 미존재 역할 ID 는 존재 검증(allowed_roles.*)으로 차단됩니다 (cross-field 중복 아님).
     */
    public function test_store_blocks_restricted_with_nonexistent_role(): void
    {
        $data = $this->baseProductData('A33-005');
        $data['purchase_restriction'] = 'restricted';
        $data['allowed_roles'] = [999999];

        $response = $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $data);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('allowed_roles.0');
    }

    /**
     * 수정 폼에서도 부모(StoreProductRequest)의 cross-field 검증이 상속됩니다.
     */
    public function test_update_inherits_restricted_role_validation(): void
    {
        // Given: restricted + 역할 1개로 등록된 상품
        $createData = $this->baseProductData('A33-006');
        $createData['purchase_restriction'] = 'restricted';
        $createData['allowed_roles'] = [$this->someRoleId()];

        $this->actingAs($this->user)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/products', $createData)
            ->assertCreated();

        $product = Product::where('product_code', 'A33-006')->first();

        // When: 수정에서 역할 전부 제거
        $updateData = $this->baseProductData('A33-006');
        $updateData['purchase_restriction'] = 'restricted';
        $updateData['allowed_roles'] = [];

        $response = $this->actingAs($this->user)
            ->putJson("/api/modules/sirsoft-ecommerce/admin/products/{$product->id}", $updateData);

        // Then: 422 차단 (부모 검증 상속)
        $response->assertStatus(422);
        $response->assertJsonValidationErrors('allowed_roles');
    }
}
