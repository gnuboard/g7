<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use App\Seo\Editor\SeoCandidateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 편집기·관리 서비스의 설치본 전용 읽기 회귀 테스트
 *
 * 확장·템플릿은 배포 원본(`{templates|modules|plugins}/_bundled/{id}`)과 설치본
 * (`{templates|modules|plugins}/{id}`) 두 곳에 있다. 편집기·관리 화면은 실제로 돌아가는
 * 설치본만 읽어야 한다 — 설치본에 파일이 없을 때 배포 원본을 대신 읽으면 사이트에서
 * 돌아가는 것과 다른 내용이 편집기에 보인다.
 *
 * 각 테스트는 고유 식별자로 설치본과 배포 원본 두 벌의 픽스처를 만들고, 두 벌에 서로 다른
 * 표식 문자열을 넣어 어느 쪽을 읽었는지 판별한다. 설치본이 있으면 설치본 표식이, 설치본이
 * 없으면 배포 원본 표식이 절대 나오지 않아야 한다. 픽스처는 tearDown 에서 지운다.
 */
class InstalledCopyOnlyReadsTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 설치본 내용에만 들어가는 표식 */
    private const INSTALLED_MARK = 'InstalledCopyMark';

    /** @var string 배포 원본 내용에만 들어가는 표식 */
    private const BUNDLED_MARK = 'BundledOriginalMark';

    private string $templateId;

    private string $moduleId;

    private string $pluginId;

    private string $adminToken;

    /** @var list<string> tearDown 에서 지울 픽스처 디렉토리 */
    private array $fixtureDirs = [];

    /**
     * 고유 식별자를 정하고 레이아웃 편집 권한을 가진 관리자 토큰을 만든다.
     *
     * 식별자에 난수 접미사를 붙여 편집기 CSS 캐시 키(식별자+mtime)가 테스트끼리 겹치지 않게 한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $this->templateId = 'g7test-instonly_tpl'.$suffix;
        $this->moduleId = 'g7test-instonly_mod'.$suffix;
        $this->pluginId = 'g7test-instonly_plg'.$suffix;

        $editPermission = Permission::firstOrCreate([
            'identifier' => 'core.templates.layouts.edit',
        ], [
            'name' => '레이아웃 편집',
            'display_name' => '레이아웃 편집',
            'type' => 'admin',
        ]);

        $role = Role::firstOrCreate(['identifier' => 'super-admin'], [
            'name' => 'Super Admin',
            'display_name' => 'Super Admin',
            'is_default' => false,
        ]);
        $role->permissions()->syncWithoutDetaching([$editPermission->id]);

        $admin = User::factory()->create();
        $admin->roles()->syncWithoutDetaching([$role->id]);
        $this->adminToken = $admin->createToken('admin')->plainTextToken;
    }

    /**
     * 테스트가 만든 설치본·배포 원본 픽스처 디렉토리를 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ($this->fixtureDirs as $dir) {
            if (File::isDirectory($dir)) {
                File::deleteDirectory($dir);
            }
        }
        $this->fixtureDirs = [];

        parent::tearDown();
    }

    /**
     * 편집기 components.json — 설치본이 있으면 배포 원본이 함께 있어도 설치본 내용을 준다.
     *
     * @scenario read_site=template_editor_components, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_editor_components_serves_installed_copy(): void
    {
        $this->writeTemplateFile('installed', 'components.json', json_encode(['Mark' => ['label' => self::INSTALLED_MARK]]));
        $this->writeTemplateFile('bundled', 'components.json', json_encode(['Mark' => ['label' => self::BUNDLED_MARK]]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/components.json");

        $response->assertOk();
        $response->assertJsonPath('data.Mark.label', self::INSTALLED_MARK);
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * 편집기 components.json — 설치본에 없으면 배포 원본을 읽지 않고 재업데이트 안내와 함께 404.
     *
     * @scenario read_site=template_editor_components, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_required_file_returns_404_with_update_guidance
     *
     * @return void 반환값 없음
     */
    public function test_editor_components_does_not_fall_back_to_bundled(): void
    {
        $this->writeTemplateFile('installed', 'template.json', json_encode(['identifier' => $this->templateId, 'type' => 'user']));
        $this->writeTemplateFile('bundled', 'components.json', json_encode(['Mark' => ['label' => self::BUNDLED_MARK]]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/components.json");

        $response->assertStatus(404);
        $response->assertJsonPath('message', __('templates.messages.installed_file_missing', ['file' => 'components.json']));
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * 편집기 routes.json — 설치본 라우트를 source 태깅해 준다(배포 원본 라우트는 섞이지 않음).
     *
     * @scenario read_site=template_editor_routes, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_editor_routes_serves_installed_copy(): void
    {
        $this->writeTemplateFile('installed', 'routes.json', json_encode([
            'version' => '1.0.0',
            'routes' => [['path' => '/installed', 'layout' => self::INSTALLED_MARK]],
        ]));
        $this->writeTemplateFile('bundled', 'routes.json', json_encode([
            'version' => '1.0.0',
            'routes' => [['path' => '/bundled', 'layout' => self::BUNDLED_MARK]],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/routes.json");

        $response->assertOk();
        $layouts = array_column($response->json('data.routes'), 'layout');
        $this->assertContains(self::INSTALLED_MARK, $layouts);
        $this->assertNotContains(self::BUNDLED_MARK, $layouts);
    }

    /**
     * 편집기 routes.json — 설치본에 없으면 배포 원본을 읽지 않고 재업데이트 안내와 함께 404.
     *
     * @scenario read_site=template_editor_routes, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_required_file_returns_404_with_update_guidance
     *
     * @return void 반환값 없음
     */
    public function test_editor_routes_does_not_fall_back_to_bundled(): void
    {
        $this->writeTemplateFile('installed', 'template.json', json_encode(['identifier' => $this->templateId, 'type' => 'user']));
        $this->writeTemplateFile('bundled', 'routes.json', json_encode([
            'version' => '1.0.0',
            'routes' => [['path' => '/bundled', 'layout' => self::BUNDLED_MARK]],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/routes.json");

        $response->assertStatus(404);
        $response->assertJsonPath('message', __('templates.messages.installed_file_missing', ['file' => 'routes.json']));
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * 편집기 라우트 트리의 공통 레이아웃 — 설치본 layouts/ 의 base 레이아웃을 수집한다.
     *
     * @scenario read_site=template_editor_layout_tree, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_editor_layout_tree_collects_installed_layouts(): void
    {
        $this->writeTemplateFile('installed', 'routes.json', json_encode(['version' => '1.0.0', 'routes' => []]));
        $this->writeTemplateFile('installed', 'layouts/installed_base.json', json_encode([
            'layout_name' => 'installed_base',
            'meta' => ['is_base' => true, 'editor_label' => self::INSTALLED_MARK],
        ]));
        $this->writeTemplateFile('bundled', 'layouts/bundled_base.json', json_encode([
            'layout_name' => 'bundled_base',
            'meta' => ['is_base' => true, 'editor_label' => self::BUNDLED_MARK],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/routes.json");

        $response->assertOk();
        $names = array_column($response->json('data.base_layouts'), 'layout_name');
        $this->assertContains('installed_base', $names);
        $this->assertNotContains('bundled_base', $names);
    }

    /**
     * 편집기 라우트 트리의 공통 레이아웃 — 설치본에 layouts/ 가 없으면 배포 원본을 순회하지 않는다.
     *
     * @scenario read_site=template_editor_layout_tree, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_editor_layout_tree_does_not_fall_back_to_bundled(): void
    {
        $this->writeTemplateFile('installed', 'routes.json', json_encode(['version' => '1.0.0', 'routes' => []]));
        $this->writeTemplateFile('bundled', 'layouts/bundled_base.json', json_encode([
            'layout_name' => 'bundled_base',
            'meta' => ['is_base' => true, 'editor_label' => self::BUNDLED_MARK],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/routes.json");

        $response->assertOk();
        $this->assertSame([], $response->json('data.base_layouts'));
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * 편집기 자산 매니페스트 — 설치본 빌드가 있으면 manifest_present=true, 출처 필드는 없다.
     *
     * @scenario read_site=template_editor_assets, file_state=installed_present
     *
     * @effects installed_file_served, editor_assets_response_omits_manifest_source
     *
     * @return void 반환값 없음
     */
    public function test_editor_assets_reports_installed_build(): void
    {
        $this->writeTemplateFile('installed', 'dist/js/components.iife.js', '/* '.self::INSTALLED_MARK.' */');
        $this->writeTemplateFile('installed', 'dist/css/components.css', '.x{color:red}');

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor-assets");

        $response->assertOk();
        $response->assertJsonPath('data.manifest_present', true);
        $this->assertNotEmpty($response->json('data.js'));
        $this->assertNotEmpty($response->json('data.css'));
        $this->assertArrayNotHasKey('manifest_source', $response->json('data'));
    }

    /**
     * 편집기 자산 매니페스트 — 설치본 빌드가 없으면 배포 원본 빌드가 있어도 manifest_present=false.
     *
     * @scenario read_site=template_editor_assets, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_required_file_returns_update_guidance_message
     *
     * @return void 반환값 없음
     */
    public function test_editor_assets_does_not_fall_back_to_bundled_build(): void
    {
        $this->writeTemplateFile('installed', 'template.json', json_encode(['identifier' => $this->templateId, 'type' => 'user']));
        $this->writeTemplateFile('bundled', 'dist/js/components.iife.js', '/* '.self::BUNDLED_MARK.' */');
        $this->writeTemplateFile('bundled', 'dist/css/components.css', '.x{color:red}');

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor-assets");

        $response->assertOk();
        $response->assertJsonPath('data.manifest_present', false);
        $response->assertJsonPath('data.js', []);
        $response->assertJsonPath('data.css', []);
        $response->assertJsonPath('message', __('templates.messages.editor_assets_missing'));
    }

    /**
     * 편집기 프리뷰 CSS — 설치본 CSS 를 서빙한다.
     *
     * @scenario read_site=template_editor_css, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_editor_css_serves_installed_copy(): void
    {
        $this->writeTemplateFile('installed', 'dist/css/components.css', '.'.self::INSTALLED_MARK.'{color:red}');
        $this->writeTemplateFile('bundled', 'dist/css/components.css', '.'.self::BUNDLED_MARK.'{color:blue}');

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/component-styles.css", 'text/css');

        $response->assertOk();
        $css = (string) $response->getContent();
        $this->assertStringContainsString(self::INSTALLED_MARK, $css);
        $this->assertStringNotContainsString(self::BUNDLED_MARK, $css);
    }

    /**
     * 편집기 프리뷰 CSS — 설치본 CSS 가 없으면 배포 원본 CSS 대신 빈 본문 200.
     *
     * @scenario read_site=template_editor_css, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_editor_css_does_not_fall_back_to_bundled(): void
    {
        $this->writeTemplateFile('installed', 'template.json', json_encode(['identifier' => $this->templateId, 'type' => 'user']));
        $this->writeTemplateFile('bundled', 'dist/css/components.css', '.'.self::BUNDLED_MARK.'{color:blue}');

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/component-styles.css", 'text/css');

        $response->assertOk();
        $this->assertSame('', (string) $response->getContent());
    }

    /**
     * 프리뷰 다크 격리 규칙 — 설치본 editor-spec 의 previewIsolation 으로 CSS 를 치환한다.
     *
     * @scenario read_site=template_editor_dark_isolation, file_state=installed_present
     *
     * @effects installed_file_served
     *
     * @return void 반환값 없음
     */
    public function test_editor_css_dark_isolation_uses_installed_spec(): void
    {
        $this->writeTemplateFile('installed', 'dist/css/components.css', '.dark .card{color:red}');
        $this->writeTemplateFile('installed', 'editor-spec.json', json_encode([
            'darkMode' => ['previewIsolation' => ['rewriteSelector' => '.dark', 'replaceWith' => '.g7le-preview-dark']],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/component-styles.css", 'text/css');

        $response->assertOk();
        $this->assertStringContainsString('.g7le-preview-dark .card', (string) $response->getContent());
    }

    /**
     * 프리뷰 다크 격리 규칙 — 설치본 editor-spec 이 없으면 배포 원본의 규칙을 쓰지 않는다(원본 CSS 그대로).
     *
     * @scenario read_site=template_editor_dark_isolation, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_editor_css_dark_isolation_ignores_bundled_spec(): void
    {
        $this->writeTemplateFile('installed', 'dist/css/components.css', '.dark .card{color:red}');
        $this->writeTemplateFile('bundled', 'editor-spec.json', json_encode([
            'darkMode' => ['previewIsolation' => ['rewriteSelector' => '.dark', 'replaceWith' => '.g7le-preview-dark']],
        ]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/component-styles.css", 'text/css');

        $response->assertOk();
        $css = (string) $response->getContent();
        $this->assertStringContainsString('.dark .card', $css);
        $this->assertStringNotContainsString('.g7le-preview-dark', $css);
    }

    /**
     * 편집기 다국어 — 설치본 lang/{locale}.json 을 준다.
     *
     * @scenario read_site=template_editor_language, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_editor_language_serves_installed_copy(): void
    {
        $this->writeTemplateFile('installed', 'lang/ko.json', json_encode(['mark' => self::INSTALLED_MARK]));
        $this->writeTemplateFile('bundled', 'lang/ko.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/lang/ko.json");

        $response->assertOk();
        $response->assertJsonPath('data.mark', self::INSTALLED_MARK);
    }

    /**
     * 편집기 다국어 — 설치본에 로케일 파일이 없으면 배포 원본 대신 빈 객체.
     *
     * @scenario read_site=template_editor_language, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_editor_language_does_not_fall_back_to_bundled(): void
    {
        $this->writeTemplateFile('installed', 'template.json', json_encode(['identifier' => $this->templateId, 'type' => 'user']));
        $this->writeTemplateFile('bundled', 'lang/ko.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->adminGet("/api/admin/templates/{$this->templateId}/editor/lang/ko.json");

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('message', __('templates.messages.language_empty'));
    }

    /**
     * 모듈 components.json 공개 서빙 — 활성 모듈의 설치본 매니페스트를 준다.
     *
     * @scenario read_site=module_components, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_module_components_serves_installed_copy(): void
    {
        $this->createActiveModule();
        $this->writeExtensionFile('modules', 'installed', $this->moduleId, 'components.json', json_encode(['mark' => self::INSTALLED_MARK]));
        $this->writeExtensionFile('modules', 'bundled', $this->moduleId, 'components.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->getJson("/api/modules/{$this->moduleId}/components.json");

        $response->assertOk();
        $response->assertJsonPath('mark', self::INSTALLED_MARK);
    }

    /**
     * 모듈 components.json 공개 서빙 — 설치본에 없으면 배포 원본 대신 빈 매니페스트.
     *
     * @scenario read_site=module_components, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_module_components_does_not_fall_back_to_bundled(): void
    {
        $this->createActiveModule();
        $this->writeExtensionFile('modules', 'installed', $this->moduleId, 'module.json', json_encode(['identifier' => $this->moduleId]));
        $this->writeExtensionFile('modules', 'bundled', $this->moduleId, 'components.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->getJson("/api/modules/{$this->moduleId}/components.json");

        $response->assertOk();
        $this->assertSame([], $response->json());
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * 플러그인 components.json 공개 서빙 — 활성 플러그인의 설치본 매니페스트를 준다.
     *
     * @scenario read_site=plugin_components, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_plugin_components_serves_installed_copy(): void
    {
        $this->createActivePlugin();
        $this->writeExtensionFile('plugins', 'installed', $this->pluginId, 'components.json', json_encode(['mark' => self::INSTALLED_MARK]));
        $this->writeExtensionFile('plugins', 'bundled', $this->pluginId, 'components.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->getJson("/api/plugins/{$this->pluginId}/components.json");

        $response->assertOk();
        $response->assertJsonPath('mark', self::INSTALLED_MARK);
    }

    /**
     * 플러그인 components.json 공개 서빙 — 설치본에 없으면 배포 원본 대신 빈 매니페스트.
     *
     * @scenario read_site=plugin_components, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_plugin_components_does_not_fall_back_to_bundled(): void
    {
        $this->createActivePlugin();
        $this->writeExtensionFile('plugins', 'installed', $this->pluginId, 'plugin.json', json_encode(['identifier' => $this->pluginId]));
        $this->writeExtensionFile('plugins', 'bundled', $this->pluginId, 'components.json', json_encode(['mark' => self::BUNDLED_MARK]));

        $response = $this->getJson("/api/plugins/{$this->pluginId}/components.json");

        $response->assertOk();
        $this->assertSame([], $response->json());
        $this->assertStringNotContainsString(self::BUNDLED_MARK, (string) $response->getContent());
    }

    /**
     * SEO 후보의 토글 설정 기본값 — 설치본 defaults.json 의 defaults.seo 를 읽는다.
     *
     * @scenario read_site=seo_defaults, file_state=installed_present
     *
     * @effects installed_file_served, bundled_copy_never_read
     *
     * @return void 반환값 없음
     */
    public function test_seo_defaults_read_installed_copy(): void
    {
        $this->writeExtensionFile('modules', 'installed', $this->moduleId, 'config/settings/defaults.json', json_encode([
            'defaults' => ['seo' => ['installed_toggle' => true]],
        ]));
        $this->writeExtensionFile('modules', 'bundled', $this->moduleId, 'config/settings/defaults.json', json_encode([
            'defaults' => ['seo' => ['bundled_toggle' => true]],
        ]));

        $this->assertSame(['installed_toggle' => true], $this->readSeoDefaults('module', $this->moduleId));
    }

    /**
     * SEO 후보의 토글 설정 기본값 — 설치본에 defaults.json 이 없으면 배포 원본 대신 빈 배열.
     *
     * @scenario read_site=seo_defaults, file_state=installed_missing_bundled_present
     *
     * @effects bundled_copy_never_read, missing_optional_file_treated_as_absent
     *
     * @return void 반환값 없음
     */
    public function test_seo_defaults_do_not_fall_back_to_bundled(): void
    {
        $this->writeExtensionFile('plugins', 'installed', $this->pluginId, 'plugin.json', json_encode(['identifier' => $this->pluginId]));
        $this->writeExtensionFile('plugins', 'bundled', $this->pluginId, 'config/settings/defaults.json', json_encode([
            'defaults' => ['seo' => ['bundled_toggle' => true]],
        ]));

        $this->assertSame([], $this->readSeoDefaults('plugin', $this->pluginId));
    }

    /**
     * 레이아웃 편집 권한을 가진 관리자로 GET 요청을 보낸다.
     *
     * @param  string  $uri  요청 경로
     * @param  string  $accept  Accept 헤더
     * @return TestResponse 응답
     */
    private function adminGet(string $uri, string $accept = 'application/json'): TestResponse
    {
        return $this->withHeaders([
            'Authorization' => "Bearer {$this->adminToken}",
            'Accept' => $accept,
        ])->get($uri);
    }

    /**
     * 템플릿 픽스처 파일을 설치본 또는 배포 원본에 쓴다.
     *
     * @param  string  $copy  `installed`(설치본) 또는 `bundled`(배포 원본)
     * @param  string  $relative  템플릿 루트 기준 상대 경로
     * @param  string  $content  파일 내용
     * @return void 반환값 없음
     */
    private function writeTemplateFile(string $copy, string $relative, string $content): void
    {
        $this->writeExtensionFile('templates', $copy, $this->templateId, $relative, $content);
    }

    /**
     * 확장 픽스처 파일을 설치본 또는 배포 원본에 쓰고 정리 대상에 등록한다.
     *
     * @param  string  $typeDir  확장 유형 디렉토리 (templates|modules|plugins)
     * @param  string  $copy  `installed`(설치본) 또는 `bundled`(배포 원본)
     * @param  string  $identifier  확장 식별자
     * @param  string  $relative  확장 루트 기준 상대 경로
     * @param  string  $content  파일 내용
     * @return void 반환값 없음
     */
    private function writeExtensionFile(string $typeDir, string $copy, string $identifier, string $relative, string $content): void
    {
        $root = $copy === 'bundled'
            ? base_path("{$typeDir}/_bundled/{$identifier}")
            : base_path("{$typeDir}/{$identifier}");

        if (! in_array($root, $this->fixtureDirs, true)) {
            $this->fixtureDirs[] = $root;
        }

        $path = $root.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
    }

    /**
     * 활성 상태의 픽스처 모듈 행을 만든다.
     *
     * @return void 반환값 없음
     */
    private function createActiveModule(): void
    {
        Module::create([
            'identifier' => $this->moduleId,
            'vendor' => 'g7test',
            'name' => ['ko' => '설치본 전용 테스트', 'en' => 'Installed only test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 활성 상태의 픽스처 플러그인 행을 만든다.
     *
     * @return void 반환값 없음
     */
    private function createActivePlugin(): void
    {
        Plugin::create([
            'identifier' => $this->pluginId,
            'vendor' => 'g7test',
            'name' => ['ko' => '설치본 전용 테스트', 'en' => 'Installed only test'],
            'version' => '1.0.0',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * SeoCandidateService 의 SEO 기본값 읽기(비공개)를 직접 호출한다.
     *
     * @param  string  $type  확장 유형 (module 또는 plugin)
     * @param  string  $id  확장 식별자
     * @return array<string, mixed> SEO 기본값 맵
     */
    private function readSeoDefaults(string $type, string $id): array
    {
        $method = new ReflectionMethod(SeoCandidateService::class, 'readSeoDefaults');
        $method->setAccessible(true);

        return $method->invoke(app(SeoCandidateService::class), $type, $id);
    }
}
