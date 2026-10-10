<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\SearchPreset;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 검색 프리셋 라우트 권한 회귀 테스트 (N-6)
 *
 * presets 라우트 4종은 형제 그룹(brands/product-labels) 규약대로 products 권한
 * 미들웨어로 보호되어야 한다. index=products.read, store/update/destroy=products.update.
 * 클레임 목록 화면도 프리셋을 쓰므로 클레임 조회 권한(claims.read)이 OR 로 함께 연다 —
 * 프리셋은 운영자 본인의 조회 조건이라 화면을 볼 수 있으면 다룰 수 있어야 한다.
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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
     *
     * @return void 반환값 없음
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

    /**
     * 클레임 목록 화면의 프리셋은 클레임 조회 권한으로 읽는다 — 상품 권한을 요구하면 클레임 권한만
     * 가진 운영자가 목록에 들어갈 때마다 403 오류 알림을 받는다(2026-10-07 실측).
     *
     * @scenario case=presets_permission
     *
     * @effects claims_presets_follow_claims_permission
     *
     * @return void 반환값 없음
     */
    public function test_claims_screen_index_allowed_with_claims_read_only(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::INDEX_URL.'?target_screen=claims')
            ->assertStatus(200);
    }

    /**
     * 클레임 화면 프리셋의 저장·이름 변경·삭제도 클레임 조회 권한으로 한다 — 운영자 개인의 조회 조건일 뿐이다.
     *
     * @scenario case=presets_permission
     *
     * @effects claims_presets_follow_claims_permission
     *
     * @return void 반환값 없음
     */
    public function test_claims_screen_store_update_destroy_allowed_with_claims_read_only(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson(self::INDEX_URL, [
                'name' => '클레임 프리셋',
                'target_screen' => 'claims',
                'conditions' => ['claimType' => 'return'],
            ]);
        $created->assertStatus(201);
        $presetId = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson(self::INDEX_URL.'/'.$presetId, ['name' => '클레임 프리셋2'])
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson(self::INDEX_URL.'/'.$presetId)
            ->assertStatus(200);
    }
}
