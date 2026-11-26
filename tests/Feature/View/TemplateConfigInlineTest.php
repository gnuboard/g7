<?php

namespace Tests\Feature\View;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Extension\Support\LifecycleWarnings;
use App\Extension\TemplateManager;
use App\Models\Template;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * 셸 HTML 의 `#g7-config` 에 템플릿 설정을 template.json `expose` 선언 키만 싣는다.
 *
 * 첫 화면 전 config API 왕복을 없애면서도, 제작자가 선언하지 않은 template.json 값(의존성·외부 자산·저장소 주소 등)은
 * 소스 보기에 드러나지 않게 한다. 선언이 없는 템플릿(제3자 포함)은 싣지 않고 엔진이 종전처럼 API 를 부른다.
 *
 * @scenario-manifest tests/scenarios/template-config-inline.yaml
 */
class TemplateConfigInlineTest extends TestCase
{
    use RefreshDatabase;

    /** @var string 시험 템플릿 식별자 */
    private const FIXTURE = 'test-exposecfg';

    /** @var string 시험 템플릿 디렉터리 */
    private string $fixturePath;

    /**
     * 시험 템플릿 디렉터리를 비우고 경고 수집기를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (! file_exists(public_path('build/core/template-engine.min.js'))) {
            $this->markTestSkipped('Core template engine not built.');
        }

        $this->fixturePath = base_path('templates/'.self::FIXTURE);
        File::deleteDirectory($this->fixturePath);
        LifecycleWarnings::current()->clear();
        TemplateManager::invalidateTemplateStatusCache();
    }

    /**
     * 시험 템플릿 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixturePath);
        TemplateManager::invalidateTemplateStatusCache();

        parent::tearDown();
    }

    /**
     * 사용자 셸에 template.json expose 선언 키만 실리고, 값은 config API 의 같은 키 값이다(없는 키는 null).
     *
     * @scenario shell=user, declaration=declared
     *
     * @effects shell_inlines_only_declared_template_keys, inlined_values_match_config_api, undeclared_template_keys_not_in_shell, config_api_response_shape_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_user_shell_inlines_declared_keys_with_config_api_values(): void
    {
        $this->assertShellInlinesDeclaredKeys('sirsoft-basic', '/');
    }

    /**
     * 관리자 셸에 template.json expose 선언 키만 실리고, 값은 config API 의 같은 키 값이다(없는 키는 null).
     *
     * @scenario shell=admin, declaration=declared
     *
     * @effects shell_inlines_only_declared_template_keys, inlined_values_match_config_api, undeclared_template_keys_not_in_shell, config_api_response_shape_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_admin_shell_inlines_declared_keys_with_config_api_values(): void
    {
        $this->assertShellInlinesDeclaredKeys('sirsoft-admin_basic', '/admin/login');
    }

    /**
     * 번들 템플릿을 설치·활성화하고 셸의 templateConfig 를 선언·config API 와 대조합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  string  $path  셸을 그릴 경로
     * @return void 반환값 없음
     */
    private function assertShellInlinesDeclaredKeys(string $identifier, string $path): void
    {
        $service = app(TemplateService::class);
        $service->installTemplate($identifier);
        $service->activateTemplate(Template::where('identifier', $identifier)->firstOrFail()->id);

        $manifest = json_decode((string) file_get_contents(base_path("templates/{$identifier}/template.json")), true);
        $declared = $manifest['expose'] ?? null;
        $this->assertIsArray($declared, "{$identifier}: template.json 에 expose 선언이 없습니다");
        $this->assertNotEmpty($declared);

        $inline = $this->shellTemplateConfig($path);
        $this->assertIsArray($inline, "{$identifier}: 셸에 templateConfig 가 없습니다");
        $this->assertSame($declared, array_keys($inline), "{$identifier}: 실린 키가 선언과 다릅니다");

        $api = $this->getJson("/api/templates/{$identifier}/config.json");
        $api->assertOk()->assertJsonPath('success', true);
        $apiData = $api->json('data');
        // 응답 형식 불변 — template.json 전체 + cache_version
        $this->assertArrayHasKey('cache_version', $apiData);
        $this->assertArrayHasKey('dependencies', $apiData);
        foreach ($declared as $key) {
            $this->assertSame($apiData[$key] ?? null, $inline[$key], "{$identifier}: {$key} 값이 config API 와 다릅니다");
        }

        foreach (['dependencies', 'externals', 'github_url', 'cache_version', 'expose'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $inline, "{$identifier}: 선언 밖 키 {$hidden} 가 실렸습니다");
        }
    }

    /**
     * expose 가 없는 템플릿은 templateConfig 를 싣지 않고(엔진이 종전처럼 API 를 부른다) 설치 경고도 내지 않는다.
     *
     * @scenario shell=fixture, declaration=absent
     *
     * @effects template_without_expose_not_inlined, template_without_expose_not_warned
     *
     * @return void 반환값 없음
     */
    public function test_template_without_expose_is_not_inlined(): void
    {
        $this->activateFixture(['scroll_restoration' => ['enabled' => true]]);

        $config = $this->shellConfig('/');

        $this->assertIsArray($config);
        $this->assertArrayNotHasKey('templateConfig', $config);
        $this->assertNull(app(TemplateService::class)->getExposedTemplateConfig(self::FIXTURE));
        // expose 를 쓰지 않는 템플릿은 엔진 키(error_config·scroll_restoration)가 있어도 설치 경고를 내지 않는다(종전 동작, 공개 문서 template-basics.md)
        $this->assertSame([], array_values(array_filter(
            LifecycleWarnings::current()->all(),
            fn (array $warning) => ($warning['step'] ?? null) === 'expose',
        )));
    }

    /**
     * 문자열이 아닌 항목·경로 모양·예약 이름은 무시하고, 선언했는데 template.json 에 없는 키는 null 로 싣는다.
     *
     * @scenario shell=fixture, declaration=invalid_items
     *
     * @effects invalid_expose_items_ignored, shell_inlines_only_declared_template_keys
     *
     * @return void 반환값 없음
     */
    public function test_invalid_expose_items_are_ignored(): void
    {
        $this->activateFixture([
            'scroll_restoration' => ['enabled' => false],
            'expose' => ['scroll_restoration', 7, 'cache_version', 'a.b', 'expose', 'not_in_manifest', 'scroll_restoration', ['x']],
        ]);

        $this->assertSame(
            ['scroll_restoration' => ['enabled' => false], 'not_in_manifest' => null],
            $this->shellTemplateConfig('/'),
        );
    }

    /**
     * expose 가 배열이 아니면 경고하고(설치는 그대로), 아무것도 싣지 않는다.
     *
     * @scenario shell=fixture, declaration=not_array
     *
     * @effects expose_not_array_warns_without_blocking
     *
     * @return void 반환값 없음
     */
    public function test_expose_not_array_warns_without_blocking(): void
    {
        $this->translateWarnings();

        $this->activateFixture(['expose' => 'scroll_restoration']);

        $this->assertSame(1, Template::where('identifier', self::FIXTURE)->count());
        $this->assertNull(app(TemplateService::class)->getExposedTemplateConfig(self::FIXTURE));
        $this->assertContains(
            ['step' => 'expose', 'target' => self::FIXTURE, 'message' => 'NOT_ARRAY', 'level' => 'warning', 'cause' => LifecycleWarnings::CAUSE_DECLARATION],
            LifecycleWarnings::current()->all(),
        );
    }

    /**
     * 엔진이 읽는 키가 template.json 에 있는데 expose 에 없으면 경고한다(첫 화면에 API 왕복 1회가 남는다).
     *
     * @scenario shell=fixture, declaration=missing_engine_keys
     *
     * @effects engine_keys_missing_from_expose_warn
     *
     * @return void 반환값 없음
     */
    public function test_engine_keys_missing_from_expose_warn(): void
    {
        $this->translateWarnings();

        // error_config 는 fixture 가 늘 갖는다 — scroll_restoration 도 두고 expose 에는 scroll_restoration 만 선언
        $this->activateFixture(['scroll_restoration' => ['enabled' => true], 'expose' => ['scroll_restoration']]);

        $this->assertContains(
            ['step' => 'expose', 'target' => self::FIXTURE, 'message' => 'MISSING[error_config]', 'level' => 'warning', 'cause' => LifecycleWarnings::CAUSE_DECLARATION],
            LifecycleWarnings::current()->all(),
        );

        // 선언이 엔진 키를 모두 덮으면 경고 없음
        LifecycleWarnings::current()->clear();
        $this->assertSame([], TemplateManager::exposeDeclarationWarnings(
            ['scroll_restoration' => [], 'error_config' => [], 'expose' => ['scroll_restoration', 'error_config']],
            self::FIXTURE,
            reportWarnings: true,
        ));
        $this->assertSame([], LifecycleWarnings::current()->all());
    }

    /**
     * 경고 문구를 시험용 문자열로 바꿉니다.
     *
     * @return void 반환값 없음
     */
    private function translateWarnings(): void
    {
        app()->setLocale('ko');
        // 묶음 파일을 먼저 읽어 둔다 — 읽기 전에 addLines 하면 같은 묶음의 다른 키가 비어 버린다
        __('extensions.warnings.column_step');
        Lang::addLines([
            'extensions.warnings.expose_not_array' => 'NOT_ARRAY',
            'extensions.warnings.expose_missing_engine_keys' => 'MISSING[:keys]',
        ], 'ko');
    }

    /**
     * 셸 HTML 의 `#g7-config` 를 읽습니다.
     *
     * @param  string  $path  요청 경로
     * @return array<string, mixed>|null 설정 (없으면 null)
     */
    private function shellConfig(string $path): ?array
    {
        $response = $this->get($path);
        $response->assertOk();
        if (preg_match('#<script type="application/json" id="g7-config">(.*?)</script>#s', (string) $response->getContent(), $m) !== 1) {
            return null;
        }

        return json_decode(html_entity_decode($m[1]), true);
    }

    /**
     * 셸 HTML 에 실린 templateConfig 를 읽습니다.
     *
     * @param  string  $path  요청 경로
     * @return array<string, mixed>|null templateConfig (없으면 null)
     */
    private function shellTemplateConfig(string $path): ?array
    {
        return $this->shellConfig($path)['templateConfig'] ?? null;
    }

    /**
     * 시험 사용자 템플릿을 만들어 설치·활성화합니다.
     *
     * @param  array<string, mixed>  $extra  template.json 에 더할 값
     * @return void 반환값 없음
     */
    private function activateFixture(array $extra): void
    {
        File::makeDirectory($this->fixturePath.'/dist/js', 0755, true);
        File::makeDirectory($this->fixturePath.'/dist/css', 0755, true);
        File::makeDirectory($this->fixturePath.'/layouts/errors', 0755, true);

        File::put($this->fixturePath.'/template.json', json_encode(array_merge([
            'identifier' => self::FIXTURE,
            'vendor' => 'test',
            'name' => ['ko' => 'Expose 시험', 'en' => 'Expose test'],
            'version' => '1.0.0',
            'license' => 'MIT',
            'description' => ['ko' => '시험', 'en' => 'test'],
            'type' => 'user',
            'locales' => ['ko', 'en'],
            'dependencies' => ['modules' => [], 'plugins' => []],
            'assets' => ['css' => ['dist/css/components.css'], 'js' => ['dist/js/components.iife.js']],
            'components' => ['basic' => [], 'composite' => [], 'layout' => []],
            'error_config' => ['layouts' => ['404' => 'errors/404', '403' => 'errors/403', '500' => 'errors/500']],
            'github_url' => 'https://example.com/repo',
        ], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        File::put($this->fixturePath.'/components.json', json_encode(['components' => []]));
        File::put($this->fixturePath.'/routes.json', json_encode(['routes' => [['path' => '/', 'layout' => 'home', 'auth_required' => false]]]));
        File::put($this->fixturePath.'/layouts/home.json', json_encode(['version' => '1.0.0', 'layout_name' => 'home', 'meta' => ['title' => 'Home'], 'components' => []]));
        File::put($this->fixturePath.'/dist/js/components.iife.js', '// test bundle');
        File::put($this->fixturePath.'/dist/css/components.css', '/* test css */');
        foreach (['404', '403', '500'] as $code) {
            File::put($this->fixturePath."/layouts/errors/{$code}.json", json_encode(['version' => '1.0.0', 'layout_name' => 'error_template', 'meta' => ['title' => 'Error']]));
        }

        $manager = app(TemplateManagerInterface::class);
        $manager->loadTemplates();
        $manager->installTemplate(self::FIXTURE);
        $manager->activateTemplate(self::FIXTURE, true);
    }
}
