<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Export;

use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 목록 응답의 내보내기 능력(`abilities.can_export`) 테스트
 *
 * 주문·상품·마일리지 목록의 [내보내기] 버튼은 다른 목록 버튼처럼 서버 abilities 로 보일지 정한다(7.1.0 abilities 게이트).
 * 목록 API 가 이미 그 목록의 조회 권한을 요구하므로 `can_export` 는 「내보내기 요청」 권한 하나로 정한다.
 */
class ExportListAbilityTest extends ExportTestCase
{
    /**
     * 목록 주소와 조회 권한
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LISTS = [
        'orders' => ['/api/modules/sirsoft-ecommerce/admin/orders', 'sirsoft-ecommerce.orders.read'],
        'products' => ['/api/modules/sirsoft-ecommerce/admin/products', 'sirsoft-ecommerce.products.read'],
        'mileage' => ['/api/modules/sirsoft-ecommerce/admin/mileage-transactions', 'sirsoft-ecommerce.mileage.read'],
    ];

    /**
     * 내보내기 요청 권한이 있으면 세 목록 모두 can_export 가 참이다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=export_create
     *
     * @effects list_abilities_carry_can_export
     */
    public function test_lists_report_can_export_with_export_permission(): void
    {
        foreach (self::LISTS as $type => [$url, $read]) {
            $admin = $this->createAdminUser([$read, 'sirsoft-ecommerce.exports.create']);
            $this->actingAs($admin)->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.abilities.can_export', true, $type);
        }
    }

    /**
     * 목록 조회 권한만 있으면 세 목록 모두 can_export 가 거짓이다 — 버튼이 보이지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario type=orders, permission=no_export_create
     *
     * @effects list_abilities_carry_can_export
     */
    public function test_lists_report_no_can_export_without_export_permission(): void
    {
        foreach (self::LISTS as $type => [$url, $read]) {
            $admin = $this->createAdminUser([$read]);
            $this->actingAs($admin)->getJson($url)
                ->assertOk()
                ->assertJsonPath('data.abilities.can_export', false, $type);
        }
    }
}
