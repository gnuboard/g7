<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 이미 저장된 `mc_*` 금액의 절사 잔차를 지웁니다.
 *
 * `applyRounding()` 은 단위로 나눠 반올림한 뒤 그 단위를 도로 곱한다. 그 마지막 곱셈에서
 * 이진 부동소수 표현이 한 칸 어긋나 `4505 × 0.01` 이 `45.050000000000004` 가 되고, 그 값이
 * 그대로 `mc_*` JSON 컬럼에 기록됐다. 1.3.0 부터는 생산 지점이 잔차를 지우지만 그것은
 * **이후 기록분에만** 적용되므로, 기존 설치본의 과거 행은 어긋난 표기를 그대로 들고 있다.
 * 화면 표시(`*_formatted`)와 PG 청구액(최소 화폐단위 정수)은 종전에도 정상이었고, 어긋나는
 * 것은 연동 시스템이 원본으로 내려받는 숫자다.
 *
 * **금액을 바꾸지 않는다 — 표기만 정규화한다.** 부동소수 잡음(상대오차 1e-12) 안에서
 * 같은 값을 내는 가장 짧은 소수 표기로 되돌릴 뿐이라, 그 허용범위를 벗어나는 값은 손대지
 * 않는다. 거래 시점 금액을 현재 절사 설정으로 다시 계산하지 않는 이유도 같다 — 주문·환불
 * 금액은 거래 시점으로 동결되어야 하고, 운영자가 그 뒤 단위를 바꿨다면 재계산은 과거
 * 기록을 고치는 일이 된다.
 *
 * 정수로 저장된 값(기본 통화 금액)은 건드리지 않는다 — `round()` 를 태우면 `5000` 이
 * `5000.0` 으로 직렬화되어 값은 그대로인데 표기가 도리어 바뀐다.
 *
 * 대상 테이블·컬럼은 스키마에서 파생한다(`ecommerce_` 접두사 + `mc_` 컬럼) — 손으로
 * 열거하면 나중에 추가된 컬럼이 조용히 빠진다.
 *
 * idempotent: 정규화된 값은 다시 태워도 같은 값이라 두 번째 실행은 갱신 0건이다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model 을 참조하지 않는다.
 */
class NormalizeStoredMultiCurrencyRounding implements DataMigration
{
    /**
     * 대상 테이블 접두사 (이 모듈 소유 테이블만 건드린다).
     */
    private const TABLE_PREFIX = 'ecommerce_';

    /**
     * 다중 통화 금액 컬럼 접두사.
     */
    private const COLUMN_PREFIX = 'mc_';

    /**
     * 표기 복원을 시도할 최대 소수 자릿수.
     */
    private const MAX_SCALE = 8;

    /**
     * 같은 값으로 볼 상대 허용오차 (부동소수 잡음 상한).
     */
    private const EPSILON = 1e-12;

    public function name(): string
    {
        return 'NormalizeStoredMultiCurrencyRounding';
    }

    public function run(UpgradeContext $context): void
    {
        $targets = $this->targets();

        if ($targets === []) {
            $context->logger->info('[ecommerce:1.3.0] mc_* 컬럼을 가진 테이블 없음 — 저장 금액 표기 정규화 스킵');

            return;
        }

        $totalRows = 0;

        foreach ($targets as $table => $columns) {
            $totalRows += $this->normalizeTable($table, $columns);
        }

        $context->logger->info(sprintf(
            '[ecommerce:1.3.0] 저장 금액 표기 정규화 완료 — 테이블 %d개 / 갱신 %d행',
            count($targets),
            $totalRows
        ));
    }

    /**
     * 정규화 대상 테이블과 그 `mc_*` 컬럼 목록을 스키마에서 파생합니다.
     *
     * @return array<string, array<int, string>> 테이블명 → 컬럼 목록
     */
    private function targets(): array
    {
        $prefix = (string) DB::getTablePrefix();
        $targets = [];

        foreach (Schema::getTables() as $table) {
            $name = is_array($table) ? (string) ($table['name'] ?? '') : (string) $table;

            if ($name === '') {
                continue;
            }

            if ($prefix !== '' && str_starts_with($name, $prefix)) {
                $name = substr($name, strlen($prefix));
            }

            if (! str_starts_with($name, self::TABLE_PREFIX)) {
                continue;
            }

            $columns = array_values(array_filter(
                Schema::getColumnListing($name),
                fn (string $column): bool => str_starts_with($column, self::COLUMN_PREFIX)
            ));

            if ($columns !== [] && in_array('id', Schema::getColumnListing($name), true)) {
                sort($columns);
                $targets[$name] = $columns;
            }
        }

        ksort($targets);

        return $targets;
    }

    /**
     * 한 테이블의 `mc_*` 컬럼을 훑어 표기를 정규화합니다.
     *
     * @param  string  $table  테이블명
     * @param  array<int, string>  $columns  대상 컬럼 목록
     * @return int 갱신한 행 수
     */
    private function normalizeTable(string $table, array $columns): int
    {
        $updated = 0;

        DB::table($table)
            ->select(array_merge(['id'], $columns))
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $columns, &$updated) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($columns as $column) {
                        $raw = $row->{$column} ?? null;

                        if (! is_string($raw) || $raw === '') {
                            continue;
                        }

                        $decoded = json_decode($raw, true);

                        if (! is_array($decoded)) {
                            continue;
                        }

                        $normalized = $this->normalizeCurrencyMap($decoded);

                        if ($normalized === $decoded) {
                            continue;
                        }

                        $changes[$column] = json_encode(
                            $normalized,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                    }

                    if ($changes === []) {
                        continue;
                    }

                    DB::table($table)->where('id', $row->id)->update($changes);
                    $updated++;
                }
            });

        return $updated;
    }

    /**
     * `통화코드 → 필드 → 금액` 형태의 맵에서 실수 금액의 표기를 정규화합니다.
     *
     * `_formatted`(문자열)와 `_meta`(배열)는 실수가 아니라 그대로 통과한다.
     *
     * @param  array<mixed>  $map  저장된 다중 통화 맵
     * @return array<mixed> 정규화된 맵
     */
    private function normalizeCurrencyMap(array $map): array
    {
        foreach ($map as $currency => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            foreach ($fields as $field => $value) {
                if (! is_float($value)) {
                    continue;
                }

                $map[$currency][$field] = $this->canonicalize($value);
            }
        }

        return $map;
    }

    /**
     * 같은 값을 내는 가장 짧은 소수 표기로 되돌립니다.
     *
     * 허용오차 안에서 일치하는 자릿수가 없으면 원래 값을 그대로 돌려준다 — 잔차가 아니라
     * 실제로 그만큼 정밀한 값이라는 뜻이므로 건드리지 않는다.
     *
     * @param  float  $value  저장된 금액
     * @return float 정규화된 금액
     */
    private function canonicalize(float $value): float
    {
        if (! is_finite($value)) {
            return $value;
        }

        $tolerance = max(abs($value), 1.0) * self::EPSILON;

        for ($scale = 0; $scale <= self::MAX_SCALE; $scale++) {
            $candidate = round($value, $scale);

            if (abs($candidate - $value) <= $tolerance) {
                return $candidate;
            }
        }

        return $value;
    }
}
