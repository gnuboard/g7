<?php

namespace Tests\Unit\Repositories\Concerns;

use App\Repositories\Concerns\CalculatesJsonContentDiff;
use PHPUnit\Framework\TestCase;

/**
 * 버전 변경량(라인 LCS 카운트) 계산의 메모리 상한과 카운트 불변을 고정합니다. [case:backend-32]
 *
 * 큰 공통 레이아웃(직렬화 2,000줄 이상)의 첫 편집기 저장은 변경 영역이 파일 전체가 된다 —
 * 편집기가 `comment` 키를 떼어내 첫 줄과 끝 근처가 동시에 달라져 prefix/suffix 트리밍이
 * 무력해지기 때문이다. 종전 구현은 그 영역의 (줄 수)² 크기 PHP 배열을 만들어 2,350줄에서
 * 약 146MB 를 썼고, PHP 기본 memory_limit(128M) 인 서버에서 저장이 500 으로 끝났다.
 * 개발 머신은 512M 이라 드러나지 않았다.
 *
 * 카운트는 LCS 길이만으로 결정된다(추가 = 새 줄 − LCS, 삭제 = 옛 줄 − LCS). 두 행만 쓰는
 * 계산으로 바꿔도 숫자가 종전(전체 표 + backtrack)과 같아야 목록의 +N/-N 이 diff 뷰와
 * 계속 일치한다 — 그 동치를 참조 구현 대비 무작위 입력으로 잠근다.
 */
class CalculatesJsonContentDiffTest extends TestCase
{
    /**
     * 트레이트를 조합한 익명 객체 (private 메서드를 공개 래퍼로 노출)
     *
     * @return object
     */
    private function subject(): object
    {
        return new class
        {
            use CalculatesJsonContentDiff;

            /** @param array<string> $a @param array<string> $b @return array{0: int, 1: int} */
            public function counts(array $a, array $b): array
            {
                return $this->diffLineCounts($a, $b);
            }

            /** @param array<string, mixed> $content @return array<string> */
            public function lines(array $content): array
            {
                return $this->contentToLines($content);
            }
        };
    }

    /**
     * 종전 구현(전체 DP 표 + backtrack) — 카운트 동치의 참조 기준.
     *
     * @param  array<string>  $a  이전 라인
     * @param  array<string>  $b  새 라인
     * @return array{0: int, 1: int} [추가, 삭제]
     */
    private function referenceCounts(array $a, array $b): array
    {
        $na = count($a);
        $nb = count($b);
        $dp = array_fill(0, $na + 1, array_fill(0, $nb + 1, 0));
        for ($i = $na - 1; $i >= 0; $i--) {
            for ($j = $nb - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }
        $added = 0;
        $removed = 0;
        $i = 0;
        $j = 0;
        while ($i < $na && $j < $nb) {
            if ($a[$i] === $b[$j]) {
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $removed++;
                $i++;
            } else {
                $added++;
                $j++;
            }
        }

        return [$added + ($nb - $j), $removed + ($na - $i)];
    }

    /**
     * 직렬화하면 2,300줄을 넘는 레이아웃 형태의 content 를 만듭니다.
     *
     * @return array<string, mixed>
     */
    private function largeLayout(): array
    {
        $components = [];
        for ($i = 0; $i < 260; $i++) {
            $components[] = [
                'id' => "node_{$i}",
                'type' => 'basic',
                'name' => 'Div',
                'props' => ['className' => "p-{$i} flex", 'title' => "노드 {$i}"],
                'children' => [],
            ];
        }

        return [
            'components' => $components,
            'layout_name' => 'big',
            'version' => '1.0.0',
        ];
    }

    /**
     * 변경 영역이 파일 전체인 큰 레이아웃의 카운트 계산이 메모리 예산 안에 머물러야 합니다.
     *
     * 이전 content 에는 정렬 시 맨 앞에 오는 `comment` 와 맨 뒤에 오는 `zzz` 가 있고 새
     * content 에는 둘 다 없다 — 편집기 첫 저장이 만드는 정확히 그 형태다. 종전 구현은 여기서
     * 146MB 를 더 썼다.
     *
     * @effects changes_summary_memory_bounded_for_full_span_change
     */
    public function test_full_span_change_of_large_layout_stays_within_memory_budget(): void
    {
        $subject = $this->subject();
        $new = $this->largeLayout();
        $old = ['comment' => '상속원 설명'] + $new + ['zzz' => 'x'];

        $oldLines = $subject->lines($old);
        $newLines = $subject->lines($new);
        $this->assertGreaterThan(2300, count($oldLines), '재현 조건: 직렬화 2,300줄 이상');

        gc_collect_cycles();
        $before = memory_get_peak_usage();
        [$added, $removed] = $subject->counts($oldLines, $newLines);
        $grown = memory_get_peak_usage() - $before;

        $this->assertLessThan(
            16 * 1024 * 1024,
            $grown,
            sprintf('카운트 계산이 %.1fMB 를 더 썼습니다 — (줄 수)² 표를 만들고 있습니다', $grown / 1048576)
        );
        // comment 삭제 + zzz 삭제 + `"version": "1.0.0",` → `"version": "1.0.0"` (삭제 1·추가 1)
        $this->assertSame([1, 3], [$added, $removed]);
    }

    /**
     * 카운트는 종전 구현(전체 표 + backtrack)과 무작위 입력에서 항상 같아야 합니다 —
     * 목록의 +N/-N 이 diff 뷰(프론트 lineDiff.ts)와 일치한다는 S11 계약의 근거다.
     *
     * @effects changes_summary_uses_line_lcs_unit_matching_diff_view
     */
    public function test_counts_equal_full_table_reference_on_random_inputs(): void
    {
        $subject = $this->subject();
        mt_srand(20260909);

        for ($case = 0; $case < 600; $case++) {
            $alphabet = mt_rand(1, 6); // 작은 알파벳 = 중복 라인(`}`, `],`)이 많은 레이아웃 형태
            $a = [];
            $b = [];
            for ($i = 0, $n = mt_rand(0, 40); $i < $n; $i++) {
                $a[] = chr(97 + mt_rand(0, $alphabet));
            }
            for ($i = 0, $m = mt_rand(0, 40); $i < $m; $i++) {
                $b[] = chr(97 + mt_rand(0, $alphabet));
            }

            $this->assertSame(
                $this->referenceCounts($a, $b),
                $subject->counts($a, $b),
                sprintf('case %d: %s vs %s', $case, implode('', $a), implode('', $b))
            );
        }
    }

    /**
     * 레이아웃 형태의 변형(노드 추가·삭제·prop 변경·순서 변경)에서도 참조 구현과 같아야 합니다.
     *
     * @effects changes_summary_uses_line_lcs_unit_matching_diff_view
     */
    public function test_counts_equal_reference_on_layout_shaped_mutations(): void
    {
        $subject = $this->subject();
        $base = [
            'components' => [
                ['id' => 'a', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'x'], 'children' => []],
                ['id' => 'b', 'type' => 'basic', 'name' => 'Span', 'props' => ['text' => '안녕'], 'children' => []],
                ['id' => 'c', 'type' => 'composite', 'name' => 'Header', 'props' => ['logo' => '/a.png']],
            ],
            'layout_name' => 'home',
            'version' => '1.0.0',
        ];

        $mutations = [
            'prop 변경' => (function () use ($base) {
                $base['components'][2]['props']['logo'] = '/api/templates/x/layout-attachments/1/file';

                return $base;
            })(),
            '노드 추가' => (function () use ($base) {
                $base['components'][] = ['id' => 'd', 'type' => 'basic', 'name' => 'P', 'props' => []];

                return $base;
            })(),
            '노드 삭제' => (function () use ($base) {
                unset($base['components'][1]);
                $base['components'] = array_values($base['components']);

                return $base;
            })(),
            '순서 변경' => (function () use ($base) {
                $base['components'] = [$base['components'][2], $base['components'][0], $base['components'][1]];

                return $base;
            })(),
            '빈 content' => [],
        ];

        $baseLines = $subject->lines($base);
        foreach ($mutations as $label => $mutated) {
            $mutatedLines = $subject->lines($mutated);
            $this->assertSame($this->referenceCounts($baseLines, $mutatedLines), $subject->counts($baseLines, $mutatedLines), $label);
            $this->assertSame($this->referenceCounts($mutatedLines, $baseLines), $subject->counts($mutatedLines, $baseLines), $label.' (역방향)');
        }
    }
}
