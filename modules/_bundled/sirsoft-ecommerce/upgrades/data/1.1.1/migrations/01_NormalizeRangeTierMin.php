<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_1_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 구간별 배송비의 구간 시작값을 현재 계약으로 정규화합니다.
 *
 * 시작값은 표시 전용이며 직전 구간의 종료값에서 파생됩니다 — 첫 구간은 0, 이후 구간은
 * 수량이면 `직전 max + 1`, 금액·무게·부피면 `직전 max` 입니다. 배송비 계산은 종료값
 * 기준 사다리 매칭이라 시작값과 무관하지만, 화면에서 시작값이 읽기전용이 된 이상
 * 저장 데이터가 규칙과 어긋나 있으면 운영자가 그 정책을 다시 저장할 수 없습니다
 * (첫 구간 시작값이 1 인 기존 데이터가 그대로 거부됨).
 *
 * 종료값과 배송비는 손대지 않습니다 — 계산 결과가 바뀌지 않습니다.
 *
 * idempotent: 이미 정규화된 정책은 변경 대상이 없어 no-op.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model 을 참조하지 않는다.
 */
class NormalizeRangeTierMin implements DataMigration
{
    private const TABLE = 'ecommerce_shipping_policy_country_settings';

    /**
     * 이산형(다음 시작값 = 직전 종료값 + 1) 부과정책.
     *
     * @var array<int, string>
     */
    private const DISCRETE_POLICIES = ['range_quantity'];

    /**
     * 연속형(다음 시작값 = 직전 종료값) 부과정책.
     *
     * @var array<int, string>
     */
    private const CONTINUOUS_POLICIES = [
        'range_amount',
        'range_weight',
        'range_volume',
        'range_volume_weight',
    ];

    public function name(): string
    {
        return 'NormalizeRangeTierMin';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'ranges')) {
            $context->logger->warning('[ecommerce:1.1.1] '.self::TABLE.'.ranges 미존재 — 스킵');

            return;
        }

        $normalized = 0;

        DB::table(self::TABLE)
            ->whereIn('charge_policy', array_merge(self::DISCRETE_POLICIES, self::CONTINUOUS_POLICIES))
            ->whereNotNull('ranges')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$normalized, $context) {
                foreach ($rows as $row) {
                    $ranges = json_decode((string) $row->ranges, true);

                    if (! is_array($ranges) || ! isset($ranges['tiers']) || ! is_array($ranges['tiers'])) {
                        continue;
                    }

                    $isDiscrete = in_array($row->charge_policy, self::DISCRETE_POLICIES, true);
                    $tiers = array_values($ranges['tiers']);
                    $changed = false;

                    foreach ($tiers as $index => $tier) {
                        if (! is_array($tier)) {
                            continue;
                        }

                        if ($index === 0) {
                            $expectedMin = 0;
                        } else {
                            $previousMax = $this->resolveMax($tiers[$index - 1] ?? []);

                            if ($previousMax === null) {
                                // 직전 구간의 종료값이 없으면 파생 근거가 없다 — 손대지 않는다
                                continue;
                            }

                            $expectedMin = $previousMax + ($isDiscrete ? 1 : 0);
                        }

                        if ((float) ($tier['min'] ?? 0) !== (float) $expectedMin) {
                            $tiers[$index]['min'] = $expectedMin;
                            $changed = true;
                        }
                    }

                    if (! $changed) {
                        continue;
                    }

                    $ranges['tiers'] = $tiers;

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update(['ranges' => json_encode($ranges, JSON_UNESCAPED_UNICODE)]);

                    $normalized++;
                    $context->logger->info("[ecommerce:1.1.1] 국가별 설정 #{$row->id} 구간 시작값 정규화");
                }
            });

        $context->logger->info("[ecommerce:1.1.1] 구간 시작값 정규화 완료: {$normalized} 건");
    }

    /**
     * 구간의 종료값을 반환합니다 (무제한이면 null).
     *
     * @param  array  $tier  구간 정의
     * @return float|null 종료값
     */
    private function resolveMax(array $tier): ?float
    {
        $max = $tier['max'] ?? null;

        if ($max === null || $max === '') {
            return null;
        }

        return (float) $max;
    }
}
