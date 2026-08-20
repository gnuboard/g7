<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_2_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 교환/반품 사유 카탈로그를 시드합니다.
 *
 * 사유 목록은 그 버전에 동결한다 — 이후 버전에서 기본 사유가 바뀌더라도
 * 1.2.0 업그레이드를 밟는 사이트는 1.2.0 시점의 목록을 받는다(스텝별 동작 동일 보장).
 *
 * 이미 존재하는 (type, code) 는 건드리지 않는다. 운영자가 이름·정렬·활성 여부를
 * 고쳤을 수 있고, 업그레이드가 그것을 되돌리면 안 된다. 신규 코드만 추가한다.
 * 삭제(stale cleanup)도 하지 않는다 — 운영자가 등록한 사유를 지울 근거가 없다.
 *
 * idempotent: 존재 여부를 먼저 확인하므로 재실행해도 추가 삽입이 없다.
 * V-1 안전: Illuminate\Support\Facades\* 만 사용 — 모듈 Enum/Model/Seeder 를 참조하지 않는다.
 */
class SeedClaimReasons implements DataMigration
{
    private const TABLE = 'ecommerce_claim_reasons';

    /**
     * 1.2.0 시점에 동결한 교환 사유 목록.
     *
     * @var array<int, array{code: string, ko: string, en: string, fault_type: string}>
     */
    private const EXCHANGE_REASONS = [
        ['code' => 'size_mismatch', 'ko' => '사이즈가 맞지 않음', 'en' => 'Size Does Not Fit', 'fault_type' => 'customer'],
        ['code' => 'color_mismatch', 'ko' => '색상/디자인이 마음에 들지 않음', 'en' => 'Color or Design Not Satisfying', 'fault_type' => 'customer'],
        ['code' => 'wrong_option_selected', 'ko' => '옵션을 잘못 선택함', 'en' => 'Wrong Option Selected', 'fault_type' => 'customer'],
        ['code' => 'defective_product', 'ko' => '상품 불량/파손', 'en' => 'Defective or Damaged Product', 'fault_type' => 'seller'],
        ['code' => 'wrong_product_delivered', 'ko' => '다른 상품이 배송됨', 'en' => 'Wrong Product Delivered', 'fault_type' => 'seller'],
        ['code' => 'product_info_different', 'ko' => '상품 정보 상이', 'en' => 'Product Info Different', 'fault_type' => 'seller'],
        ['code' => 'damaged_in_transit', 'ko' => '배송 중 파손', 'en' => 'Damaged in Transit', 'fault_type' => 'carrier'],
        ['code' => 'etc', 'ko' => '기타', 'en' => 'Etc', 'fault_type' => 'customer'],
    ];

    /**
     * 1.2.0 시점에 동결한 반품 사유 목록.
     *
     * @var array<int, array{code: string, ko: string, en: string, fault_type: string}>
     */
    private const RETURN_REASONS = [
        ['code' => 'changed_mind', 'ko' => '단순 변심', 'en' => 'Changed Mind', 'fault_type' => 'customer'],
        ['code' => 'size_mismatch', 'ko' => '사이즈가 맞지 않음', 'en' => 'Size Does Not Fit', 'fault_type' => 'customer'],
        ['code' => 'wrong_option_selected', 'ko' => '옵션을 잘못 선택함', 'en' => 'Wrong Option Selected', 'fault_type' => 'customer'],
        ['code' => 'defective_product', 'ko' => '상품 불량/파손', 'en' => 'Defective or Damaged Product', 'fault_type' => 'seller'],
        ['code' => 'wrong_product_delivered', 'ko' => '다른 상품이 배송됨', 'en' => 'Wrong Product Delivered', 'fault_type' => 'seller'],
        ['code' => 'product_info_different', 'ko' => '상품 정보 상이', 'en' => 'Product Info Different', 'fault_type' => 'seller'],
        ['code' => 'delayed_delivery', 'ko' => '배송 지연', 'en' => 'Delayed Delivery', 'fault_type' => 'seller'],
        ['code' => 'damaged_in_transit', 'ko' => '배송 중 파손', 'en' => 'Damaged in Transit', 'fault_type' => 'carrier'],
        ['code' => 'etc', 'ko' => '기타', 'en' => 'Etc', 'fault_type' => 'customer'],
    ];

    public function name(): string
    {
        return 'SeedClaimReasons';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $context->logger->warning('[ecommerce:1.2.0] '.self::TABLE.' 테이블 미존재 — 스킵');

            return;
        }

        $created = 0;
        $created += $this->seedType('exchange', self::EXCHANGE_REASONS, $context);
        $created += $this->seedType('return', self::RETURN_REASONS, $context);

        $context->logger->info('[ecommerce:1.2.0] 교환/반품 사유 시드 완료 — 신규 '.$created.'건');
    }

    /**
     * 한 유형의 사유 목록을 시드합니다.
     *
     * @param  string  $type  사유 유형 (exchange, return)
     * @param  array<int, array{code: string, ko: string, en: string, fault_type: string}>  $reasons  사유 목록
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 신규 생성 건수
     */
    private function seedType(string $type, array $reasons, UpgradeContext $context): int
    {
        $now = now();
        $created = 0;

        foreach ($reasons as $index => $reason) {
            $exists = DB::table(self::TABLE)
                ->where('type', $type)
                ->where('code', $reason['code'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table(self::TABLE)->insert([
                'type' => $type,
                'code' => $reason['code'],
                'name' => json_encode(
                    ['ko' => $reason['ko'], 'en' => $reason['en']],
                    JSON_UNESCAPED_UNICODE
                ),
                'fault_type' => $reason['fault_type'],
                'is_user_selectable' => true,
                'is_active' => true,
                'sort_order' => $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created++;
            $context->logger->info(
                '[ecommerce:1.2.0] 사유 추가 — '.$type.'/'.$reason['code'].' ('.$reason['ko'].')'
            );
        }

        return $created;
    }
}
