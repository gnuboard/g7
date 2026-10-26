<?php

namespace Tests\Feature\Extension;

use App\Extension\Exceptions\ExtensionSeederFailedException;
use App\Extension\HookCacheManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * 확장 설치 시더 — 프로바이더 선등록·시더 실패 시 설치 실패·되돌림 (7.1.3).
 *
 * 코어의 확장 프로바이더 등록기는 부팅 때 `modules/`·`plugins/` 에 이미 있는 디렉터리만 훑는다. 새 설치는
 * 같은 프로세스 안에서 `_pending` 에서 디렉터리를 복사한 뒤 시더를 돌리므로, 프로바이더의 `$repositories`
 * 바인딩이 없어 시더가 저장소 인터페이스를 해석하는 순간 실패했고, 그 예외를 로그만 남기고 삼켜 설치가
 * 「성공」했다(기본 데이터 0건). 이제 설치 전에 프로바이더를 선등록하고, 시더가 실패하면 설치를 실패로
 * 끝내며 이번 설치가 만든 행·테이블·설정·디렉터리를 되돌린다.
 *
 * 픽스처는 `tests/Fixtures/Extension/SeederProvider/{modules,plugins}/zztest-seedprov` 를 `_pending` 으로
 * 복사해 쓴다(부팅 이후 놓인 디렉터리 = 실제 설치 상황). DDL 이 트랜잭션을 끊으므로 DB 트레이트 없이
 * tearDown 에서 직접 정리한다.
 *
 * @scenario-manifest tests/scenarios/install-seeder-provider.yaml
 */
class InstallSeederProviderTest extends TestCase
{
    private const ID = 'zztest-seedprov';

    protected array $requiredExtensions = ['modules/zztest-seedprov', 'plugins/zztest-seedprov'];

    /**
     * 픽스처를 _pending 으로 복사하고 훅 캐시 굽기를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        putenv('ZZTEST_SEEDPROV_MODE=ok');

        foreach (['modules', 'plugins'] as $type) {
            $this->cleanupExtension($type);
            File::copyDirectory(
                base_path("tests/Fixtures/Extension/SeederProvider/{$type}/".self::ID),
                base_path("{$type}/_pending/".self::ID),
            );
        }

        // 설치 성공 경로는 훅 캐시를 다시 굽는다 — 실제 bootstrap/cache/hooks.php 를 시험 DB 기준으로 덮지 않게 막는다
        $hookCache = Mockery::mock(HookCacheManager::class)->makePartial();
        $hookCache->shouldReceive('generate')->andReturn([]);
        $this->app->instance(HookCacheManager::class, $hookCache);
    }

    /**
     * 설치된 픽스처와 _pending 사본·테이블·행을 모두 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        putenv('ZZTEST_SEEDPROV_MODE');

        foreach (['modules', 'plugins'] as $type) {
            $this->cleanupExtension($type);
        }

        parent::tearDown();
    }

    /**
     * 부팅 뒤 놓인 모듈의 시더가 프로바이더 저장소 바인딩을 해석해 기본 데이터를 만든다.
     *
     * @scenario extension_type=module, seeder_outcome=ok, prior_install=none
     *
     * @effects seeder_resolves_provider_repository_binding
     *
     * @return void 반환값 없음
     */
    public function test_module_seeder_resolves_provider_binding_on_fresh_install(): void
    {
        $manager = app(ModuleManager::class);

        $this->assertTrue($manager->installModule(self::ID));

        $this->assertSame(1, DB::table('zztest_seedprov_probes')->count(), '시더가 만든 기본 데이터가 있어야 한다');
    }

    /**
     * 시더가 실패하면 설치가 실패하고, 이번 설치가 만든 DB 행·테이블·권한·설정·활성 디렉터리가 남지 않는다.
     *
     * @scenario extension_type=module, seeder_outcome=throw, prior_install=none
     *
     * @effects seeder_failure_fails_install_with_seeder_name, failed_fresh_install_leaves_nothing
     *
     * @return void 반환값 없음
     */
    public function test_module_seeder_failure_fails_install_and_rolls_back(): void
    {
        putenv('ZZTEST_SEEDPROV_MODE=throw');
        $manager = app(ModuleManager::class);

        try {
            $manager->installModule(self::ID);
            $this->fail('시더 실패가 설치 실패로 올라와야 한다');
        } catch (ExtensionSeederFailedException $e) {
            $this->assertStringContainsString('ProbeSeeder', $e->getMessage());
            $this->assertStringContainsString('probe seeder failure', $e->getMessage());
        }

        $this->assertNull(DB::table('modules')->where('identifier', self::ID)->first(), '모듈 행이 남으면 안 된다');
        $this->assertFalse(Schema::hasTable('zztest_seedprov_probes'), '이번 설치가 만든 테이블이 남으면 안 된다');
        $this->assertSame(0, DB::table('permissions')->where('extension_identifier', self::ID)->count(), '권한 행이 남으면 안 된다');
        $this->assertDirectoryDoesNotExist(base_path('modules/'.self::ID), '활성 디렉터리가 남으면 안 된다');
        $this->assertDirectoryDoesNotExist(ExtensionStoragePath::module(self::ID), '스토리지·설정이 남으면 안 된다');
    }

    /**
     * 선언된 시더 클래스가 없으면 「클래스를 찾을 수 없음」 사유로 설치가 실패한다.
     *
     * @scenario extension_type=module, seeder_outcome=missing_class, prior_install=none
     *
     * @effects missing_declared_seeder_class_fails_install
     *
     * @return void 반환값 없음
     */
    public function test_module_missing_declared_seeder_class_fails_install(): void
    {
        putenv('ZZTEST_SEEDPROV_MODE=missing');

        $this->expectException(ExtensionSeederFailedException::class);
        $this->expectExceptionMessage(__('extensions.errors.seeder_class_not_found'));

        app(ModuleManager::class)->installModule(self::ID);
    }

    /**
     * 강제 재설치(호출 전 DB 행 있음)가 시더에서 실패하면 운영 데이터를 지우지 않는다.
     *
     * @scenario extension_type=module, seeder_outcome=throw, prior_install=installed
     *
     * @effects failed_forced_reinstall_preserves_existing_data
     *
     * @return void 반환값 없음
     */
    public function test_module_forced_reinstall_failure_preserves_existing_rows(): void
    {
        $manager = app(ModuleManager::class);
        $this->assertTrue($manager->installModule(self::ID));

        putenv('ZZTEST_SEEDPROV_MODE=throw');
        File::copyDirectory(
            base_path('tests/Fixtures/Extension/SeederProvider/modules/'.self::ID),
            base_path('modules/_pending/'.self::ID),
        );

        try {
            $manager->installModule(self::ID, force: true);
            $this->fail('시더 실패가 설치 실패로 올라와야 한다');
        } catch (ExtensionSeederFailedException) {
            // 기대 동작
        }

        $this->assertNotNull(DB::table('modules')->where('identifier', self::ID)->first(), '기존 모듈 행은 보존');
        $this->assertTrue(Schema::hasTable('zztest_seedprov_probes'), '기존 테이블은 보존');
        $this->assertSame(1, DB::table('zztest_seedprov_probes')->count(), '기존 데이터는 보존');
    }

    /**
     * 활성화·비활성화도 훅 캐시를 다시 굽는다 — 활성 목록이 바뀌었는데 캐시가 그대로면 켠 확장의 리스너가 빠진다 (7.1.3).
     *
     * @scenario cache=hooks, change=activation_toggled
     *
     * @effects activation_toggle_regenerates_hook_cache
     *
     * @return void 반환값 없음
     */
    public function test_activation_and_deactivation_regenerate_hook_cache(): void
    {
        $manager = app(ModuleManager::class);
        $this->assertTrue($manager->installModule(self::ID));

        $hookCache = Mockery::mock(HookCacheManager::class)->makePartial();
        $hookCache->shouldReceive('generate')->twice()->andReturn([]);
        $this->app->instance(HookCacheManager::class, $hookCache);

        $this->assertTrue($manager->activateModule(self::ID)['success']);
        $this->assertTrue($manager->deactivateModule(self::ID)['success']);
    }

    /**
     * 부팅 뒤 놓인 플러그인의 시더도 프로바이더 저장소 바인딩을 해석한다.
     *
     * @scenario extension_type=plugin, seeder_outcome=ok, prior_install=none
     *
     * @effects seeder_resolves_provider_repository_binding
     *
     * @return void 반환값 없음
     */
    public function test_plugin_seeder_resolves_provider_binding_on_fresh_install(): void
    {
        $this->assertTrue(app(PluginManager::class)->installPlugin(self::ID));

        $this->assertSame(1, DB::table('zztest_seedprov_plugin_probes')->count());
    }

    /**
     * 플러그인 시더가 실패하면 설치가 실패하고 이번 설치가 만든 것이 남지 않는다.
     *
     * @scenario extension_type=plugin, seeder_outcome=throw, prior_install=none
     *
     * @effects seeder_failure_fails_install_with_seeder_name, failed_fresh_install_leaves_nothing
     *
     * @return void 반환값 없음
     */
    public function test_plugin_seeder_failure_fails_install_and_rolls_back(): void
    {
        putenv('ZZTEST_SEEDPROV_MODE=throw');

        try {
            app(PluginManager::class)->installPlugin(self::ID);
            $this->fail('시더 실패가 설치 실패로 올라와야 한다');
        } catch (ExtensionSeederFailedException $e) {
            $this->assertStringContainsString('ProbeSeeder', $e->getMessage());
        }

        $this->assertNull(DB::table('plugins')->where('identifier', self::ID)->first());
        $this->assertFalse(Schema::hasTable('zztest_seedprov_plugin_probes'));
        $this->assertSame(0, DB::table('permissions')->where('extension_identifier', self::ID)->count());
        $this->assertDirectoryDoesNotExist(base_path('plugins/'.self::ID));
        $this->assertDirectoryDoesNotExist(ExtensionStoragePath::plugin(self::ID));
    }

    /**
     * 플러그인도 선언된 시더 클래스가 없으면 설치가 실패한다.
     *
     * @scenario extension_type=plugin, seeder_outcome=missing_class, prior_install=none
     *
     * @effects missing_declared_seeder_class_fails_install
     *
     * @return void 반환값 없음
     */
    public function test_plugin_missing_declared_seeder_class_fails_install(): void
    {
        putenv('ZZTEST_SEEDPROV_MODE=missing');

        $this->expectException(ExtensionSeederFailedException::class);

        app(PluginManager::class)->installPlugin(self::ID);
    }

    /**
     * 플러그인 강제 재설치가 시더에서 실패해도 운영 데이터를 지우지 않는다.
     *
     * @scenario extension_type=plugin, seeder_outcome=throw, prior_install=installed
     *
     * @effects failed_forced_reinstall_preserves_existing_data
     *
     * @return void 반환값 없음
     */
    public function test_plugin_forced_reinstall_failure_preserves_existing_rows(): void
    {
        $manager = app(PluginManager::class);
        $this->assertTrue($manager->installPlugin(self::ID));

        putenv('ZZTEST_SEEDPROV_MODE=throw');
        File::copyDirectory(
            base_path('tests/Fixtures/Extension/SeederProvider/plugins/'.self::ID),
            base_path('plugins/_pending/'.self::ID),
        );

        try {
            $manager->installPlugin(self::ID, force: true);
            $this->fail('시더 실패가 설치 실패로 올라와야 한다');
        } catch (ExtensionSeederFailedException) {
            // 기대 동작
        }

        $this->assertNotNull(DB::table('plugins')->where('identifier', self::ID)->first());
        $this->assertSame(1, DB::table('zztest_seedprov_plugin_probes')->count());
    }

    /**
     * 픽스처 확장의 활성·_pending 디렉터리, 스토리지, DB 행·테이블·마이그레이션 기록을 지웁니다.
     *
     * @param  string  $type  확장 유형 (modules | plugins)
     * @return void 반환값 없음
     */
    private function cleanupExtension(string $type): void
    {
        foreach ([base_path("{$type}/".self::ID), base_path("{$type}/_pending/".self::ID)] as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }

        $storage = $type === 'modules' ? ExtensionStoragePath::module(self::ID) : ExtensionStoragePath::plugin(self::ID);
        if (File::isDirectory($storage)) {
            File::deleteDirectory($storage);
        }

        $table = $type === 'modules' ? 'zztest_seedprov_probes' : 'zztest_seedprov_plugin_probes';
        Schema::dropIfExists($table);
        DB::table('migrations')->where('migration', 'like', '%create_'.$table.'_table')->delete();
        DB::table($type)->where('identifier', self::ID)->delete();
        $permissionIds = DB::table('permissions')->where('extension_identifier', self::ID)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->where('extension_identifier', self::ID)->delete();
        DB::table('menus')->where('extension_identifier', self::ID)->delete();
    }
}
