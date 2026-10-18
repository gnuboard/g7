<?php

namespace Tests\Feature\Extension;

use App\Extension\ExtensionManager;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Models\Plugin;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 확장 업데이트가 버전 기록 뒤 단계(레이아웃 갱신)에서 실패하면 DB 버전도 업데이트 전으로 돌아가는지 고정하는 회귀 테스트 (#727).
 *
 * 결함(수정 전): 업데이트는 새 버전을 DB 에 먼저 저장(커밋)한 뒤 레이아웃을 갱신한다. 레이아웃 파일 검증이 실패하면
 * 파일은 백업으로 되돌아가지만 DB 는 새 버전으로 남아, 관리자 화면이 설치되지 않은 버전을 보여 주고 "업데이트 없음" 으로
 * 판정돼 같은 업데이트를 다시 시도할 수도 없었다(실측: 파일 0.2.0 · DB 0.2.1).
 *
 * 실제 업데이트 경로(백업 → 번들 스테이징 → 파일 교체 → DB 갱신 → 레이아웃 갱신)를 가짜 확장으로 돌리고, 새 버전에
 * 깨진 레이아웃 파일을 넣어 레이아웃 갱신 단계에서 실패시킨다. 모듈·플러그인은 오토로드·훅 캐시 재생성이 실제 파일을
 * 건드리므로 확장 관리자(ExtensionManager)만 목으로 바꾼다.
 */
class ExtensionUpdateFailureRestoresVersionTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 픽스처 디스크 산출물을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();

        parent::tearDown();
    }

    /**
     * 화면 한 장짜리 레이아웃 JSON 을 만듭니다.
     *
     * @param  string  $name  레이아웃 이름
     * @param  string  $text  화면 문구
     * @return array<string, mixed> 레이아웃 JSON
     */
    private function screen(string $name, string $text): array
    {
        return ['version' => '1.0.0', 'layout_name' => $name, 'components' => [['id' => $name, 'type' => 'basic', 'name' => 'Div', 'text' => $text]]];
    }

    /**
     * 활성 디렉토리를 번들 디렉토리로 복사하고 매니페스트 버전을 올린 뒤, 레이아웃 디렉토리에 깨진 파일을 넣습니다.
     *
     * @param  string  $type  확장 종류 디렉토리 (modules|plugins|templates)
     * @param  string  $identifier  확장 식별자
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  string  $layoutsDir  확장 루트 기준 레이아웃 디렉토리
     * @return string 번들 경로
     */
    private function stageBrokenBundledVersion(string $type, string $identifier, string $manifestFile, string $layoutsDir): string
    {
        $bundled = base_path("{$type}/_bundled/{$identifier}");
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path("{$type}/{$identifier}"), $bundled);

        $manifest = json_decode(File::get("{$bundled}/{$manifestFile}"), true);
        $manifest['version'] = '1.1.0';
        File::put("{$bundled}/{$manifestFile}", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        File::ensureDirectoryExists("{$bundled}/{$layoutsDir}");
        File::put("{$bundled}/{$layoutsDir}/broken.json", '{ "layout_name": "broken", ');

        return $bundled;
    }

    /**
     * 오토로드·훅 캐시를 실제로 굽지 않도록 확장 관리자를 목으로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    private function fakeExtensionManager(): void
    {
        $this->app->instance(ExtensionManager::class, Mockery::mock(ExtensionManager::class, function (MockInterface $m) {
            $m->shouldIgnoreMissing();
            $m->shouldReceive('hasComposerDependenciesAt')->andReturn(false);
        }));
        $this->app->forgetInstance(ModuleManager::class);
        $this->app->forgetInstance(PluginManager::class);
    }

    /**
     * 템플릿 업데이트가 레이아웃 갱신에서 실패하면 파일과 함께 DB 버전·업데이트 표시도 업데이트 전으로 돌아간다.
     *
     * @scenario source=template, path=update_failed_after_version_recorded, unit=version_columns
     *
     * @effects template_update_failure_restores_version_columns
     *
     * @return void 반환값 없음
     */
    public function test_template_update_failing_at_layout_refresh_restores_version(): void
    {
        $this->makeFixtureTemplate(['home.json' => $this->screen('home', 'HOME1')]);
        $this->writeFixtureTemplateManifest();
        Template::where('identifier', $this->fixtureTemplate)->update([
            'latest_version' => '1.1.0',
            'update_available' => true,
            'update_source' => 'bundled',
            'name' => ['ko' => '업데이트 전 이름', 'en' => 'Before Update'],
        ]);
        $this->stageBrokenBundledVersion('templates', $this->fixtureTemplate, 'template.json', 'layouts');

        $manager = app(TemplateManager::class);
        $manager->loadTemplates();

        try {
            $manager->updateTemplate($this->fixtureTemplate, true, null, 'overwrite', 'bundled');
            $this->fail('깨진 레이아웃이 든 새 버전 업데이트는 실패해야 합니다.');
        } catch (\RuntimeException) {
            // 기대한 실패
        }

        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.0.0', $row->version, '파일은 1.0.0 으로 돌아갔는데 DB 가 새 버전을 가리킵니다.');
        $this->assertSame('1.1.0', $row->latest_version);
        $this->assertTrue((bool) $row->update_available, '같은 업데이트를 다시 시도할 수 있어야 합니다.');
        $this->assertSame('bundled', $row->update_source, '업데이트 출처도 업데이트 전 값이어야 합니다.');
        $this->assertSame('업데이트 전 이름', $row->name['ko'] ?? null, '이름도 업데이트 전 값이어야 합니다(새 판 매니페스트 이름으로 남으면 안 된다).');
        $this->assertSame('active', $row->status);
        $this->assertSame('1.0.0', json_decode(File::get(base_path("templates/{$this->fixtureTemplate}/template.json")), true)['version']);
    }

    /**
     * 모듈 업데이트가 레이아웃 갱신에서 실패하면 DB 버전도 업데이트 전으로 돌아간다.
     *
     * @scenario source=module, path=update_failed_after_version_recorded, unit=version_columns
     *
     * @effects module_update_failure_restores_version_columns
     *
     * @return void 반환값 없음
     */
    public function test_module_update_failing_at_layout_refresh_restores_version(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => $this->screen('index', 'A1')]);
        Module::where('identifier', $this->fixtureModule)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled', 'vendor_mode' => 'composer']);
        $this->stageBrokenBundledVersion('modules', $this->fixtureModule, 'module.json', 'resources/layouts');
        $this->fakeExtensionManager();

        $manager = app(ModuleManager::class);
        $manager->loadModules();

        try {
            $manager->updateModule($this->fixtureModule, true, null, VendorMode::Auto, 'overwrite', null, 'bundled');
            $this->fail('깨진 레이아웃이 든 새 버전 업데이트는 실패해야 합니다.');
        } catch (\RuntimeException) {
            // 기대한 실패
        }

        $row = Module::where('identifier', $this->fixtureModule)->firstOrFail();
        $this->assertSame('1.0.0', $row->version, '파일은 1.0.0 으로 돌아갔는데 DB 가 새 버전을 가리킵니다.');
        $this->assertTrue((bool) $row->update_available, '같은 업데이트를 다시 시도할 수 있어야 합니다.');
        $this->assertSame('bundled', $row->update_source);
        $this->assertSame('composer', $row->getRawOriginal('vendor_mode'), 'vendor_mode 도 업데이트 전 값이어야 합니다.');
        $this->assertSame('active', $row->status);
        $this->assertFileDoesNotExist(base_path("modules/{$this->fixtureModule}/resources/layouts/broken.json"));
    }

    /**
     * 플러그인 업데이트가 레이아웃 갱신에서 실패하면 DB 버전도 업데이트 전으로 돌아간다.
     *
     * @scenario source=plugin, path=update_failed_after_version_recorded, unit=version_columns
     *
     * @effects plugin_update_failure_restores_version_columns
     *
     * @return void 반환값 없음
     */
    public function test_plugin_update_failing_at_layout_refresh_restores_version(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixturePlugin(['settings.json' => $this->screen('settings', 'P1')]);
        Plugin::where('identifier', $this->fixturePlugin)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled', 'vendor_mode' => 'composer']);
        $this->stageBrokenBundledVersion('plugins', $this->fixturePlugin, 'plugin.json', 'resources/layouts');
        $this->fakeExtensionManager();

        $manager = app(PluginManager::class);
        $manager->loadPlugins();

        try {
            $manager->updatePlugin($this->fixturePlugin, true, null, VendorMode::Auto, 'overwrite', null, 'bundled');
            $this->fail('깨진 레이아웃이 든 새 버전 업데이트는 실패해야 합니다.');
        } catch (\RuntimeException) {
            // 기대한 실패
        }

        $row = Plugin::where('identifier', $this->fixturePlugin)->firstOrFail();
        $this->assertSame('1.0.0', $row->version, '파일은 1.0.0 으로 돌아갔는데 DB 가 새 버전을 가리킵니다.');
        $this->assertTrue((bool) $row->update_available, '같은 업데이트를 다시 시도할 수 있어야 합니다.');
        $this->assertSame('bundled', $row->update_source);
        $this->assertSame('composer', $row->getRawOriginal('vendor_mode'), 'vendor_mode 도 업데이트 전 값이어야 합니다.');
        $this->assertSame('active', $row->status);
        $this->assertFileDoesNotExist(base_path("plugins/{$this->fixturePlugin}/resources/layouts/broken.json"));
    }

    /**
     * 백업을 지운 뒤(완료 훅 단계) 실패하면 파일은 이미 새 버전이라 DB 도 새 버전을 유지한다 — 반대 방향 불일치 금지.
     *
     * 파일 복원이 불가능한 단계에서 버전 칸만 되돌리면 「파일은 새 버전, DB 는 옛 버전」 이 되어, 종전 결함의 거울상이 된다.
     * 버전 칸 복원은 파일 복원이 실제로 된 경우에만 한다.
     *
     * @scenario source=template, path=update_failed_after_backup_deleted, unit=version_columns
     *
     * @effects update_failure_after_backup_deleted_keeps_new_version
     *
     * @return void 반환값 없음
     */
    public function test_failure_after_backup_deleted_keeps_new_version(): void
    {
        $this->makeFixtureTemplate(['home.json' => $this->screen('home', 'HOME1')]);
        $this->writeFixtureTemplateManifest();
        $this->fixtureTemplateModel->update(['latest_version' => '1.1.0', 'update_available' => true]);
        $bundled = base_path("templates/_bundled/{$this->fixtureTemplate}");
        $this->fixturePaths[] = $bundled;
        File::copyDirectory(base_path("templates/{$this->fixtureTemplate}"), $bundled);
        $manifest = json_decode(File::get("{$bundled}/template.json"), true);
        $manifest['version'] = '1.1.0';
        File::put("{$bundled}/template.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $listener = function (): void {
            throw new \RuntimeException('완료 훅 구독자 실패');
        };
        HookManager::addAction('core.templates.updated', $listener);

        $manager = app(TemplateManager::class);
        $manager->loadTemplates();

        try {
            $manager->updateTemplate($this->fixtureTemplate, true, null, 'overwrite', 'bundled');
            $this->fail('완료 훅 구독자가 던지면 업데이트는 실패로 보고돼야 합니다.');
        } catch (\RuntimeException) {
            // 기대한 실패
        } finally {
            HookManager::removeAction('core.templates.updated', $listener);
        }

        $row = Template::where('identifier', $this->fixtureTemplate)->firstOrFail();
        $this->assertSame('1.1.0', json_decode(File::get(base_path("templates/{$this->fixtureTemplate}/template.json")), true)['version'], '백업이 지워져 파일은 새 버전으로 남는다.');
        $this->assertSame('1.1.0', $row->version, '파일이 새 버전이면 DB 도 새 버전이어야 합니다.');
        $this->assertSame('active', $row->status);
    }
}
