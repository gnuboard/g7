<?php

namespace Tests\Feature\Extension;

use App\Console\Commands\Concerns\PrunesBuildOutput;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Http\Requests\Public\Template\ServeTemplateAssetRequest;
use App\Models\Plugin;
use App\Models\Template;
use App\Rules\SafeTemplatePath;
use App\Seo\SeoRenderer;
use App\Services\ExtensionStaticCacheService;
use App\Services\PluginService;
use App\Services\TemplateService;
use App\Support\AssetUrl;
use App\Support\CustomAssets;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 동봉 자산 · 사용자 추가 자산의 서빙 테스트
 *
 * 확장이 담은 구동 자산(`dist/vendor/**`)과 운영자가 넣은 자산(`custom/**`)이 실제로
 * 서빙되는지, 그리고 **비활성 확장은 서빙되지 않는지**를 본다. 자산 404 는 예외를
 * 남기지 않고 화면 기능만 사라지므로, 경로 규약이 어긋나면 배포본에서만 드러난다.
 *
 * DB 대신 저장소를 모킹한다 — 검증 대상은 경로 해석과 활성 게이트이지 DB 스키마가 아니다.
 */
class VendoredAssetServingTest extends TestCase
{
    /** 테스트용 가짜 템플릿 식별자 */
    private const FAKE_TEMPLATE = 'g7test-serving';

    /** 테스트용 가짜 플러그인 식별자 */
    private const FAKE_PLUGIN = 'g7test-serving-plugin';

    protected function setUp(): void
    {
        parent::setUp();

        CustomAssets::flushCache();

        // 템플릿: dist/vendor(동봉) + custom(운영자)
        File::ensureDirectoryExists(base_path('templates/'.self::FAKE_TEMPLATE.'/dist/vendor/lib/1.0.0/css'));
        File::put(base_path('templates/'.self::FAKE_TEMPLATE.'/dist/vendor/lib/1.0.0/css/lib.css'), '/* vendored */');
        File::ensureDirectoryExists(base_path('templates/'.self::FAKE_TEMPLATE.'/custom'));
        File::put(base_path('templates/'.self::FAKE_TEMPLATE.'/custom/custom.css'), '/* operator */');
        File::put(base_path('templates/'.self::FAKE_TEMPLATE.'/custom/notes.txt'), 'not an asset');

        // 플러그인: 확장 루트 기준
        File::ensureDirectoryExists(base_path('plugins/'.self::FAKE_PLUGIN.'/dist/vendor/lib/1.0.0'));
        File::put(base_path('plugins/'.self::FAKE_PLUGIN.'/dist/vendor/lib/1.0.0/lib.js'), '// vendored');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(base_path('templates/'.self::FAKE_TEMPLATE));
        File::deleteDirectory(base_path('plugins/'.self::FAKE_PLUGIN));
        CustomAssets::flushCache();
        AssetUrl::forceMode(null);
        Mockery::close();

        parent::tearDown();
    }

    /**
     * 저장되지 않은 템플릿 모델을 만듭니다.
     *
     * @param  string  $status  확장 상태 값
     * @return Template 모델 인스턴스
     */
    private function makeTemplate(string $status): Template
    {
        $template = new Template;
        $template->identifier = self::FAKE_TEMPLATE;
        $template->status = $status;

        return $template;
    }

    /**
     * 저장되지 않은 플러그인 모델을 만듭니다.
     *
     * @param  string  $status  확장 상태 값
     * @return Plugin 모델 인스턴스
     */
    private function makePlugin(string $status): Plugin
    {
        $plugin = new Plugin;
        $plugin->identifier = self::FAKE_PLUGIN;
        $plugin->status = $status;

        return $plugin;
    }

    /**
     * 지정 상태의 템플릿을 돌려주는 서비스를 만듭니다.
     *
     * @param  string  $status  확장 상태 값
     * @return TemplateService 모킹된 저장소를 물린 서비스
     */
    private function templateService(string $status): TemplateService
    {
        $repository = Mockery::mock(TemplateRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')
            ->andReturn($this->makeTemplate($status));

        $this->app->instance(TemplateRepositoryInterface::class, $repository);

        return $this->app->make(TemplateService::class);
    }

    /**
     * 지정 상태의 플러그인을 돌려주는 서비스를 만듭니다.
     *
     * @param  string  $status  확장 상태 값
     * @return PluginService 모킹된 저장소를 물린 서비스
     */
    private function pluginService(string $status): PluginService
    {
        $repository = Mockery::mock(PluginRepositoryInterface::class);
        $repository->shouldReceive('findByIdentifier')
            ->andReturn($this->makePlugin($status));

        $this->app->instance(PluginRepositoryInterface::class, $repository);

        return $this->app->make(PluginService::class);
    }

    /**
     * @scenario asset_class=vendored, outcome=loaded
     *
     * @effects runtime_asset_served_same_origin
     */
    #[Test]
    public function 템플릿_동봉_자산은_dist_이하로_해석된다(): void
    {
        $result = $this->templateService(ExtensionStatus::Active->value)
            ->getAssetFilePath(self::FAKE_TEMPLATE, 'vendor/lib/1.0.0/css/lib.css');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame('text/css', $result['mimeType']);
        $this->assertStringContainsString('/dist/vendor/', str_replace('\\', '/', $result['filePath']));
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=css
     *
     * @effects custom_asset_loaded_after_extension_bundles
     */
    #[Test]
    public function 템플릿_운영자_자산은_dist_밖의_custom_으로_해석된다(): void
    {
        // custom/ 은 빌드 산출물이 아니라 사람이 넣은 파일이고 확장 교체가 보존하는
        // 대상이라 dist/ 안에 둘 수 없다 — 서빙도 그 비대칭을 알아야 한다.
        $result = $this->templateService(ExtensionStatus::Active->value)
            ->getAssetFilePath(self::FAKE_TEMPLATE, 'custom/custom.css');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $normalized = str_replace('\\', '/', $result['filePath']);
        $this->assertStringContainsString('/'.self::FAKE_TEMPLATE.'/custom/custom.css', $normalized);
        $this->assertStringNotContainsString('/dist/', $normalized);
    }

    /**
     * @scenario custom_source=convention_scan
     *
     * @effects inactive_extension_custom_not_served
     */
    #[Test]
    public function 비활성_템플릿의_자산은_서빙되지_않는다(): void
    {
        $result = $this->templateService(ExtensionStatus::Inactive->value)
            ->getAssetFilePath(self::FAKE_TEMPLATE, 'vendor/lib/1.0.0/css/lib.css');

        $this->assertFalse($result['success']);
        $this->assertSame('template_not_found', $result['error']);
    }

    /**
     * @scenario custom_source=convention_scan, custom_asset=static_file
     *
     * @effects custom_disallowed_extension_blocked
     */
    #[Test]
    public function 허용되지_않은_확장자는_서빙되지_않는다(): void
    {
        $result = $this->templateService(ExtensionStatus::Active->value)
            ->getAssetFilePath(self::FAKE_TEMPLATE, 'custom/notes.txt');

        $this->assertFalse($result['success']);
        $this->assertSame('file_type_not_allowed', $result['error']);
    }

    /**
     * @effects custom_path_traversal_blocked
     */
    #[Test]
    public function 경로_이탈은_차단된다(): void
    {
        $result = $this->templateService(ExtensionStatus::Active->value)
            ->getAssetFilePath(self::FAKE_TEMPLATE, '../../../.env');

        $this->assertFalse($result['success']);
    }

    #[Test]
    public function 플러그인_동봉_자산은_확장_루트_기준으로_해석된다(): void
    {
        $result = $this->pluginService(ExtensionStatus::Active->value)
            ->getAssetFilePath(self::FAKE_PLUGIN, 'dist/vendor/lib/1.0.0/lib.js');

        $this->assertTrue($result['success'], (string) ($result['error'] ?? ''));
        $this->assertSame('application/javascript', $result['mimeType']);
    }

    #[Test]
    public function 비활성_플러그인의_자산은_서빙되지_않는다(): void
    {
        $result = $this->pluginService(ExtensionStatus::Inactive->value)
            ->getAssetFilePath(self::FAKE_PLUGIN, 'dist/vendor/lib/1.0.0/lib.js');

        $this->assertFalse($result['success']);
        $this->assertSame('plugin_not_found', $result['error']);
    }

    /**
     * @effects runtime_asset_served_same_origin
     */
    #[Test]
    public function 자산_url_은_두_모드_모두_same_origin_이다(): void
    {
        foreach (['extension', 'extensionless'] as $mode) {
            AssetUrl::forceMode($mode);

            $url = AssetUrl::templateAsset(self::FAKE_TEMPLATE, 'vendor/lib/1.0.0/css/lib.css', null, false);

            $this->assertStringStartsWith('/api/templates/assets/', $url, "모드: {$mode}");
            $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, "모드: {$mode}");
        }
    }

    /**
     * 빌드 산출물 정리는 동봉 자산을 지우지 않는다.
     *
     * vite 의 `emptyOutDir` 를 끄고 정리 책임을 빌드 커맨드로 옮긴 이유가 이것이다.
     * 정리 규칙이 vendor 를 함께 지우면 매 빌드마다 동봉 자산이 사라지고, 그 사실은
     * 배포본을 열어보기 전까지 드러나지 않는다.
     *
     *
     * @effects vendored_asset_survives_rebuild
     */
    #[Test]
    public function 빌드_산출물_정리가_동봉_자산을_보존한다(): void
    {
        $workspace = storage_path('framework/testing/prune-'.uniqid());
        File::ensureDirectoryExists($workspace.'/dist/js');
        File::ensureDirectoryExists($workspace.'/dist/vendor/lib/1.0.0');
        File::put($workspace.'/dist/js/old.js', '// old');
        File::put($workspace.'/dist/index.d.ts', 'export {};');
        File::put($workspace.'/dist/vendor/lib/1.0.0/lib.js', '// vendored');

        $command = new class
        {
            use PrunesBuildOutput;

            /**
             * 정리를 실행합니다.
             *
             * @param  string  $path  확장 루트 경로
             * @return array<int, string> 삭제된 항목
             */
            public function run(string $path): array
            {
                return $this->pruneBuildOutput($path);
            }
        };

        $removed = $command->run($workspace);

        $this->assertFileExists($workspace.'/dist/vendor/lib/1.0.0/lib.js', '동봉 자산이 빌드 정리에 삭제됐습니다.');
        $this->assertFileDoesNotExist($workspace.'/dist/js/old.js');
        $this->assertFileDoesNotExist($workspace.'/dist/index.d.ts');
        $this->assertNotContains('vendor', $removed);

        File::deleteDirectory($workspace);
    }

    /**
     * 봇 렌더 경로의 스타일시트도 same-origin 자산으로 해석된다.
     *
     * 검색엔진이 보는 화면이 사용자 화면과 다른 자산을 쓰면, 자체 제공 전환이 그 경로에서만
     * 무력화된다 — 그리고 그 사실은 봇 화면을 따로 열어보기 전까지 드러나지 않는다.
     *
     *
     * @effects bot_render_path_uses_same_origin_asset, runtime_asset_served_same_origin
     */
    #[Test]
    public function 봇_렌더_경로의_스타일시트가_same_origin_으로_해석된다(): void
    {
        $renderer = $this->app->make(SeoRenderer::class);
        $method = new \ReflectionMethod($renderer, 'resolveConfigStylesheets');
        $method->setAccessible(true);

        $resolved = $method->invoke($renderer, [
            'vendor/font-awesome/6.4.0/css/all.inlined.css',
            'https://cdn.example.com/keep-as-is.css',
            '/api/templates/assets/t/already-absolute.css',
        ], self::FAKE_TEMPLATE);

        $this->assertStringStartsWith('/api/templates/assets/', $resolved[0]);
        $this->assertStringNotContainsString('/dist/', $resolved[0]);

        // 절대 URL 과 이미 절대 경로인 항목은 그대로 둔다 (판정 범위를 넘지 않는다)
        $this->assertSame('https://cdn.example.com/keep-as-is.css', $resolved[1]);
        $this->assertSame('/api/templates/assets/t/already-absolute.css', $resolved[2]);
    }

    /**
     * 자산 요청 검증의 컨테인먼트 기준이 **실제로 읽는 디렉토리**와 일치한다.
     *
     * 템플릿 자산은 `dist/` 이하가 기본이지만 `custom/` 만은 그 밖에 있다. 검증 기준을
     * `dist` 로 고정하면 `custom/**` 은 realpath 가 실패해 문자열 접두 비교로만 통과하므로,
     * 검증한 경로와 읽는 경로가 서로 다른 상태가 된다. 그 상태는 오류를 남기지 않는다 —
     * 룰이 realpath 존재를 필수로 바꾸는 순간 custom 서빙만 조용히 404 가 된다.
     *
     * @effects custom_asset_containment_base_matches_serving_root
     */
    #[Test]
    public function 자산_요청_검증_기준_경로가_서빙_경로와_일치한다(): void
    {
        $base = fn (string $path): string => (function () {
            $rules = $this->rules();

            foreach ($rules['path'] as $rule) {
                if ($rule instanceof SafeTemplatePath) {
                    $property = new \ReflectionProperty($rule, 'basePath');
                    $property->setAccessible(true);

                    return $property->getValue($rule);
                }
            }

            return '';
        })->call($this->makeAssetRequest($path));

        // custom/ 은 확장 루트 기준 — TemplateService::getAssetFilePath 와 같은 분기
        $this->assertSame(
            base_path('templates/'.self::FAKE_TEMPLATE),
            $base('custom/custom.css')
        );

        // 그 외에는 종전대로 dist 기준
        $this->assertSame(
            base_path('templates/'.self::FAKE_TEMPLATE.'/dist'),
            $base('vendor/font-awesome/6.4.0/css/all.inlined.css')
        );
    }

    /**
     * 정적 게시가 운영자 소유 자산(`custom/`)을 자기 원본 루트로 싣는다.
     *
     * 게시하지 않으면 이 자산만 API 경로에 남는데, 그 경로에서는 CSS 내부 상대 `url()`
     * 이 해석되지 않는다 — 문서가 안내하는 "폰트·이미지를 custom/ 에 두고 상대 경로로
     * 참조" 가 성립하지 않는다. 정적 확장자 URL 은 public 아래 실제 파일일 때만 200 이
     * 되므로 게시가 유일한 성립 조건이다.
     *
     * @effects custom_asset_published_with_extension_assets
     */
    #[Test]
    public function 정적_게시가_운영자_자산을_자기_루트로_싣는다(): void
    {
        $service = $this->app->make(ExtensionStaticCacheService::class);
        $method = new \ReflectionMethod($service, 'publishTemplate');
        $method->setAccessible(true);

        // publishTemplate 이 custom 원본을 **자기 호출로** 게시하는지 — 소스에 두 번째
        // publishDistAssets 호출이 있고 그 대상이 custom 루트임을 구조로 확인한다.
        $source = file_get_contents(app_path('Services/ExtensionStaticCacheService.php'));

        $this->assertStringContainsString(
            'base_path("templates/{$identifier}/".CustomAssets::DIRECTORY)',
            $source,
            'custom 원본을 게시하는 호출이 없습니다 — 상대 url() 이 다시 깨집니다.'
        );
        $this->assertStringContainsString(
            'excludeCustom: false',
            $source,
            'custom 원본 게시에서 custom 제외 가드가 꺼져 있지 않습니다 — 하위가 조용히 누락됩니다.'
        );
    }

    /**
     * dist 원본으로 들어온 `custom/` 하위는 여전히 건너뛴다.
     *
     * 운영자 파일은 자기 원본 루트로 게시되므로, dist 를 통해 한 번 더 실리면 같은
     * 파일이 두 경로에 놓여 어느 쪽이 유효한지가 갈린다.
     *
     * @effects custom_asset_published_with_extension_assets
     */
    #[Test]
    public function dist_원본에서는_운영자_디렉토리를_건너뛴다(): void
    {
        $service = $this->app->make(ExtensionStaticCacheService::class);
        $method = new \ReflectionMethod($service, 'isCustomAssetPath');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, CustomAssets::DIRECTORY.'/custom.css'));
        $this->assertTrue($method->invoke($service, CustomAssets::DIRECTORY.'/fonts/MyFont.woff2'));
        $this->assertTrue($method->invoke($service, CustomAssets::DIRECTORY));

        // 이름이 겹치는 정상 산출물까지 막지 않는다
        $this->assertFalse($method->invoke($service, 'css/custom-theme.css'));
        $this->assertFalse($method->invoke($service, 'vendor/font-awesome/6.4.0/css/all.inlined.css'));
    }

    /**
     * 테스트용 자산 요청을 만든다 (라우트 파라미터 해석까지 재현).
     */
    private function makeAssetRequest(string $path): ServeTemplateAssetRequest
    {
        $request = ServeTemplateAssetRequest::create(
            '/api/templates/assets/'.self::FAKE_TEMPLATE.'/'.$path
        );

        $route = new Route(['GET'], '/api/templates/assets/{identifier}/{path}', []);
        $route->parameters = ['identifier' => self::FAKE_TEMPLATE, 'path' => $path];
        $request->setRouteResolver(fn () => $route);
        $request->merge(['identifier' => self::FAKE_TEMPLATE, 'path' => $path]);

        return $request;
    }
}
