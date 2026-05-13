<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\File;

class TranslationServiceProvider extends ServiceProvider
{
    /**
     * 서비스를 등록합니다.
     *
     * @return void
     */
    public function register(): void
    {
        //
    }

    /**
     * 서비스를 부트스트랩하고 다국어 파일들을 로드합니다.
     *
     * @return void
     */
    public function boot(): void
    {
        $this->loadModuleTranslations();
        $this->loadPluginTranslations();
    }

    /**
     * 모든 모듈의 다국어 파일들을 로드하고 등록합니다.
     *
     * @return void
     */
    private function loadModuleTranslations(): void
    {
        $modulesPath = base_path('modules');
        
        if (!File::exists($modulesPath)) {
            return;
        }

        $modules = File::directories($modulesPath);
        
        foreach ($modules as $modulePath) {
            $moduleName = basename($modulePath);
            $langPath = $modulePath . '/src/lang';
            
            if (File::exists($langPath)) {
                $this->loadTranslationsFrom($langPath, strtolower($moduleName));
            }
        }
    }

    /**
     * 모든 플러그인의 다국어 파일들을 로드하고 등록합니다.
     *
     * @return void
     */
    private function loadPluginTranslations(): void
    {
        $pluginsPath = base_path('plugins');
        
        if (!File::exists($pluginsPath)) {
            return;
        }

        $plugins = File::directories($pluginsPath);
        
        foreach ($plugins as $pluginPath) {
            $pluginName = basename($pluginPath);
            $langPath = $pluginPath . '/lang';
            
            if (File::exists($langPath)) {
                $this->loadTranslationsFrom($langPath, strtolower($pluginName));
            }
        }
    }
}
