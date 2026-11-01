<?php

namespace Tests\Feature\Seo;

use App\Contracts\Extension\ModuleInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\Template;
use App\Models\TemplateLayout;
use App\Repositories\TemplateRepository;
use App\Seo\AbstractSitemapContributor;
use App\Seo\SeoDeclarationCollector;
use App\Seo\SeoToggleEvaluator;
use App\Seo\SitemapGenerator;
use App\Seo\TemplateRouteResolver;
use App\Services\LayoutService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * 사이트맵 정적 화면 — 번들 sirsoft-basic 실물 라우트·레이아웃으로 실제 SitemapGenerator 결과를 잰다 (#155)
 *
 * 사이트맵 정적 화면은 봇 화면과 같은 기준을 쓴다 — 병합 레이아웃 `meta.seo.enabled` 가 참이고 `toggle_setting` 이
 * 켜진, 고정 경로의 공개 화면만 싣는다. 번들 `routes.json` 은 테스트 전용 템플릿 디렉터리에 복사하고(설치본
 * `templates/sirsoft-basic` 은 저장소 밖이다), 레이아웃은 설치 경로와 같은 컴파일(ValidatesLayoutFiles)을 거쳐 DB 에
 * 심는다. 그래서 `extends` 병합 결과(`_user_base` 상속 → errors·identity 는 SEO 꺼짐)가 실제와 같다.
 *
 * 트러블슈팅 `[case:backend-61]` 의 회귀 그물이다.
 *
 * 시나리오 매니페스트: tests/scenarios/sitemap-static-routes.yaml
 */
class SitemapStaticRoutesTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'test-sitemap_basic';

    private const HELLO_MODULE = 'gnuboard7-hello_module';

    /** @var callable|null 시험이 붙인 라우트 주입 필터 */
    private $routeFilter = null;

    /**
     * 테스트용 템플릿에 번들 sirsoft-basic 라우트·레이아웃(+ hello_module 사용자 레이아웃)을 심습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.locale', 'ko');
        Config::set('app.supported_locales', ['ko']);
        Config::set('g7_settings.core.seo.sitemap_hreflang_enabled', true);
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_boards', true);
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search_result', true);

        File::ensureDirectoryExists(base_path('templates/'.self::TEMPLATE));
        File::copy(base_path('templates/_bundled/sirsoft-basic/routes.json'), base_path('templates/'.self::TEMPLATE.'/routes.json'));

        $template = Template::factory()->create([
            'identifier' => self::TEMPLATE,
            'type' => 'user',
            'status' => ExtensionStatus::Active->value,
        ]);

        $compiler = new class
        {
            use ValidatesLayoutFiles;

            /**
             * 설치 경로와 같은 방식으로 레이아웃 디렉터리를 컴파일합니다.
             *
             * @param  string  $path  레이아웃 디렉터리
             * @param  string  $id  확장 식별자
             * @param  string  $type  확장 종류
             * @return array<int, array{data: array<string, mixed>, layout_name: string}> 컴파일 결과
             */
            public function run(string $path, string $id, string $type): array
            {
                return $this->validateLayoutFiles($path, $id, $type, true);
            }
        };

        foreach ($compiler->run(base_path('templates/_bundled/sirsoft-basic/layouts'), self::TEMPLATE, 'template') as $layout) {
            $this->seedLayout($template->id, $layout['layout_name'], $layout['data'], 'template', self::TEMPLATE);
        }

        foreach ($compiler->run(base_path('modules/_bundled/'.self::HELLO_MODULE.'/resources/layouts/user'), self::HELLO_MODULE, 'module') as $layout) {
            $this->seedLayout($template->id, self::HELLO_MODULE.'.'.$layout['layout_name'], $layout['data'], 'module', self::HELLO_MODULE);
        }
    }

    /**
     * 시험이 만든 템플릿 디렉터리와 필터를 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($this->routeFilter !== null) {
            HookManager::removeFilter('core.routes.filter_merged', $this->routeFilter);
        }

        File::deleteDirectory(base_path('templates/'.self::TEMPLATE));

        parent::tearDown();
    }

    /**
     * 레이아웃 한 건을 DB 에 심습니다.
     *
     * @param  int  $templateId  템플릿 id
     * @param  string  $name  레이아웃 이름
     * @param  array<string, mixed>  $content  레이아웃 내용
     * @param  string  $sourceType  출처 종류
     * @param  string  $sourceIdentifier  출처 식별자
     * @return void 반환값 없음
     */
    private function seedLayout(int $templateId, string $name, array $content, string $sourceType, string $sourceIdentifier): void
    {
        TemplateLayout::create([
            'template_id' => $templateId,
            'name' => $name,
            'content' => $content,
            'extends' => $content['extends'] ?? null,
            'source_type' => $sourceType,
            'source_identifier' => $sourceIdentifier,
        ]);
    }

    /**
     * 활성 모듈을 정해 실제 수집기·판정기를 쓰는 사이트맵 생성기를 만듭니다.
     *
     * @param  array<int, string>  $activeModules  활성 모듈 식별자
     * @return SitemapGenerator 생성기
     */
    private function generator(array $activeModules): SitemapGenerator
    {
        $templateManager = $this->createMock(TemplateManagerInterface::class);
        $templateManager->method('getTemplateInfo')->willReturn(['identifier' => self::TEMPLATE, 'type' => 'user']);
        $templateManager->method('getActiveTemplate')->willReturn(['identifier' => self::TEMPLATE, 'type' => 'user']);

        $modules = [];
        foreach ($activeModules as $identifier) {
            $module = $this->createMock(ModuleInterface::class);
            $module->method('getIdentifier')->willReturn($identifier);
            $modules[$identifier] = $module;
        }

        $moduleManager = $this->createMock(ModuleManagerInterface::class);
        $moduleManager->method('getActiveModules')->willReturn($modules);
        $moduleManager->method('getModule')->willReturnCallback(fn (string $id) => $modules[$id] ?? null);

        $pluginManager = $this->createMock(PluginManagerInterface::class);
        $pluginManager->method('getActivePlugins')->willReturn([]);
        $pluginManager->method('getPlugin')->willReturn(null);

        $templateService = new TemplateService(
            new TemplateRepository,
            $templateManager,
            $moduleManager,
            $pluginManager,
            app(LayoutVersionRepositoryInterface::class)
        );

        // 수집기의 레이아웃 이름 해석은 봇 렌더와 같은 해석기를 쓴다 — 활성 확장 목(Mock)을 그대로 물려 둔다
        $collector = new SeoDeclarationCollector(
            $templateService,
            app(LayoutService::class),
            $templateManager,
            new TemplateRouteResolver($templateService, $templateManager, $moduleManager, $pluginManager),
        );

        return new SitemapGenerator(app(TemplateRouteResolver::class), $templateService, $collector, new SeoToggleEvaluator);
    }

    /**
     * 주어진 경로를 내는 기여자를 만듭니다.
     *
     * @param  string  $identifier  기여자 식별자
     * @param  array<int, string>  $urls  상대 경로 목록
     * @return AbstractSitemapContributor 기여자
     */
    private function contributor(string $identifier, array $urls): AbstractSitemapContributor
    {
        return new class($identifier, $urls) extends AbstractSitemapContributor
        {
            /**
             * 식별자와 경로를 받아 둡니다.
             *
             * @param  string  $identifier  기여자 식별자
             * @param  array<int, string>  $urls  상대 경로 목록
             */
            public function __construct(private string $identifier, private array $urls) {}

            /**
             * 기여자 식별자를 돌려줍니다.
             *
             * @return string 식별자
             */
            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            /**
             * 경로를 하나씩 내보냅니다.
             *
             * @return iterable<array<string, mixed>> URL 항목
             */
            public function getUrlsLazy(): iterable
            {
                foreach ($this->urls as $url) {
                    yield ['url' => $url];
                }
            }
        };
    }

    /**
     * 사이트맵 XML 의 loc 를 사이트 주소 기준 경로로 풀어냅니다.
     *
     * @param  string  $xml  사이트맵 XML 본문
     * @return array<int, string> 경로 목록 (`/`, `/boards` …)
     */
    private function paths(string $xml): array
    {
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document);

        $base = rtrim(url('/'), '/');
        $paths = [];
        foreach ($document->url as $url) {
            $path = substr((string) $url->loc, strlen($base));
            $paths[] = $path === '' ? '/' : $path;
        }

        return $paths;
    }

    /**
     * 기본 템플릿 홈은 레이아웃 priority·changefreq 로 실리고, SEO 가 꺼진 오류·본인인증 화면은 빠진다.
     *
     * @scenario case=core_only
     *
     * @effects bundled_home_in_sitemap, seo_disabled_layouts_excluded
     *
     * @return void 반환값 없음
     */
    public function test_bundled_home_is_included_and_seo_disabled_screens_are_excluded(): void
    {
        $xml = $this->generator([])->generate();
        $paths = $this->paths($xml);

        $this->assertContains('/', $paths, '홈(/)이 사이트맵에 없습니다');
        $this->assertStringContainsString('<loc>'.rtrim(url('/'), '/')."</loc>\n    <changefreq>daily</changefreq>\n    <priority>1.0</priority>", $xml);
        foreach (['/404', '/403', '/500', '/identity/challenge', '/identity/verify', '/login', '/register'] as $excluded) {
            $this->assertNotContains($excluded, $paths, "{$excluded} 는 사이트맵에 들어가면 안 됩니다");
        }
        // 게시판 모듈이 꺼져 있으면 requires 로 라우트 자체가 없다
        $this->assertNotContains('/boards', $paths);
        $this->assertContains('/search', $paths);
    }

    /**
     * 게시판 모듈이 켜지면 `/boards`·`/boards/popular` 가 실리고, 게시판 기여자가 같은 `/boards` 를 내도 1건이다.
     *
     * 실제 게시판 기여자는 게시판 테이블을 읽으므로, 같은 주소를 내는 기여자를 등록해 중복만 잰다. 식별자를
     * `sirsoft-board` 로 두면 테스트 DB 의 확장 설치 상태에 따라 비활성 기여자로 걸러질 수 있어 확장과 무관한 이름을 쓴다.
     *
     * @scenario case=board_active
     *
     * @effects board_routes_follow_module_and_dedupe
     *
     * @return void 반환값 없음
     */
    public function test_board_routes_are_included_once_when_board_module_active(): void
    {
        $generator = $this->generator(['sirsoft-board']);
        $generator->registerContributor($this->contributor('test-board-contributor', ['/boards', '/board/free']));

        $paths = $this->paths($generator->generate());

        $this->assertSame(1, count(array_keys($paths, '/boards', true)), '/boards 는 정확히 1건이어야 합니다');
        $this->assertSame(1, count(array_keys($paths, '/boards/popular', true)));
        $this->assertContains('/board/free', $paths);

        // 게시판 목록 SEO 토글을 끄면 정적 /boards 는 빠지고, 토글을 선언하지 않은 /boards/popular 는 남는다
        Config::set('g7_settings.modules.sirsoft-board.seo.seo_boards', false);
        $offPaths = $this->paths($this->generator(['sirsoft-board'])->generate());
        $this->assertNotContains('/boards', $offPaths);
        $this->assertContains('/boards/popular', $offPaths);
    }

    /**
     * 쇼핑몰 「검색 결과 SEO」 설정을 끄면 `/search` 가 빠지고 켜면 실린다 (D2).
     *
     * @scenario case=search_toggle
     *
     * @effects search_route_follows_toggle
     *
     * @return void 반환값 없음
     */
    public function test_search_route_follows_ecommerce_toggle(): void
    {
        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search_result', false);
        $this->assertNotContains('/search', $this->paths($this->generator([])->generate()));

        Config::set('g7_settings.modules.sirsoft-ecommerce.seo.seo_search_result', true);
        $paths = $this->paths($this->generator([])->generate());
        $this->assertContains('/search', $paths);
        $this->assertContains('/', $paths);
    }

    /**
     * 모듈 소유 화면(hello_module `*\/memos`)은 고정값이 아니라 「그 라우트 병합 레이아웃의 meta.seo.enabled」 를 따른다.
     *
     * #154 가 이 레이아웃의 SEO 를 켜면 포함되는 것이 맞다. 어느 쪽이 먼저 들어가도 깨지지 않도록 레이아웃 값으로 단언한다.
     * 모듈 라우트 로더는 설치본 디렉터리(저장소 밖)를 읽으므로, 같은 모양의 라우트를 공개 확장점
     * `core.routes.filter_merged` 로 넣는다.
     *
     * @scenario case=module_route
     *
     * @effects module_route_follows_merged_layout_seo
     *
     * @return void 반환값 없음
     */
    public function test_module_route_inclusion_equals_merged_layout_seo_enabled(): void
    {
        $this->routeFilter = function (array $routes): array {
            $routes[] = [
                'path' => '*/memos',
                'layout' => self::HELLO_MODULE.'.user_memo_list',
                'auth_required' => false,
                'source' => ['kind' => 'module', 'identifier' => self::HELLO_MODULE],
            ];

            return $routes;
        };
        HookManager::addFilter('core.routes.filter_merged', $this->routeFilter);

        $merged = app(LayoutService::class)->getLayout(self::TEMPLATE, self::HELLO_MODULE.'.user_memo_list');
        $expected = ($merged['meta']['seo']['enabled'] ?? false) === true;

        $paths = $this->paths($this->generator([self::HELLO_MODULE])->generate());

        $this->assertSame($expected, in_array('/memos', $paths, true), '/memos 포함 여부가 병합 레이아웃 meta.seo.enabled 와 다릅니다');
    }

    /**
     * 고정 경로 공개 라우트 전부에서 「사이트맵 포함 여부 == 병합 레이아웃 seo.enabled && 토글 켜짐」 이다.
     *
     * @scenario case=invariant
     *
     * @effects static_inclusion_equals_bot_render_criteria
     *
     * @return void 반환값 없음
     */
    public function test_inclusion_matches_bot_render_criteria_for_every_fixed_route(): void
    {
        $routes = json_decode((string) File::get(base_path('templates/_bundled/sirsoft-basic/routes.json')), true)['routes'];
        $paths = $this->paths($this->generator([])->generate());
        $evaluator = new SeoToggleEvaluator;
        $checked = 0;

        foreach ($routes as $route) {
            $path = $route['path'] ?? '';
            if (($route['auth_required'] ?? false) || ($route['guest_only'] ?? false) || empty($route['layout'])
                || str_contains($path, ':') || str_contains($path, '{{') || ! empty($route['requires'])) {
                continue;
            }

            $seo = app(LayoutService::class)->getLayout(self::TEMPLATE, $route['layout'])['meta']['seo'] ?? [];
            $expected = ($seo['enabled'] ?? false) && $evaluator->isEnabled(null, null, $seo);

            $this->assertSame($expected, in_array($path, $paths, true), "{$path} ({$route['layout']}) 의 포함 여부가 봇 화면 기준과 다릅니다");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(7, $checked, '대조한 고정 경로가 너무 적습니다');
    }
}
