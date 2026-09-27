<?php

namespace Tests\Unit\Analytics;

use App\Services\Analytics\DailyAccumulator;
use PHPUnit\Framework\TestCase;

/**
 * 일 누적기 — 확장 차원 라벨 수 상한.
 *
 * 상한은 방문 수가 많은 라벨부터 남기고, 방문 수가 같으면 라벨 순으로 고른다. 재집계할 때마다
 * 남는 라벨이 바뀌면 같은 날의 리포트가 실행마다 달라진다.
 */
class DailyAccumulatorTest extends TestCase
{
    /**
     * 차원 행 하나를 방문 여러 개로 더합니다.
     *
     * @param  DailyAccumulator  $daily  누적기
     * @param  string  $dimension  차원 이름
     * @param  string  $label  라벨
     * @param  int  $visits  방문 수
     * @param  string  $visitorType  방문자 유형
     * @return void 없음
     */
    private function add(DailyAccumulator $daily, string $dimension, string $label, int $visits, string $visitorType = 'guest'): void
    {
        for ($i = 0; $i < $visits; $i++) {
            $daily->addDimension($dimension, $label, $visitorType, ['visit_key' => $label.'-'.$visitorType.'-'.$i, 'hits' => 1]);
        }
    }

    /**
     * 상한을 넘으면 방문 수 상위만 남고, 유형이 갈려도 라벨 단위로 센다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects dimension_max_cardinality_keeps_top_labels
     *
     * @return void 없음
     */
    public function test_cap_keeps_top_labels_across_visitor_types(): void
    {
        $daily = new DailyAccumulator;
        $this->add($daily, 'ext:v:x', 'a', 1);
        $this->add($daily, 'ext:v:x', 'b', 2);
        $this->add($daily, 'ext:v:x', 'b', 2, 'member');
        $this->add($daily, 'ext:v:x', 'c', 3);
        $this->add($daily, 'page_url', 'p', 1);

        $rows = $daily->dimensionRows('2026-09-01', '2026-09-02 00:00:00', ['ext:v:x' => 2]);
        $labels = array_values(array_unique(array_map(
            fn (array $row) => $row['label'],
            array_filter($rows, fn (array $row) => $row['dimension'] === 'ext:v:x'),
        )));
        sort($labels);

        $this->assertSame(['b', 'c'], $labels, 'b 는 유형 둘에 걸쳐 방문 4 — 라벨 단위로 합쳐 센다');
        $this->assertCount(3, array_filter($rows, fn (array $row) => $row['dimension'] === 'ext:v:x'), 'b 의 두 유형 행이 모두 남는다');
        $this->assertCount(1, array_filter($rows, fn (array $row) => $row['dimension'] === 'page_url'), '상한이 없는 차원은 그대로');
    }

    /**
     * 방문 수가 같으면 라벨 순으로 골라 실행마다 같은 결과를 낸다.
     *
     * @scenario point=dimension, extension_state=active, input=registered
     *
     * @effects dimension_max_cardinality_keeps_top_labels
     *
     * @return void 없음
     */
    public function test_cap_breaks_ties_by_label(): void
    {
        foreach ([['z', 'y', 'x'], ['x', 'z', 'y']] as $order) {
            $daily = new DailyAccumulator;
            foreach ($order as $label) {
                $this->add($daily, 'ext:v:x', $label, 1);
            }

            $labels = array_map(fn (array $row) => $row['label'], $daily->dimensionRows('2026-09-01', '2026-09-02 00:00:00', ['ext:v:x' => 2]));
            sort($labels);

            $this->assertSame(['x', 'y'], $labels, '넣은 순서와 무관하게 같은 라벨이 남는다');
        }
    }
}
