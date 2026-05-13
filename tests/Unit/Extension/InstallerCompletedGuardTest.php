<?php

namespace Tests\Unit\Extension;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `config('app.installer_completed')` 가드가 Schema::hasTable() 호출을
 * 건너뛰는지 검증합니다.
 *
 * 성능 회귀 방지 목적: 설치 완료 상태에서 매 요청 `information_schema.tables`
 * 쿼리가 실행되지 않아야 합니다.
 */
class InstallerCompletedGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config(['app.installer_completed' => false]);
        parent::tearDown();
    }

    public function test_module_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        DB::enableQueryLog();
        ModuleManager::getActiveModuleIdentifiers();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $schemaQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'information_schema.tables')
        );

        $this->assertCount(0, $schemaQueries, 'installer_completed=true 에서는 information_schema.tables 쿼리가 발생하지 않아야 합니다');
    }

    public function test_plugin_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        DB::enableQueryLog();
        PluginManager::getActivePluginIdentifiers();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $schemaQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'information_schema.tables')
        );

        $this->assertCount(0, $schemaQueries);
    }

    public function test_template_trait_skips_has_table_when_installer_completed(): void
    {
        config(['app.installer_completed' => true]);

        DB::enableQueryLog();
        TemplateManager::getActiveTemplateIdentifiers();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $schemaQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'information_schema.tables')
        );

        $this->assertCount(0, $schemaQueries);
    }

    public function test_module_trait_falls_back_to_has_table_when_installer_not_completed(): void
    {
        config(['app.installer_completed' => false]);

        DB::enableQueryLog();
        ModuleManager::getActiveModuleIdentifiers();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $schemaQueries = array_filter(
            $queries,
            fn ($q) => str_contains($q['query'], 'information_schema.tables')
        );

        $this->assertGreaterThan(0, count($schemaQueries), 'installer_completed=false 에서는 기존 hasTable 경로가 실행되어야 합니다');
    }
}
