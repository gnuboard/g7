<?php

namespace App\Providers;

use App\Extension\HookManager;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Listeners\ExtensionCompatibilityAlertListener;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
