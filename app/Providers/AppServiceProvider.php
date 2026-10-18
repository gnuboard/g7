<?php

namespace App\Providers;

use App\Contracts\Extension\HookManagerInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Helpers\ResponseHelper;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Notifications\NotificationChannelManager;
use App\Services\ChannelReadinessService;
use App\Services\GeoIpService;
use App\Support\Routing\DualExtensionRoute;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Boost\BoostServiceProvider;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalAdapter;
use League\Flysystem\PathPrefixing\PathPrefixedAdapter;
use League\Flysystem\ReadOnly\ReadOnlyFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\Visibility;
use League\Flysystem\WhitespacePathNormalizer;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        // 자산 URL 이중 모드 Route 매크로 (dualSuffix / dualAsset).
        // boot() 가 아니라 register() 에서 등록하는 이유: 라우트 파일은 프레임워크의
        // 라우팅 부트스트랩(boot 단계)에서 로드되므로, 프로바이더 간 boot 순서에
        // 의존하면 매크로 미정의 시점에 라우트가 로드될 수 있다. 모든 프로바이더의
        // register() 는 어떤 boot() 보다 먼저 실행되므로 여기가 유일하게 안전한 지점이다.
        DualExtensionRoute::register();

        // NOTE: Faker 부재 시 FakerShim 대체는 app/Support/SampleData/bootstrap.php 에서 처리
        // (composer autoload.files 진입점 — vendor/autoload.php 로드 직후 실행되어
        //  Laravel 의 fake() 헬퍼 정의 시점에 \Faker\Factory 가 이미 alias 되어 있음)

        // 알림 발송 공통 디스패처 — 채널 독립 발송 + 발송 전후 G7 훅 실행
        $this->app->singleton(
            ChannelManager::class,
            fn ($app) => new NotificationChannelManager($app)
        );

        // 채널 Readiness 검증 — 미설정 채널 발송 사전 차단
        $this->app->singleton(
            ChannelReadinessCheckerInterface::class,
            ChannelReadinessService::class
        );

        // TODO: TemplateManagerInterface 바인딩을 추가해야 함

        // PluginManagerInterface 바인딩
        $this->app->bind(
            PluginManagerInterface::class,
            PluginManager::class
        );

        // ModuleManagerInterface 바인딩
        $this->app->bind(
            ModuleManagerInterface::class,
            ModuleManager::class
        );

        // HookManagerInterface 바인딩
        $this->app->bind(
            HookManagerInterface::class,
            HookManager::class
        );

        // GeoIpService 싱글톤 등록
        $this->app->singleton(GeoIpService::class);

        // 상대경로 순회를 거부하는 로컬 디스크 드라이버 (config/filesystems.php 의 driver: local-safe).
        // 디스크 해석보다 먼저 등록돼야 하므로 boot() 가 아니라 register() 에서 건다.
        $this->callAfterResolving('filesystem', function (FilesystemManager $manager) {
            $manager->extend('local-safe', fn ($app, array $config) => $this->createTraversalSafeLocalDisk($config));
        });

        // Laravel Boost (개발 전용 - dont-discover 대상, 클래스 존재 시에만 등록)
        if (class_exists(BoostServiceProvider::class)) {
            $this->app->register(BoostServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // View Composer 등록
        View::composer('admin', TemplateComposer::class);
        View::composer('app', UserTemplateComposer::class);

        // SQL 쿼리 로그 설정
        $this->configureSqlQueryLogging();

        // 아웃바운드 HTTP 프록시 설정
        $this->configureOutboundProxy();

        // 로그인 라우트 per-IP 백업 throttle — 보안 환경설정의 per-account 잠금과 2중 방어.
        // 존재하지 않는 계정에 대한 brute-force / 동일 IP 의 다른 계정 시도까지 차단.
        $this->configureLoginRateLimiter();
    }

    /**
     * 상대경로 순회(`..`)를 거부하는 로컬 디스크를 생성합니다.
     *
     * 표준 `local` 드라이버는 Flysystem 을 `WhitespacePathNormalizer` 기본값
     * (allowRelativePathTraversal=true)으로 만들어 `a/b/../..` 를 조용히 상위 경로로 축약하고,
     * 디스크 설정의 `allow_relative_path_traversal` 키도 Flysystem 에 넘기지 않는다.
     * 사용자 입력이 경로에 섞일 수 있는 디스크는 이 드라이버로 바꿔, 스토리지 드라이버의
     * 경로 가드가 뚫리더라도 `..` 가 어댑터에 닿기 전에 PathTraversalDetected 로 막는다.
     *
     * 생성 절차는 FilesystemManager::createLocalDriver() 와 같고 정규화기만 다르다.
     * 사용자 정의 드라이버에는 디스크 이름이 전달되지 않으므로 `serve` 서명 URL 서빙은
     * 지원하지 않는다 — 이 드라이버를 쓰는 디스크는 serve=false 여야 한다.
     *
     * @param  array<string, mixed>  $config  디스크 설정
     * @return LocalFilesystemAdapter 로컬 디스크
     */
    private function createTraversalSafeLocalDisk(array $config): LocalFilesystemAdapter
    {
        $visibility = PortableVisibilityConverter::fromArray(
            $config['permissions'] ?? [],
            $config['directory_visibility'] ?? $config['visibility'] ?? Visibility::PRIVATE
        );

        $links = ($config['links'] ?? null) === 'skip'
            ? LocalAdapter::SKIP_LINKS
            : LocalAdapter::DISALLOW_LINKS;

        $adapter = new LocalAdapter($config['root'], $visibility, $config['lock'] ?? LOCK_EX, $links);

        $filesystem = new Flysystem(
            $this->wrapLocalSafeAdapter($adapter, $config),
            Arr::only($config, ['directory_visibility', 'disable_asserts', 'retain_visibility', 'temporary_url', 'url', 'visibility']),
            new WhitespacePathNormalizer((bool) ($config['allow_relative_path_traversal'] ?? false))
        );

        return new LocalFilesystemAdapter($filesystem, $adapter, $config);
    }

    /**
     * local-safe 디스크에 Laravel 기본 local 드라이버와 같은 `read-only`·`prefix` 옵션을 적용합니다.
     *
     * 기본 드라이버(`FilesystemManager::createFlysystem`)는 두 옵션을 어댑터 래퍼로 처리하는데, 사용자 정의
     * 드라이버는 그 경로를 타지 않아 옵션이 조용히 무시됐다. 같은 래퍼로 감싸고, 래퍼 패키지가 없으면
     * (Laravel 도 선택 의존성으로 두는 league/flysystem-read-only · league/flysystem-path-prefixing)
     * 무시하지 않고 설정 오류로 알린다 — 읽기 전용이라 믿은 디스크에 쓰기가 되는 일을 막는다.
     *
     * @param  FlysystemAdapter  $adapter  로컬 어댑터
     * @param  array<string, mixed>  $config  디스크 설정
     * @return FlysystemAdapter 옵션을 반영한 어댑터
     *
     * @throws InvalidArgumentException 옵션에 필요한 래퍼 패키지가 없을 때
     */
    private function wrapLocalSafeAdapter(FlysystemAdapter $adapter, array $config): FlysystemAdapter
    {
        if ($config['read-only'] ?? false) {
            if (! class_exists(ReadOnlyFilesystemAdapter::class)) {
                throw new InvalidArgumentException(__('exceptions.storage.local_safe_option_package', ['option' => 'read-only', 'package' => 'league/flysystem-read-only']));
            }
            $adapter = new ReadOnlyFilesystemAdapter($adapter);
        }

        if (! empty($config['prefix'])) {
            if (! class_exists(PathPrefixedAdapter::class)) {
                throw new InvalidArgumentException(__('exceptions.storage.local_safe_option_package', ['option' => 'prefix', 'package' => 'league/flysystem-path-prefixing']));
            }
            $adapter = new PathPrefixedAdapter($adapter, $config['prefix']);
        }

        return $adapter;
    }

    /**
     * 로그인 엔드포인트(`/api/auth/login`, `/api/auth/admin/login`) 의 per-IP RateLimiter 를 등록합니다.
     *
     * 보안 환경설정 `security.max_login_attempts` 에 비례하여 분당 허용량을 산출하되
     * 최소 30 회/분 을 보장 (정상 사용자 오타/타이핑 실수에 대비). 설정 조회 실패 시
     * 기본값 60 회/분 으로 폴백 — 부팅 안전성 (마이그레이션 전 진입) 확보.
     *
     * @return void
     */
    private function configureLoginRateLimiter(): void
    {
        RateLimiter::for('auth-login', function (Request $request) {
            try {
                $perAccount = (int) g7_core_settings('security.max_login_attempts', 5);
                $maxPerMinute = max(30, $perAccount * 6);
            } catch (\Throwable $e) {
                $maxPerMinute = 60;
            }

            // 기본 응답은 영문 "Too Many Attempts." 이다 — 로그인 화면은 이 문구를
            // 그대로 노출하므로 다국어 키로 갈아끼운다.
            return Limit::perMinute($maxPerMinute)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return ResponseHelper::error(
                        'auth.too_many_attempts',
                        429,
                        null,
                        // `count` 로 「1 second」/「2 seconds」 를 고른다.
                        ['seconds' => (int) ($headers['Retry-After'] ?? 60), 'count' => (int) ($headers['Retry-After'] ?? 60)]
                    )->withHeaders($headers);
                });
        });
    }

    /**
     * 아웃바운드 HTTP 프록시를 설정합니다.
     *
     * 환경설정에 프록시가 지정되어 있으면 `Http::` 파사드로 나가는 모든 요청이 그 프록시를
     * 경유합니다. 결제 승인, 코어 업데이트 조회, GeoIP 내려받기, 알림 웹훅 등 확장이 보내는
     * 요청까지 함께 적용되므로, 확장 코드를 고치지 않고도 출발지 IP 를 바꿀 수 있습니다.
     *
     * 적용 여부 판정은 `App\Support\OutboundProxy` 가 소유하며, 이 메서드는 판정 결과만
     * 소비합니다 — 디버그 모드 게이트를 여기서 다시 검사하지 않는 이유입니다.
     *
     * 개별 요청이 `withOptions(['proxy' => ...])` 로 지정한 값은 전역 옵션보다 우선합니다.
     *
     * @return void
     */
    private function configureOutboundProxy(): void
    {
        $proxy = config('g7.outbound_proxy');

        if (empty($proxy)) {
            return;
        }

        Http::globalOptions(['proxy' => $proxy]);
    }

    /**
     * SQL 쿼리 로깅을 설정합니다.
     *
     * 환경설정에서 sql_query_log가 활성화된 경우
     * 모든 SQL 쿼리를 storage/logs/query.log에 기록합니다.
     *
     * @return void
     */
    private function configureSqlQueryLogging(): void
    {
        if (! config('g7.sql_query_log', false)) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            $sql = $query->sql;
            $bindings = $query->bindings;
            $time = $query->time;

            // 바인딩 값을 SQL에 삽입하여 완전한 쿼리 생성
            foreach ($bindings as $binding) {
                $value = is_numeric($binding) ? $binding : "'{$binding}'";
                $sql = preg_replace('/\?/', (string) $value, $sql, 1);
            }

            Log::channel('query')->info("Query ({$time}ms): {$sql}");
        });
    }
}
