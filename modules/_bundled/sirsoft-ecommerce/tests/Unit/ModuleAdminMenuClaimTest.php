<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 메뉴 클레임 항목 등록 테스트 (S6c)
 *
 * '취소/반품/교환 관리' 메뉴가 주문 관리 직후에, 클레임 조회 권한으로 등록되는지 고정한다.
 * 메뉴 선언은 `syncDeclarativeArtifacts()` 가 업데이트마다 다시 맞추므로 선언 자체가 계약이다.
 */
class ModuleAdminMenuClaimTest extends ModuleTestCase
{
    public function test_claims_menu_is_registered_right_after_orders(): void
    {
        $children = collect((new Module)->getAdminMenus()[0]['children']);

        $claims = $children->firstWhere('slug', 'sirsoft-ecommerce-claims');

        $this->assertNotNull($claims, '취소/반품/교환 관리 메뉴가 등록되어야 합니다.');
        $this->assertSame('/admin/ecommerce/claims', $claims['url']);
        $this->assertSame('sirsoft-ecommerce.claims.read', $claims['permission']);

        // 주문 관리 직후 배치 (order 축)
        $orders = $children->firstWhere('slug', 'sirsoft-ecommerce-orders');
        $this->assertSame($orders['order'] + 1, $claims['order']);

        // order 값 중복 없음 (뒤 항목 시프트 검증)
        $orderValues = $children->pluck('order');
        $this->assertSame($orderValues->count(), $orderValues->unique()->count());
    }
}
