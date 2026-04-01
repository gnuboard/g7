<?php

namespace App\Providers;

use App\Enums\ExtensionStatus;
use App\Extension\ExtensionManager;
use App\Models\Module;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ModuleRouteServiceProvider extends ServiceProvider
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
            $this->loadModuleRoutes();
        });
    }

    /**
     * 모듈의 라우트 파일들을 로드합니다.
     *
     * 활성화된 모듈만 라우트를 등록합니다.
     */
    protected function loadModuleRoutes(): void
    {
        // .env 파일이 없으면 스킵 (인스톨러 실행 전)
        if (! File::exists(base_path('.env'))) {
            return;
        }

        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        // DB 연결 가능 여부 및 테이블/컬럼 존재 여부 확인
        try {
            if (! Schema::hasTable('modules')) {
                return;
            }

            // identifier 컬럼이 없으면 스킵 (마이그레이션 미적용 상태)
            if (! Schema::hasColumn('modules', 'identifier')) {
                return;
            }
        } catch (\Exception) {
            // DB 연결 실패 시 조용히 스킵 (설정 오류, 마이그레이션 전 등)
            return;
        }

        // 활성화된 모듈 identifier 목록 가져오기
        $activeModuleIdentifiers = Module::where('status', ExtensionStatus::Active->value)
            ->pluck('identifier')
            ->toArray();

        $modules = File::directories($modulesPath);

        foreach ($modules as $module) {
            $moduleName = basename($module);
            $moduleFile = $module.'/module.php';

            // 활성화된 모듈만 라우트 로드
            if (! in_array($moduleName, $activeModuleIdentifiers)) {
                continue;
            }

            // 모듈 파일이 존재하는지 확인
            if (! File::exists($moduleFile)) {
                continue;
            }

            // 디렉토리명(vendor-module)을 네임스페이스(Vendor\Module)로 변환
            $namespace = $this->convertDirectoryToNamespace($moduleName);
            $moduleClass = "Modules\\{$namespace}\\Module";

            // 클래스가 아직 로드되지 않은 경우에만 require
            // (_bundled에서 이미 로드된 경우 중복 선언 방지)
            if (! class_exists($moduleClass, false)) {
                require_once $moduleFile;
            }

            if (! class_exists($moduleClass)) {
                continue;
            }

            $moduleInstance = new $moduleClass;
            $routes = $moduleInstance->getRoutes();

            // API 라우트 로드
            if (isset($routes['api']) && File::exists($routes['api'])) {
                Route::prefix('api/modules/'.$moduleName)
                    ->name('api.modules.'.$moduleName.'.')
                    ->middleware('api')
                    ->group($routes['api']);
            }

            // 웹 라우트 로드
            if (isset($routes['web']) && File::exists($routes['web'])) {
                Route::prefix('modules/'.$moduleName)
                    ->name('web.modules.'.$moduleName.'.')
                    ->middleware('web')
                    ->group($routes['web']);
            }
        }
    }

    /**
     * 디렉토리명(vendor-module)을 네임스페이스(Vendor\Module)로 변환합니다.
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-sample)
     * @return string 네임스페이스 (예: Sirsoft\Sample)
     */
    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }
}
