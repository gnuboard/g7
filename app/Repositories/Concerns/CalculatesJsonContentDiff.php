<?php

namespace App\Repositories\Concerns;

/**
 * JSON 콘텐츠 diff 계산 trait
 *
 * 레이아웃·레이아웃 확장 등 JSON 스냅샷 기반 버전 저장소가 공유하는 순수 JSON diff
 * 알고리즘을 제공합니다. 도메인 종속성이 없습니다.
 *
 * 측정 단위는 "라인"이다. 두 content 를 키 정렬 pretty-print JSON 으로 직렬화한 뒤
 * 라인 단위 LCS(최장 공통 부분수열)로 추가/삭제 라인을 센다. 이는 버전 비교 diff
 * 뷰(프론트엔드 lineDiff.ts)와 동일한 알고리즘·동일한 직렬화·동일한 단위이므로,
 * 버전 목록의 변경량과 diff 뷰의 +추가/-삭제 숫자가 항상 일치한다.
 *
 * 종전에는 백엔드가 JSON "키 경로" 단위로 세고 diff 뷰는 "라인" 단위로 세어 같은
 * 변경에도 숫자가 달랐다(예: 객체 1개 추가 = 키 경로 6 vs 라인 8). 키 경로는 구조
 * 문자({}[])를 세지 않으므로 라인 수와 구조적으로 일치할 수 없어, 라인 LCS 를
 * 단일 SSoT 로 삼는다.
 */
trait CalculatesJsonContentDiff
{
    /**
     * LCS 변경 영역 상한 — 프론트 lineDiff.ts 의 DIFF_MAX_LINES 와 동일.
     *
     * 트리밍 후 변경 영역이 이 값을 넘으면 O(n·m) DP 를 회피하고 라인 집합 차집합으로
     * 근사한다(비정상적으로 큰 변경에서만 발동, 인접 버전 비교는 해당 없음).
     */
    private const int DIFF_MAX_LINES = 4000;

    /**
     * JSON content 변경사항 카운트 계산 (라인 단위 LCS)
     *
     * 버전 목록에 표시할 변경량은 "추가 라인 수 / 삭제 라인 수 / 문자 수 변화" 세 정수뿐이다.
     * 따라서 라인 원문을 저장하지 않고 카운트만 반환한다(종전엔 라인 원문 배열을 통째로
     * 저장해 건당 수십 KB 가 적재되던 비효율을 제거). 측정은 버전 비교 diff
     * 뷰(프론트 lineDiff.ts)와 동일한 라인 단위 LCS 라 두 화면의 숫자가 항상 일치한다.
     *
     * 라인 단위 diff 에는 "수정(modified)" 개념이 없다 — 값이 바뀐 라인은 삭제+추가로
     * 표현된다(diff 뷰의 -/+ 와 동일). 따라서 modified 카운트는 두지 않는다.
     *
     * 반환 키는 원 설계(시더·마이그레이션 주석의 `{added: int, removed: int}`)와 동일하게
     * added/removed 에 카운트(정수)를 담는다.
     *
     * @param  array  $oldContent  이전 콘텐츠
     * @param  array  $newContent  새 콘텐츠
     * @return array{added: int, removed: int, char_diff: int}
     */
    public function calculateChanges(array $oldContent, array $newContent): array
    {
        $oldLines = $this->contentToLines($oldContent);
        $newLines = $this->contentToLines($newContent);

        [$addedCount, $removedCount] = $this->diffLineCounts($oldLines, $newLines);

        // 문자 수 변화 — 직렬화된 본문 기준(diff 뷰와 동일 직렬화).
        $charDiff = mb_strlen(implode("\n", $newLines)) - mb_strlen(implode("\n", $oldLines));

        return [
            'added' => $addedCount,
            'removed' => $removedCount,
            'char_diff' => $charDiff,
        ];
    }

    /**
     * content 객체를 diff 비교용 안정 pretty-print JSON 라인 배열로 직렬화한다.
     *
     * 키를 재귀 정렬(객체만, 배열 순서는 보존)하고 2-space 들여쓰기로 직렬화한다.
     * 프론트엔드 lineDiff.ts 의 stableStringify 와 동일 규칙이라 양측 라인 집합이
     * 일치한다.
     *
     * @param  array  $content  대상 content
     * @return array<string> 라인 배열(개행 제외)
     */
    private function contentToLines(array $content): array
    {
        $sorted = $this->sortKeysDeep($content);
        // JSON_PRETTY_PRINT 는 4-space 이므로 2-space 로 환산(라인 수는 폭과 무관하지만
        // 라인 내용 비교 일관성을 위해 프론트와 동일 폭으로 맞춘다).
        $json = json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            $json = '';
        }
        $json = $this->reindentToTwoSpaces($json);

        $lines = explode("\n", $json);
        // 끝 개행으로 생긴 빈 마지막 요소 제거 (프론트 splitLines 와 동일 normalize)
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /**
     * PHP json_encode 의 4-space 들여쓰기를 2-space 로 환산한다.
     *
     * 각 라인의 선행 공백(4의 배수)을 절반으로 줄인다. 문자열 값 내부의 공백은
     * 라인 선두가 아니므로 영향받지 않는다.
     *
     * @param  string  $json  4-space pretty JSON
     * @return string 2-space pretty JSON
     */
    private function reindentToTwoSpaces(string $json): string
    {
        return preg_replace_callback(
            '/^( +)/m',
            fn (array $m) => str_repeat(' ', (int) (strlen($m[1]) / 2)),
            $json
        ) ?? $json;
    }

    /**
     * 키를 재귀적으로 정렬(객체만, list 순서는 보존)
     *
     * @param  mixed  $value  대상 값
     * @return mixed 정렬된 값
     */
    private function sortKeysDeep(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);
        $mapped = array_map(fn ($v) => $this->sortKeysDeep($v), $value);
        if (! $isList) {
            ksort($mapped);
        }

        return $mapped;
    }

    /**
     * 라인 배열 두 개의 LCS diff — 추가/삭제 라인 "수"를 반환한다.
     *
     * 공통 prefix/suffix 를 먼저 트리밍해 LCS DP 입력을 변경 영역으로 축소한다(큰
     * content 의 작은 변경도 빠르게 처리). 라인 원문은 누적하지 않고 카운트만 세므로
     * 메모리도 절약된다(저장 대상은 카운트뿐). 프론트 computeLineDiff 와 동일 전략이라
     * added/removed 카운트가 일치한다.
     *
     * @param  array<string>  $a  이전 라인
     * @param  array<string>  $b  새 라인
     * @return array{0: int, 1: int} [추가 라인 수, 삭제 라인 수]
     */
    private function diffLineCounts(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $n = count($a);
        $m = count($b);

        // 공통 prefix
        $prefix = 0;
        $maxPrefix = min($n, $m);
        while ($prefix < $maxPrefix && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        // 공통 suffix
        $suffix = 0;
        $maxSuffix = min($n, $m) - $prefix;
        while ($suffix < $maxSuffix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $midA = array_slice($a, $prefix, $n - $suffix - $prefix);
        $midB = array_slice($b, $prefix, $m - $suffix - $prefix);

        $na = count($midA);
        $nb = count($midB);

        // 안전 가드 — 변경 영역이 과대하면 LCS 를 생략하고 라인 집합 차집합으로 근사한다.
        // 프론트 lineDiff.ts 의 DIFF_MAX_LINES(4000) 와 동일 임계. 인접 버전 비교는 변경
        // 영역이 작아 이 경로를 타지 않으며, 비정상적으로 큰 변경에서만 O(n·m) DP 를 회피한다.
        if ($na > self::DIFF_MAX_LINES || $nb > self::DIFF_MAX_LINES) {
            return $this->approximateLineCounts($midA, $midB);
        }

        // LCS DP
        $dp = array_fill(0, $na + 1, array_fill(0, $nb + 1, 0));
        for ($i = $na - 1; $i >= 0; $i--) {
            for ($j = $nb - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $midA[$i] === $midB[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        // backtrack — 삭제/추가 라인 수만 카운트
        $added = 0;
        $removed = 0;
        $i = 0;
        $j = 0;
        while ($i < $na && $j < $nb) {
            if ($midA[$i] === $midB[$j]) {
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
        $removed += $na - $i;
        $added += $nb - $j;

        return [$added, $removed];
    }

    /**
     * 변경 영역이 과대할 때의 라인 diff 카운트 근사 — multiset 차집합
     *
     * LCS 없이 라인 빈도수 차이로 추가/삭제 라인 수를 근사한다. 순서를 고려하지 않으므로
     * LCS 보다 부정확할 수 있으나(이동을 추가+삭제로 셈), 비정상적으로 큰 변경에서만
     * 발동하므로 실용상 충분하다. 정확 일치 라인은 변경에서 제외된다.
     *
     * @param  array<string>  $a  이전 라인
     * @param  array<string>  $b  새 라인
     * @return array{0: int, 1: int} [추가 라인 수, 삭제 라인 수]
     */
    private function approximateLineCounts(array $a, array $b): array
    {
        $oldFreq = array_count_values($a);
        $newFreq = array_count_values($b);

        $removed = 0;
        foreach ($oldFreq as $line => $cnt) {
            $removed += max(0, $cnt - ($newFreq[$line] ?? 0));
        }

        $added = 0;
        foreach ($newFreq as $line => $cnt) {
            $added += max(0, $cnt - ($oldFreq[$line] ?? 0));
        }

        return [$added, $removed];
    }
}
