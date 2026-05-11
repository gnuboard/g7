<?php

namespace Tests\Feature\Upgrades;

use App\Extension\UpgradeContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * 코어 7.0.0-beta.5 업그레이드 스텝의 user_overrides dot-path 변환 검증.
 *
 * 검증 시나리오:
 *   1. 다국어 컬럼명 'name' → ['name.ko', 'name.en'] 변환
 *   2. ja 활성 시 ['name.ko', 'name.en', 'name.ja'] 까지 확장
 *   3. 이미 dot-path 인 row 는 idempotent (변경 없음)
 *   4. user_overrides=null/[] 인 row 는 변환 없음
 *   5. Role 의 ['name', 'description'] 모두 변환
 *   6. 외부 식별자 (역할/권한 식별자) 는 그대로 유지
 *   7. scalar 컬럼은 그대로 유지
 */
class UserOverridesSubKeyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private object $upgrade;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        require_once base_path('upgrades/Upgrade_7_0_0_beta_4.php');

        $class = 'App\\Upgrades\\Upgrade_7_0_0_beta_4';
        $this->upgrade = new $class();
        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.3',
            toVersion: '7.0.0-beta.4',
            currentStep: '7.0.0-beta.4',
        );
    }

    /**
     * 코어 beta.4 의 user_overrides 마이그레이션 private 메서드만 호출.
     *
     * (전체 run() 호출 시 IDV 시더가 함께 실행되어 테스트 격리 어려움 — 본 검증 대상은
     *  user_overrides 변환 로직이므로 해당 private 메서드를 reflection 으로 직접 실행)
     */
    private function runMigrate(): void
    {
        $reflection = new ReflectionClass($this->upgrade);
        $method = $reflection->getMethod('migrateUserOverridesToDotPath');
        $method->setAccessible(true);
        $method->invoke($this->upgrade, $this->context);
    }

    public function test_menus_name_column_expands_to_dot_paths_for_supported_locales(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '메뉴', 'en' => 'Menu']),
            'slug' => 'test-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
            'user_overrides' => json_encode(['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('menus')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en'], $overrides);
    }

    public function test_menus_dot_path_includes_active_ja_locale(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'ja']]);

        $id = DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '메뉴', 'en' => 'Menu', 'ja' => 'メニュー']),
            'slug' => 'test-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
            'user_overrides' => json_encode(['name']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('menus')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(['name.ko', 'name.en', 'name.ja'], $overrides);
    }

    public function test_already_dot_path_overrides_are_idempotent(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '메뉴', 'en' => 'Menu']),
            'slug' => 'test-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
            'user_overrides' => json_encode(['name.ko']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('menus')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEquals(['name.ko'], $overrides);
    }

    public function test_null_user_overrides_unchanged(): void
    {
        $id = DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '메뉴', 'en' => 'Menu']),
            'slug' => 'test-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
            'user_overrides' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('menus')->where('id', $id)->first(['user_overrides']);
        $this->assertNull($row->user_overrides);
    }

    public function test_role_expands_both_translatable_columns(): void
    {
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('roles')->insertGetId([
            'identifier' => 'test_role_'.uniqid(),
            'name' => json_encode(['ko' => '역할', 'en' => 'Role']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Description']),
            'is_active' => true,
            'user_overrides' => json_encode(['name', 'description']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('roles')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en', 'description.ko', 'description.en'],
            $overrides
        );
    }

    public function test_non_translatable_external_identifier_preserved(): void
    {
        // 메뉴의 user_overrides 에 'name' (다국어) + 'admin' (역할 식별자) 혼재 시나리오
        config(['app.supported_locales' => ['ko', 'en']]);

        $id = DB::table('menus')->insertGetId([
            'name' => json_encode(['ko' => '메뉴', 'en' => 'Menu']),
            'slug' => 'test-'.uniqid(),
            'url' => '/test',
            'icon' => 'home',
            'order' => 0,
            'is_active' => true,
            'user_overrides' => json_encode(['name', 'admin', 'order']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runMigrate();

        $row = DB::table('menus')->where('id', $id)->first(['user_overrides']);
        $overrides = json_decode($row->user_overrides, true);
        // 'name' 은 dot-path 로 확장, 'admin'/'order' 는 그대로 유지
        $this->assertEqualsCanonicalizing(
            ['name.ko', 'name.en', 'admin', 'order'],
            $overrides
        );
    }
}
