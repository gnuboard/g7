<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use App\Upgrades\Data\Ext\Plugins\SirsoftPayKginicis\V1_0_0\Migrations\MigrateNaverpayBrandButtonKey;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/upgrades/data/1.0.0/migrations/MigrateNaverpayBrandButtonKey.php';

class MigrateNaverpayBrandButtonKeyTest extends TestCase
{
    private string $settingsPath;

    private bool $hadOriginalSettings = false;

    private ?string $originalSettings = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settingsPath = storage_path('app/plugins/sirsoft-pay_kginicis/settings/setting.json');
        $this->hadOriginalSettings = File::exists($this->settingsPath);
        $this->originalSettings = $this->hadOriginalSettings ? File::get($this->settingsPath) : null;

        File::ensureDirectoryExists(dirname($this->settingsPath));
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginalSettings && $this->originalSettings !== null) {
            File::put($this->settingsPath, $this->originalSettings);
        } else {
            File::delete($this->settingsPath);
        }

        parent::tearDown();
    }

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

    public function test_skips_when_settings_file_is_missing(): void
    {
        File::delete($this->settingsPath);

        $this->runMigration();

        $this->assertFalse(File::exists($this->settingsPath));
    }

    private function runMigration(): void
    {
        (new MigrateNaverpayBrandButtonKey)->run(new UpgradeContext(
            fromVersion: '1.0.0-beta.6',
            toVersion: '1.0.0',
            currentStep: '1.0.0',
        ));
    }
}
