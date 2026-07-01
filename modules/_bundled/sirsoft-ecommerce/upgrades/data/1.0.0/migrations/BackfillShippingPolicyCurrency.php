<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 배송정책 국가별 설정의 통화를 상점 기본 통화로 통일.
 *
 * 배송정책 통화는 관리자가 자유 지정할 수 있었으나(country_settings.currency_code),
 * 배송비는 기본 통화 정수로 합산되므로 정책마다 다른 통화를 허용하면 합계 단위가 섞여
 * 정합성이 깨진다. 상품 등록과 동일하게 통화를 상점 기본 통화(language_currency.default_currency)로
 * 고정하도록 전환했고(ShippingPolicyService::forceDefaultCurrency), 기존 데이터 중 기본 통화와
 * 다른 통화로 저장된 행을 기본 통화로 통일한다.
 *
 * idempotent: 모든 행이 이미 기본 통화이면 no-op. V-1 안전: Facades\DB/Schema/File 만 사용.
 */
class BackfillShippingPolicyCurrency implements DataMigration
{
    private const TABLE = 'ecommerce_shipping_policy_country_settings';

    private const COLUMN = 'currency_code';

    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    public function name(): string
    {
        return 'BackfillShippingPolicyCurrency';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            $context->logger->info('[ecommerce:1.0.0] 배송정책 통화 컬럼 없음 — 통화 통일 스킵');

            return;
        }

        $defaultCurrency = $this->defaultCurrency();

        $affected = DB::table(self::TABLE)
            ->where(self::COLUMN, '!=', $defaultCurrency)
            ->update([self::COLUMN => $defaultCurrency]);

        $context->logger->info(sprintf(
            '[ecommerce:1.0.0] 배송정책 통화 통일 완료 — 기본=%s, %d건 갱신',
            $defaultCurrency,
            $affected
        ));
    }

    /**
     * 상점 기본 통화 코드를 반환합니다.
     *
     * 테스트 환경에서는 운영 storage 오염을 막기 위해 framework/testing 경로를 사용합니다
     * (EcommerceSettingsService 의 저장 경로 분기와 동일).
     *
     * @return string 기본 통화 코드 (미설정 시 KRW)
     */
    private function defaultCurrency(): string
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            return 'KRW';
        }

        $settings = json_decode((string) File::get($path), true);

        return is_array($settings) ? ($settings['default_currency'] ?? 'KRW') : 'KRW';
    }

    /**
     * language_currency.json 의 저장 경로를 반환합니다.
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        $base = app()->runningUnitTests()
            ? 'framework/testing/modules/'
            : 'app/modules/';

        return storage_path($base.self::MODULE_IDENTIFIER.'/settings/language_currency.json');
    }
}
