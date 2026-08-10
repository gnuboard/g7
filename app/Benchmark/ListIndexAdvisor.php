<?php

namespace App\Benchmark;

use App\Benchmark\DTO\BenchmarkProfile;
use Illuminate\Support\Facades\Schema;

/**
 * 목록 프로파일 선언에서 필요한 색인을 도출하고 스키마와 대조합니다.
 *
 * 배경: 지연 조인은 inner 가 키 컬럼만 읽게 만들지만, 그 inner 가 **인덱스 순서 그대로**
 * 끝나야 깊은 OFFSET 이 싸진다. 정렬을 덮는 인덱스가 없으면 inner 도 filesort 로 전체를
 * 훑으므로 개선 폭이 사라진다. 그런데 어떤 색인이 필요한지는 지금까지 테이블마다 사람이
 * 손으로 설계했고, 맞는지 검사하는 장치가 없었다.
 *
 * 필요한 정보는 이미 계측 프로파일에 선언돼 있다 — `filters` / `order` / `soft_delete` 를
 * 이으면 그대로 색인 레시피가 된다:
 *
 *   (등치 필터 컬럼들 → soft_delete 면 deleted_at → 정렬 컬럼들 → 기본키)
 *
 * 이 클래스는 그 도출을 한 곳에 두어, 새 목록이 프로파일을 선언하는 순간 필요한 색인이
 * 자동으로 드러나게 한다. 색인 설계를 사람의 기억이 아니라 이미 있는 선언에 붙이는 것이라
 * 호출자가 추가로 선언할 것은 없다.
 *
 * 등치가 아닌 필터(`in` / `not in` / 범위)는 선행 컬럼에 넣지 않는다 — 선행 컬럼이 등치로
 * 고정되지 않으면 뒤따르는 정렬 컬럼을 인덱스 순서로 쓸 수 없기 때문이다. 이 판정을 틀리면
 * "색인이 있는데도 filesort" 라는, 눈으로는 구분되지 않는 상태를 정상으로 보고하게 된다.
 */
class ListIndexAdvisor
{
    /** 선행 컬럼으로 쓸 수 있는 연산자 (등치만) */
    private const EQUALITY_OPERATORS = ['='];

    /**
     * 프로파일이 요구하는 색인 선행 컬럼 목록을 도출합니다.
     *
     * @param  array<string, mixed>  $options  목록 프로파일 옵션
     * @return array<int, string> 선행 컬럼 순서 (등치 → deleted_at → 정렬 → 기본키)
     */
    public function requiredColumns(array $options, string $keyName = 'id'): array
    {
        $leading = [];

        foreach (($options['filters'] ?? []) as $column => $declared) {
            if (is_array($declared) && count($declared) === 2 && is_string($declared[0])) {
                // [연산자, 값] 형태 — 등치만 선행 컬럼 자격이 있다
                if (in_array(strtolower($declared[0]), self::EQUALITY_OPERATORS, true)) {
                    $leading[] = (string) $column;
                }

                continue;
            }

            // 값만 준 형태는 등가 비교
            $leading[] = (string) $column;
        }

        if ($options['soft_delete'] ?? false) {
            $leading[] = 'deleted_at';
        }

        foreach (($options['order'] ?? []) as $spec) {
            $column = is_array($spec) ? ($spec[0] ?? null) : $spec;

            if (is_string($column) && $column !== '') {
                $leading[] = $column;
            }
        }

        // 정렬 끝에 기본키가 없으면 동률 구간에서 filesort 가 남는다
        if (end($leading) !== $keyName) {
            $leading[] = $keyName;
        }

        return array_values(array_unique($leading));
    }

    /**
     * 도출한 색인이 실제 스키마에 있는지 대조합니다.
     *
     * @param  array<string, mixed>  $options  목록 프로파일 옵션
     * @return array{
     *     table: string|null,
     *     required: array<int, string>,
     *     satisfied_by: string|null,
     *     partial_by: string|null,
     *     status: string
     * } status: satisfied | tiebreak_missing | missing | table_absent | not_applicable
     */
    public function inspect(array $options, string $keyName = 'id'): array
    {
        $table = $options['table'] ?? null;
        $required = $this->requiredColumns($options, $keyName);

        $result = [
            'table' => $table,
            'required' => $required,
            'satisfied_by' => null,
            'partial_by' => null,
            'status' => 'not_applicable',
        ];

        if (! is_string($table) || $table === '') {
            return $result;
        }

        if (! Schema::hasTable($table)) {
            $result['status'] = 'table_absent';

            return $result;
        }

        // 기본키를 뺀 형태 — 색인은 있으나 tie-break 만 빠진 경우를 구분한다.
        // 이 둘은 처방이 다르다: 전자는 색인 신설, 후자는 기존 색인에 기본키를 덧붙이는 교체.
        $withoutKey = $required;
        if (end($withoutKey) === $keyName) {
            array_pop($withoutKey);
        }

        foreach (Schema::getIndexes($table) as $index) {
            $columns = $index['columns'] ?? [];

            if ($result['satisfied_by'] === null && $this->hasPrefix($columns, $required)) {
                $result['satisfied_by'] = $index['name'];
            }

            if ($this->hasPrefix($columns, $withoutKey)) {
                // UNIQUE 색인은 값이 중복되지 않으므로 그 컬럼만으로 이미 전순서다.
                // 기본키를 덧붙일 필요가 없고, 붙여도 filesort 가 줄지 않는다.
                if ($result['satisfied_by'] === null && ($index['unique'] ?? false)) {
                    $result['satisfied_by'] = $index['name'];
                }

                if ($result['partial_by'] === null) {
                    $result['partial_by'] = $index['name'];
                }
            }
        }

        $result['status'] = match (true) {
            $result['satisfied_by'] !== null => 'satisfied',
            $result['partial_by'] !== null => 'tiebreak_missing',
            default => 'missing',
        };

        return $result;
    }

    /**
     * 프로파일에 선언된 면제 사유를 돌려줍니다.
     *
     * 행 수가 고정되거나 상한이 작아 색인이 불필요한 목록은 프로파일에 사유를 적어 면제한다.
     * 사유 없는 면제를 허용하지 않는 이유는, 면제가 쌓이면 검사 자체가 무의미해지는데
     * 사유가 없으면 나중에 그 판단이 여전히 옳은지 확인할 방법이 없기 때문이다.
     *
     * @param  array<string, mixed>  $options  목록 프로파일 옵션
     * @return string|null 면제 사유 (없으면 null)
     */
    public function exemptionReason(array $options): ?string
    {
        $reason = $options['index_exempt'] ?? null;

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }

    /**
     * 프로파일 목록을 한 번에 점검합니다.
     *
     * @param  array<string, BenchmarkProfile>  $profiles  목록 축 프로파일
     * @return array<int, array<string, mixed>> 프로파일별 점검 결과
     */
    public function inspectAll(array $profiles): array
    {
        $rows = [];

        foreach ($profiles as $key => $profile) {
            $options = $profile->options ?? [];

            $rows[] = array_merge(
                ['profile' => is_string($key) ? $key : $profile->qualifiedKey()],
                $this->inspect($options),
                ['exemption' => $this->exemptionReason($options)]
            );
        }

        return $rows;
    }

    /**
     * 인덱스 컬럼이 요구 목록을 선행 프리픽스로 포함하는지 판정합니다.
     *
     * @param  array<int, string>  $columns  인덱스 컬럼 순서
     * @param  array<int, string>  $required  요구 선행 컬럼
     */
    private function hasPrefix(array $columns, array $required): bool
    {
        if ($required === [] || count($columns) < count($required)) {
            return false;
        }

        return array_slice(array_values($columns), 0, count($required)) === array_values($required);
    }
}
