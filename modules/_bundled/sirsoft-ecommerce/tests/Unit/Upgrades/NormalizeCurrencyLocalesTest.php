<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use Tests\Support\UpgradeFiles;

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

    /**
     * 통화 locales 정합화 마이그레이션을 불러오고 설정 경로와 사이트 지원 언어(ko·en·ja)를 고정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        $migDir = dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations';
        UpgradeFiles::requireOnce($migDir.'/NormalizeCurrencyLocales.php');

        // 운영 storage 오염 방지: 테스트 환경 경로 (마이그레이션의 runningUnitTests 분기와 일치)
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        $this->settingsFile = $this->settingsPath.'/language_currency.json';

        // 사이트 지원 언어 고정 (테스트 격리)
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);
    }

    /**
     * 테스트가 기록한 통화 설정 파일을 지워 다음 테스트에 새지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }
        parent::tearDown();
    }

    /**
     * 검증 대상 통화 locales 정합화 마이그레이션 인스턴스를 새로 만든다.
     *
     * @return object NormalizeCurrencyLocales 인스턴스
     */
    private function migration(): object
    {
        $class = self::MIGRATION_NS;

        return new $class;
    }

    /**
     * 1.0.0-beta.4 → 1.0.0 업그레이드 단계를 나타내는 컨텍스트를 만든다.
     *
     * @return UpgradeContext 1.0.0 단계 업그레이드 컨텍스트
     */
    private function context(): UpgradeContext
    {
        return new UpgradeContext(
            fromVersion: '1.0.0-beta.4',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        );
    }

    /**
     * 통화 설정 파일에 주어진 설정 배열을 JSON 으로 기록한다.
     *
     * @param  array  $settings  기록할 language_currency 설정
     * @return void 반환값 없음
     */
    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->settingsPath);
        File::put($this->settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 마이그레이션 후 통화 설정 파일 내용을 배열로 읽는다.
     *
     * @return array 저장된 language_currency 설정
     */
    private function readSettings(): array
    {
        return json_decode((string) File::get($this->settingsFile), true);
    }

    /**
     * 지원 언어 밖 locale(zh·de 등)은 지우고 유효값은 보존하며, 비게 된 통화에는 KRW 외 기본값 en 을 준다.
     *
     * @scenario case=unsupported_removed
     *
     * @effects unsupported_locales_removed, valid_locales_preserved, empty_non_krw_falls_back_to_en
     *
     * @return void 반환값 없음
     */
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

    /**
     * KRW 의 locale 이 모두 무효라 비게 되면 기본값 ko 로 채워진다.
     *
     * @scenario case=krw_fallback
     *
     * @effects empty_krw_falls_back_to_ko
     *
     * @return void 반환값 없음
     */
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

    /**
     * 유효·무효 locale 이 섞이면 유효값만 순서대로 남기고 기본값으로 폴백하지 않는다.
     *
     * @scenario case=mixed_valid_invalid
     *
     * @effects unsupported_locales_removed, valid_locales_preserved
     *
     * @return void 반환값 없음
     */
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

    /**
     * ja 언어팩이 없는 사이트에서는 JPY 의 ja 가 지워지고 en 으로 폴백된다.
     *
     * @scenario case=site_locale_missing
     *
     * @effects unsupported_locales_removed, empty_non_krw_falls_back_to_en
     *
     * @return void 반환값 없음
     */
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

    /**
     * 이미 정합한 설정은 두 번 실행해도 locales 가 바뀌지 않는다.
     *
     * @scenario case=rerun
     *
     * @effects normalization_idempotent
     *
     * @return void 반환값 없음
     */
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

    /**
     * 설정 파일이 없으면 예외 없이 건너뛰고 파일을 새로 만들지 않는다.
     *
     * @scenario case=missing_file
     *
     * @effects missing_settings_file_skipped
     *
     * @return void 반환값 없음
     */
    public function test_missing_file_is_noop(): void
    {
        // 파일 미존재 시 예외 없이 스킵
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        ($this->migration())->run($this->context());

        $this->assertFalse(File::exists($this->settingsFile));
    }

    /**
     * 중복된 locale 은 하나로 합쳐 첫 등장 순서를 유지한다.
     *
     * @scenario case=duplicates
     *
     * @effects duplicate_locales_deduplicated
     *
     * @return void 반환값 없음
     */
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
