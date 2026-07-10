<?php

namespace App\Providers;

use App\Extension\ExtensionManager;
use App\Extension\Testing\ExtensionTestAllowlist;
use App\Support\InstallerContext;
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

        // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 쿼리 제거).
        // 인스톨러 이전 환경에서는 기존 체크 경로로 폴백.
        //
        // 단, 마이그레이션 계열 명령 실행 중에는 INSTALLER_COMPLETED=true 라도 테이블이
        // 아직 없을 수 있으므로(빈 DB 새 서버에 .env 복사 후 migrate 전) fast-path 를
        // 무력화하고 hasTable 검증 경로로 진입한다. 빈 DB 면 여기서 early return 하여
        // 이후 플러그인 부팅/상태 조회가 table not found 로 부팅을 깨뜨리는 것을 막는다.
        if (! config('app.installer_completed') || InstallerContext::isSchemaMutatingCommand()) {
            try {
                if (! Schema::hasTable('plugins')) {
                    return;
                }
            } catch (\Exception) {
                // DB 연결 실패 시 조용히 스킵 (설정 오류, 마이그레이션 전 등)
                return;
            }
        }

        $plugins = File::directories($pluginsPath);
        $allowlistActive = ExtensionTestAllowlist::isActive();

        foreach ($plugins as $plugin) {
            $pluginName = basename($plugin);
            $pluginFile = $plugin.'/plugin.php';

            // 테스트 환경 확장 격리: allowlist 밖 플러그인의 라우트 등록 차단
            if ($allowlistActive && ! ExtensionTestAllowlist::isAllowed('plugin', $pluginName)) {
                continue;
            }

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
