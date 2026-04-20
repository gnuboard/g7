<?php

namespace Tests\Feature\Services;

use App\Services\CoreUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * CoreUpdateService::collectBundledExtensionUpdates() 테스트.
 *
 * 핵심 불변:
 *   - _bundled/{id}/{manifest}.json 의 version 이 DB 현재 version 보다 크면 감지
 *   - GitHub 상태와 무관 (Manager::checkXxxUpdate() 의 GitHub 엄격 우선 정책 우회)
 *   - _bundled manifest 부재 또는 버전 동일/이하면 미감지
 */
class CollectBundledExtensionUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private string $modulesDir;

    private string $pluginsDir;

    private string $templatesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modulesDir = base_path('modules/_bundled');
        $this->pluginsDir = base_path('plugins/_bundled');
        $this->templatesDir = base_path('templates/_bundled');
    }

    protected function tearDown(): void
    {
        // 테스트용 임시 번들 디렉토리 정리
        foreach (['test-bundled-mod', 'test-same-mod', 'test-older-mod'] as $id) {
            $path = $this->modulesDir.'/'.$id;
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }
        foreach (['test-bundled-plug'] as $id) {
            $path = $this->pluginsDir.'/'.$id;
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }
        foreach (['test-bundled-tpl'] as $id) {
            $path = $this->templatesDir.'/'.$id;
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    private function writeManifest(string $dir, string $id, string $manifestName, string $version): void
    {
        $path = $dir.'/'.$id;
        File::ensureDirectoryExists($path);
        File::put($path.'/'.$manifestName, json_encode(['identifier' => $id, 'version' => $version]));
    }

    public function test_detects_bundled_module_with_newer_version(): void
    {
        DB::table('modules')->insert([
            'identifier' => 'test-bundled-mod',
            'vendor' => 'test',
            'name' => json_encode(['ko' => 'T']),
            'description' => json_encode(['ko' => 'T']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeManifest($this->modulesDir, 'test-bundled-mod', 'module.json', '1.1.0');

        $result = app(CoreUpdateService::class)->collectBundledExtensionUpdates();

        $modules = collect($result['modules'])->pluck('identifier')->all();
        $this->assertContains('test-bundled-mod', $modules);

        $entry = collect($result['modules'])->firstWhere('identifier', 'test-bundled-mod');
        $this->assertEquals('1.0.0', $entry['current_version']);
        $this->assertEquals('1.1.0', $entry['latest_version']);
        $this->assertEquals('bundled', $entry['update_source']);
    }

    public function test_skips_when_bundled_version_equals_current(): void
    {
        DB::table('modules')->insert([
            'identifier' => 'test-same-mod',
            'vendor' => 'test',
            'name' => json_encode(['ko' => 'T']),
            'description' => json_encode(['ko' => 'T']),
            'version' => '1.2.3',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeManifest($this->modulesDir, 'test-same-mod', 'module.json', '1.2.3');

        $result = app(CoreUpdateService::class)->collectBundledExtensionUpdates();

        $modules = collect($result['modules'])->pluck('identifier')->all();
        $this->assertNotContains('test-same-mod', $modules);
    }

    public function test_skips_when_bundled_version_is_older(): void
    {
        DB::table('modules')->insert([
            'identifier' => 'test-older-mod',
            'vendor' => 'test',
            'name' => json_encode(['ko' => 'T']),
            'description' => json_encode(['ko' => 'T']),
            'version' => '2.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->writeManifest($this->modulesDir, 'test-older-mod', 'module.json', '1.9.0');

        $result = app(CoreUpdateService::class)->collectBundledExtensionUpdates();

        $modules = collect($result['modules'])->pluck('identifier')->all();
        $this->assertNotContains('test-older-mod', $modules);
    }

    public function test_detects_bundled_plugin_and_template_with_newer_version(): void
    {
        DB::table('plugins')->insert([
            'identifier' => 'test-bundled-plug',
            'vendor' => 'test',
            'name' => json_encode(['ko' => 'T']),
            'description' => json_encode(['ko' => 'T']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->writeManifest($this->pluginsDir, 'test-bundled-plug', 'plugin.json', '1.1.0');

        DB::table('templates')->insert([
            'identifier' => 'test-bundled-tpl',
            'vendor' => 'test',
            'type' => 'admin',
            'name' => json_encode(['ko' => 'T']),
            'description' => json_encode(['ko' => 'T']),
            'version' => '1.0.0',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->writeManifest($this->templatesDir, 'test-bundled-tpl', 'template.json', '1.2.0');

        $result = app(CoreUpdateService::class)->collectBundledExtensionUpdates();

        $pluginIds = collect($result['plugins'])->pluck('identifier')->all();
        $templateIds = collect($result['templates'])->pluck('identifier')->all();

        $this->assertContains('test-bundled-plug', $pluginIds);
        $this->assertContains('test-bundled-tpl', $templateIds);
    }
}
