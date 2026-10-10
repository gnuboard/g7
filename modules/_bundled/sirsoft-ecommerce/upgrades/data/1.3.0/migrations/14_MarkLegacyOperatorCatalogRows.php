<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1.2.1 이전에 운영자가 만든 택배사·배송유형·클레임 사유에 생성자 표식(created_by)을 채웁니다.
 *
 * 1.3.0 시더(ShippingCarrierSeeder·ShippingTypeSeeder·ClaimReasonSeeder)는 시드 목록에서 빠진 미사용 행을
 * 정리하되, 운영자가 만든 행은 남기려고 범위를 `created_by IS NULL`(시더가 만든 행)로 좁혔다(#727 W7).
 * 그런데 1.2.1 의 관리자 화면은 created_by 를 채우지 않았다 — 1.2.1 시절 운영자가 만든 행도 NULL 이라, 업그레이드 뒤
 * 언어팩 활성화·재시드 때 쓰이지 않은 운영자 행이 시드 잔재로 보여 조용히 지워진다(7.1.0 출시 점검 EU-2).
 *
 * 그래서 created_by 가 비어 있으면서 **역대 시더가 넣은 적 없는 코드**인 행을 운영자 행으로 보고, 생성자를 사이트
 * 최고관리자(is_super, 가장 작은 id)로 채운다. 이 세 목록 화면은 생성자를 표시하지 않는다(정리 범위 판정에만 쓴다).
 * 시더 코드인 행은 그대로 둔다 — 시드 목록에서 빠진 옛 시드 행은 계속 정리 대상이다.
 *
 * 코드 목록은 출시본 7.0.0-beta.7 ~ 7.0.11(이커머스 ~1.2.1)과 1.3.0 시더가 넣은 코드의 합집합을 동결한 사본이다.
 *
 * idempotent: 채운 행은 created_by 가 생겨 두 번째 실행 대상에서 빠진다. 최고관리자가 없으면 아무것도 바꾸지 않고 경고한다.
 * V-1 안전: Facades 만 사용.
 *
 * @upgrade-path A
 */
class MarkLegacyOperatorCatalogRows implements DataMigration
{
    /**
     * 테이블별 역대 시더 코드(동결 사본)
     *
     * @var array<string, list<string>>
     */
    private const SEEDED_CODES = [
        'ecommerce_shipping_carriers' => [
            'cj', 'dhl', 'ems', 'fedex', 'hanjin', 'logen', 'lotte', 'other', 'sagawa', 'sf', 'ups', 'yamato',
        ],
        'ecommerce_shipping_types' => [
            'custom', 'cvs', 'digital', 'direct', 'express', 'freight', 'international_ems', 'international_standard',
            'parcel', 'pickup', 'quick',
        ],
        'ecommerce_claim_reasons' => [
            'admin_cancel', 'auto_cancel_expired', 'changed_mind', 'color_mismatch', 'damaged_in_transit',
            'defective_product', 'delayed_delivery', 'etc', 'order_mistake', 'product_info_different', 'reorder_other',
            'size_mismatch', 'wrong_option_selected', 'wrong_product_delivered',
        ],
    ];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'MarkLegacyOperatorCatalogRows';
    }

    /**
     * 세 카탈로그 테이블의 1.2.1 운영자 행에 생성자를 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'is_super')) {
            $context->logger->warning('[ecommerce:1.3.0] users.is_super 부재 — 운영자 카탈로그 행 표식 스킵');

            return;
        }

        $ownerId = DB::table('users')->where('is_super', true)->orderBy('id')->value('id');
        if ($ownerId === null) {
            $context->logger->warning('[ecommerce:1.3.0] 최고관리자가 없어 1.2.1 운영자 택배사·배송유형·클레임 사유에 생성자를 채우지 못했습니다 — 재시드 때 쓰이지 않은 운영자 행이 지워질 수 있습니다');

            return;
        }

        foreach (self::SEEDED_CODES as $table => $codes) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'created_by')) {
                $context->logger->info("[ecommerce:1.3.0] {$table}.created_by 부재 — 운영자 행 표식 스킵");

                continue;
            }

            $marked = DB::table($table)
                ->whereNull('created_by')
                ->whereNotIn('code', $codes)
                ->update(['created_by' => $ownerId]);

            $context->logger->info("[ecommerce:1.3.0] {$table} 1.2.1 운영자 행 생성자 표식: {$marked}행 (최고관리자 #{$ownerId})");
        }
    }
}
