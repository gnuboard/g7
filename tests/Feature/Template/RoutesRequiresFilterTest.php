<?php

namespace Tests\Feature\Template;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Models\Module;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * routes.json 라우트 키 `requires` — 필요한 모듈·플러그인이 모두 활성일 때만 라우트를 낸다 (#822).
 *
 * - 공개 routes 응답·SEO·사이트맵·정적 사본은 {@see TemplateService::getRoutesDataWithModules()} 한 곳의 필터를 쓴다.
 * - 형식 오류·모르는 유형은 라우트를 남기고 경고(fail-open).
 * - 편집기 경로(getEditorRoutesDataWithModules·getLayoutRoutePathMap)와 layout:check-installed 는 거르지 않는다.
 */
class RoutesRequiresFilterTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = 'test-requires_user';

    private const MODULE = 'test-requires-module';

    private TemplateService $templateService;

    private TemplateManagerInterface $templateManager;

    private ModuleManagerInterface $moduleManager;

    private PluginManagerInterface $pluginManager;

    /**
     * 모의 확장 관리자로 TemplateService 를 만들고 활성 사용자 템플릿 행을 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->templateManager = $this->createMock(TemplateManagerInterface::class);
        $this->moduleManager = $this->createMock(ModuleManagerInterface::class);
        $this->pluginManager = $this->createMock(PluginManagerInterface::class);

        $this->templateManager->method('getTemplateInfo')->willReturn(['identifier' => self::TEMPLATE, 'type' => 'user']);

        $this->templateService = new TemplateService(
            new TemplateRepository,
            $this->templateManager,
            $this->moduleManager,
            $this->pluginManager,
            app(LayoutVersionRepositoryInterface::class)
        );

        Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);
    }

    /**
     * 시험이 만든 템플릿·모듈 디렉터리를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        foreach ([base_path('templates/'.self::TEMPLATE), base_path('modules/'.self::MODULE)] as $path) {
            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        parent::tearDown();
    }

    /**
     * 템플릿 routes.json 을 원문 그대로 씁니다 (빈 맵 `{}` 보존 확인용).
     *
     * @param  string  $routesJson  `routes` 배열 JSON 원문
     * @return void 반환값 없음
     */
    private function writeTemplateRoutes(string $routesJson): void
    {
        File::ensureDirectoryExists(base_path('templates/'.self::TEMPLATE));
        File::put(base_path('templates/'.self::TEMPLATE.'/routes.json'), '{"version":"1.0.0","routes":'.$routesJson.'}');
    }

    /**
     * 활성 확장을 설정합니다.
     *
     * @param  array<int, string>  $modules  활성 모듈 식별자
     * @param  array<int, string>  $plugins  활성 플러그인 식별자
     * @return void 반환값 없음
     */
    private function activate(array $modules, array $plugins = []): void
    {
        $moduleMocks = [];
        foreach ($modules as $identifier) {
            $mock = $this->createMock(ModuleInterface::class);
            $mock->method('getIdentifier')->willReturn($identifier);
            $moduleMocks[$identifier] = $mock;
        }

        $pluginMocks = [];
        foreach ($plugins as $identifier) {
            $mock = $this->createMock(PluginInterface::class);
            $mock->method('getIdentifier')->willReturn($identifier);
            $pluginMocks[$identifier] = $mock;
        }

        $this->moduleManager->method('getActiveModules')->willReturn($moduleMocks);
        $this->pluginManager->method('getActivePlugins')->willReturn($pluginMocks);
    }

    /**
     * 라우트 목록에서 path 만 뽑습니다 — 필터 훅이 넣는 코어 시스템 라우트(미리보기 등)는 뺍니다.
     *
     * @param  array<string, mixed>  $result  getRoutesDataWithModules 결과
     * @return array<int, string> path 목록
     */
    private function paths(array $result): array
    {
        return $this->declaredPaths($result['data']['routes'] ?? []);
    }

    /**
     * 템플릿·모듈·플러그인이 선언한 라우트의 path 만 뽑습니다.
     *
     * @param  array<int, array<string, mixed>>  $routes  라우트 목록
     * @return array<int, string> path 목록
     */
    private function declaredPaths(array $routes): array
    {
        $declared = array_filter($routes, fn ($route) => ($route['source']['kind'] ?? null) !== 'core');

        return array_values(array_map(fn ($route) => $route['path'], $declared));
    }

    /**
     * 샘플 라우트 — 게시판만·쇼핑몰만·둘 다·플러그인·조건 없음.
     *
     * @return string routes 배열 JSON 원문
     */
    private function sampleRoutes(): string
    {
        return json_encode([
            ['path' => '*/', 'layout' => 'home'],
            ['path' => '*/boards', 'layout' => 'board/boards', 'requires' => ['modules' => ['sirsoft-board']]],
            ['path' => '*/shop', 'layout' => 'shop/index', 'requires' => ['modules' => ['sirsoft-ecommerce']]],
            ['path' => '*/mypage/inquiries', 'layout' => 'mypage/inquiries', 'requires' => ['modules' => ['sirsoft-ecommerce', 'sirsoft-board']]],
            ['path' => '*/postcode', 'layout' => 'postcode', 'requires' => ['plugins' => ['sirsoft-daum_postcode']]],
        ]);
    }

    /**
     * 적힌 확장이 모두 켜져 있으면 라우트를 그대로 내고 requires 키도 응답에 남는다.
     *
     * @scenario case=requires_met
     *
     * @effects met_requires_routes_are_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function all_required_extensions_active_keeps_routes(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate(['sirsoft-board', 'sirsoft-ecommerce'], ['sirsoft-daum_postcode']);

        $result = $this->templateService->getRoutesDataWithModules(self::TEMPLATE);

        $this->assertTrue($result['success']);
        $this->assertSame(['*/', '*/boards', '*/shop', '*/mypage/inquiries', '*/postcode'], $this->paths($result));
        // requires 는 응답에 그대로 남는다(클라이언트 타입 정합)
        $this->assertSame(['modules' => ['sirsoft-board']], $result['data']['routes'][1]['requires']);
    }

    /**
     * 하나라도 꺼져 있으면 그 라우트를 뺀다 — 여럿 적으면 모두 켜져야 한다.
     *
     * @scenario case=requires_unmet
     *
     * @effects unmet_requires_routes_are_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unmet_requires_drops_route_and_requires_all(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate(['sirsoft-board']);

        $result = $this->templateService->getRoutesDataWithModules(self::TEMPLATE);

        // 쇼핑몰 꺼짐 → /shop 제외, 둘 다 필요한 /mypage/inquiries 도 제외, 플러그인 꺼짐 → /postcode 제외
        $this->assertSame(['*/', '*/boards'], $this->paths($result));
    }

    /**
     * 코어만 켠 사이트는 requires 없는 라우트만 남는다.
     *
     * @scenario case=requires_unmet
     *
     * @effects unmet_requires_routes_are_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function core_only_site_keeps_only_unconditioned_routes(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate([]);

        $this->assertSame(['*/'], $this->paths($this->templateService->getRoutesDataWithModules(self::TEMPLATE)));
    }

    /**
     * 업데이트 도중이라 빠진 경우만 열화로 표시해 공개 응답·정적 사본이 캐시하지 않게 한다.
     *
     * @scenario case=extension_in_transition
     *
     * @effects in_transition_extension_marks_merge_degraded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function required_extension_mid_update_marks_result_degraded(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate(['sirsoft-board']);

        // 꺼둔 모듈 때문에 빠진 것은 정상 결과 — 캐시해도 된다
        Module::factory()->create(['identifier' => 'sirsoft-ecommerce', 'status' => ExtensionStatus::Inactive->value]);
        $this->assertSame(['*/', '*/boards'], $this->paths($this->templateService->getRoutesDataWithModules(self::TEMPLATE)));
        $this->assertFalse($this->templateService->lastRouteMergeWasDegraded());

        // 업데이트 도중이라 빠진 것은 잠깐의 열화 — 공개 응답·정적 사본이 캐시하지 않게 표시한다
        Module::query()->where('identifier', 'sirsoft-ecommerce')->update(['status' => ExtensionStatus::Updating->value]);
        DB::enableQueryLog();
        $result = $this->templateService->getRoutesDataWithModules(self::TEMPLATE);
        $lookups = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_contains($query['query'], 'modules') && in_array('sirsoft-ecommerce', $query['bindings'], true),
        );
        DB::disableQueryLog();

        $this->assertSame(['*/', '*/boards'], $this->paths($result));
        $this->assertTrue($this->templateService->lastRouteMergeWasDegraded());
        $this->assertTrue($result['degraded']);
        // 같은 모듈을 요구하는 라우트가 둘(/shop · /mypage/inquiries)이어도 상태 조회는 한 번
        $this->assertCount(1, $lookups);
    }

    /**
     * 확장 상태 조회는 저장소를 거친다 — 서비스가 모델을 직접 조회하지 않는다.
     *
     * @scenario case=extension_in_transition
     *
     * @effects in_transition_extension_marks_merge_degraded
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function transition_lookup_goes_through_repositories(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/TemplateService.php'));

        $this->assertStringNotContainsString('use App\\Models\\Module;', $source);
        $this->assertStringNotContainsString('use App\\Models\\Plugin;', $source);
        $this->assertStringContainsString('ModuleRepositoryInterface', $source);
        $this->assertStringContainsString('PluginRepositoryInterface', $source);
    }

    /**
     * layout:check-installed 가 routes 의 requires 형식 오류를 표로 알린다(D3) — 거르지 않은 선언 전체를 본다.
     *
     * @scenario case=requires_malformed
     *
     * @effects malformed_requires_fail_open_with_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function check_installed_reports_malformed_requires(): void
    {
        $this->writeTemplateRoutes(json_encode([
            ['path' => '*/ok', 'layout' => 'ok', 'requires' => ['modules' => ['sirsoft-board']]],
            ['path' => '*/string', 'layout' => 'a', 'requires' => 'sirsoft-board'],
            ['path' => '*/unknown-type', 'layout' => 'c', 'requires' => ['templates' => ['sirsoft-basic']]],
            ['path' => '*/modules-string', 'layout' => 'd', 'requires' => ['modules' => 'sirsoft-board']],
            ['path' => '*/non-string-id', 'layout' => 'e', 'requires' => ['modules' => [123]]],
        ]));
        $this->activate([]);
        $this->app->instance(TemplateService::class, $this->templateService);

        Artisan::call('layout:check-installed', ['--template' => self::TEMPLATE, '--json' => true]);
        $report = json_decode(trim(Artisan::output()), true);

        $warnings = $report['route_requires_warnings'] ?? null;
        $this->assertIsArray($warnings);
        $this->assertSame(['*/string', '*/unknown-type', '*/modules-string', '*/non-string-id'], array_column($warnings, 'path'));
        $this->assertSame(['not_object', 'unknown_type', 'not_list', 'non_string_id'], array_column($warnings, 'code'));
        $this->assertSame(self::TEMPLATE, $warnings[0]['template']);

        // 표 출력에도 절이 나온다(경고 — 종료 코드에 반영하지 않는다)
        Artisan::call('layout:check-installed', ['--template' => self::TEMPLATE]);
        $this->assertStringContainsString(__('layouts.console.requires_header'), Artisan::output());
    }

    /**
     * 모듈이 선언한 routes 의 requires 도 같은 필터를 탄다.
     *
     * @scenario case=module_route_requires
     *
     * @effects unmet_requires_routes_are_dropped
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function module_route_requires_is_filtered_too(): void
    {
        $this->writeTemplateRoutes(json_encode([['path' => '*/', 'layout' => 'home']]));
        File::ensureDirectoryExists(base_path('modules/'.self::MODULE.'/resources/routes'));
        File::put(base_path('modules/'.self::MODULE.'/resources/routes/user.json'), json_encode(['version' => '1.0.0', 'routes' => [
            ['path' => '*/m/plain', 'layout' => 'plain'],
            ['path' => '*/m/needs-plugin', 'layout' => 'needs', 'requires' => ['plugins' => ['sirsoft-marketing']]],
        ]]));
        $this->activate([self::MODULE]);

        $this->assertSame(['*/', '*/m/plain'], $this->paths($this->templateService->getRoutesDataWithModules(self::TEMPLATE)));
    }

    /**
     * 형식이 틀린 requires 는 라우트를 남기고 경고를 남긴다(fail-open) — 알려진 유형의 미충족은 그대로 뺀다.
     *
     * @scenario case=requires_malformed
     *
     * @effects malformed_requires_fail_open_with_warning
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function malformed_requires_fails_open_with_warning(): void
    {
        $this->writeTemplateRoutes(json_encode([
            ['path' => '*/string', 'layout' => 'a', 'requires' => 'sirsoft-board'],
            ['path' => '*/list', 'layout' => 'b', 'requires' => ['sirsoft-board']],
            ['path' => '*/unknown-type', 'layout' => 'c', 'requires' => ['templates' => ['sirsoft-basic']]],
            ['path' => '*/modules-string', 'layout' => 'd', 'requires' => ['modules' => 'sirsoft-board']],
            ['path' => '*/non-string-id', 'layout' => 'e', 'requires' => ['modules' => [123]]],
            ['path' => '*/unknown-plus-unmet', 'layout' => 'f', 'requires' => ['module' => ['x'], 'modules' => ['sirsoft-board']]],
        ]));
        $this->activate([]);

        Log::shouldReceive('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'requires'))
            ->times(6);
        Log::shouldReceive('warning')->withAnyArgs()->zeroOrMoreTimes();
        Log::shouldReceive('info', 'debug', 'error')->withAnyArgs()->zeroOrMoreTimes();

        $result = $this->templateService->getRoutesDataWithModules(self::TEMPLATE);

        // 형식이 틀린 조건만 「채워짐」 — 알려진 유형의 미충족(/unknown-plus-unmet 의 modules)은 그대로 뺀다
        $this->assertSame(['*/string', '*/list', '*/unknown-type', '*/modules-string', '*/non-string-id'], $this->paths($result));
    }

    /**
     * 빈 requires(`{}`·빈 목록·null)는 조건 없음 — 빈 맵 보존 디코드에서도 같다.
     *
     * @scenario case=requires_empty
     *
     * @effects met_requires_routes_are_kept
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function empty_requires_is_no_condition_in_both_decode_modes(): void
    {
        $this->writeTemplateRoutes('[{"path":"*/a","layout":"a","requires":{}},{"path":"*/b","layout":"b","requires":{"modules":{}}},{"path":"*/c","layout":"c","requires":{"modules":[]}},{"path":"*/d","layout":"d","requires":null}]');
        $this->activate([]);

        $this->assertSame(['*/a', '*/b', '*/c', '*/d'], $this->paths($this->templateService->getRoutesDataWithModules(self::TEMPLATE)));
        $this->assertSame(['*/a', '*/b', '*/c', '*/d'], $this->paths($this->templateService->getRoutesDataWithModules(self::TEMPLATE, preserveEmptyMaps: true)));
    }

    /**
     * applyRequires: false 는 선언된 라우트를 전부 돌려준다(layout:check-installed 경로).
     *
     * @scenario case=unfiltered_consumers
     *
     * @effects editor_and_layout_check_see_every_route
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function apply_requires_false_returns_every_declared_route(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate([]);

        $result = $this->templateService->getRoutesDataWithModules(self::TEMPLATE, applyRequires: false);

        $this->assertSame(['*/', '*/boards', '*/shop', '*/mypage/inquiries', '*/postcode'], $this->paths($result));
    }

    /**
     * 편집기 routes 와 코드 편집기 경로 맵은 꺼진 모듈 화면도 담는다.
     *
     * @scenario case=unfiltered_consumers
     *
     * @effects editor_and_layout_check_see_every_route
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function editor_routes_are_not_filtered(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate([]);

        $editor = $this->templateService->getEditorRoutesDataWithModules(self::TEMPLATE);
        $this->assertTrue($editor['success']);
        $this->assertSame(['*/', '*/boards', '*/shop', '*/mypage/inquiries', '*/postcode'], $this->paths($editor));

        // 코드 편집기의 레이아웃 → 경로 맵도 꺼진 모듈 화면을 포함한다
        $map = $this->templateService->getLayoutRoutePathMap(self::TEMPLATE);
        $this->assertArrayHasKey('board/boards', $map);
        $this->assertArrayHasKey('shop/index', $map);
    }

    /**
     * 공개 routes API 응답에서 미충족 라우트가 빠진다.
     *
     * @scenario case=public_api
     *
     * @effects public_routes_response_omits_unmet_routes
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function public_routes_api_omits_unmet_routes(): void
    {
        $this->writeTemplateRoutes($this->sampleRoutes());
        $this->activate(['sirsoft-ecommerce']);
        $this->app->instance(TemplateService::class, $this->templateService);

        $response = $this->getJson('/api/templates/'.self::TEMPLATE.'/routes.json');

        $response->assertOk();
        $this->assertSame(['*/', '*/shop'], $this->declaredPaths($response->json('data.routes')));
    }

    /**
     * 호출처 계약 — 공개 응답·SEO·사이트맵·정적 사본·캐시 예열은 기본값(필터)으로, 선언 전체를 봐야 하는
     * layout:check-installed 와 코드 편집기 경로 맵만 `applyRequires: false` 로 부른다.
     *
     * @scenario case=call_sites
     *
     * @effects serving_call_sites_apply_requires
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function call_sites_use_the_agreed_filter_mode(): void
    {
        $filtered = [
            'app/Http/Controllers/Api/Public/PublicTemplateController.php',
            'app/Seo/SeoDeclarationCollector.php',
            'app/Seo/TemplateRouteResolver.php',
            'app/Services/ExtensionStaticCacheService.php',
            'app/Extension/TemplateManager.php',
        ];
        foreach ($filtered as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringContainsString('getRoutesDataWithModules(', $source, $file);
            $this->assertStringNotContainsString('applyRequires: false', $source, $file);
        }

        // 사이트맵 정적 화면은 SEO 선언 수집기(위 목록의 필터 적용 호출처)를 거쳐 라우트를 받는다 (#155)
        $sitemap = (string) file_get_contents(base_path('app/Seo/SitemapGenerator.php'));
        $this->assertStringContainsString('$this->declarationCollector->collect()', $sitemap);
        $this->assertStringNotContainsString('applyRequires: false', $sitemap);

        $command = (string) file_get_contents(base_path('app/Console/Commands/Layout/LayoutCheckInstalledCommand.php'));
        $this->assertMatchesRegularExpression('/getRoutesDataWithModules\([^;]*applyRequires: false\)/', $command);

        $service = (string) file_get_contents(base_path('app/Services/TemplateService.php'));
        $this->assertMatchesRegularExpression('/function getLayoutRoutePathMap[\s\S]*?getRoutesDataWithModules\(\$identifier, applyRequires: false\)/', $service);
    }
}
