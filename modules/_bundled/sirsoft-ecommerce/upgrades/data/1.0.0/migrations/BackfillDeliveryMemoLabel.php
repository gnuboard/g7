<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 주문 배송지의 delivery_memo(프리셋 키)를 delivery_memo_label(표시 라벨)로 백필합니다.
 *
 * 주문별 당시 locale 기록이 없으므로 사이트 기본 locale(config('app.locale')) 기준으로
 * 일괄 백필합니다. 프리셋 키(door/security/parcel_box/call)는 enums.delivery_memo_preset
 * 라벨로, 그 외 자유 텍스트는 원문을 그대로 라벨에 복사합니다.
 *
 * idempotent: delivery_memo_label 이 이미 채워진 row 는 건너뜁니다. V-1 안전: Facades\DB/Schema +
 * 코어 __() 헬퍼만 사용(모듈 Service/Repository/Enum 의존 없음).
 */
class BackfillDeliveryMemoLabel implements DataMigration
{
    private const TABLE = 'ecommerce_order_addresses';

    /** @var array<int, string> 프리셋 키 (DeliveryMemoPresetEnum 값과 일치) */
    private const PRESET_KEYS = ['door', 'security', 'parcel_box', 'call'];

    public function name(): string
    {
        return 'BackfillDeliveryMemoLabel';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)
            || ! Schema::hasColumn(self::TABLE, 'delivery_memo')
            || ! Schema::hasColumn(self::TABLE, 'delivery_memo_label')) {
            $context->logger->info('[ecommerce:1.0.0] delivery_memo_label 백필 — 대상 스키마 부재로 스킵');

            return;
        }

        $locale = config('app.locale', 'ko');
        $labels = $this->presetLabels($locale);
        $count = 0;

        DB::table(self::TABLE)
            ->whereNotNull('delivery_memo')
            ->where('delivery_memo', '<>', '')
            ->whereNull('delivery_memo_label')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($labels, &$count) {
                foreach ($rows as $row) {
                    $memo = $row->delivery_memo;
                    // 프리셋이면 라벨로, 자유 텍스트면 원문 보존
                    $label = $labels[$memo] ?? $memo;

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update(['delivery_memo_label' => $label]);
                    $count++;
                }
            });

        $context->logger->info("[ecommerce:1.0.0] delivery_memo_label 백필 완료: {$count} 건 (locale={$locale})");
    }

    /**
     * 프리셋 키 → 대상 로케일 라벨 맵을 반환합니다.
     *
     * @param  string  $locale  대상 로케일
     * @return array<string, string> 키-라벨 맵
     */
    private function presetLabels(string $locale): array
    {
        $map = [];
        foreach (self::PRESET_KEYS as $key) {
            $map[$key] = __('sirsoft-ecommerce::enums.delivery_memo_preset.'.$key, [], $locale);
        }

        return $map;
    }
}
