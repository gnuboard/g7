<?php

namespace App\Providers;

use App\Extension\ExtensionManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

/**
 * 모듈 ServiceProvider 자동 발견 및 등록
 *
 * modules 디렉토리를 스캔하여 모든 모듈의 ServiceProvider를 자동으로 등록합니다.
 * register() 시점에 실행되어 타이밍 이슈 없이 Repository 바인딩이 가능합니다.
 */
class ModuleServiceProvider extends ServiceProvider
{
    /**
     * 모듈 ServiceProvider들을 등록합니다.
     */
    public function register(): void
    {
        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        foreach ($this->discoverModuleServiceProviders($modulesPath) as $providerClass) {
            $this->app->register($providerClass);
        }
    }

    /**
     * modules 디렉토리를 스캔하여 모든 모듈의 ServiceProvider 클래스들을 발견합니다.
     *
     * @param  string  $modulesPath  모듈 디렉토리 경로
     * @return array<string> ServiceProvider 클래스명 배열
     */
    protected function discoverModuleServiceProviders(string $modulesPath): array
    {
        $providers = [];
        $directories = File::directories($modulesPath);

        foreach ($directories as $directory) {
            $moduleName = basename($directory);
            $providersPath = $directory.'/src/Providers';

            if (! File::exists($providersPath)) {
                continue;
            }

            // Providers 디렉토리 내 모든 PHP 파일 스캔
            $providerFiles = File::glob($providersPath.'/*.php');

            foreach ($providerFiles as $providerFile) {
                $providerClass = $this->resolveProviderClass($moduleName, $providerFile);

                if ($providerClass && class_exists($providerClass)) {
                    $providers[] = $providerClass;
                }
            }
        }

        return $providers;
    }

    /**
     * 파일 경로에서 ServiceProvider 클래스명을 추론합니다.
     *
     * @param  string  $moduleName  모듈 디렉토리명 (예: sirsoft-board)
     * @param  string  $providerFile  Provider 파일 경로
     * @return string|null ServiceProvider 클래스명 또는 null
     */
    protected function resolveProviderClass(string $moduleName, string $providerFile): ?string
    {
        $fileName = pathinfo($providerFile, PATHINFO_FILENAME);

        // ServiceProvider로 끝나는 파일만 처리
        if (! str_ends_with($fileName, 'ServiceProvider')) {
            return null;
        }

        // vendor-module 형식을 네임스페이스로 변환
        // sirsoft-board -> Sirsoft\Board
        $namespace = $this->convertDirectoryToNamespace($moduleName);

        return "Modules\\{$namespace}\\Providers\\{$fileName}";
    }

    /**
     * 디렉토리명(vendor-module)을 네임스페이스(Vendor\Module)로 변환합니다.
     *
     * @param  string  $directoryName  디렉토리명 (예: sirsoft-board)
     * @return string 네임스페이스 (예: Sirsoft\Board)
     */
    protected function convertDirectoryToNamespace(string $directoryName): string
    {
        return ExtensionManager::directoryToNamespace($directoryName);
    }
}
