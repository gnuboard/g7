<?php

namespace App\Providers;

use App\Extension\ExtensionManager;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PluginRouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * 라우트 모델 바인딩, 패턴 필터 및 기타 라우트 구성을 정의합니다.
     */
    public function boot(): void
    {
        $this->routes(function () {
            $this->loadPluginRoutes();
        });
    }

    /**
     * 플러그인의 라우트 파일들을 로드합니다.
     */
    protected function loadPluginRoutes(): void
    {
        // .env 파일이 없으면 스킵 (인스톨러 실행 전)
        if (! File::exists(base_path('.env'))) {
            return;
        }

        $pluginsPath = base_path('plugins');

        if (! File::exists($pluginsPath)) {
            return;
        }

        // DB 연결 가능 여부 및 테이블 존재 여부 확인
        try {
            if (! Schema::hasTable('plugins')) {
                return;
            }
        } catch (\Exception) {
            // DB 연결 실패 시 조용히 스킵 (설정 오류, 마이그레이션 전 등)
            return;
        }

        $plugins = File::directories($pluginsPath);

        foreach ($plugins as $plugin) {
            $pluginName = basename($plugin);
            $pluginFile = $plugin.'/plugin.php';

            // 플러그인 파일이 존재하는지 확인
            if (! File::exists($pluginFile)) {
                continue;
            }

            // vendor-plugin 형식을 네임스페이스로 변환
            $namespace = $this->convertDirectoryToNamespace($pluginName);
            $pluginClass = "Plugins\\{$namespace}\\Plugin";

            // 클래스가 아직 로드되지 않은 경우에만 require
            // (_bundled에서 이미 로드된 경우 중복 선언 방지)
            if (! class_exists($pluginClass, false)) {
                require_once $pluginFile;
            }

            if (! class_exists($pluginClass)) {
                continue;
            }

            $pluginInstance = new $pluginClass;
            $routes = $pluginInstance->getRoutes();

            // API 라우트 로드
            if (isset($routes['api']) && File::exists($routes['api'])) {
                Route::prefix('api/plugins/'.$pluginName)
                    ->name('api.plugins.'.$pluginName.'.')
                    ->middleware('api')
                    ->group($routes['api']);
            }

            // 웹 라우트 로드
            if (isset($routes['web']) && File::exists($routes['web'])) {
                Route::prefix('plugins/'.$pluginName)
                    ->name('web.plugins.'.$pluginName.'.')
                    ->middleware('web')
                    ->group($routes['web']);
            }
        }
    }

    /**
     * 디렉토리명(vendor-plugin)을 네임스페이스(Vendor\Plugin)로 변환합니다.
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-tosspayments)
     * @return string 네임스페이스 (예: Sirsoft\Tosspayments)
     */
    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }
}
