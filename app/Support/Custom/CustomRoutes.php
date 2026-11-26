<?php

namespace App\Support\Custom;

use App\Contracts\Extension\CacheInterface;
use App\Support\StaticExtensionPattern;
use Illuminate\Container\Container;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;

/**
 * 개발자 라우트 파일 `routes/custom.php`(custom 예약 자리) 로더.
 *
 * 코어 `routes/web.php` 맨 앞에서 불려, G7 화면 catch-all(`/{any?}`·`/admin/{any?}`)보다 먼저 개발자 라우트를
 * 등록한다 — 라라벨 라우트 순서대로 개발자 화면이 이기고, 등록하지 않은 주소는 G7 화면이 그대로 그린다.
 *
 * - 모든 custom 라우트에 미들웨어 묶음 `custom.screen`(정식 주소 이동·봇 표식·봇 기록·서버 폴백 집계)을 씌운다.
 * - 코어 API·자산 주소(`/api`·`/plugins`·`/modules`·`/_boost`·`/build`·`/storage`·`/install`, 정적 자산 확장자)를
 *   가리는 라우트는 등록하지 않고 경고 로그를 남긴다 — 사이트 전체가 망가지는 것을 막는다.
 * - 엔진 라우터가 이 주소로 갈 때 전체 페이지 이동을 하도록 GET 패턴 목록(`G7Config.customRoutes`)을 만든다.
 *
 * @since 7.2.0
 */
class CustomRoutes
{
    /** 개발자 라우트 파일 (base 상대) */
    public const FILE = 'routes/custom.php';

    /** custom 라우트에 씌우는 미들웨어 묶음 이름 */
    public const MIDDLEWARE_GROUP = 'custom.screen';

    /** custom 라우트가 쓸 수 없는 첫 구간 (코어 API·확장 자산·빌드·설치기·개발 대시보드) */
    public const RESERVED_PREFIXES = ['api', 'plugins', 'modules', '_boost', 'build', 'storage', 'install', 'dev'];

    /** 코어가 루트에 내놓는 사이트맵 파일 이름 (`/sitemap.xml`·`/sitemap-{n}.xml`) — 첫 구간 전체와 맞춘다 */
    public const RESERVED_SITEMAP_PATTERN = '/^sitemap(-[^\/]+)?\.xml$/i';

    /** 라우트 캐시 기준 패턴 보관 키 접두 */
    private const PATTERN_CACHE_PREFIX = 'custom_routes.patterns.';

    /** 패턴 보관 기간(초) — 키에 라우트 캐시 파일 서명이 들어 있어 낡은 값은 읽히지 않고 기간이 지나 사라진다 */
    private const PATTERN_CACHE_TTL = 86400;

    /**
     * 이번 프로세스에서 거부한 라우트.
     *
     * @var array<int, array{methods: string, uri: string, reason: string}>
     */
    private static array $rejected = [];

    /**
     * 범위별 GET 패턴 메모이즈 (라우트 집합 객체 id 기준).
     *
     * @var array{key: int|null, value: array{user: array<int, string>, admin: array<int, string>}}|null
     */
    private static ?array $patternCache = null;

    /**
     * `routes/custom.php` 가 있으면 불러 등록합니다 (없으면 아무 일 없음).
     *
     * @param  Router  $router  라우터
     * @param  string|null  $file  라우트 파일 절대 경로 (null 이면 base_path(FILE))
     * @return void 반환값 없음
     */
    public static function load(Router $router, ?string $file = null): void
    {
        $file ??= base_path(self::FILE);
        if (! is_file($file)) {
            return;
        }

        $before = self::routeIds($router->getRoutes());

        // web 그룹을 명시한다 — routes/web.php 안에서 불릴 때는 이미 web 그룹이라 라우트 미들웨어 중복 제거(uniqueMiddleware)로
        // 한 번만 돈다. 다른 자리(시험·도구)에서 불려도 세션·CSRF·로케일이 빠지지 않게 한다.
        $router->middleware(['web', self::MIDDLEWARE_GROUP])->group($file);

        $added = array_filter(
            $router->getRoutes()->getRoutes(),
            fn (Route $route) => ! isset($before[spl_object_id($route)]),
        );

        $rejected = [];
        foreach ($added as $route) {
            $reason = self::forbiddenReason($route->uri());
            if ($reason !== null) {
                $rejected[spl_object_id($route)] = true;
                $entry = ['methods' => implode('|', $route->methods()), 'uri' => '/'.ltrim($route->uri(), '/'), 'reason' => $reason];
                self::$rejected[] = $entry;
                Log::warning('[custom] routes/custom.php 의 라우트를 등록하지 않았습니다 — 코어 예약 주소를 가립니다', $entry);
            }
        }

        if ($rejected !== []) {
            $kept = new RouteCollection;
            foreach ($router->getRoutes()->getRoutes() as $route) {
                if (! isset($rejected[spl_object_id($route)])) {
                    $kept->add($route);
                }
            }
            $router->setRoutes($kept);
        }

        self::$patternCache = null;
    }

    /**
     * 라우트 파일을 실제 라우터와 분리된 라우터에 불러 등록 결과·거부 목록을 돌려줍니다 (`custom:check` 용).
     *
     * 라우트 캐시가 켜져 있으면 `routes/web.php` 가 실행되지 않아 거부 기록이 남지 않으므로, 점검은 파일을
     * 따로 읽어 판정한다.
     *
     * @param  string|null  $file  라우트 파일 절대 경로
     * @return array{exists: bool, registered: array<int, array{methods: string, uri: string, action: string, middleware: array<int, string>}>, rejected: array<int, array{methods: string, uri: string, reason: string}>}
     */
    public static function inspect(?string $file = null): array
    {
        $file ??= base_path(self::FILE);
        if (! is_file($file)) {
            return ['exists' => false, 'registered' => [], 'rejected' => []];
        }

        $app = Container::getInstance();
        $original = $app->make('router');
        $sandbox = new Router($app->make('events'), $app);
        $app->instance('router', $sandbox);
        Facade::clearResolvedInstance('router');

        try {
            $sandbox->middleware(['web', self::MIDDLEWARE_GROUP])->group($file);
        } finally {
            $app->instance('router', $original);
            Facade::clearResolvedInstance('router');
        }

        $registered = [];
        $rejected = [];
        foreach ($sandbox->getRoutes()->getRoutes() as $route) {
            $uri = '/'.ltrim($route->uri(), '/');
            $methods = implode('|', array_diff($route->methods(), ['HEAD']));
            $reason = self::forbiddenReason($route->uri());
            if ($reason !== null) {
                $rejected[] = ['methods' => $methods, 'uri' => $uri, 'reason' => $reason];

                continue;
            }
            $registered[] = [
                'methods' => $methods,
                'uri' => $uri,
                'action' => ltrim((string) $route->getActionName(), '\\'),
                'middleware' => array_values(array_map('strval', $route->middleware())),
            ];
        }

        return ['exists' => true, 'registered' => $registered, 'rejected' => $rejected];
    }

    /**
     * URI 가 custom 라우트로 쓸 수 없는 주소면 그 이유를 돌려줍니다.
     *
     * @param  string  $uri  라우트 URI (앞 슬래시 유무 무관)
     * @return string|null 거부 이유(`reserved_prefix:{접두}`·`static_extension`), 쓸 수 있으면 null
     */
    public static function forbiddenReason(string $uri): ?string
    {
        $path = trim($uri, '/');
        $first = strtolower(explode('/', $path)[0] ?? '');
        if (in_array($first, self::RESERVED_PREFIXES, true) || preg_match(self::RESERVED_SITEMAP_PATTERN, $first) === 1) {
            return 'reserved_prefix:'.$first;
        }

        $last = (string) (strrchr($path, '/') ?: '/'.$path);
        if (preg_match('/\.('.implode('|', array_map('preg_quote', StaticExtensionPattern::servedExtensions())).')$/i', $last) === 1) {
            return 'static_extension';
        }

        return null;
    }

    /**
     * 이번 프로세스에서 거부한 라우트 목록.
     *
     * @return array<int, array{methods: string, uri: string, reason: string}> 거부 목록
     */
    public static function rejected(): array
    {
        return self::$rejected;
    }

    /**
     * 엔진에 내려 줄 custom GET 주소 패턴을 범위별로 돌려줍니다.
     *
     * `{param}`·`{param?}` 구간은 `*` 로 바꾼다. `/admin` 으로 시작하면 관리자 범위다. 라우트 캐시를 쓰면 캐시된
     * 라우트 집합에서 그대로 읽으므로 캐시 재생성 때 함께 바뀐다.
     *
     * @param  Router|null  $router  라우터 (null 이면 컨테이너)
     * @return array{user: array<int, string>, admin: array<int, string>} 범위별 패턴
     */
    public static function patterns(?Router $router = null): array
    {
        $router ??= Container::getInstance()->make('router');
        $routes = $router->getRoutes();
        $key = spl_object_id($routes);
        if (self::$patternCache !== null && self::$patternCache['key'] === $key) {
            return self::$patternCache['value'];
        }

        // 라우트 캐시를 쓰면 캐시된 라우트 집합에서 GET 목록을 꺼낼 때마다 모든 라우트 객체를 다시 만든다 — 셸 요청마다
        // 그 비용을 치르지 않도록 라우트 캐시 파일 기준으로 보관하고, route:cache 를 다시 만들면 새로 만든다.
        $persistentKey = self::persistentKey($routes);
        $out = $persistentKey === null
            ? self::buildPatterns($routes)
            : self::rememberPatterns($persistentKey, $routes);

        self::$patternCache = ['key' => $key, 'value' => $out];

        return $out;
    }

    /**
     * 라우트 캐시 파일 기준 보관 키를 만듭니다.
     *
     * @param  RouteCollectionInterface  $routes  라우트 집합
     * @return string|null 캐시된 라우트 집합이면 키, 아니면 null(매번 만들어도 싸다)
     */
    private static function persistentKey(RouteCollectionInterface $routes): ?string
    {
        if (! $routes instanceof CompiledRouteCollection) {
            return null;
        }
        $path = Container::getInstance()->make('app')->getCachedRoutesPath();
        if (! is_file($path)) {
            return null;
        }

        return self::PATTERN_CACHE_PREFIX.sha1($path.'|'.filemtime($path).'|'.filesize($path));
    }

    /**
     * 보관한 패턴을 읽고, 없으면 만들어 보관합니다. 캐시 저장소가 실패하면 만들어서 돌려준다.
     *
     * @param  string  $key  보관 키
     * @param  RouteCollectionInterface  $routes  라우트 집합
     * @return array{user: array<int, string>, admin: array<int, string>} 범위별 패턴
     */
    private static function rememberPatterns(string $key, RouteCollectionInterface $routes): array
    {
        try {
            return app(CacheInterface::class)->remember($key, fn () => self::buildPatterns($routes), self::PATTERN_CACHE_TTL);
        } catch (\Throwable $e) {
            Log::warning('[CustomRoutes] 패턴 보관 실패', ['error' => $e->getMessage()]);

            return self::buildPatterns($routes);
        }
    }

    /**
     * 라우트 집합에서 custom GET 주소 패턴을 만듭니다.
     *
     * @param  RouteCollectionInterface  $routes  라우트 집합
     * @return array{user: array<int, string>, admin: array<int, string>} 범위별 패턴
     */
    private static function buildPatterns(RouteCollectionInterface $routes): array
    {
        $out = ['user' => [], 'admin' => []];
        foreach ($routes->get('GET') as $route) {
            if (! in_array(self::MIDDLEWARE_GROUP, (array) $route->middleware(), true)) {
                continue;
            }
            // 경로 자리는 제약(where)이 있으면 `{정규식}`, 없으면 `*` — 제약을 잃으면 `/board/{slug}/{id}`(숫자)가
            // 엔진의 `/board/free/write` 이동까지 가로채 전체 페이지 이동으로 만든다. 슬래시를 받을 수 있는
            // 제약은 구간 비교로 표현할 수 없어 `*` 로 둔다.
            $wheres = $route->wheres;
            $pattern = '/'.ltrim((string) preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($wheres): string {
                $where = $wheres[$m[1]] ?? null;

                return is_string($where) && $where !== '' && ! str_contains($where, '/') ? '{'.$where.'}' : '*';
            }, $route->uri()), '/');
            $scope = ($pattern === '/admin' || str_starts_with($pattern, '/admin/')) ? 'admin' : 'user';
            if (! in_array($pattern, $out[$scope], true)) {
                $out[$scope][] = $pattern;
            }
        }

        return $out;
    }

    /**
     * 테스트·재로드용 내부 상태 초기화.
     *
     * @return void 반환값 없음
     */
    public static function flushState(): void
    {
        self::$rejected = [];
        self::$patternCache = null;
    }

    /**
     * 라우트 집합의 객체 id 집합을 만듭니다.
     *
     * @param  RouteCollectionInterface  $routes  라우트 집합
     * @return array<int, true> 객체 id => true
     */
    private static function routeIds($routes): array
    {
        $ids = [];
        foreach ($routes->getRoutes() as $route) {
            $ids[spl_object_id($route)] = true;
        }

        return $ids;
    }
}
