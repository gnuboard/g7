<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\ExtensionManager;
use App\Extension\Helpers\ExtensionPendingHelper;
use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Extension\Vendor\VendorMode;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\Template;
use App\Models\User;
use App\Services\ModuleService;
use App\Support\RouteCacheHelper;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\Concerns\BuildsLayoutMergeFixtures;
use Tests\TestCase;

/**
 * 확장 업데이트가 새 판 manifest 의 선언 형식 오류를 생명주기 경고로 알리는지 (코어 7.1.5)
 *
 * 결함(수정 전): 모듈·플러그인의 최근 검색어 범위 선언(`search_scopes`)과 템플릿의 필수 저장 키 선언(`necessary_storage`)은
 * 설치·활성화 때만 형식을 검사해 틀린 항목을 결과 창·CLI 경고로 알렸다. 업데이트(관리자 업데이트 창·`module:update`·
 * `plugin:update`·`template:update`·코어 업데이트의 번들 일괄 업데이트)로 새 판이 틀린 선언을 가져오면 로그에만 남고
 * 운영자·개발자에게 보이지 않았다. 이제 업데이트가 끝난 뒤 새 판 선언을 같은 함수로 검사해 결과 `warnings` 에 싣는다.
 *
 * 실제 업데이트 경로(백업 → 번들 스테이징 → 파일 교체 → 재로드 → DB 갱신 → 레이아웃 갱신)를 가짜 확장(`testmerge-*`)으로
 * 돌린다. 오토로드·훅 캐시 재생성은 실제 파일을 건드리므로 확장 관리자를 목으로, 라우트 캐시 재생성은 미뤄 둔다.
 */
class UpdateManifestDeclarationWarningTest extends TestCase
{
    use BuildsLayoutMergeFixtures;
    use DatabaseTransactions;

    /**
     * 틀린 항목이 섞인 최근 검색어 범위 선언 — 이름 형식이 틀린 범위 하나, 보관 개수가 범위 밖인 범위 하나, 정상 범위 하나.
     *
     * @var array<string, array<string, mixed>>
     */
    private const INVALID_SEARCH_SCOPES = [
        'rates' => ['max_count' => 5],
        'Bad Name!' => ['max_count' => 5],
        'big' => ['max_count' => 999],
    ];

    /**
     * 테스트 준비 — 라우트 캐시 재생성을 미룬다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setRouteCacheDeferDepth(1);
    }

    /**
     * 픽스처 디스크 산출물을 지우고 라우트 캐시 미룸을 푼다(재생성은 하지 않는다).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->cleanupLayoutMergeFixtures();
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
     * 활성 디렉토리를 번들 디렉토리로 복사하고 매니페스트를 1.1.0 판으로 올리며 선언을 덧붙입니다.
     *
     * @param  string  $type  확장 종류 디렉토리 (modules|plugins|templates)
     * @param  string  $identifier  확장 식별자
     * @param  string  $manifestFile  매니페스트 파일명
     * @param  array<string, mixed>  $extra  새 판 매니페스트에 덧붙일 키
     * @return void 반환값 없음
     */
    private function stageBundledVersion(string $type, string $identifier, string $manifestFile, array $extra): void
    {
        $bundled = base_path("{$type}/_bundled/{$identifier}");
        $this->fixturePaths[] = $bundled;
        FilePermissionHelper::copyDirectory(base_path("{$type}/{$identifier}"), $bundled, null, ExtensionPendingHelper::EXCLUDED_DIRECTORIES);

        $manifest = json_decode(File::get("{$bundled}/{$manifestFile}"), true);
        $manifest = [...$manifest, ...$extra, 'version' => '1.1.0'];
        File::put("{$bundled}/{$manifestFile}", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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
        $this->app->forgetInstance(ModuleService::class);
    }

    /**
     * 경고 목록에서 단계가 일치하는 항목만 고릅니다.
     *
     * @param  array<int, array<string, mixed>>  $warnings  생명주기 경고 목록
     * @param  string  $step  단계
     * @return array<int, array<string, mixed>> 일치 항목
     */
    private function warningsOf(array $warnings, string $step): array
    {
        return array_values(array_filter($warnings, fn (array $w) => ($w['step'] ?? null) === $step));
    }

    /**
     * 선언 형식 오류 경고 한 건의 모양을 단언합니다 — 대상은 확장 식별자, 수준 warning, cause=declaration, 사유에 버린 항목.
     *
     * @param  array<int, array<string, mixed>>  $warnings  생명주기 경고 목록
     * @param  string  $step  단계
     * @param  string  $identifier  확장 식별자
     * @param  array<int, string>  $mentions  사유에 있어야 할 버린 항목 원문
     * @return void 반환값 없음
     */
    private function assertDeclarationWarning(array $warnings, string $step, string $identifier, array $mentions): void
    {
        $items = $this->warningsOf($warnings, $step);
        $this->assertCount(1, $items, "업데이트 결과 warnings 에 {$step} 선언 경고가 한 건 있어야 합니다: ".json_encode($warnings, JSON_UNESCAPED_UNICODE));
        $this->assertSame($identifier, $items[0]['target']);
        $this->assertSame(LifecycleWarnings::LEVEL_WARNING, $items[0]['level']);
        $this->assertSame(LifecycleWarnings::CAUSE_DECLARATION, $items[0]['cause'] ?? null, '선언 오류 표지(cause=declaration)가 있어야 결과 창이 개발자 안내를 붙입니다.');
        foreach ($mentions as $mention) {
            $this->assertStringContainsString($mention, $items[0]['message']);
        }
    }

    /**
     * 모듈 업데이트(번들 일괄 업데이트 경로)가 새 판의 틀린 search_scopes 선언을 결과 warnings 에 싣는다.
     *
     * @scenario case=scope_declaration_update_warning
     *
     * @effects invalid_scope_declaration_reported_on_update
     *
     * @return void 반환값 없음
     */
    public function test_module_update_reports_invalid_search_scopes_declaration(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => $this->screen('index', 'A1')]);
        Module::where('identifier', $this->fixtureModule)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled']);
        $this->stageBundledVersion('modules', $this->fixtureModule, 'module.json', ['search_scopes' => self::INVALID_SEARCH_SCOPES]);
        $this->fakeExtensionManager();

        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $result = $manager->updateModule($this->fixtureModule, true, null, VendorMode::Auto, 'overwrite', null, 'bundled');

        $this->assertTrue($result['success']);
        $this->assertSame('1.1.0', $result['to_version']);
        $this->assertDeclarationWarning($result['warnings'], 'search_scopes', $this->fixtureModule, ['Bad Name!', 'big']);
    }

    /**
     * 플러그인 업데이트가 새 판의 틀린 search_scopes 선언을 결과 warnings 에 싣는다.
     *
     * @scenario case=scope_declaration_update_warning
     *
     * @effects invalid_scope_declaration_reported_on_update
     *
     * @return void 반환값 없음
     */
    public function test_plugin_update_reports_invalid_search_scopes_declaration(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixturePlugin(['settings.json' => $this->screen('settings', 'P1')]);
        Plugin::where('identifier', $this->fixturePlugin)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled']);
        $this->stageBundledVersion('plugins', $this->fixturePlugin, 'plugin.json', ['search_scopes' => self::INVALID_SEARCH_SCOPES]);
        $this->fakeExtensionManager();

        $manager = app(PluginManager::class);
        $manager->loadPlugins();
        $result = $manager->updatePlugin($this->fixturePlugin, true, null, VendorMode::Auto, 'overwrite', null, 'bundled');

        $this->assertTrue($result['success']);
        $this->assertDeclarationWarning($result['warnings'], 'search_scopes', $this->fixturePlugin, ['Bad Name!', 'big']);
    }

    /**
     * 새 판 선언이 정상이면 업데이트 결과에 search_scopes 경고가 없다 — 검사가 잘못 울리지 않는다.
     *
     * @scenario case=scope_declaration_update_warning
     *
     * @effects valid_scope_declaration_silent_on_update
     *
     * @return void 반환값 없음
     */
    public function test_update_with_valid_search_scopes_declaration_reports_nothing(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => $this->screen('index', 'A1')]);
        Module::where('identifier', $this->fixtureModule)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled']);
        $this->stageBundledVersion('modules', $this->fixtureModule, 'module.json', ['search_scopes' => ['rates' => ['max_count' => 5]]]);
        $this->fakeExtensionManager();

        $manager = app(ModuleManager::class);
        $manager->loadModules();
        $result = $manager->updateModule($this->fixtureModule, true, null, VendorMode::Auto, 'overwrite', null, 'bundled');

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->warningsOf($result['warnings'], 'search_scopes'));
    }

    /**
     * 관리자 업데이트 API 응답 data.warnings 에 같은 선언 경고가 실린다 — 관리자 결과 창이 그리는 자리.
     *
     * @scenario case=scope_declaration_update_warning
     *
     * @effects invalid_scope_declaration_reported_on_update
     *
     * @return void 반환값 없음
     */
    public function test_admin_update_response_carries_search_scopes_declaration_warning(): void
    {
        $this->makeFixtureTemplate();
        $this->makeFixtureModule(['index.json' => $this->screen('index', 'A1')]);
        Module::where('identifier', $this->fixtureModule)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled']);
        $this->stageBundledVersion('modules', $this->fixtureModule, 'module.json', ['search_scopes' => self::INVALID_SEARCH_SCOPES]);
        $this->fakeExtensionManager();

        $token = $this->createAdminUser(['core.modules.read', 'core.modules.install'])->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson("/api/admin/modules/{$this->fixtureModule}/update", ['layout_strategy' => 'overwrite']);

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertDeclarationWarning((array) $response->json('data.warnings'), 'search_scopes', $this->fixtureModule, ['Bad Name!', 'big']);
    }

    /**
     * 템플릿 업데이트가 새 판의 틀린 necessary_storage 선언을 결과 warnings 에 싣는다.
     *
     * @scenario declaration=invalid_items, template_state=active
     *
     * @effects invalid_declaration_reported_on_update
     *
     * @return void 반환값 없음
     */
    public function test_template_update_reports_invalid_necessary_storage_declaration(): void
    {
        $this->makeFixtureTemplate(['home.json' => $this->screen('home', 'HOME1')]);
        $this->writeFixtureTemplateManifest();
        Template::where('identifier', $this->fixtureTemplate)->update(['latest_version' => '1.1.0', 'update_available' => true, 'update_source' => 'bundled']);
        $this->stageBundledVersion('templates', $this->fixtureTemplate, 'template.json', [
            'necessary_storage' => ['localStorage' => ['g7_ok', 'bad key!'], 'indexedDB' => ['x']],
        ]);

        $manager = app(TemplateManager::class);
        $manager->loadTemplates();
        $result = $manager->updateTemplate($this->fixtureTemplate, true, null, 'overwrite', 'bundled');

        $this->assertTrue($result['success']);
        $this->assertDeclarationWarning($result['warnings'], 'necessary_storage', $this->fixtureTemplate, ['bad key!', 'indexedDB']);
    }

    /**
     * 관리자 업데이트 권한을 가진 사용자를 만듭니다.
     *
     * @param  array<int, string>  $permissions  권한 식별자 목록
     * @return User 관리자 사용자
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }
}
