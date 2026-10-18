<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 통화별 사용 언어(locales) 정합화 마이그레이션 테스트
 *
 * 시드 기본값이 사이트 지원 언어와 무관한 locale(zh, de/fr/es/it)을 박아두어 통화 설정
 * 저장이 검증(in:supported_locales)에서 영구 차단되던 결함을 정리한다. 유효값은 보존,
 * 무효값만 제거하고, 빈 통화에만 기본값(KRW→ko, 그 외→en)을 부여한다.
 */
class NormalizeCurrencyLocalesTest extends ModuleTestCase
{
    private const MIGRATION_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_0\\Migrations\\NormalizeCurrencyLocales';

    private string $settingsPath;

    private string $settingsFile;

    protected function setUp(): void
    {
        parent::setUp();
        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        $migDir = dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations';
        require_once $migDir.'/NormalizeCurrencyLocales.php';

        // 운영 storage 오염 방지: 테스트 환경 경로 (마이그레이션의 runningUnitTests 분기와 일치)
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        $this->settingsFile = $this->settingsPath.'/language_currency.json';

        // 사이트 지원 언어 고정 (테스트 격리)
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
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

    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->settingsPath);
        File::put($this->settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function readSettings(): array
    {
        return json_decode((string) File::get($this->settingsFile), true);
    }

    public function test_unsupported_locales_removed_and_defaults_applied(): void
    {
        // 현 워크스페이스 결함 재현: CNY=zh, EUR=de/fr/es/it (지원 언어 ko/en/ja 외)
        $this->writeSettings([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'locales' => ['ko']],
                ['code' => 'USD', 'locales' => ['en']],
                ['code' => 'JPY', 'locales' => ['ja']],
                ['code' => 'CNY', 'locales' => ['zh']],
                ['code' => 'EUR', 'locales' => ['de', 'fr', 'es', 'it']],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');

        // 유효값은 보존
        $this->assertSame(['ko'], $byCode['KRW']['locales']);
        $this->assertSame(['en'], $byCode['USD']['locales']);
        $this->assertSame(['ja'], $byCode['JPY']['locales']);
        // 무효값 제거 → 빈 배열 → 기본값(KRW 외 = en)
        $this->assertSame(['en'], $byCode['CNY']['locales']);
        $this->assertSame(['en'], $byCode['EUR']['locales']);
    }

    public function test_krw_empty_falls_back_to_ko(): void
    {
        // KRW 의 유일 locale 이 무효면 ko 로 폴백
        $this->writeSettings([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'locales' => ['zh']],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        $this->assertSame(['ko'], $byCode['KRW']['locales']);
    }

    public function test_mixed_valid_and_invalid_keeps_only_valid(): void
    {
        // 유효 + 무효 혼재 시 유효값만 남기고 폴백하지 않음
        $this->writeSettings([
            'default_currency' => 'USD',
            'currencies' => [
                ['code' => 'USD', 'locales' => ['en', 'de', 'ja', 'zh']],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        $this->assertSame(['en', 'ja'], $byCode['USD']['locales']);
    }

    public function test_ja_removed_when_not_supported(): void
    {
        // ja 언어팩 미설치 환경(지원=ko/en) → JPY=ja 제거 후 빈배열 → en 폴백
        config(['app.supported_locales' => ['ko', 'en']]);

        $this->writeSettings([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'JPY', 'locales' => ['ja']],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        $this->assertSame(['en'], $byCode['JPY']['locales']);
    }

    public function test_is_idempotent(): void
    {
        // 이미 정합한 데이터는 두 번 실행해도 불변
        $this->writeSettings([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'locales' => ['ko']],
                ['code' => 'CNY', 'locales' => ['en']],
            ],
        ]);

        ($this->migration())->run($this->context());
        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        $this->assertSame(['ko'], $byCode['KRW']['locales']);
        $this->assertSame(['en'], $byCode['CNY']['locales']);
    }

    public function test_missing_file_is_noop(): void
    {
        // 파일 미존재 시 예외 없이 스킵
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        ($this->migration())->run($this->context());

        $this->assertFalse(File::exists($this->settingsFile));
    }

    public function test_duplicate_locales_deduplicated(): void
    {
        $this->writeSettings([
            'default_currency' => 'USD',
            'currencies' => [
                ['code' => 'USD', 'locales' => ['en', 'en', 'ja']],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = collect($this->readSettings()['currencies'])->keyBy('code');
        $this->assertSame(['en', 'ja'], $byCode['USD']['locales']);
    }
}
