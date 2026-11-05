<?php

namespace Tests\Feature\Template;

use App\Enums\LayoutSourceType;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Services\LayoutService;
use App\Services\TemplateService;
use App\Services\TemplateSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Helpers\CreatesTemplateSettingsFixtures;
use Tests\TestCase;

/**
 * 템플릿 설정 화면 교차 등록·라우트 생성 테스트 (#434)
 *
 * 설정 화면은 켜진 관리자 템플릿에 `{소유}.template_settings`(source_type=template, source_identifier=소유) 로 등록된다.
 * 오버라이드 행도 같은 모양이라, 관리자 템플릿의 자기 레이아웃 갱신·고아 오버라이드 정리가 이 행을 지우지 않아야 한다.
 */
class TemplateSettingsLayoutRegistrationTest extends TestCase
{
    use CreatesTemplateSettingsFixtures;
    use RefreshDatabase;

    /**
     * 테스트가 만든 번들 디렉토리 (업데이트 소스)
     *
     * @var array<int, string>
     */
    private array $bundledPaths = [];

    /**
     * 테스트가 만든 픽스처·캐시를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->bundledPaths as $path) {
            File::deleteDirectory($path);
        }

        foreach (File::glob(storage_path('app/extension_backups/templates/qa434-*')) ?: [] as $backup) {
            File::deleteDirectory($backup);
        }

        $this->cleanUpTemplateSettingsFixtures();
        Cache::flush();

        parent::tearDown();
    }

    /**
     * 켜진 관리자 템플릿을 하나 만듭니다(설정 화면을 그릴 호스트).
     *
     * @return Template 관리자 템플릿 행
     */
    private function makeHost(): Template
    {
        $host = $this->makeSettingsTemplate('qa434-host', 'admin', 'active');

        // 자기 레이아웃 하나 — 레이아웃 갱신이 동기화·고아 오버라이드 정리까지 실제로 돌게 한다
        File::ensureDirectoryExists(base_path('templates/qa434-host/layouts'));
        File::put(base_path('templates/qa434-host/layouts/qa434_page.json'), json_encode([
            'version' => '1.0.0',
            'layout_name' => 'qa434_page',
            'meta' => ['title' => 'qa434'],
            'components' => [['id' => 'qa434_page_root', 'type' => 'basic', 'name' => 'Div', 'props' => ['className' => 'p-4']]],
        ]));

        return $host;
    }

    /**
     * 등록하면 켜진 관리자 템플릿에 설정 화면 행이 생기고, 꺼진 사용자 템플릿도 등록된다.
     *
     * @scenario case=registration
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_registers_settings_layout_into_active_admin_template(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');

        $count = app(TemplateManager::class)->registerSettingsLayoutsForAllInstalled('qa434-host');

        $this->assertGreaterThanOrEqual(2, $count);
        $row = TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->first();
        $this->assertNotNull($row);
        $this->assertSame(LayoutSourceType::Template, $row->source_type);
        $this->assertSame('qa434-user', $row->source_identifier);
        $this->assertTrue($row->isTemplateSettingsLayout());
        $this->assertNotNull(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-host.template_settings')->first(), '관리자 템플릿 자기 설정 화면도 등록된다');
    }

    /**
     * 관리자 템플릿 자기 레이아웃 갱신(고아 오버라이드 정리 포함)이 교차 등록 행을 지우지 않는다.
     *
     * @scenario case=registration
     *
     * @effects cross_rows_survive_admin_refresh
     *
     * @return void 반환값 없음
     */
    public function test_admin_layout_refresh_keeps_settings_rows(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');

        $result = $manager->refreshTemplateLayouts('qa434-host', true);
        $this->assertTrue($result['success']);
        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434_page')->exists(), '자기 레이아웃 동기화가 실제로 돌았다');

        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());
        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-host.template_settings')->exists());
    }

    /**
     * 설정 화면 행 제거는 그 템플릿 행만 지운다.
     *
     * @scenario case=registration
     *
     * @effects rows_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_remove_settings_rows_targets_only_owner(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');

        $this->assertSame(1, $manager->removeSettingsLayoutRows('qa434-user'));

        $this->assertFalse(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());
        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-host.template_settings')->exists());
    }

    /**
     * 관리자 템플릿 라우트에 설치된 템플릿마다 설정 화면 라우트가 생기고, 보기 권한은 템플릿 조회다.
     *
     * @scenario case=routes
     *
     * @effects settings_route_generated
     * @effects no_supplied_screen_means_no_route
     *
     * @return void 반환값 없음
     */
    public function test_admin_routes_include_settings_routes(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $this->makeSettingsTemplate('qa434-nolayout', 'user', 'inactive', null, false);
        File::ensureDirectoryExists(base_path('templates/qa434-host'));
        File::put(base_path('templates/qa434-host/routes.json'), json_encode(['version' => '1.0.0', 'routes' => []]));

        $result = app(TemplateService::class)->getRoutesDataWithModules('qa434-host');
        $routes = collect($result['data']['routes'] ?? []);

        $route = $routes->firstWhere('path', '*/admin/templates/qa434-user/settings');
        $this->assertNotNull($route, '설정 화면 라우트가 있어야 한다');
        $this->assertSame('qa434-user.template_settings', $route['layout']);
        $this->assertSame('core.templates.read', $route['meta']['permission']);
        $this->assertNull($routes->firstWhere('path', '*/admin/templates/qa434-nolayout/settings'), '설정 화면을 공급하지 않은 템플릿은 라우트가 없다');

        // 공용 화면은 없다(#434 D32) — 켜진 관리자 템플릿에 옛 공용 화면 레이아웃이 남아 있어도 그 화면으로 열지 않는다
        File::ensureDirectoryExists(base_path('templates/qa434-host/layouts'));
        File::put(base_path('templates/qa434-host/layouts/admin_template_settings.json'), json_encode(['version' => '1.0.0', 'layout_name' => 'admin_template_settings', 'components' => []]));
        app(TemplateSettingsService::class)->clearCache();

        $routes = collect(app(TemplateService::class)->getRoutesDataWithModules('qa434-host')['data']['routes'] ?? []);
        $this->assertNull($routes->firstWhere('path', '*/admin/templates/qa434-nolayout/settings'));
        $this->assertSame('qa434-user.template_settings', $routes->firstWhere('path', '*/admin/templates/qa434-user/settings')['layout'] ?? null);

        $info = app(TemplateManager::class)->getTemplateInfo('qa434-nolayout');
        $this->assertFalse($info['has_settings'], '목록 「설정」 은 잠긴다');
        $this->assertNull($info['settings_route']);
    }

    /**
     * 관리자 템플릿을 켜는 진입점이 설치된 모든 템플릿의 직접 쓴 설정 화면을 그 관리자 템플릿에 등록한다.
     *
     * @scenario case=registration
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_activating_admin_template_registers_settings_rows(): void
    {
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $host = $this->makeSettingsTemplate('qa434-host', 'admin', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates(); // 부팅 뒤 만든 픽스처 템플릿을 매니저가 알게 한다

        $manager->activateTemplate('qa434-host', true);

        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());
    }

    /**
     * 사용자 템플릿 삭제 진입점은 교차 등록 행을 지우고, 「데이터도 삭제」 면 설정 파일·첨부까지 지운다. 삭제 정보는 저장 폴더·첨부 수를 알린다.
     *
     * @scenario case=uninstall
     *
     * @effects rows_removed_on_uninstall, uninstall_delete_data_purges_settings
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_entry_point_removes_rows_and_data(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates(); // 부팅 뒤 만든 픽스처 템플릿을 매니저가 알게 한다
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');
        $favicon = $this->makeFaviconAttachment('template_settings.qa434-user.branding.favicon');
        app(TemplateSettingsService::class)->save('qa434-user', ['branding' => ['favicon' => [$favicon->id]]]);

        $info = $manager->getTemplateUninstallInfo('qa434-user');
        $this->assertSame('settings', $info['storage_directories'][0]['name'] ?? null);
        $this->assertSame(1, $info['settings_attachments_count']);

        $this->assertTrue($manager->uninstallTemplate('qa434-user', deleteData: true));

        $this->assertFalse(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());
        $this->assertNull(\App\Models\Attachment::withTrashed()->find($favicon->id));
        $this->assertDirectoryDoesNotExist(\App\Support\ExtensionStoragePath::template('qa434-user', 'settings'));
    }

    /**
     * 켜진 관리자 템플릿이 `layouts/overrides/{템플릿id}/template_settings.*` 로 그 템플릿 설정 화면을 덮어쓴다 —
     * 행 소유(source_identifier)는 화면을 공급한 템플릿 그대로이고, 덮어쓰기 파일을 지우고 새로 고치면 원본으로 돌아간다 (#434 D28).
     *
     * @scenario case=registration
     *
     * @effects admin_override_replaces_settings_screen
     * @effects cross_rows_survive_admin_refresh
     *
     * @return void 반환값 없음
     */
    public function test_admin_override_replaces_settings_screen(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');

        $overrideDir = base_path('templates/qa434-host/layouts/overrides/qa434-user');
        File::ensureDirectoryExists($overrideDir);
        File::put($overrideDir.'/template_settings.json', json_encode([
            'version' => '1.0.0', 'layout_name' => 'qa434-user.template_settings', 'extends' => '_admin_base',
            'slots' => ['content' => [['id' => 'qa434_override', 'type' => 'basic', 'name' => 'Div', 'props' => []]]],
        ]));

        $manager->refreshTemplateLayouts('qa434-host', true);

        $row = TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->first();
        $this->assertSame('qa434-user', $row->source_identifier, '소유자는 화면을 공급한 템플릿 그대로');
        $this->assertTrue($row->isTemplateSettingsLayout());
        $this->assertStringContainsString('qa434_override', json_encode($row->content), '관리자 템플릿 덮어쓰기 파일로 그린다');
        $this->assertSame(1, TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->count(), '행은 하나 — 덮기 행이 따로 생기지 않는다');

        File::deleteDirectory(base_path('templates/qa434-host/layouts/overrides'));
        $manager->refreshTemplateLayouts('qa434-host', true);

        $row->refresh();
        $this->assertStringNotContainsString('qa434_override', json_encode($row->content), '덮어쓰기를 지우면 템플릿 원본으로 돌아간다');
        $this->assertStringContainsString('qa434_root', json_encode($row->content));
    }

    /**
     * 설정 화면 부품 묶음은 꺼진 템플릿도 규약 경로 두 파일만 내주고, 관리자 화면 설정에 설치 템플릿 묶음 주소가 실린다 (#434 D30).
     *
     * @scenario case=settings_bundle_load
     *
     * @effects settings_assets_served_for_inactive_template
     * @effects settings_bundles_loaded_on_admin
     *
     * @return void 반환값 없음
     */
    public function test_settings_bundle_served_for_installed_templates(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $dist = base_path('templates/qa434-user/dist');
        File::ensureDirectoryExists($dist.'/settings');
        File::ensureDirectoryExists($dist.'/js');
        File::put($dist.'/settings/settings.iife.js', 'window.__qa434 = 1;');
        File::put($dist.'/settings/settings.css', '.qa434{}');
        File::put($dist.'/js/components.iife.js', 'window.__qa434_main = 1;');

        $service = app(TemplateService::class);
        $this->assertTrue($service->getAssetFilePath('qa434-user', 'settings/settings.iife.js')['success'], '꺼진 템플릿도 설정 묶음은 내준다');
        $this->assertTrue($service->getAssetFilePath('qa434-user', 'settings/settings.css')['success']);
        $this->assertSame('template_not_found', $service->getAssetFilePath('qa434-user', 'js/components.iife.js')['error'], '규약 밖 파일은 켜진 템플릿만');
        $this->assertSame('template_not_found', $service->getAssetFilePath('qa434-user', 'settings/../js/components.iife.js')['error'], '경로를 돌려 써도 규약 경로가 아니면 막는다');

        $bundles = collect(app(TemplateSettingsService::class)->settingsAssetUrls(7));
        $user = $bundles->firstWhere('identifier', 'qa434-user');
        $this->assertNotNull($user);
        $this->assertStringContainsString('qa434-user', $user['js']);
        $this->assertStringContainsString('settings/settings.iife.js', $user['js']);
        $this->assertStringContainsString('settings/settings.css', $user['css']);
        $this->assertNull($bundles->firstWhere('identifier', 'qa434-host'), '묶음을 빌드하지 않은 템플릿은 싣지 않는다');

        // 관리자 화면 뷰 컴포저가 같은 목록을 싣는다
        $captured = [];
        $view = \Mockery::mock(\Illuminate\View\View::class);
        $view->shouldReceive('with')->andReturnUsing(function (string $key, $value) use (&$captured, $view) {
            $captured[$key] = $value;

            return $view;
        });
        app(\App\Http\View\Composers\TemplateComposer::class)->compose($view);
        $this->assertSame('qa434-user', collect($captured['templateSettingsAssets'] ?? [])->firstWhere('identifier', 'qa434-user')['identifier'] ?? null);
    }

    /**
     * 관리자 템플릿 번역 응답에 설치 템플릿의 설정 화면 문구가 `{템플릿id}.{묶음}` 으로 감싸여 실린다 — 부품 매니페스트 `lang` 의 묶음만 (#434 D28).
     *
     * @scenario case=settings_bundle_load
     *
     * @effects settings_strings_wrapped_for_admin
     *
     * @return void 반환값 없음
     */
    public function test_admin_language_wraps_template_settings_strings(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        File::ensureDirectoryExists(base_path('templates/qa434-host/lang'));
        File::put(base_path('templates/qa434-host/lang/ko.json'), json_encode(['admin' => ['title' => 'host']]));
        File::ensureDirectoryExists(base_path('templates/qa434-user/lang'));
        File::put(base_path('templates/qa434-user/lang/ko.json'), json_encode([
            'settings' => ['screen' => ['choose_file' => '파일 고르기 434']],
            'common' => ['ok' => '확인 434'],
            'user' => ['big' => '싣지 않는 묶음'],
        ], JSON_UNESCAPED_UNICODE));
        File::put(base_path('templates/qa434-user/components.settings.json'), json_encode([
            'identifier' => 'qa434-user', 'version' => '1.0.0', 'lang' => ['settings', 'common'],
            'components' => ['basic' => [], 'composite' => [], 'layout' => []],
        ]));

        $data = app(TemplateService::class)->getLanguageDataWithModules('qa434-host', 'ko')['data'];

        $this->assertSame('파일 고르기 434', $data['qa434-user']['settings']['screen']['choose_file'] ?? null);
        $this->assertSame('확인 434', $data['qa434-user']['common']['ok'] ?? null);
        $this->assertArrayNotHasKey('user', $data['qa434-user'], '매니페스트가 밝히지 않은 묶음은 싣지 않는다');

        $userData = app(TemplateService::class)->getLanguageDataWithModules('qa434-user', 'ko', includeInactive: true)['data'];
        $this->assertArrayNotHasKey('qa434-user', $userData, '사용자 템플릿 응답·꺼진 템플릿 조회에는 감싸 싣지 않는다');
    }

    /**
     * 설치 진입점이 읽을 수 있는 템플릿 파일만 둡니다(DB 행 없음) — 오류 화면 3종·`error_config` 포함.
     *
     * @param  string  $identifier  식별자
     * @param  string|null  $declarationJson  선언 파일 원문 (null 이면 기본 선언)
     * @return void 반환값 없음
     */
    private function makeInstallableFiles(string $identifier, ?string $declarationJson = null): void
    {
        $this->makeSettingsTemplate($identifier, 'user', 'inactive');
        Template::where('identifier', $identifier)->delete();

        $root = base_path('templates/'.$identifier);
        $manifest = json_decode(File::get($root.'/template.json'), true);
        $manifest['error_config'] = ['layouts' => ['404' => 'errors/404', '403' => 'errors/403', '500' => 'errors/500']];
        File::put($root.'/template.json', json_encode($manifest));
        File::ensureDirectoryExists($root.'/layouts/errors');

        foreach (['404', '403', '500'] as $code) {
            File::put($root.'/layouts/errors/'.$code.'.json', json_encode([
                'version' => '1.0.0',
                'layout_name' => 'errors/'.$code,
                'meta' => ['title' => $code],
                'components' => [['id' => 'qa434_err_'.$code, 'type' => 'basic', 'name' => 'Div', 'props' => []]],
            ]));
        }

        if ($declarationJson !== null) {
            File::put($root.'/config/settings/defaults.json', $declarationJson);
        }
    }

    /**
     * 활성 템플릿 디렉토리를 번들 디렉토리로 복사해 새 판(1.1.0)을 만듭니다 — 업데이트 진입점의 소스.
     *
     * @param  string  $identifier  식별자
     * @param  \Closure|null  $change  번들 경로를 받아 새 판 파일을 고치는 함수
     * @return void 반환값 없음
     */
    private function stageBundledVersion(string $identifier, ?\Closure $change = null): void
    {
        $bundled = base_path('templates/_bundled/'.$identifier);
        $this->bundledPaths[] = $bundled;
        File::copyDirectory(base_path('templates/'.$identifier), $bundled);

        $manifest = json_decode(File::get($bundled.'/template.json'), true);
        $manifest['version'] = '1.1.0';
        File::put($bundled.'/template.json', json_encode($manifest));

        if ($change !== null) {
            $change($bundled);
        }
    }

    /**
     * 설치 진입점이 직접 쓴 설정 화면을 켜진 관리자 템플릿에 교차 등록한다(꺼진 채 설치돼도).
     *
     * @scenario case=registration
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_install_entry_point_registers_settings_layout(): void
    {
        $host = $this->makeHost();
        $this->makeInstallableFiles('qa434-user');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();

        $this->assertTrue($manager->installTemplate('qa434-user'));

        $this->assertSame('inactive', Template::where('identifier', 'qa434-user')->value('status'));
        $row = TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->first();
        $this->assertNotNull($row, '설치 진입점이 설정 화면 행을 등록해야 한다');
        $this->assertSame('qa434-user', $row->source_identifier);
    }

    /**
     * 깨진 선언(JSON 오류·모르는 칸 종류)은 설치 진입점을 막는다 — 템플릿 행도, 설정 화면 행도 생기지 않는다.
     *
     * @scenario case=schema
     *
     * @effects invalid_schema_rejected
     *
     * @return void 반환값 없음
     */
    public function test_install_entry_point_rejects_invalid_declaration(): void
    {
        $host = $this->makeHost();
        $broken = $this->defaultDeclaration();
        $broken['schema']['display']['show_banner']['type'] = 'slider';

        foreach (['qa434-badjson' => '{"_meta": {', 'qa434-badtype' => json_encode($broken)] as $identifier => $json) {
            $this->makeInstallableFiles($identifier, $json);
            $manager = app(TemplateManager::class);
            $manager->loadTemplates();

            try {
                $manager->installTemplate($identifier);
                $this->fail($identifier.' — 깨진 선언인데 설치가 통과했다');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }

            $this->assertFalse(Template::where('identifier', $identifier)->exists(), $identifier.' 행이 남지 않는다');
            $this->assertFalse(TemplateLayout::where('template_id', $host->id)->where('name', $identifier.'.template_settings')->exists());
        }
    }

    /**
     * 꺼진 템플릿의 업데이트 진입점(keep 전략)은 설정 화면 행을 새 판으로 다시 등록한다.
     *
     * @scenario case=registration
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_update_entry_point_reregisters_settings_layout(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');
        TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->forceDelete();

        $this->stageBundledVersion('qa434-user');
        $result = $manager->updateTemplate('qa434-user', true, null, 'keep', 'bundled');

        $this->assertTrue($result['success']);
        $this->assertSame('1.1.0', Template::where('identifier', 'qa434-user')->value('version'));
        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists(), '업데이트가 설정 화면 행을 다시 등록해야 한다');
    }

    /**
     * 업데이트 진입점은 새 판의 깨진 선언을 막는다 — 실패로 끝나고 판·파일은 옛 판으로 돌아간다.
     *
     * @scenario case=schema
     *
     * @effects invalid_schema_rejected
     *
     * @return void 반환값 없음
     */
    public function test_update_entry_point_rejects_invalid_declaration(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();

        $this->stageBundledVersion('qa434-user', function (string $bundled) {
            $broken = $this->defaultDeclaration();
            $broken['schema']['display']['show_banner']['type'] = 'slider';
            File::put($bundled.'/config/settings/defaults.json', json_encode($broken));
        });

        $failed = false;

        try {
            $result = $manager->updateTemplate('qa434-user', true, null, 'keep', 'bundled');
            $failed = ! ($result['success'] ?? false);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue($failed, '새 판 선언이 깨졌으면 업데이트가 실패해야 한다');
        $this->assertSame('1.0.0', Template::where('identifier', 'qa434-user')->value('version'));
        $this->assertStringNotContainsString('slider', File::get(base_path('templates/qa434-user/config/settings/defaults.json')), '파일은 옛 판으로 복원된다');
    }

    /**
     * 새 판에서 번들 `settings/` 화면이 사라지면 업데이트가 교차 등록 행을 지운다.
     *
     * @scenario case=registration
     *
     * @effects rows_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_update_removes_rows_when_settings_screen_disappears(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');
        $this->assertTrue(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());

        $this->stageBundledVersion('qa434-user', fn (string $bundled) => File::deleteDirectory($bundled.'/settings'));
        $result = $manager->updateTemplate('qa434-user', true, null, 'keep', 'bundled');

        $this->assertTrue($result['success']);
        $this->assertDirectoryDoesNotExist(base_path('templates/qa434-user/settings'));
        $this->assertFalse(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists(), '사라진 설정 화면 행은 지워져야 한다');
    }

    /**
     * 「데이터도 삭제」 를 고르지 않은 삭제는 교차 등록 행만 지우고 설정 파일·설정 첨부는 남긴다.
     *
     * @scenario case=uninstall
     *
     * @effects rows_removed_on_uninstall
     *
     * @return void 반환값 없음
     */
    public function test_uninstall_without_delete_data_keeps_settings_and_attachments(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');
        $favicon = $this->makeFaviconAttachment('template_settings.qa434-user.branding.favicon');
        app(TemplateSettingsService::class)->save('qa434-user', ['branding' => ['favicon' => [$favicon->id]]]);
        $settingsFile = \App\Support\ExtensionStoragePath::template('qa434-user', 'settings').'/branding.json';
        $this->assertFileExists($settingsFile);

        $this->assertTrue($manager->uninstallTemplate('qa434-user', deleteData: false));

        $this->assertFalse(TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->exists());
        $this->assertFileExists($settingsFile, '설정 파일은 남는다');
        $this->assertNotNull(\App\Models\Attachment::find($favicon->id), '설정 첨부는 남는다');
    }

    /**
     * 설치되지 않은 템플릿(디렉토리·설정 화면은 있으나 DB 행 없음)은 설정 화면 라우트를 만들지 않는다.
     *
     * @scenario case=routes
     *
     * @effects settings_route_generated
     *
     * @return void 반환값 없음
     */
    public function test_settings_route_excludes_uninstalled_template(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $this->makeSettingsTemplate('qa434-gone', 'user', 'inactive');
        Template::where('identifier', 'qa434-gone')->delete();
        File::put(base_path('templates/qa434-host/routes.json'), json_encode(['version' => '1.0.0', 'routes' => []]));
        app(TemplateSettingsService::class)->clearCache();

        $routes = collect(app(TemplateService::class)->getRoutesDataWithModules('qa434-host')['data']['routes'] ?? []);

        $this->assertNotNull($routes->firstWhere('path', '*/admin/templates/qa434-user/settings'), '설치된 템플릿은 라우트가 있다');
        $this->assertNull($routes->firstWhere('path', '*/admin/templates/qa434-gone/settings'), '미설치 템플릿은 라우트가 없다');
    }

    /**
     * 편집 모드로 교차 등록 설정 화면을 읽으면 데이터소스 출처가 플러그인이 아니라 template(소유 템플릿)이다.
     *
     * @scenario case=registration
     *
     * @effects settings_layout_registered_into_active_admin
     *
     * @return void 반환값 없음
     */
    public function test_cross_row_data_source_meta_is_template_kind(): void
    {
        $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        File::put(base_path('templates/qa434-user/settings/template_settings.json'), json_encode([
            'version' => '1.0.0',
            'layout_name' => 'template_settings',
            'meta' => ['title' => 'qa434'],
            'data_sources' => [['id' => 'qa434_settings', 'type' => 'api', 'endpoint' => '/api/admin/templates/qa434-user/settings', 'method' => 'GET', 'auto_fetch' => true]],
            'components' => [['id' => 'qa434_root', 'type' => 'basic', 'name' => 'Div', 'props' => []]],
        ]));
        app(TemplateManager::class)->registerSettingsLayoutsForAllInstalled('qa434-host');

        $layout = app(LayoutService::class)->getLayout('qa434-host', 'qa434-user.template_settings', true);

        $this->assertSame(['kind' => 'template', 'identifier' => 'qa434-user'], $layout['data_sources'][0]['__source'] ?? null);
    }

    /**
     * keep 전략 업데이트는 운영자가 고친 교차 등록 행 내용을 지키고, 운영자가 손대지 않은 부분만 새 판을 들인다(3자 병합).
     *
     * @scenario case=registration
     *
     * @effects cross_rows_survive_admin_refresh
     *
     * @return void 반환값 없음
     */
    public function test_update_keep_preserves_operator_edits_on_settings_row(): void
    {
        $host = $this->makeHost();
        $this->makeSettingsTemplate('qa434-user', 'user', 'inactive');
        $manager = app(TemplateManager::class);
        $manager->loadTemplates();
        $manager->registerSettingsLayoutsForAllInstalled('qa434-host');

        // 운영자 편집 — 행 본문만 바뀌고 원본(original_content)은 그대로다
        $row = TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->firstOrFail();
        $content = $row->content;
        $content['meta']['title'] = '운영자 제목';
        $row->content = $content;
        $row->save();
        $this->assertTrue(app(\App\Extension\Layout\LayoutRowSynchronizer::class)->isOperatorModified($row->fresh()), '전제 — 운영자 수정본으로 판정된다');

        // 새 판은 운영자가 건드리지 않은 칸(클래스)을 바꾼다
        $this->stageBundledVersion('qa434-user', function (string $bundled) {
            $layout = json_decode(File::get($bundled.'/settings/template_settings.json'), true);
            $layout['slots']['content'][0]['props']['className'] = 'p-8';
            File::put($bundled.'/settings/template_settings.json', json_encode($layout));
        });

        $result = $manager->updateTemplate('qa434-user', true, null, 'keep', 'bundled');
        $this->assertTrue($result['success']);

        $content = TemplateLayout::where('template_id', $host->id)->where('name', 'qa434-user.template_settings')->firstOrFail()->content;
        $this->assertSame('운영자 제목', $content['meta']['title'] ?? null, 'keep 업데이트가 운영자 편집을 덮으면 안 된다');
        $this->assertSame('p-8', $content['slots']['content'][0]['props']['className'] ?? null, '운영자가 손대지 않은 칸은 새 판으로 바뀐다');
    }
}
