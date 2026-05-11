<?php

namespace App\Providers;

use App\Extension\HookManager;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Listeners\ExtensionCompatibilityAlertListener;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // NOTE: Faker 부재 시 FakerShim 대체는 app/Support/SampleData/bootstrap.php 에서 처리
        // (composer autoload.files 진입점 — vendor/autoload.php 로드 직후 실행되어
        //  Laravel 의 fake() 헬퍼 정의 시점에 \Faker\Factory 가 이미 alias 되어 있음)

        // 알림 발송 공통 디스패처 — 채널 독립 발송 + 발송 전후 G7 훅 실행
        $this->app->singleton(
            \Illuminate\Notifications\ChannelManager::class,
            fn ($app) => new \App\Notifications\NotificationChannelManager($app)
        );

        // 채널 Readiness 검증 — 미설정 채널 발송 사전 차단
        $this->app->singleton(
            \App\Contracts\Notifications\ChannelReadinessCheckerInterface::class,
            \App\Services\ChannelReadinessService::class
        );

        // TODO: TemplateManagerInterface 바인딩을 추가해야 함

        // PluginManagerInterface 바인딩
        $this->app->bind(
            \App\Contracts\Extension\PluginManagerInterface::class,
            \App\Extension\PluginManager::class
        );

        // ModuleManagerInterface 바인딩
        $this->app->bind(
            \App\Contracts\Extension\ModuleManagerInterface::class,
            \App\Extension\ModuleManager::class
        );

        // HookManagerInterface 바인딩
        $this->app->bind(
            \App\Contracts\Extension\HookManagerInterface::class,
            \App\Extension\HookManager::class
        );

        // GeoIpService 싱글톤 등록
        $this->app->singleton(\App\Services\GeoIpService::class);

        // Laravel Boost (개발 전용 - dont-discover 대상, 클래스 존재 시에만 등록)
        if (class_exists(\Laravel\Boost\BoostServiceProvider::class)) {
            $this->app->register(\Laravel\Boost\BoostServiceProvider::class);
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

        // 확장 호환성 알림 리스너 등록
        $this->registerExtensionCompatibilityAlertListener();

        // SQL 쿼리 로그 설정
        $this->configureSqlQueryLogging();

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
     * 확장 호환성 알림 리스너를 등록합니다.
     *
     * 코어 버전 호환성 문제로 자동 비활성화된 확장에 대한
     * 알림을 관리자 대시보드에 표시하기 위한 훅 리스너입니다.
     */
    private function registerExtensionCompatibilityAlertListener(): void
    {
        $listener = new ExtensionCompatibilityAlertListener;
        $subscribedHooks = ExtensionCompatibilityAlertListener::getSubscribedHooks();

        foreach ($subscribedHooks as $hookName => $config) {
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
