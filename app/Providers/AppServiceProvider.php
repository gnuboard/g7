<?php

namespace App\Providers;

use App\Contracts\Extension\HookManagerInterface;
use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Notifications\ChannelReadinessCheckerInterface;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Listeners\ExtensionCompatibilityAlertListener;
use App\Listeners\StaticPublishFailureAlertListener;
use App\Notifications\NotificationChannelManager;
use App\Services\ChannelReadinessService;
use App\Services\GeoIpService;
use App\Support\Routing\DualExtensionRoute;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Laravel\Boost\BoostServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
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

        // Laravel Boost (개발 전용 - dont-discover 대상, 클래스 존재 시에만 등록)
        if (class_exists(BoostServiceProvider::class)) {
            $this->app->register(BoostServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // View Composer 등록
        View::composer('admin', TemplateComposer::class);
        View::composer('app', UserTemplateComposer::class);

        // 코어 훅 리스너 등록 (대시보드 알림 등)
        $this->registerCoreHookListeners();

        // SQL 쿼리 로그 설정
        $this->configureSqlQueryLogging();

        // 아웃바운드 HTTP 프록시 설정
        $this->configureOutboundProxy();

        // 로그인 라우트 per-IP 백업 throttle — 보안 환경설정의 per-account 잠금과 2중 방어.
        // 존재하지 않는 계정에 대한 brute-force / 동일 IP 의 다른 계정 시도까지 차단.
        $this->configureLoginRateLimiter();
    }

    /**
     * 로그인 엔드포인트(`/api/auth/login`, `/api/auth/admin/login`) 의 per-IP RateLimiter 를 등록합니다.
     *
     * 보안 환경설정 `security.max_login_attempts` 에 비례하여 분당 허용량을 산출하되
     * 최소 30 회/분 을 보장 (정상 사용자 오타/타이핑 실수에 대비). 설정 조회 실패 시
     * 기본값 60 회/분 으로 폴백 — 부팅 안전성 (마이그레이션 전 진입) 확보.
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

            return Limit::perMinute($maxPerMinute)->by($request->ip());
        });
    }

    /**
     * 코어가 소유한 훅 리스너를 등록합니다.
     *
     * 확장 리스너와 달리 코어 리스너는 자동 발견 대상이 아니므로 여기서 등록한다. 목록만
     * 늘리면 되도록 루프로 둔다 — 리스너마다 등록 메서드를 복제하면 한 곳만 빠져도 그
     * 리스너가 조용히 동작하지 않는다(등록 실패는 예외도 로그도 남기지 않는다).
     *
     * 등록 대상:
     * - `ExtensionCompatibilityAlertListener` — 코어 호환성으로 자동 비활성화/재호환된 확장
     * - `StaticPublishFailureAlertListener` — 부트스트랩 리소스 정적 게시 실패 (#122)
     */
    private function registerCoreHookListeners(): void
    {
        $listenerClasses = [
            ExtensionCompatibilityAlertListener::class,
            StaticPublishFailureAlertListener::class,
        ];

        foreach ($listenerClasses as $listenerClass) {
            $listener = new $listenerClass;

            foreach ($listenerClass::getSubscribedHooks() as $hookName => $config) {
                $method = $config['method'] ?? 'handle';
                $priority = $config['priority'] ?? 10;
                $type = $config['type'] ?? 'action';

                if ($type === 'filter') {
                    HookManager::addFilter($hookName, [$listener, $method], $priority);
                } else {
                    HookManager::addAction($hookName, [$listener, $method], $priority);
                }
            }
        }
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
