<?php

namespace App\Http\View\Composers\Traits;

use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use Illuminate\Support\Facades\Log;

/**
 * 활성 확장(모듈/플러그인) 메타 수집 Trait
 *
 * window.G7Config.activeModules / activePlugins 로 노출할
 * 식별자/표시명/버전 메타를 수집합니다.
 *
 * 기존 modules / plugins 키(ModuleSettingsService::getAllActiveSettings 결과)는
 * hasSettings() 필터로 인해 활성 전수가 아니며 식별자 외 메타가 없어
 * 레이아웃 편집기의 활성 확장 SSoT 로 쓸 수 없습니다.
 * 본 trait 는 ModuleManager::getActiveModules() / PluginManager::getActivePlugins()
 * 결과를 직접 사용해 활성 전수의 메타를 노출합니다(원본 키는 비파괴적 보존).
 *
 * @property ModuleManager $moduleManager
 * @property PluginManager $pluginManager
 */
trait CollectsActiveExtensionMeta
{
    /**
     * 활성 모듈 메타 배열 수집
     *
     * @return array<int, array{identifier: string, display_name: string|array, version: string}>
     */
    private function collectActiveModulesMeta(): array
    {
        $meta = [];

        try {
            $activeModules = $this->moduleManager->getActiveModules();

            foreach ($activeModules as $identifier => $module) {
                $meta[] = [
                    'identifier' => $module->getIdentifier(),
                    'display_name' => $module->getName(),
                    'version' => $module->getVersion(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to collect active modules meta: '.$e->getMessage());
        }

        return $meta;
    }

    /**
     * 활성 플러그인 메타 배열 수집
     *
     * @return array<int, array{identifier: string, display_name: string|array, version: string}>
     */
    private function collectActivePluginsMeta(): array
    {
        $meta = [];

        try {
            $activePlugins = $this->pluginManager->getActivePlugins();

            foreach ($activePlugins as $identifier => $plugin) {
                $meta[] = [
                    'identifier' => $plugin->getIdentifier(),
                    'display_name' => $plugin->getName(),
                    'version' => $plugin->getVersion(),
                ];
            }
        } catch (\Exception $e) {
            Log::warning('Failed to collect active plugins meta: '.$e->getMessage());
        }

        return $meta;
    }
}
