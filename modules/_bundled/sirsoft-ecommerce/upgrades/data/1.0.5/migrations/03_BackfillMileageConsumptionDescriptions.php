<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 마일리지 사용·차감 거래의 비어 있는 내용(description)을 채웁니다.
 *
 * 적립 거래는 생성 시 description 을 기록했지만 사용·차감 거래는 기록하지 않아,
 * 회원 마일리지 내역의 「내용」 칸이 음수 행에서만 공백이었습니다. 생성 경로는
 * 수정했으나 이미 쌓인 행은 그대로 비어 있으므로 여기서 채웁니다.
 *
 * 금액은 저장된 음수 amount 의 절대값을 쓰고, 문구는 활동 로그와 같은 다국어 키를 씁니다.
 *
 * idempotent: description 이 NULL 이거나 빈 문자열인 행만 갱신하므로 재실행해도 안전합니다.
 * V-1 안전: Facades\DB/Schema 만 사용하며 모델·서비스에 의존하지 않습니다.
 */
class BackfillMileageConsumptionDescriptions implements DataMigration
{
    private const TABLE = 'ecommerce_mileage_transactions';

    /** @var array<string, string> 거래 유형 → 다국어 키 */
    private const DESCRIPTION_KEYS = [
        'admin_deduct' => 'sirsoft-ecommerce::activity_log.description.mileage_admin_deduct',
        'order_use' => 'sirsoft-ecommerce::activity_log.description.mileage_use',
    ];

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillMileageConsumptionDescriptions';
    }

    /**
     * 비어 있는 사용·차감 거래 내용을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->info('[ecommerce:1.0.5] 마일리지 거래 테이블 부재 — 내용 백필 스킵');

            return;
        }

        $updated = 0;

        foreach (self::DESCRIPTION_KEYS as $type => $key) {
            DB::table(self::TABLE)
                ->where('type', $type)
                ->where(function ($query) {
                    $query->whereNull('description')->orWhere('description', '');
                })
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($key, &$updated) {
                    foreach ($rows as $row) {
                        DB::table(self::TABLE)
                            ->where('id', $row->id)
                            ->update([
                                'description' => __($key, ['amount' => abs((float) $row->amount)]),
                            ]);
                        $updated++;
                    }
                });
        }

        $context->logger->info("[ecommerce:1.0.5] 마일리지 사용·차감 내용 백필 완료: {$updated}건");
    }
}
