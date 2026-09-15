<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\SearchPreset;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 검색 프리셋 라우트 권한 회귀 테스트 (N-6)
 *
 * presets 라우트 4종은 형제 그룹(brands/product-labels) 규약대로 products 권한
 * 미들웨어로 보호되어야 한다. index=products.read, store/update/destroy=products.update.
 */
class SearchPresetPermissionTest extends ModuleTestCase
{
    private const INDEX_URL = '/api/modules/sirsoft-ecommerce/admin/presets';

    /**
     * products.read 권한이 없는 관리자는 프리셋 목록 조회가 403.
     *
     * @scenario case=presets_permission
     *
     * @effects presets_require_product_permission
     */
    public function test_index_forbidden_without_products_read(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::INDEX_URL)
            ->assertStatus(403);
    }

    /**
     * products.read 권한이 있는 관리자는 프리셋 목록 조회가 200.
     *
     * @scenario case=presets_permission
     *
     * @effects presets_require_product_permission
     */
    public function test_index_allowed_with_products_read(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::INDEX_URL)
            ->assertStatus(200);
    }

    /**
     * products.update 권한이 없는 관리자는 프리셋 생성이 403.
     *
     * @scenario case=presets_permission
     *
     * @effects presets_require_product_permission
     */
    public function test_store_forbidden_without_products_update(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.read']);

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::INDEX_URL, [
                'name' => '내 프리셋',
                'conditions' => ['status' => 'active'],
            ])
            ->assertStatus(403);
    }

    /**
     * products.update 권한이 있는 관리자는 프리셋 생성이 허용된다.
     *
     * @scenario case=presets_permission
     *
     * @effects presets_require_product_permission
     */
    public function test_store_allowed_with_products_update(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.products.update']);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson(self::INDEX_URL, [
                'name' => '내 프리셋',
                'conditions' => ['status' => 'active'],
            ]);

        // 권한 게이트를 통과했으므로 403 이 아니어야 하며, 정상 생성(201)된다.
        $this->assertNotSame(403, $response->status());
        $response->assertStatus(201);
    }
}
