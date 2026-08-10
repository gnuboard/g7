<?php

namespace Tests\Unit\Benchmark;

use App\Benchmark\ListIndexAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\AssertsListIndexCoverage;
use Tests\TestCase;

/**
 * 코어 목록 프로파일의 색인 커버리지 검사
 *
 * 지연 조인은 inner 가 키 컬럼만 읽게 만들지만, 그 inner 가 인덱스 순서 그대로 끝나야
 * 깊은 OFFSET 이 싸진다. 정렬을 덮는 색인이 없으면 inner 도 filesort 로 전체를 훑어
 * 개선 폭이 사라지는데, 이 실패는 화면에서 "뒤로 갈수록 느리다" 로만 드러난다.
 *
 * 필요한 색인은 프로파일 선언(filters / order / soft_delete)에서 도출한다 — 새 목록이
 * 프로파일을 선언하면 필요한 색인이 자동으로 검사 대상이 되므로, 색인 설계를 매번 손으로
 * 반복하지 않아도 된다.
 *
 * @scenario case=list_index_coverage
 *
 * @effects list_profiles_declare_matching_index
 */
class ListIndexCoverageTest extends TestCase
{
    use AssertsListIndexCoverage;
    use RefreshDatabase;

    public function test_core_list_profiles_have_matching_indexes(): void
    {
        $this->assertListIndexCoverage('core');
    }

    /**
     * 도출 규칙 자체가 맞는지 검사합니다.
     *
     * 커버리지 검사가 초록이어도 도출이 틀렸으면 엉뚱한 색인을 요구하거나 요구하지 않는다.
     * 판정기를 판정한다.
     */
    public function test_required_columns_are_derived_from_declaration(): void
    {
        $advisor = new ListIndexAdvisor;

        $this->assertSame(
            ['board_id', 'is_notice', 'deleted_at', 'created_at', 'id'],
            $advisor->requiredColumns([
                'filters' => ['board_id' => 1, 'is_notice' => 0],
                'soft_delete' => true,
                'order' => [['created_at', 'desc'], ['id', 'desc']],
            ]),
            '등치 필터 → deleted_at → 정렬 → 기본키 순서로 도출되어야 한다'
        );

        $this->assertSame(
            ['deleted_at', 'ordered_at', 'id'],
            $advisor->requiredColumns([
                // in / not in 은 선행 컬럼이 등치로 고정되지 않아 뒤의 정렬을 인덱스로 쓸 수 없다
                'filters' => ['order_status' => ['not in', ['draft', 'hidden']]],
                'soft_delete' => true,
                'order' => [['ordered_at', 'desc'], ['id', 'desc']],
            ]),
            '비-등치 필터는 선행 컬럼에서 제외되어야 한다'
        );

        $this->assertSame(
            ['created_at', 'id'],
            $advisor->requiredColumns([
                'order' => [['created_at', 'desc']],
            ]),
            '정렬 끝에 기본키가 없으면 자동으로 덧붙여야 한다'
        );
    }

    /**
     * 면제는 사유가 있을 때만 인정되는지 검사합니다.
     */
    public function test_exemption_requires_a_reason(): void
    {
        $advisor = new ListIndexAdvisor;

        $this->assertNull($advisor->exemptionReason([]));
        $this->assertNull($advisor->exemptionReason(['index_exempt' => '   ']));
        $this->assertNull($advisor->exemptionReason(['index_exempt' => true]));
        $this->assertSame('행 수 고정', $advisor->exemptionReason(['index_exempt' => '행 수 고정']));
    }
}
