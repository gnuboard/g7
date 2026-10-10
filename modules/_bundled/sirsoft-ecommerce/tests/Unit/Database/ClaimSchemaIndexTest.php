<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database;

use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 클레임 스키마 인덱스 테스트 (P16)
 *
 * 인덱스는 조회가 실제로 쓰는 모양이어야 한다 — 선두가 될 수 없는 단독 인덱스는 읽기에
 * 기여하지 않으면서 모든 쓰기에 비용을 물린다.
 */
class ClaimSchemaIndexTest extends ModuleTestCase
{
    /**
     * 테이블의 인덱스 이름 → 컬럼 목록 맵을 반환합니다.
     *
     * @param  string  $table  테이블명 (프리픽스 없는 논리명)
     * @return array<string, array<int, string>> 인덱스 맵
     */
    private function indexMap(string $table): array
    {
        $map = [];

        foreach (Schema::getIndexes($table) as $index) {
            $map[$index['name']] = $index['columns'];
        }

        return $map;
    }

    /**
     * 첨부 정렬은 귀속 대상 복합 인덱스가 받고, sort_order 단독 인덱스는 두지 않는다.
     *
     * 조회 전건이 `claimable_type` · `claimable_id` 등치로 먼저 좁힌 뒤 정렬하므로
     * sort_order 단독 인덱스는 선두가 될 수 없다.
     */
    #[Test]
    public function claim_images_orders_through_the_claimable_composite_index(): void
    {
        $indexes = $this->indexMap('ecommerce_claim_images');

        $this->assertArrayHasKey('ecommerce_claim_images_claimable_index', $indexes);
        $this->assertSame(
            ['claimable_type', 'claimable_id', 'sort_order'],
            $indexes['ecommerce_claim_images_claimable_index'],
            '정렬 컬럼이 복합 인덱스 꼬리에 없으면 정렬이 인덱스로 해결되지 않는다'
        );

        $this->assertArrayNotHasKey(
            'ecommerce_claim_images_sort_order_index',
            $indexes,
            'sort_order 단독 인덱스는 어떤 조회에서도 선두가 될 수 없다'
        );
    }

    /**
     * 결제 상태 단독 인덱스는 유지한다 — 고아 정리 배치가 선두로 쓴다.
     *
     * "사문 인덱스" 판정의 대조군이다. 이 단언이 깨지면 그 배치 조회가 풀스캔이 된다.
     */
    #[Test]
    public function claim_payments_keeps_the_status_index_used_by_the_reaper(): void
    {
        $indexes = $this->indexMap('ecommerce_claim_payments');

        $this->assertArrayHasKey('ecommerce_claim_payments_status_index', $indexes);
        $this->assertSame(['payment_status'], $indexes['ecommerce_claim_payments_status_index']);
    }
}
