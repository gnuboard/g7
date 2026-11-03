<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit;

use Modules\Sirsoft\Ecommerce\Module;
use Tests\PureTestCase;

/**
 * 관리자 메뉴 클레임 항목 등록 테스트 (S6c)
 *
 * '취소/반품/교환 관리' 메뉴가 주문 관리 직후에, 클레임 조회 권한으로 등록되는지 고정한다.
 * 메뉴 선언은 `syncDeclarativeArtifacts()` 가 업데이트마다 다시 맞추므로 선언 자체가 계약이다.
 */
class ModuleAdminMenuClaimTest extends PureTestCase
{
    /**
     * 취소/반품/교환 관리 메뉴가 클레임 URL·클레임 조회 권한으로 주문 관리 바로 다음 순서에 등록되고, 메뉴 order 값이 겹치지 않는다.
     *
     * @scenario case=claims_menu_after_orders
     *
     * @effects claims_menu_registered_with_claim_read_permission, claims_menu_ordered_right_after_orders, admin_menu_order_values_unique
     *
     * @return void 반환값 없음
     */
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
