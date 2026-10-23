<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionUpdateJournal;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Models\Plugin;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 새 판 진입 파일(module.php·plugin.php)의 클래스를 다시 읽지 못하면 확장 업데이트가 실패하는지 (코어 7.1.3, 계획 WP2·D10)
 *
 * 결함(수정 전): 업데이트는 파일을 바꾼 뒤 진입 클래스를 다시 읽는다. 새 판의 네임스페이스·클래스 이름이 틀려 클래스가 없으면
 * 경고 없이 넘어가 부팅 때 읽은 옛 판 인스턴스로 나머지 단계를 돌고, DB 판은 새 판으로 적혀 「성공」했다 — 새 판이 선언한
 * 산출물(권한·메뉴 등)은 옛 클래스 기준이라 반영되지 않고 데이터는 옛 형태인데 판만 올라갔다. 이제 판을 적기 전에 실패해
 * 기존 업데이트 실패 경로(파일 복원·판 유지)를 탄다.
 *
 * 실제 업데이트 경로를 가짜 확장(`testmerge-*`)과 ZIP 업데이트 경로로 돌린다 — 새 판 소스는 `storage/framework/testing` 아래
 * 임시 폴더에 두고, 오토로드·훅 캐시 재생성은 실제 파일을 건드리므로 확장 관리자를 목으로, 라우트 캐시 재생성은 미뤄 둔다.
 */
class UpdateReloadClassMissingFailsTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /** 스텝 실행 기록 파일 */
    private string $stepLog;

    /** 새 판 소스 임시 폴더 (식별자 => 경로) */
    private array $newVersionSources = [];

    /** 시험이 만든 임시 폴더 (tearDown 정리) */
    private array $tempDirs = [];

    /**
     * 테스트 준비 — 스텝 기록 파일·진행 기록을 비우고 라우트 캐시 재생성을 미룬다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->stepLog = storage_path('framework/testing/g7test-rcm-steps-'.getmypid().'.log');
        File::ensureDirectoryExists(dirname($this->stepLog));
        File::delete($this->stepLog);
        config(['g7test_rcm.log' => $this->stepLog]);

        ExtensionUpdateJournal::discard('module', $this->fixtureModule);
        ExtensionUpdateJournal::discard('plugin', $this->fixturePlugin);

        $this->setRouteCacheDeferDepth(1);
    }

    /**
     * 픽스처 디스크 산출물·진행 기록·스텝 기록을 지우고 라우트 캐시 미룸을 푼다(재생성은 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();
        ExtensionUpdateJournal::discard('module', $this->fixtureModule);
        ExtensionUpdateJournal::discard('plugin', $this->fixturePlugin);
        File::delete($this->stepLog);
        foreach ($this->tempDirs as $dir) {
            File::deleteDirectory($dir);
        }
        $this->setRouteCacheDeferDepth(0);

        parent::tearDown();
    }

    /**
     * 라우트 캐시 재생성 미룸 깊이를 정합니다 — 0 으로 되돌릴 때 쌓인 재생성 요청은 버린다.
     *
     * @param  int  $depth  미룸 깊이
     * @return void 반환값 없음
     */
    private function setRouteCacheDeferDepth(int $depth): void
    {
        $ref = new \ReflectionClass(RouteCacheHelper::class);
        $ref->setStaticPropertyValue('deferDepth', $depth);
        if ($depth === 0) {
            $ref->setStaticPropertyValue('rebuildPending', false);
        }
    }

    /**
     * 시험 전용 임시 폴더를 만듭니다 — tearDown 이 지운다.
     *
     * @param  string  $name  폴더 이름 접두
     * @return string 폴더 경로
     */
    private function tempDir(string $name): string
    {
        $dir = storage_path('framework/testing/g7test-rcm-'.$name.'-'.uniqid());
        File::ensureDirectoryExists($dir);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * 오토로드·훅 캐시를 실제로 굽지 않도록 확장 관리자를 목으로 바꿉니다 — ZIP 준비는 새 판 소스의 임시 사본을 돌려준다.
     *
     * @return void 반환값 없음
     */
    private function fakeExtensionManager(): void
    {
        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, function (MockInterface $m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('hasComposerDependenciesAt')->andReturn(false);
            $m->shouldReceive('prepareZipSource')->andReturnUsing(function (string $zipPath, string $identifier): array {
                $temp = $this->tempDir('zip');
                File::copyDirectory($this->newVersionSources[$identifier], $temp.'/extracted');

                return ['temp_dir' => $temp, 'extracted_dir' => $temp.'/extracted', 'to_version' => '1.0.1', 'manifest' => []];
            });
        }));
        $this->app->forgetInstance(ModuleManager::class);
        $this->app->forgetInstance(PluginManager::class);
    }

    /**
     * 활성 디렉토리를 임시 폴더로 복사해 1.0.1 판 소스를 만듭니다 — 진입 파일 네임스페이스를 오타로 바꾸고 1.0.1 스텝을 넣는다.
     *
     * @param  string  $type  확장 종류 디렉토리 (modules|plugins)
     * @param  string  $identifier  확장 식별자
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  string  $entryFile  진입 파일명 (module.php|plugin.php)
     * @param  string  $namespace  진입 클래스 네임스페이스 (예: Modules\Testmerge\Kmod)
     * @return void 반환값 없음
     */
    private function stageNewVersionWithTypo(string $type, string $identifier, string $manifestFile, string $entryFile, string $namespace): void
    {
        $source = $this->tempDir('src-'.$identifier);
        $this->newVersionSources[$identifier] = $source;
        File::copyDirectory(base_path("{$type}/{$identifier}"), $source);

        $manifest = json_decode(File::get("{$source}/{$manifestFile}"), true);
        $manifest['version'] = '1.0.1';
        File::put("{$source}/{$manifestFile}", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // 네임스페이스 오타 — 파일은 있지만 기대 클래스(네임스페이스\Module|Plugin)는 정의되지 않는다
        File::put("{$source}/{$entryFile}", str_replace("namespace {$namespace};", "namespace {$namespace}Typo;", File::get("{$source}/{$entryFile}")));

        File::ensureDirectoryExists("{$source}/upgrades");
        File::put("{$source}/upgrades/Upgrade_1_0_1.php", <<<PHP
<?php

namespace {$namespace}\\Upgrades;

use App\\Contracts\\Extension\\UpgradeStepInterface;
use App\\Extension\\UpgradeContext;

class Upgrade_1_0_1 implements UpgradeStepInterface
{
    public function run(UpgradeContext \$context): void
    {
        file_put_contents(config('g7test_rcm.log'), "1.0.1\\n", FILE_APPEND);
    }
}
PHP);
    }

    /**
     * 새 판 진입 클래스를 못 읽은 업데이트는 판을 적기 전에 실패하고, 판·파일·스텝이 업데이트 전 그대로 남는다 — 모듈·플러그인.
     *
     * @scenario source=module, path=update_entry_class_missing
     *
     * @effects update_fails_when_new_entry_class_missing_and_keeps_version
     *
     * @return void 반환값 없음
     */
    public function test_update_fails_when_new_entry_class_cannot_be_loaded(): void
    {
        // @scenario source=plugin, path=update_entry_class_missing
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => ['version' => '1.0.0', 'layout_name' => 'index', 'components' => [['id' => 'index', 'type' => 'basic', 'name' => 'Div', 'text' => 'A1']]]]);
        $this->makeFixturePlugin(['settings.json' => ['version' => '1.0.0', 'layout_name' => 'settings', 'components' => [['id' => 'settings', 'type' => 'basic', 'name' => 'Div', 'text' => 'P1']]]]);
        $this->stageNewVersionWithTypo('modules', $this->fixtureModule, 'module.json', 'module.php', 'Modules\\Testmerge\\Kmod');
        $this->stageNewVersionWithTypo('plugins', $this->fixturePlugin, 'plugin.json', 'plugin.php', 'Plugins\\Testmerge\\Kplug');
        $this->fakeExtensionManager();

        foreach ([
            ['modules', Module::class, $this->fixtureModule, 'module.php', fn () => tap(app(ModuleManager::class), fn ($m) => $m->loadModules())->updateModule($this->fixtureModule, false, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-rcm.zip')],
            ['plugins', Plugin::class, $this->fixturePlugin, 'plugin.php', fn () => tap(app(PluginManager::class), fn ($m) => $m->loadPlugins())->updatePlugin($this->fixturePlugin, false, null, VendorMode::Auto, 'overwrite', null, null, 'g7test-rcm.zip')],
        ] as [$dir, $model, $identifier, $entryFile, $update]) {
            File::delete($this->stepLog);
            $entryBefore = File::get(base_path("{$dir}/{$identifier}/{$entryFile}"));

            $failure = null;
            try {
                $update();
            } catch (\RuntimeException $e) {
                $failure = $e;
            }

            $row = $model::query()->where('identifier', $identifier)->firstOrFail();
            $this->assertNotNull($failure, "{$identifier}: 새 판 진입 클래스를 못 읽으면 업데이트가 실패해야 합니다(기록된 판 {$row->version}).");
            $this->assertStringContainsString($entryFile, $failure->getMessage(), "{$identifier}: 실패 사유에 진입 파일명이 있어야 합니다.");
            $this->assertSame('1.0.0', $row->version, "{$identifier}: 판은 업데이트 전 그대로여야 합니다.");
            $this->assertSame(ExtensionStatus::Active->value, $row->status, "{$identifier}: 상태는 업데이트 전으로 복원돼야 합니다.");
            $this->assertSame([], is_file($this->stepLog) ? array_filter(explode("\n", (string) File::get($this->stepLog))) : [], "{$identifier}: 업그레이드 스텝은 돌지 않아야 합니다.");
            $this->assertSame($entryBefore, File::get(base_path("{$dir}/{$identifier}/{$entryFile}")), "{$identifier}: 진입 파일은 백업으로 복원돼야 합니다.");
        }
    }
}
