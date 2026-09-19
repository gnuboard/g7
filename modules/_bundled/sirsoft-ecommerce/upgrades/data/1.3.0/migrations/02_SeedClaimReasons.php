<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_3_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 교환/반품 사유 카탈로그를 시드합니다.
 *
 * 사유 목록은 그 버전에 동결한다 — 이후 버전에서 기본 사유가 바뀌더라도
 * 1.3.0 업그레이드를 밟는 사이트는 1.3.0 시점의 목록을 받는다(스텝별 동작 동일 보장).
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
     * 1.3.0 시점에 동결한 교환 사유 목록.
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
     * 1.3.0 시점에 동결한 반품 사유 목록.
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
            $context->logger->warning('[ecommerce:1.3.0] '.self::TABLE.' 테이블 미존재 — 스킵');

            return;
        }

        $translations = $this->loadLanguagePackTranslations($context);

        $created = 0;
        $created += $this->seedType('exchange', self::EXCHANGE_REASONS, $translations, $context);
        $created += $this->seedType('return', self::RETURN_REASONS, $translations, $context);

        $context->logger->info('[ecommerce:1.3.0] 교환/반품 사유 시드 완료 — 신규 '.$created.'건');
    }

    /**
     * 설치된 언어팩의 사유 시드 번역을 코드별로 모읍니다.
     *
     * 신규 설치는 `ClaimReasonSeeder` 가 `seed.*.translations` 필터로 언어팩 번역을 병합하지만,
     * 업그레이드 경로는 이 데이터 마이그레이션이 직접 삽입하므로 그 병합을 타지 않는다 — 그대로 두면
     * 업그레이드한 일본어 상점에서만 기본 사유 이름이 한국어로 남는다. V-1 규약(Illuminate 파사드만)
     * 에 맞춰 활성 언어팩 디렉토리의 `lang-packs/{pack}/seed/claim_reasons.json` 을 직접 읽는다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<string, array<string, string>> code → [locale → 번역 이름]
     */
    private function loadLanguagePackTranslations(UpgradeContext $context): array
    {
        $root = base_path('lang-packs');
        if (! File::isDirectory($root)) {
            return [];
        }

        $translations = [];

        foreach (File::directories($root) as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '_')) {
                continue; // _bundled / _pending 은 활성 팩이 아니다
            }

            // 매니페스트 없는 디렉토리(설치 중단 잔여·백업 폴더)는 팩이 아니다 — `File::get` 은 부재 시
            // 예외를 던지므로 먼저 확인한다. 안 그러면 폴더 하나가 이 시드 전체를 중단시킨다.
            if (! File::exists($dir.'/language-pack.json')) {
                continue;
            }

            $manifest = json_decode((string) File::get($dir.'/language-pack.json'), true);
            if (! is_array($manifest)
                || ($manifest['target_identifier'] ?? null) !== 'sirsoft-ecommerce'
                || empty($manifest['locale'])
                || ! File::exists($dir.'/seed/claim_reasons.json')) {
                continue;
            }

            $seed = json_decode((string) File::get($dir.'/seed/claim_reasons.json'), true);
            if (! is_array($seed)) {
                continue;
            }

            foreach ($seed as $code => $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null) && $entry['name'] !== '') {
                    $translations[$code][$manifest['locale']] = $entry['name'];
                }
            }

            $context->logger->info('[ecommerce:1.3.0] 언어팩 사유 번역 병합 — '.$name.' ('.$manifest['locale'].')');
        }

        return $translations;
    }

    /**
     * 한 유형의 사유 목록을 시드합니다.
     *
     * @param  string  $type  사유 유형 (exchange, return)
     * @param  array<int, array{code: string, ko: string, en: string, fault_type: string}>  $reasons  사유 목록
     * @param  array<string, array<string, string>>  $translations  언어팩 번역 (code → locale → 이름)
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return int 신규 생성 건수
     */
    private function seedType(string $type, array $reasons, array $translations, UpgradeContext $context): int
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
                    ['ko' => $reason['ko'], 'en' => $reason['en']] + ($translations[$reason['code']] ?? []),
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
                '[ecommerce:1.3.0] 사유 추가 — '.$type.'/'.$reason['code'].' ('.$reason['ko'].')'
            );
        }

        return $created;
    }
}
