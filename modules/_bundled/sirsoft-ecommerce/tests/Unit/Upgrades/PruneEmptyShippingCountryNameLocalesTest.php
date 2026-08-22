<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송가능 국가명의 빈 로케일 키 청소 마이그레이션 테스트 (#459)
 *
 * 구 국가 추가 폼은 한국어/영문 두 입력칸을 항상 렌더했고, 운영자가 한쪽을 비운 채 저장하면
 * `{"ko":"프랑스","en":""}` 처럼 빈 문자열이 저장본에 박혔다. 빈 문자열은 "값이 있음" 도
 * "부재" 도 아닌 어중간한 상태라, 저장본을 읽는 쪽마다 처리가 갈린다.
 *
 * 부재 로케일은 비워 두는 것이 이 시스템의 계약이다 — 기본 10개국은 언어팩
 * (settings.countries.{code}.name)이 읽기 시점에 보강하고, 운영자가 직접 추가한 국가는
 * 운영자가 채운다. 따라서 빈 문자열 키는 제거해 "부재" 로 정규화한다.
 *
 * 이름을 새로 채우지는 않는다 — 청소만 한다.
 */
class PruneEmptyShippingCountryNameLocalesTest extends ModuleTestCase
{
    private const MIGRATION_NS = 'App\\Upgrades\\Data\\Ext\\Modules\\SirsoftEcommerce\\V1_0_2\\Migrations\\PruneEmptyShippingCountryNameLocales';

    private string $settingsPath;

    private string $settingsFile;

    protected function setUp(): void
    {
        parent::setUp();
        // DataMigration 클래스는 오토로드 밖 — 직접 로드
        $migDir = dirname(__DIR__, 3).'/upgrades/data/1.0.2/migrations';
        require_once $migDir.'/PruneEmptyShippingCountryNameLocales.php';

        // 운영 storage 오염 방지: 테스트 환경 경로 (마이그레이션의 runningUnitTests 분기와 일치)
        $this->settingsPath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');
        $this->settingsFile = $this->settingsPath.'/shipping.json';
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
            fromVersion: '1.0.1',
            toVersion: '1.0.2',
            currentStep: '1.0.2',
        );
    }

    private function writeSettings(array $settings): void
    {
        File::ensureDirectoryExists($this->settingsPath);
        File::put($this->settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function readCountries(): array
    {
        $settings = json_decode((string) File::get($this->settingsFile), true);

        return collect($settings['available_countries'])->keyBy('code')->all();
    }

    public function test_empty_string_locale_keys_are_removed(): void
    {
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea'], 'is_active' => true],
                ['code' => 'FR', 'name' => ['ko' => '프랑스', 'en' => ''], 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());

        $byCode = $this->readCountries();
        // 채워진 값은 그대로
        $this->assertSame(['ko' => '대한민국', 'en' => 'South Korea'], $byCode['KR']['name']);
        // 빈 문자열 키만 제거 — 부재로 정규화
        $this->assertSame(['ko' => '프랑스'], $byCode['FR']['name']);
    }

    public function test_whitespace_only_locale_keys_are_removed(): void
    {
        // 공백만 입력한 경우도 빈 값으로 취급
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'DE', 'name' => ['ko' => '독일', 'en' => '   ', 'ja' => "\t"], 'is_active' => false],
            ],
        ]);

        ($this->migration())->run($this->context());

        $this->assertSame(['ko' => '독일'], $this->readCountries()['DE']['name']);
    }

    public function test_surviving_values_are_trimmed(): void
    {
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'IT', 'name' => ['ko' => '  이탈리아  ', 'en' => ' Italy '], 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());

        $this->assertSame(['ko' => '이탈리아', 'en' => 'Italy'], $this->readCountries()['IT']['name']);
    }

    public function test_no_locale_names_leaves_empty_object_not_null(): void
    {
        // 모든 로케일이 빈 값이면 name 은 빈 객체가 된다(키 자체는 유지) — 백엔드 검증이
        // `name` 을 array 로 요구하므로 null 로 만들면 저장이 깨진다.
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'XX', 'name' => ['ko' => '', 'en' => ''], 'is_active' => false],
            ],
        ]);

        ($this->migration())->run($this->context());

        $this->assertSame([], $this->readCountries()['XX']['name']);
    }

    public function test_country_names_are_never_filled_in(): void
    {
        // 이름을 새로 채우지 않는다. 부재 로케일은 언어팩이 읽기 시점에 보강한다.
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());

        // ja/en 이 추가되지 않아야 한다
        $this->assertSame(['ko' => '대한민국'], $this->readCountries()['KR']['name']);
    }

    public function test_other_country_fields_are_preserved(): void
    {
        $this->writeSettings([
            'default_country' => 'KR',
            'international_shipping_enabled' => true,
            'available_countries' => [
                ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => ''], 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());

        $settings = json_decode((string) File::get($this->settingsFile), true);
        $this->assertTrue($settings['international_shipping_enabled']);
        $this->assertSame('KR', $settings['default_country']);
        $this->assertTrue($settings['available_countries'][0]['is_active']);
        $this->assertSame('KR', $settings['available_countries'][0]['code']);
    }

    public function test_is_idempotent(): void
    {
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'KR', 'name' => ['ko' => '대한민국'], 'is_active' => true],
                ['code' => 'FR', 'name' => ['ko' => '프랑스', 'en' => ''], 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());
        $first = $this->readCountries();

        ($this->migration())->run($this->context());
        $second = $this->readCountries();

        $this->assertSame($first, $second);
        $this->assertSame(['ko' => '프랑스'], $second['FR']['name']);
    }

    public function test_missing_file_is_noop(): void
    {
        if (File::exists($this->settingsFile)) {
            File::delete($this->settingsFile);
        }

        ($this->migration())->run($this->context());

        $this->assertFalse(File::exists($this->settingsFile));
    }

    public function test_missing_available_countries_is_noop(): void
    {
        $this->writeSettings(['default_country' => 'KR']);

        ($this->migration())->run($this->context());

        $settings = json_decode((string) File::get($this->settingsFile), true);
        $this->assertSame('KR', $settings['default_country']);
        $this->assertArrayNotHasKey('available_countries', $settings);
    }

    public function test_non_array_name_is_left_untouched(): void
    {
        // 구 스키마 잔재(문자열 name)는 이 마이그레이션의 책임이 아니다 — 건드리지 않는다.
        $this->writeSettings([
            'default_country' => 'KR',
            'available_countries' => [
                ['code' => 'KR', 'name' => '대한민국', 'is_active' => true],
            ],
        ]);

        ($this->migration())->run($this->context());

        $this->assertSame('대한민국', $this->readCountries()['KR']['name']);
    }
}
