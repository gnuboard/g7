<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 통화 통일 백필 마이그레이션 테스트
 *
 * 기본 통화와 다른 통화로 저장된 배송정책 국가별 설정을 기본 통화로 통일하는지 검증한다.
 */
class BackfillShippingPolicyCurrencyTest extends ModuleTestCase
{
    private const MIGRATION_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\BackfillShippingPolicyCurrency';

    private string $settingsPath;

    private string $settingsFile;

    protected function setUp(): void
    {
        parent::setUp();

        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        $migDir = dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations';
        require_once $migDir.'/BackfillShippingPolicyCurrency.php';

        // 운영 storage 오염 방지: 테스트 환경 경로 사용 (마이그레이션의 runningUnitTests 분기와 일치)
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        $this->settingsFile = $this->settingsPath.'/language_currency.json';
    }

    protected function tearDown(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }
        parent::tearDown();
    }

    private function migration(): object
    {
        $class = self::MIGRATION_NS;

        return new $class;
    }

    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.0.0-beta.4',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );
    }

    private function writeDefaultCurrency(string $code): void
    {
        File::ensureDirectoryExists($this->settingsPath);
        File::put($this->settingsFile, json_encode(['default_currency' => $code], JSON_PRETTY_PRINT));
    }

    private function makeCountrySetting(string $currency, string $countryCode = 'KR'): ShippingPolicyCountrySetting
    {
        $policy = ShippingPolicy::create([
            'name' => ['ko' => '정책', 'en' => 'Policy'],
            'is_active' => true,
        ]);

        return $policy->countrySettings()->create([
            'country_code' => $countryCode,
            'shipping_method' => 'parcel',
            'currency_code' => $currency,
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'is_active' => true,
        ]);
    }

    public function test_backfills_non_default_currency_to_default(): void
    {
        // Given: 기본 통화 KRW + USD/EUR 로 저장된 배송정책 설정
        $this->writeDefaultCurrency('KRW');
        $usd = $this->makeCountrySetting('USD', 'US');
        $eur = $this->makeCountrySetting('EUR', 'DE');
        $krw = $this->makeCountrySetting('KRW', 'KR');

        // When: 백필 실행
        $this->migration()->run($this->context());

        // Then: 모든 설정 통화가 기본 통화 KRW 로 통일
        $this->assertSame('KRW', $usd->fresh()->currency_code);
        $this->assertSame('KRW', $eur->fresh()->currency_code);
        $this->assertSame('KRW', $krw->fresh()->currency_code);
    }

    public function test_backfills_to_non_krw_default_currency(): void
    {
        // Given: 기본 통화 USD + KRW 로 저장된 설정
        $this->writeDefaultCurrency('USD');
        $krw = $this->makeCountrySetting('KRW', 'KR');

        // When: 백필 실행
        $this->migration()->run($this->context());

        // Then: 기본 통화 USD 로 통일
        $this->assertSame('USD', $krw->fresh()->currency_code);
    }

    public function test_is_idempotent_when_all_already_default(): void
    {
        // Given: 모든 설정이 이미 기본 통화
        $this->writeDefaultCurrency('KRW');
        $krw = $this->makeCountrySetting('KRW', 'KR');

        // When: 두 번 실행해도 안전
        $this->migration()->run($this->context());
        $this->migration()->run($this->context());

        // Then: 통화 불변
        $this->assertSame('KRW', $krw->fresh()->currency_code);
    }
}
