<?php

namespace Tests\Feature\Custom;

use App\Contracts\Extension\TemplateManagerInterface;
use App\Models\Analytics\AnalyticsVisit;
use App\Seo\BotDetector;
use App\Seo\TemplateRouteResolver;
use App\Support\Custom\CustomRoutes;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * custom 예약 자리 라우트(`routes/custom.php`) Feature 테스트 (#792 WP-K2)
 *
 * 개발자 라우트가 G7 화면 catch-all 보다 먼저 등록되어 이기고, 등록하지 않은 주소는 G7 셸이 그대로 그리며,
 * 코어 예약 주소를 가리는 라우트는 등록되지 않는지 잠근다. 실제 운영 순서(routes/web.php 맨 앞)를 재현하려고
 * 라우트 집합을 비운 뒤 custom 파일을 먼저 불러오고 기존 라우트를 다시 얹는다.
 */
class CustomRouteTest extends TestCase
{
    use DatabaseTransactions;

    private string $tmpDir;

    /**
     * 임시 라우트 디렉터리를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        CustomRoutes::flushState();
        $this->tmpDir = storage_path('framework/testing/custom_route_'.uniqid());
        File::ensureDirectoryExists($this->tmpDir);
    }

    /**
     * 임시 파일을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmpDir);
        CustomRoutes::flushState();

        parent::tearDown();
    }

    /**
     * custom GET 라우트가 사용자·관리자 catch-all 보다 이기고, custom.screen 묶음이 씌워집니다.
     *
     * @scenario case=custom_wins_over_catch_all
     *
     * @effects custom_route_wins_user, custom_route_wins_admin, custom_screen_middleware_applied
     *
     * @return void 반환값 없음
     */
    public function test_custom_routes_win_over_catch_all(): void
    {
        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/board/{slug}', fn (string $slug) => 'custom-list:'.$slug);
Route::get('/admin/custom-example', fn () => 'custom-admin');
PHP);

        $this->get('/board/free')->assertOk()->assertSeeText('custom-list:free');
        $this->get('/admin/custom-example')->assertOk()->assertSeeText('custom-admin');

        $route = app('router')->getRoutes()->match(Request::create('/board/free'));
        $this->assertContains(CustomRoutes::MIDDLEWARE_GROUP, $route->middleware());
    }

    /**
     * custom 에 없는 주소는 G7 셸이 그대로 그립니다 (주소 단위 부분 위임).
     *
     * @scenario case=unregistered_falls_back_to_shell
     *
     * @effects unregistered_address_is_shell
     *
     * @return void 반환값 없음
     */
    public function test_unregistered_address_is_g7_shell(): void
    {
        $resolver = Mockery::mock(TemplateRouteResolver::class);
        $resolver->shouldReceive('routeExists')->andReturn(true);
        $this->app->instance(TemplateRouteResolver::class, $resolver);

        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/board/{slug}', fn (string $slug) => 'custom-list:'.$slug)->where('slug', '[a-z0-9-]+');
PHP);

        $this->get('/board/free/write')->assertOk()->assertSee('id="app"', false)->assertDontSeeText('custom-list');
    }

    /**
     * 코어 예약 주소(`/api/x`)와 정적 자산 주소(`/build/x.js`)를 가리는 라우트는 등록되지 않고 경고 로그를 남기며,
     * 코어 응답이 그대로 나갑니다.
     *
     * @scenario case=forbidden_rejected
     *
     * @effects forbidden_route_not_registered, forbidden_route_logged, core_response_kept
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_routes_are_rejected_with_warning(): void
    {
        Log::spy();

        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/api/x', fn () => 'hijacked-api');
Route::get('/hello.js', fn () => 'hijacked-js');
Route::get('/ok-page', fn () => 'ok');
PHP);

        $rejected = array_column(CustomRoutes::rejected(), 'uri');
        $this->assertContains('/api/x', $rejected);
        $this->assertContains('/hello.js', $rejected);
        $this->assertNotContains('/ok-page', $rejected);

        $this->getJson('/api/x')->assertNotFound()->assertDontSeeText('hijacked-api');
        $this->get('/ok-page')->assertOk()->assertSeeText('ok');

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains((string) $message, 'routes/custom.php'))->twice();
    }

    /**
     * 금지 주소 판정 — 예약 첫 구간과 정적 자산 확장자만 막고, 비슷한 이름은 통과시킵니다.
     *
     * @scenario case=forbidden_rules
     *
     * @effects forbidden_reason_is_exact
     *
     * @return void 반환값 없음
     */
    public function test_forbidden_reason_rules(): void
    {
        $this->assertSame('reserved_prefix:api', CustomRoutes::forbiddenReason('api/x'));
        $this->assertSame('reserved_prefix:build', CustomRoutes::forbiddenReason('/build/core/app.js'));
        $this->assertSame('reserved_prefix:install', CustomRoutes::forbiddenReason('install'));
        $this->assertSame('static_extension', CustomRoutes::forbiddenReason('/assets/app.css'));
        $this->assertNull(CustomRoutes::forbiddenReason('/apis/x'));
        $this->assertNull(CustomRoutes::forbiddenReason('/board/{slug}'));
        $this->assertNull(CustomRoutes::forbiddenReason('/admin/custom-example'));
        $this->assertNull(CustomRoutes::forbiddenReason('/custom-jquery-example'));
    }

    /**
     * 활성 사용자 템플릿이 없어도 custom 라우트는 200 입니다 (템플릿 의존성 검사를 거치지 않는다).
     *
     * @scenario case=no_user_template
     *
     * @effects custom_route_independent_of_template
     *
     * @return void 반환값 없음
     */
    public function test_custom_route_works_without_user_template(): void
    {
        $manager = Mockery::mock(TemplateManagerInterface::class)->shouldIgnoreMissing();
        $manager->shouldReceive('getActiveTemplate')->andReturn(null);
        $manager->shouldReceive('getActiveTemplateIdentifier')->andReturn(null);
        $this->app->instance(TemplateManagerInterface::class, $manager);

        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/no-template-page', fn () => 'still-works');
PHP);

        $this->get('/no-template-page')->assertOk()->assertSeeText('still-works');
        $route = app('router')->getRoutes()->match(Request::create('/no-template-page'));
        $this->assertNotContains('template.dependencies:user', app('router')->gatherRouteMiddleware($route));
    }

    /**
     * 봇 UA 요청은 custom 화면에서도 `seo.is_bot` 표식을 받아 서버 폴백 집계에서 빠집니다.
     *
     * @scenario case=bot_marked
     *
     * @effects bot_marked_on_custom_screen
     *
     * @return void 반환값 없음
     */
    public function test_bot_request_is_marked_on_custom_screen(): void
    {
        $detector = Mockery::mock(BotDetector::class)->makePartial();
        $detector->shouldReceive('isBot')->andReturnUsing(fn ($request) => str_contains((string) $request->userAgent(), 'Googlebot'));
        $this->app->instance(BotDetector::class, $detector);

        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/bot-probe', fn () => request()->attributes->get('seo.is_bot') === true ? 'bot' : 'human');
PHP);

        $this->get('/bot-probe', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'])->assertSeeText('bot');
        $this->get('/bot-probe', ['User-Agent' => 'Mozilla/5.0 Chrome/141'])->assertSeeText('human');
    }

    /**
     * custom 화면의 서버 폴백 방문 집계는 사람 요청만 세고 봇 요청은 세지 않습니다 (계획 WP-K2 시험
     * 「봇 UA → seo.is_bot + 서버 폴백 집계 제외」의 집계 쪽).
     *
     * @scenario case=bot_marked
     *
     * @effects bot_excluded_from_server_fallback_on_custom_screen
     *
     * @return void 반환값 없음
     */
    public function test_bot_is_excluded_from_server_fallback_on_custom_screen(): void
    {
        $detector = Mockery::mock(BotDetector::class)->makePartial();
        // 통계 수집기가 이름으로 알아보지 못하는 UA 를 봇으로 판정시킨다 — 집계에서 빠지는 이유가 봇 표식뿐이게 한다
        $detector->shouldReceive('isBot')->andReturnUsing(fn ($request) => str_contains((string) $request->userAgent(), 'G7MarkerProbe'));
        $this->app->instance(BotDetector::class, $detector);

        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/visit-probe', fn () => response('<!doctype html><html><body>custom</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
PHP);
        $before = AnalyticsVisit::count();

        $this->get('/visit-probe', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36 G7MarkerProbe'])->assertOk();
        $this->assertSame($before, AnalyticsVisit::count(), '봇 요청은 서버 폴백 방문으로 세지 않는다');

        $this->get('/visit-probe', ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'])->assertOk();
        $this->assertSame($before + 1, AnalyticsVisit::count(), '사람 요청은 서버 폴백 방문 1건');
    }

    /**
     * 엔진에 내려 줄 GET 패턴 — `{param}` → `*`, `/admin` 은 관리자 범위, POST 는 제외.
     *
     * @scenario case=patterns
     *
     * @effects custom_route_patterns_scoped
     *
     * @return void 반환값 없음
     */
    public function test_patterns_are_scoped_and_get_only(): void
    {
        $this->loadCustomRoutes(<<<'PHP'
<?php
use Illuminate\Support\Facades\Route;
Route::get('/board/{slug}', fn () => 'x');
Route::get('/board/{slug}/{id}', fn () => 'x')->whereNumber('id');
Route::get('/admin/custom-example', fn () => 'x');
Route::post('/board/{slug}/like', fn () => 'x');
PHP);

        $patterns = CustomRoutes::patterns();
        // 제약(whereNumber)은 `{정규식}` 으로 남는다 — 잃으면 `/board/free/write` 도 custom 으로 판정된다
        $this->assertSame(['/board/*', '/board/*/{[0-9]+}'], $patterns['user']);
        $this->assertSame(['/admin/custom-example'], $patterns['admin']);
    }

    /**
     * 라우트 캐시를 쓰면 패턴을 라우트 캐시 파일 기준으로 보관해 요청마다 전체 라우트를 다시 만들지 않고,
     * 라우트 캐시를 다시 만들면 새로 만듭니다 (계획 WP-K2 「산출 결과를 캐시, 재생성 때 갱신」).
     *
     * @scenario case=patterns
     *
     * @effects custom_route_patterns_cached_with_route_cache
     *
     * @return void 반환값 없음
     */
    public function test_patterns_follow_route_cache_file(): void
    {
        // 라라벨은 이 값이 `/` 로 시작하지 않으면 base 상대로 읽는다(Windows 드라이브 경로 포함) — base 상대로 준다
        $relative = 'storage/framework/testing-routes-cache-'.uniqid().'.php';
        $cacheFile = base_path($relative);
        File::ensureDirectoryExists(dirname($cacheFile));
        file_put_contents($cacheFile, '<?php // v1');
        // 시험 부트스트랩이 시험 전용 라우트 캐시 경로를 넣어 둔다 — 지우지 말고 끝나면 그 값으로 되돌린다
        $previous = [$_SERVER['APP_ROUTES_CACHE'] ?? null, $_ENV['APP_ROUTES_CACHE'] ?? null, getenv('APP_ROUTES_CACHE')];
        $_SERVER['APP_ROUTES_CACHE'] = $_ENV['APP_ROUTES_CACHE'] = $relative;
        putenv('APP_ROUTES_CACHE='.$relative);

        try {
            CustomRoutes::flushState();
            $this->assertSame(['/board/*'], CustomRoutes::patterns($this->compiledRouter(['/board/{slug}']))['user']);

            // 다음 요청: 새 라우트 집합 객체지만 라우트 캐시 파일이 같으면 보관한 값을 쓴다(라우트를 다시 훑지 않는다)
            CustomRoutes::flushState();
            $this->assertSame(['/board/*'], CustomRoutes::patterns($this->compiledRouter(['/other']))['user']);

            // route:cache 재생성 — 파일이 바뀌면 새로 만든다
            file_put_contents($cacheFile, '<?php // v2 regenerated');
            touch($cacheFile, time() + 5);
            clearstatcache();
            CustomRoutes::flushState();
            $this->assertSame(['/other'], CustomRoutes::patterns($this->compiledRouter(['/other']))['user']);
        } finally {
            [$server, $env, $put] = $previous;
            if ($server === null) {
                unset($_SERVER['APP_ROUTES_CACHE']);
            } else {
                $_SERVER['APP_ROUTES_CACHE'] = $server;
            }
            if ($env === null) {
                unset($_ENV['APP_ROUTES_CACHE']);
            } else {
                $_ENV['APP_ROUTES_CACHE'] = $env;
            }
            putenv($put === false ? 'APP_ROUTES_CACHE' : 'APP_ROUTES_CACHE='.$put);
            File::delete($cacheFile);
            CustomRoutes::flushState();
        }
    }

    /**
     * 캐시된(컴파일된) 라우트 집합을 가진 라우터를 만듭니다 — `route:cache` 뒤 요청의 라우터와 같은 모양.
     *
     * @param  array<int, string>  $uris  custom.screen 을 씌운 GET 주소
     * @return Router 라우터
     */
    private function compiledRouter(array $uris): Router
    {
        $collection = new RouteCollection;
        foreach ($uris as $uri) {
            $route = new Route(['GET', 'HEAD'], $uri, ['uses' => 'App\Custom\Http\Controllers\FakeController@show']);
            $route->middleware(['web', CustomRoutes::MIDDLEWARE_GROUP]);
            $collection->add($route);
        }
        $router = new Router(app('events'), app());
        $router->setCompiledRoutes($collection->compile());

        return $router;
    }

    /**
     * 파일이 없으면 아무 라우트도 더하지 않습니다.
     *
     * @scenario case=no_file
     *
     * @effects missing_file_is_noop
     *
     * @return void 반환값 없음
     */
    public function test_missing_file_is_noop(): void
    {
        $router = app('router');
        $before = count($router->getRoutes()->getRoutes());
        CustomRoutes::load($router, $this->tmpDir.'/nope.php');

        $this->assertCount($before, $router->getRoutes()->getRoutes());
        $this->assertSame(['exists' => false, 'registered' => [], 'rejected' => []], CustomRoutes::inspect($this->tmpDir.'/nope.php'));
    }

    /**
     * 점검용 inspect 는 실제 라우터를 건드리지 않고 등록·거부 목록을 돌려줍니다.
     *
     * @scenario case=inspect_sandbox
     *
     * @effects inspect_does_not_touch_router
     *
     * @return void 반환값 없음
     */
    public function test_inspect_uses_sandbox_router(): void
    {
        $file = $this->tmpDir.'/custom.php';
        File::put($file, "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/sandbox-page', fn () => 'x');\nRoute::get('/api/evil', fn () => 'x');\n");
        $before = count(app('router')->getRoutes()->getRoutes());

        $result = CustomRoutes::inspect($file);

        $this->assertTrue($result['exists']);
        $this->assertSame(['/sandbox-page'], array_column($result['registered'], 'uri'));
        $this->assertSame(['/api/evil'], array_column($result['rejected'], 'uri'));
        $this->assertCount($before, app('router')->getRoutes()->getRoutes());
        $this->assertSame(app('router'), \Illuminate\Support\Facades\Route::getFacadeRoot());
    }

    /**
     * 운영 순서대로 custom 라우트를 먼저, 기존 라우트(G7 catch-all 포함)를 뒤에 등록합니다.
     *
     * @param  string  $source  routes/custom.php 내용
     * @return void 반환값 없음
     */
    private function loadCustomRoutes(string $source): void
    {
        $file = $this->tmpDir.'/custom.php';
        File::put($file, $source);

        $router = app('router');
        $existing = $router->getRoutes()->getRoutes();
        $router->setRoutes(new RouteCollection);

        CustomRoutes::load($router, $file);

        $collection = $router->getRoutes();
        foreach ($existing as $route) {
            // 프로젝트의 실제 routes/custom.php 는 빼고 시험 원본만 남긴다 — 개발자 라우트가 있는 사이트에서도 결과가 같다
            if (in_array(CustomRoutes::MIDDLEWARE_GROUP, (array) $route->middleware(), true)) {
                continue;
            }
            $collection->add($route);
        }
        $collection->refreshNameLookups();
        $collection->refreshActionLookups();
    }
}
