<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Support\ExtensionStoragePath;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_0_0\Migrations\MigrateNaverpayBrandButtonKey;
use Illuminate\Support\Facades\File;
use Plugins\Sirsoft\PayKginicis\Tests\PluginTestCase;
use Tests\Support\UpgradeFiles;

UpgradeFiles::requireOnce(dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations/MigrateNaverpayBrandButtonKey.php');

class MigrateNaverpayBrandButtonKeyTest extends PluginTestCase
{
    private string $settingsPath;

    private bool $hadOriginalSettings = false;

    private ?string $originalSettings = null;

    /**
     * 플러그인 설정 파일 경로를 잡고 기존 원본 내용을 백업한 뒤 설정 디렉터리를 마련한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = ExtensionStoragePath::plugin('sirsoft-pay_kginicis', 'settings').'/setting.json';
        $this->hadOriginalSettings = File::exists($this->settingsPath);
        $this->originalSettings = $this->hadOriginalSettings ? File::get($this->settingsPath) : null;

        File::ensureDirectoryExists(dirname($this->settingsPath));
    }

    /**
     * 백업해 둔 원본 플러그인 설정을 되돌리고, 원본이 없었으면 테스트가 만든 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->hadOriginalSettings && $this->originalSettings !== null) {
            File::put($this->settingsPath, $this->originalSettings);
        } else {
            File::delete($this->settingsPath);
        }

        parent::tearDown();
    }

    /**
     * 옛 네이버페이 전용 브랜드 버튼 키 값이 공통 키 easy_pay_show_brand_button 으로 옮겨지고 옛 키는 지워지며 다른 설정은 보존된다.
     *
     * @scenario case=legacy_key_only
     *
     * @effects legacy_naverpay_brand_button_moved_to_common_key
     *
     * @return void 반환값 없음
     */
    public function test_migrates_naverpay_brand_button_setting_to_common_brand_button_setting(): void
    {
        File::put($this->settingsPath, json_encode([
            'easy_pay_naverpay_brand_button' => false,
            'easy_pay_naverpay' => true,
        ], JSON_THROW_ON_ERROR));

        $this->runMigration();

        $settings = json_decode(File::get($this->settingsPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('easy_pay_naverpay_brand_button', $settings);
        $this->assertArrayHasKey('easy_pay_show_brand_button', $settings);
        $this->assertFalse($settings['easy_pay_show_brand_button']);
        $this->assertTrue($settings['easy_pay_naverpay']);
    }

    /**
     * 옛 키와 공통 키가 함께 있으면 공통 키의 기존 값을 유지하고 옛 키만 지운다.
     *
     * @scenario case=both_keys
     *
     * @effects existing_common_brand_button_value_preserved
     *
     * @return void 반환값 없음
     */
    public function test_preserves_existing_common_brand_button_setting_when_both_keys_exist(): void
    {
        File::put($this->settingsPath, json_encode([
            'easy_pay_naverpay_brand_button' => true,
            'easy_pay_show_brand_button' => false,
        ], JSON_THROW_ON_ERROR));

        $this->runMigration();

        $settings = json_decode(File::get($this->settingsPath), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayNotHasKey('easy_pay_naverpay_brand_button', $settings);
        $this->assertFalse($settings['easy_pay_show_brand_button']);
    }

    /**
     * 플러그인 설정 파일이 없으면 마이그레이션이 건너뛰고 새 파일을 만들지 않는다.
     *
     * @scenario case=settings_missing
     *
     * @effects missing_settings_file_skipped
     *
     * @return void 반환값 없음
     */
    public function test_skips_when_settings_file_is_missing(): void
    {
        File::delete($this->settingsPath);

        $this->runMigration();

        $this->assertFalse(File::exists($this->settingsPath));
    }

    /**
     * 1.0.0-beta.6 → 1.0.0 업그레이드 컨텍스트로 네이버페이 브랜드 버튼 키 이관 마이그레이션을 실행합니다.
     *
     * @return void 반환값 없음
     */
    private function runMigration(): void
    {
        (new MigrateNaverpayBrandButtonKey)->run(new UpgradeContext(
            fromVersion: '1.0.0-beta.6',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        ));
    }
}
