<?php

namespace App\Providers;

use App\Extension\ExtensionManager;
use App\Extension\Testing\ExtensionTestAllowlist;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * 플러그인 ServiceProvider 자동 발견 및 등록
 *
 * plugins 디렉토리를 스캔하여 모든 플러그인의 ServiceProvider를 자동으로 등록합니다.
 * register() 시점에 실행되어 타이밍 이슈 없이 Repository 바인딩이 가능합니다.
 */
class PluginServiceProvider extends ServiceProvider
{
    /**
     * 플러그인 ServiceProvider들을 등록합니다.
     *
     * @return void 반환값 없음
     */
    public function register(): void
    {
        $pluginsPath = base_path('plugins');

        if (! File::exists($pluginsPath)) {
            return;
        }

        $allowlistActive = ExtensionTestAllowlist::isActive();

        foreach ($this->discoverPluginServiceProviders($pluginsPath) as $provider) {
            // 테스트 환경 확장 격리: allowlist 밖 플러그인의 ServiceProvider 등록 차단
            if ($allowlistActive && ! ExtensionTestAllowlist::isAllowed('plugin', $provider['name'])) {
                continue;
            }

            $this->app->register($provider['class']);
        }
    }

    /**
     * plugins 디렉토리를 스캔하여 모든 플러그인의 ServiceProvider 클래스들을 발견합니다.
     *
     * 각 항목은 플러그인 디렉토리명('name')과 ServiceProvider 클래스명('class')을
     * 함께 담습니다. 디렉토리명은 테스트 환경 확장 격리 가드에 사용됩니다.
     *
     * @param  string  $pluginsPath  플러그인 디렉토리 경로
     * @return array<array{name: string, class: string}> ServiceProvider 정보 배열
     */
    protected function discoverPluginServiceProviders(string $pluginsPath): array
    {
        $providers = [];
        $directories = File::directories($pluginsPath);

        foreach ($directories as $directory) {
            foreach (self::providerClassesIn($directory) as $providerClass) {
                $providers[] = ['name' => basename($directory), 'class' => $providerClass];
            }
        }

        return $providers;
    }

    /**
     * 플러그인 디렉터리 하나에서 ServiceProvider 클래스를 찾습니다.
     *
     * 부팅 때 등록 규칙과 설치 도중 선등록(ExtensionProviderRegistrar)이 같은 규칙을 쓰도록 공용으로 둔다.
     * 파일은 있는데 클래스를 찾을 수 없으면(네임스페이스 오타·오토로드 누락) 경고 로그를 남기고 건너뛴다.
     *
     * @param  string  $directory  플러그인 디렉터리 경로 (plugins/{vendor-plugin})
     * @return array<int, class-string> ServiceProvider 클래스명 목록
     */
    public static function providerClassesIn(string $directory): array
    {
        $pluginName = basename($directory);
        $providersPath = $directory.'/src/Providers';

        if (! File::exists($providersPath)) {
            return [];
        }

        $classes = [];

        // Providers 디렉토리 내 모든 PHP 파일 스캔
        foreach (File::glob($providersPath.'/*.php') as $providerFile) {
            $providerClass = self::resolveProviderClass($pluginName, $providerFile);

            if ($providerClass === null) {
                continue;
            }

            if (! class_exists($providerClass)) {
                Log::warning('[PluginServiceProvider] 프로바이더 파일은 있지만 클래스를 찾을 수 없어 등록하지 않았습니다', [
                    'plugin' => $pluginName,
                    'expected_class' => $providerClass,
                    'file' => basename($providerFile),
                ]);

                continue;
            }

            $classes[] = $providerClass;
        }

        return $classes;
    }

    /**
     * 파일 경로에서 ServiceProvider 클래스명을 추론합니다.
     *
     * @param  string  $pluginName  플러그인 디렉토리명 (예: sirsoft-payment)
     * @param  string  $providerFile  Provider 파일 경로
     * @return string|null ServiceProvider 클래스명 또는 null
     */
    public static function resolveProviderClass(string $pluginName, string $providerFile): ?string
    {
        $fileName = pathinfo($providerFile, PATHINFO_FILENAME);

        // ServiceProvider로 끝나는 파일만 처리
        if (! str_ends_with($fileName, 'ServiceProvider')) {
            return null;
        }

        // vendor-plugin 형식을 네임스페이스로 변환
        // sirsoft-payment -> Sirsoft\Payment
        $namespace = ExtensionManager::directoryToNamespace($pluginName);

        return "Plugins\\{$namespace}\\Providers\\{$fileName}";
    }
}
