<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\SearchPreset;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 주문 목록 화면의 검색 프리셋은 주문 조회 권한으로도 열린다 (S3-D6, 2026-10-07 실측)
 *
 * 프리셋 라우트가 상품 권한(+ 클레임 조회)만 받아, 주문 조회 권한만 가진 운영자가 주문 목록에 들어갈
 * 때마다 `GET admin/presets?target_screen=orders` 가 403 이 되어 「해당 권한이 없습니다.」 알림이 떴다.
 * 프리셋은 운영자 본인의 조회 조건(소유권은 서비스가 검사)이라 화면을 볼 수 있으면 다룰 수 있어야 한다.
 */
class SearchPresetOrderPermissionTest extends ModuleTestCase
{
    private const INDEX_URL = '/api/modules/sirsoft-ecommerce/admin/presets';

    /**
     * 주문 조회 권한만 가진 운영자도 주문 화면 프리셋 목록을 읽는다.
     *
     * @scenario case=presets_permission
     *
     * @effects orders_presets_follow_orders_permission
     *
     * @return void 반환값 없음
     */
    public function test_orders_screen_index_allowed_with_orders_read_only(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::INDEX_URL.'?target_screen=orders')
            ->assertStatus(200);
    }

    /**
     * 주문 조회 권한만 가진 운영자도 주문 화면 프리셋을 저장한다.
     *
     * @scenario case=presets_permission
     *
     * @effects orders_presets_follow_orders_permission
     *
     * @return void 반환값 없음
     */
    public function test_orders_screen_store_allowed_with_orders_read_only(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.orders.read']);

        $this->actingAs($admin, 'sanctum')
            ->postJson(self::INDEX_URL, [
                'name' => '내 주문 프리셋',
                'target_screen' => 'orders',
                'conditions' => ['order_status' => ['payment_complete']],
            ])
            ->assertStatus(201);
    }

    /**
     * 주문·상품·클레임 권한이 모두 없으면 여전히 403 이다.
     *
     * @scenario case=presets_permission
     *
     * @effects orders_presets_follow_orders_permission
     *
     * @return void 반환값 없음
     */
    public function test_index_forbidden_without_any_screen_permission(): void
    {
        $admin = $this->createAdminUser();

        $this->actingAs($admin, 'sanctum')
            ->getJson(self::INDEX_URL.'?target_screen=orders')
            ->assertStatus(403);
    }
}
