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
 * ## 운영자·시스템 발 환불 사유 2종도 여기서 보장한다
 *
 * 취소·환불이 클레임 접수 한 입구로 모이면서 사유 코드가 실행 경로의 전제가 됐다 —
 * `OrderRefundRequestService` 는 코드를 못 찾으면 접수 트랜잭션 전체를 되돌린다. 사람이 고르지
 * 않는 두 코드(`admin_cancel` · `auto_cancel_expired`)는 코드가 직접 지목하므로, 이 행이 없는
 * 기존 설치본은 만료 주문 자동취소가 전량 실패하고 가상계좌 환불은 PG 로 돈이 나간 뒤 장부만
 * 롤백된다. 시더(`ClaimReasonSeeder`)는 설치 때만 돌아 업그레이드 경로에서는 이 스텝이 유일한
 * 공급원이다.
 *
 * 예전에는 이 보장을 모듈 마이그레이션(`2026_09_12_000001`)이 했다. 마이그레이션은 언어팩을
 * 읽지 않아 일본어 상점에서 두 사유만 한국어로 남았고, 이름도 시더와 달라(「결제 기한 만료」 대
 * 「입금 기한 만료 자동취소」) 신규 설치와 업그레이드의 결과가 갈렸다. 그래서 데이터 보장은 이
 * 스텝으로 옮기고 값은 시더와 같게 동결했다.
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
     * 1.3.0 시점에 동결한 환불 사유 목록 — 운영자·시스템이 코드로 지목하는 2종만.
     *
     * 고객이 고르는 환불 사유(주문 실수 등)는 1.3.0 이전부터 있었으므로 여기서 다시 넣지 않는다.
     * 값은 `ClaimReasonSeeder::getDefaults()` 의 같은 코드와 같다 — 달라지면 신규 설치와
     * 업그레이드한 상점의 사유 이름이 갈린다.
     *
     * @var array<int, array{code: string, ko: string, en: string, fault_type: string, is_user_selectable: bool, sort_order: int}>
     */
    private const REFUND_REASONS = [
        ['code' => 'admin_cancel', 'ko' => '관리자 취소', 'en' => 'Admin Cancel', 'fault_type' => 'seller', 'is_user_selectable' => false, 'sort_order' => 5],
        ['code' => 'auto_cancel_expired', 'ko' => '입금 기한 만료 자동취소', 'en' => 'Auto-cancelled (payment deadline expired)', 'fault_type' => 'customer', 'is_user_selectable' => false, 'sort_order' => 6],
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

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'SeedClaimReasons';
    }

    /**
     * 교환·반품 사유와 운영자·시스템 발 환불 사유를 시드합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
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
        $created += $this->seedType('refund', self::REFUND_REASONS, $translations, $context);

        $context->logger->info('[ecommerce:1.3.0] 교환/반품/운영자 환불 사유 시드 완료 — 신규 '.$created.'건');
    }

    /**
     * 설치된 언어팩의 사유 시드 번역을 코드별로 모읍니다.
     *
     * 신규 설치는 `ClaimReasonSeeder` 가 `seed.*.translations` 필터로 언어팩 번역을 병합하지만,
     * 업그레이드 경로는 이 데이터 마이그레이션이 직접 삽입하므로 그 병합을 타지 않는다 — 그대로 두면
     * 업그레이드한 일본어 상점에서만 기본 사유 이름이 한국어로 남는다. V-1 규약(Illuminate 파사드만)
     * 에 맞춰 활성 언어팩 디렉토리의 `lang-packs/{pack}/seed/claim_reasons.json` 을 직접 읽는다.
     *
     * 번들 일괄 업데이트는 모듈을 먼저, 언어팩을 나중에 갱신한다. 그래서 이 스텝이 도는 시점의 활성 팩은 옛 판이라
     * 1.3.0 에서 새로 생긴 사유 코드의 번역이 없다 — 활성 팩 seed 에 없는 코드는 같은 이름의 번들 팩
     * (`lang-packs/_bundled/{pack}/seed/claim_reasons.json`)에서 메운다. 활성 팩에 있는 코드는 활성 팩 값을 쓴다(7.1.0 출시 점검 EU-5).
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return array<string, array<string, string>> code → [locale → 번역 이름]
     */
    private function loadLanguagePackTranslations(UpgradeContext $context): array
    {
        $root = $this->languagePackRoot();
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
                || empty($manifest['locale'])) {
                continue;
            }

            $seed = $this->readSeed($dir.'/seed/claim_reasons.json');
            foreach ($this->readSeed($root.'/_bundled/'.$name.'/seed/claim_reasons.json') as $code => $entry) {
                if (! array_key_exists($code, $seed)) {
                    $seed[$code] = $entry;
                }
            }

            if ($seed === []) {
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
     * 사유 시드 번역 파일을 읽습니다.
     *
     * @param  string  $path  `lang-packs/{pack}/seed/claim_reasons.json` 경로(번들 원본은 `lang-packs/_bundled/{pack}/seed/claim_reasons.json`)
     * @return array<string, mixed> 코드 → 항목 (파일이 없거나 형식이 틀리면 빈 배열)
     */
    private function readSeed(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $seed = json_decode((string) File::get($path), true);

        return is_array($seed) ? $seed : [];
    }

    /**
     * 언어팩 루트 디렉토리를 반환합니다.
     *
     * @return string 언어팩 루트 절대 경로
     */
    protected function languagePackRoot(): string
    {
        return base_path('lang-packs');
    }

    /**
     * 한 유형의 사유 목록을 시드합니다.
     *
     * 항목에 `is_user_selectable`·`sort_order` 가 있으면 그 값을 쓰고, 없으면 고객 선택 가능·목록
     * 순서로 넣는다(교환·반품 사유의 종전 동작).
     *
     * @param  string  $type  사유 유형 (exchange, return, refund)
     * @param  array<int, array{code: string, ko: string, en: string, fault_type: string, is_user_selectable?: bool, sort_order?: int}>  $reasons  사유 목록
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
                'is_user_selectable' => $reason['is_user_selectable'] ?? true,
                'is_active' => true,
                'sort_order' => $reason['sort_order'] ?? $index,
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
