<?php

namespace Tests\Feature\Api\Public;

use App\Contracts\Extension\CacheInterface;
use App\Enums\ExtensionStatus;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Http\Controllers\Api\Public\PublicTemplateController;
use App\Models\Template;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 미인증 무제한 서버 캐시 생성(DoS) 회귀 테스트 (KVE-2026-2336)
 *
 * 수정 전: 공개 routes/lang 엔드포인트가 클라이언트 `?v=` 정수를 서버 캐시 키에 그대로 넣어
 * `?v=1,2,3,…` 요청마다 새 캐시 엔트리(lang 은 건당 수백 KB)를 기록했고, 공개 lane 에는
 * 속도 제한이 없었다.
 */
class PublicTemplateCacheAmplificationTest extends TestCase
{
    use DatabaseTransactions;

    private const TEMPLATE = 'sirsoft-admin_basic';

    /**
     * public_ext 속도 제한을 받는 공개 데이터 응답 (서버가 계산·캐시하는 경로)
     *
     * @var array<int, string>
     */
    private const THROTTLED_PUBLIC_ROUTES = [
        'api.public.templates.routes',
        'api.public.templates.config',
        'api.public.templates.editor_spec',
        'api.public.templates.components',
        'api.public.templates.language',
        'api.public.layouts.preview.serve',
        'api.public.layouts.serve',
        'api.public.modules.editor_spec',
        'api.public.modules.components',
        'api.public.plugins.editor_spec',
        'api.public.plugins.components',
    ];

    /**
     * public_ext 속도 제한을 받지 않는 공개 라우트 (파일 서빙이거나 자체 제한이 있는 경로)
     *
     * @var array<int, string>
     */
    private const UNTHROTTLED_PUBLIC_ROUTES = [
        'api.public.analytics.collect',
        'api.public.consent',
        'api.public.analytics.summary',
        'api.public.templates.assets',
        'api.public.templates.layout-attachment-file',
        'api.public.modules.bundle.js',
        'api.public.modules.bundle.css',
        'api.public.modules.assets',
        'api.public.plugins.bundle.js',
        'api.public.plugins.bundle.css',
        'api.public.plugins.assets',
        'api.public.users.profile',
        'api.public.locales.active',
        'api.public.extensions.icon',
        'api.public.system.asset-probe',
    ];

    private CacheInterface $cache;

    /**
     * 활성 템플릿 행을 준비합니다 (파일은 활성 디렉토리의 실제 템플릿).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Template::updateOrCreate(
            ['identifier' => self::TEMPLATE],
            [
                'vendor' => 'sirsoft',
                'name' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
                'version' => '1.0.0',
                'type' => 'admin',
                'status' => ExtensionStatus::Active->value,
                'description' => ['ko' => '기본 관리자 템플릿', 'en' => 'Basic Admin Template'],
            ]
        );

        $this->cache = app(CacheInterface::class);
        RateLimiter::clear('public_ext'.sha1('|127.0.0.1'));
    }

    /**
     * 서로 다른 `?v` 로 routes 를 요청해도 서버 캐시 키는 서버 버전 하나뿐이다.
     *
     * @scenario endpoint=routes, client_version=present
     *
     * @effects client_version_never_reaches_server_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_routes_cache_key_ignores_client_version(): void
    {
        $server = PublicTemplateController::getExtensionCacheVersion();

        foreach ([910001, 910002, 910003] as $v) {
            $this->getJson('/api/templates/'.self::TEMPLATE."/routes.json?v={$v}")->assertStatus(200);
            $this->assertFalse($this->cache->has('template.routes.'.self::TEMPLATE.".v{$v}"), "?v={$v} 가 서버 캐시 키가 되면 안 됩니다.");
        }

        $this->assertTrue($this->cache->has('template.routes.'.self::TEMPLATE.".v{$server}"));
    }

    /**
     * 서로 다른 `?v` 로 lang 을 요청해도 서버 캐시 키는 서버 버전 하나뿐이다.
     *
     * @scenario endpoint=language, client_version=present
     *
     * @effects client_version_never_reaches_server_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_language_cache_key_ignores_client_version(): void
    {
        $server = PublicTemplateController::getExtensionCacheVersion();

        foreach ([910001, 910002, 910003] as $v) {
            $this->getJson('/api/templates/'.self::TEMPLATE."/lang/ko.json?v={$v}")->assertStatus(200);
            $this->assertFalse($this->cache->has('template.language.'.self::TEMPLATE.".ko.v{$v}"), "?v={$v} 가 서버 캐시 키가 되면 안 됩니다.");
        }

        $this->assertTrue($this->cache->has('template.language.'.self::TEMPLATE.".ko.v{$server}"));
    }

    /**
     * `?v` 없는 lang 요청도 서버 버전 키 하나에만 캐시된다 (`.v0` 사각 키 없음).
     *
     * @scenario endpoint=language, client_version=absent
     *
     * @effects client_version_never_reaches_server_cache_key
     *
     * @return void 반환값 없음
     */
    public function test_unversioned_language_request_uses_server_version_key(): void
    {
        $server = PublicTemplateController::getExtensionCacheVersion();

        $this->getJson('/api/templates/'.self::TEMPLATE.'/lang/ko.json')->assertStatus(200);

        $this->assertTrue($this->cache->has('template.language.'.self::TEMPLATE.".ko.v{$server}"));
        $this->assertFalse($this->cache->has('template.language.'.self::TEMPLATE.'.ko.v0'));
    }

    /**
     * 버전 키드 요청은 종전대로 공개 HTTP 캐시 정책을 유지한다 (`?v` 의 HTTP 정책 용도 회귀 가드).
     *
     * @scenario endpoint=routes, client_version=present
     *
     * @effects versioned_url_keeps_http_cache_policy
     *
     * @return void 반환값 없음
     */
    public function test_versioned_routes_request_still_gets_etag(): void
    {
        $server = PublicTemplateController::getExtensionCacheVersion();

        $response = $this->getJson('/api/templates/'.self::TEMPLATE."/routes.json?v={$server}");

        $response->assertStatus(200);
        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    /**
     * 존재하지 않는 템플릿의 routes 에러 결과는 캐시하지 않는다.
     *
     * @scenario endpoint=routes, client_version=absent
     *
     * @effects error_result_is_not_cached
     *
     * @return void 반환값 없음
     */
    public function test_routes_error_result_is_not_cached(): void
    {
        $server = PublicTemplateController::getExtensionCacheVersion();

        $this->getJson('/api/templates/kve-nonexistent-template/routes.json')->assertStatus(404);

        $this->assertFalse($this->cache->has("template.routes.kve-nonexistent-template.v{$server}"));
    }

    /**
     * 공개 라우트마다 속도 제한 여부가 정해진 분류와 일치한다 — 데이터 응답만 public_ext 제한을 받는다.
     *
     * 모집단은 등록된 `api.public.*` 라우트 전체다. 새 공개 라우트가 두 목록 어디에도 없으면 실패해,
     * 제한을 걸지 말지 결정 없이 추가되지 않게 한다.
     *
     * @scenario endpoint=routes, client_version=absent
     *
     * @effects public_data_routes_are_throttled, static_asset_routes_are_not_throttled
     *
     * @return void 반환값 없음
     */
    public function test_only_public_data_routes_are_throttled(): void
    {
        $seen = ['data' => [], 'static' => []];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! str_starts_with($name, 'api.public.')) {
                continue;
            }

            $base = str_replace('.extensionless', '', $name);
            $throttles = array_values(array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_ends_with($m, ',public_ext')
            ));

            if (in_array($base, self::THROTTLED_PUBLIC_ROUTES, true)) {
                $seen['data'][$base] = true;
                $this->assertSame(
                    ['throttle:'.config('auth.throttle.public').',public_ext'],
                    $throttles,
                    "{$name} 은 공개 데이터 응답이라 public_ext 제한이 한 번 걸려야 합니다."
                );
            } elseif (in_array($base, self::UNTHROTTLED_PUBLIC_ROUTES, true)) {
                $seen['static'][$base] = true;
                $this->assertSame([], $throttles, "{$name} 은 정적 자산이라 public_ext 제한을 받으면 안 됩니다.");
            } else {
                $this->fail("공개 라우트 {$name} 의 속도 제한 분류가 없습니다 — THROTTLED/UNTHROTTLED 목록에 추가하세요.");
            }
        }

        $this->assertEqualsCanonicalizing(self::THROTTLED_PUBLIC_ROUTES, array_keys($seen['data']));
        $this->assertEqualsCanonicalizing(self::UNTHROTTLED_PUBLIC_ROUTES, array_keys($seen['static']));
    }

    /**
     * 데이터 응답은 상한을 넘으면 429 가 나고, 같은 방문자의 정적 자산(번들·프로브)은 계속 200 이다.
     *
     * 프록시 뒤에서 전 방문자가 IP 하나로 보여 데이터 응답이 막혀도 번들까지 막히지는 않는다.
     *
     * @scenario endpoint=static_asset, client_version=absent
     *
     * @effects public_data_routes_are_throttled, static_asset_routes_are_not_throttled
     *
     * @return void 반환값 없음
     */
    public function test_data_route_hits_429_while_static_assets_stay_available(): void
    {
        $limit = (int) explode(',', (string) config('auth.throttle.public'))[0];

        $first = $this->get('/api/templates/'.self::TEMPLATE.'/routes.json');
        $first->assertStatus(200);
        $this->assertSame((string) $limit, $first->headers->get('X-RateLimit-Limit'));

        // 상한까지 채운 뒤 다음 요청은 429 (비로그인 키 = 접두사 + sha1(도메인|IP))
        RateLimiter::increment('public_ext'.sha1('|127.0.0.1'), 60, $limit);

        $this->get('/api/templates/'.self::TEMPLATE.'/routes.json')->assertStatus(429);

        $probe = $this->get('/api/system/asset-probe.js');
        $probe->assertStatus(200);
        $this->assertNull($probe->headers->get('X-RateLimit-Limit'), '정적 자산에는 속도 제한 헤더가 없어야 합니다.');
    }

    /**
     * 캐시 버전 bump 는 같은 초 안에서도 반드시 값이 커진다 — 서버 키가 서버 버전만으로 조립되므로
     * 같은 값이 되면 무효화가 조용히 무시된다.
     *
     * @scenario endpoint=language, client_version=absent
     *
     * @effects cache_version_bump_is_strictly_monotonic
     *
     * @return void 반환값 없음
     */
    public function test_cache_version_bump_is_strictly_monotonic(): void
    {
        $bumper = new class
        {
            use ClearsTemplateCaches;

            /**
             * 캐시 버전을 올립니다.
             *
             * @return void 반환값 없음
             */
            public function bump(): void
            {
                $this->incrementExtensionCacheVersion();
            }
        };

        // 현재 버전이 time() 보다 앞서 있어도(같은 초 연속 bump 재현) 다음 값은 그보다 커야 한다
        $ahead = time() + 100;
        $this->cache->put('ext.cache_version', $ahead);

        $bumper->bump();
        $first = PublicTemplateController::getExtensionCacheVersion();
        $bumper->bump();
        $second = PublicTemplateController::getExtensionCacheVersion();

        $this->assertGreaterThan($ahead, $first);
        $this->assertGreaterThan($first, $second);
    }

    /**
     * 정리: throttle 카운터를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        RateLimiter::clear('public_ext'.sha1('|127.0.0.1'));

        parent::tearDown();
    }
}
