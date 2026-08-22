<?php

namespace Tests\Feature\Extension;

use App\Enums\ExtensionStatus;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Providers\ModuleRouteServiceProvider;
use App\Providers\PluginRouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * 확장 라우트가 "활성 상태" 로 게이트되는지에 대한 회귀 테스트.
 *
 * 확장을 비활성화하면 화면·메뉴·프론트엔드 에셋은 사라지지만, 라우트 등록이 게이트되지
 * 않으면 그 확장의 API 는 계속 호출 가능한 상태로 남는다. 컨트롤러 파일이 그대로 있으므로
 * 요청은 정상 처리되고, 오류도 로그도 남지 않는다 — 화면만 보고는 알 수 없다.
 *
 * 모듈 쪽에는 이 게이트가 있었으나 플러그인 쪽에는 처음부터 없었다(도입된 적 없음).
 * 두 경로가 같은 기준을 쓰는지 실제 라우트 등록 결과로 확인한다.
 */
class ExtensionRouteActiveGateTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> 이 테스트가 라우트 등록까지 확인할 확장 */
    protected array $requiredExtensions = ['plugins/sirsoft-gdpr', 'modules/sirsoft-page'];

    /** @var Router|null 교체 전 원본 라우터 */
    private ?Router $originalRouter = null;

    protected function tearDown(): void
    {
        $this->restoreRouter();

        parent::tearDown();
    }

    #[Test]
    public function 비활성_플러그인의_라우트는_등록되지_않는다(): void
    {
        $this->seedPlugin('sirsoft-gdpr', ExtensionStatus::Active);
        PluginManager::invalidatePluginStatusCache();

        $active = $this->registerPluginRoutes();

        $this->assertNotEmpty(
            $this->urisFor($active, 'api/plugins/sirsoft-gdpr'),
            '활성 플러그인의 라우트가 등록되지 않았다 — 판정기 모집단이 비어 아래 단언이 무의미해진다'
        );

        $this->seedPlugin('sirsoft-gdpr', ExtensionStatus::Inactive);
        PluginManager::invalidatePluginStatusCache();

        $inactive = $this->registerPluginRoutes();

        $this->assertSame(
            [],
            $this->urisFor($inactive, 'api/plugins/sirsoft-gdpr'),
            '비활성 플러그인의 라우트가 등록됐다 — 화면·메뉴만 사라지고 API 는 계속 호출 가능한 상태가 된다'
        );
    }

    #[Test]
    public function 비활성_모듈의_라우트는_등록되지_않는다(): void
    {
        $this->seedModule('sirsoft-page', ExtensionStatus::Active);
        ModuleManager::invalidateModuleStatusCache();

        $active = $this->registerModuleRoutes();

        $this->assertNotEmpty(
            $this->urisFor($active, 'api/modules/sirsoft-page'),
            '활성 모듈의 라우트가 등록되지 않았다 — 판정기 모집단이 비어 아래 단언이 무의미해진다'
        );

        $this->seedModule('sirsoft-page', ExtensionStatus::Inactive);
        ModuleManager::invalidateModuleStatusCache();

        $inactive = $this->registerModuleRoutes();

        $this->assertSame(
            [],
            $this->urisFor($inactive, 'api/modules/sirsoft-page'),
            '비활성 모듈의 라우트가 등록됐다'
        );
    }

    #[Test]
    public function 두_라우트_프로바이더가_같은_활성_게이트를_쓴다(): void
    {
        // 한쪽에만 게이트가 있으면 그 비대칭은 오류가 아니라 "조용히 열린 경로" 로만
        // 나타난다. 소스에서 게이트 존재를 함께 확인해, 한쪽만 제거되는 회귀를 막는다.
        $cases = [
            ModuleRouteServiceProvider::class => 'getActiveModuleIdentifiers',
            PluginRouteServiceProvider::class => 'getActivePluginIdentifiers',
        ];

        foreach ($cases as $class => $resolver) {
            $source = file_get_contents((new ReflectionClass($class))->getFileName());

            $this->assertStringContainsString(
                'self::'.$resolver.'()',
                $source,
                $class.' 가 활성 확장 목록을 조회하지 않는다'
            );

            $this->assertMatchesRegularExpression(
                '/if \(! in_array\(\$\w+, \$active\w+\)\) \{\s*continue;/',
                $source,
                $class.' 가 활성 목록으로 라우트 등록을 게이트하지 않는다 — '
                .'비활성 확장의 API 가 계속 호출 가능해진다'
            );
        }
    }

    /**
     * 플러그인 라우트를 격리된 라우터에 등록하고 그 라우터를 반환합니다.
     *
     * @return Router 등록 결과가 담긴 라우터
     */
    private function registerPluginRoutes(): Router
    {
        return $this->registerRoutesWith(PluginRouteServiceProvider::class, 'loadPluginRoutes');
    }

    /**
     * 모듈 라우트를 격리된 라우터에 등록하고 그 라우터를 반환합니다.
     *
     * @return Router 등록 결과가 담긴 라우터
     */
    private function registerModuleRoutes(): Router
    {
        return $this->registerRoutesWith(ModuleRouteServiceProvider::class, 'loadModuleRoutes');
    }

    /**
     * 프로바이더의 라우트 로더를 격리된 라우터 위에서 실행합니다.
     *
     * 애플리케이션 라우터를 그대로 쓰면 이미 부팅 시 등록된 라우트와 섞여 "이번 호출이
     * 등록한 것" 을 분리할 수 없다. 빈 라우터로 교체해 이번 호출의 결과만 관측한다.
     *
     * @param  string  $providerClass  라우트 프로바이더 클래스
     * @param  string  $loader  라우트 로더 메서드명
     * @return Router 등록 결과가 담긴 라우터
     */
    private function registerRoutesWith(string $providerClass, string $loader): Router
    {
        $this->restoreRouter();
        $this->originalRouter = app('router');

        $fresh = new Router(app('events'), app());
        app()->instance('router', $fresh);
        Facade::clearResolvedInstance('router');

        $provider = new $providerClass(app());
        (new ReflectionClass($provider))->getMethod($loader)->invoke($provider);

        $this->restoreRouter();

        return $fresh;
    }

    /**
     * 교체했던 애플리케이션 라우터를 원복합니다.
     */
    private function restoreRouter(): void
    {
        if ($this->originalRouter === null) {
            return;
        }

        app()->instance('router', $this->originalRouter);
        Facade::clearResolvedInstance('router');
        $this->originalRouter = null;
    }

    /**
     * 라우터에 등록된 URI 중 주어진 접두사로 시작하는 것을 반환합니다.
     *
     * @param  Router  $router  대상 라우터
     * @param  string  $prefix  URI 접두사
     * @return array<int, string> 매칭된 URI 목록
     */
    private function urisFor(Router $router, string $prefix): array
    {
        $uris = [];

        foreach ($router->getRoutes() as $route) {
            if (str_starts_with($route->uri(), $prefix)) {
                $uris[] = $route->uri();
            }
        }

        return array_values(array_unique($uris));
    }

    /**
     * plugins 테이블에 지정한 상태로 행을 준비합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  ExtensionStatus  $status  적용할 상태
     */
    private function seedPlugin(string $identifier, ExtensionStatus $status): void
    {
        $this->seedExtensionRow('plugins', $identifier, $status);
    }

    /**
     * modules 테이블에 지정한 상태로 행을 준비합니다.
     *
     * @param  string  $identifier  모듈 식별자
     * @param  ExtensionStatus  $status  적용할 상태
     */
    private function seedModule(string $identifier, ExtensionStatus $status): void
    {
        $this->seedExtensionRow('modules', $identifier, $status);
    }

    /**
     * 확장 테이블에 식별자/상태 행을 upsert 합니다.
     *
     * @param  string  $table  대상 테이블 (modules|plugins)
     * @param  string  $identifier  확장 식별자
     * @param  ExtensionStatus  $status  적용할 상태
     */
    private function seedExtensionRow(string $table, string $identifier, ExtensionStatus $status): void
    {
        $existing = DB::table($table)->where('identifier', $identifier)->first();

        if ($existing !== null) {
            DB::table($table)->where('identifier', $identifier)->update([
                'status' => $status->value,
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table($table)->insert([
            'identifier' => $identifier,
            'vendor' => explode('-', $identifier)[0],
            'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
            'version' => '1.0.0',
            'status' => $status->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
