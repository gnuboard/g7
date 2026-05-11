<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use ReflectionClass;

/**
 * Board 모듈 1.0.0-beta.4 업그레이드 스텝의 user_overrides dot-path 변환 검증.
 */
class BoardUserOverridesSubKeyMigrationTest extends ModuleTestCase
{
    private object $upgrade;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 3).'/upgrades/Upgrade_1_0_0_beta_4.php';

        $class = 'Modules\\Sirsoft\\Board\\Upgrades\\Upgrade_1_0_0_beta_4';
        $this->upgrade = new $class();
        $this->context = new UpgradeContext(
            fromVersion: '1.0.0-beta.3',
            toVersion: '1.0.0-beta.4',
            currentStep: '1.0.0-beta.4',
        );
    }

    private function runUpgrade(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('run');
        $method->invoke($this->upgrade, $this->context);
    }

    public function test_board_types_name_expands_to_dot_paths(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic', 'ja' => '基本型']),
            'user_overrides' => json_encode(['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en', 'name.ja'], $overrides);
    }

    public function test_already_dot_path_idempotent(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic']),
            'user_overrides' => json_encode(['name.ko']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $this->assertEquals(['name.ko'], json_decode($row->user_overrides, true));
    }

    public function test_null_user_overrides_unchanged(): void
    {
        $id = DB::table('board_types')->insertGetId([
            'slug' => 'test-'.uniqid(),
            'name' => json_encode(['ko' => '기본형', 'en' => 'Basic']),
            'user_overrides' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runUpgrade();

        $row = DB::table('board_types')->where('id', $id)->first(['user_overrides']);
        $this->assertNull($row->user_overrides);
    }
}
