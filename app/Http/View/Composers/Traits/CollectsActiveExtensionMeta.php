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
 * 수집에 실패하면 빈 배열이 아니라 null 을 돌려줍니다(#822, 7.1.4). 빈 배열은 「켜진 확장이 없다」로 읽혀
 * 사용자 화면의 `{{_global.activeModules?.includes('id') ?? true}}` 가드가 모든 모듈 화면을 숨기지만, null 이면
 * 엔진이 `_global.activeModules` 를 싣지 않아 옛 코어처럼 「모두 켜짐」으로 읽힙니다 — 검색봇 화면
 * (SeoRenderer 는 실패 시 키를 생략)과 같은 답입니다. G7Config 를 읽는 편집기·핸들러 대기는 모두 배열인지 먼저 본다.
 *
 * @property ModuleManager $moduleManager
 * @property PluginManager $pluginManager
 */
trait CollectsActiveExtensionMeta
{
    /**
     * 활성 모듈 메타 배열 수집
     *
     * @return array<int, array{identifier: string, display_name: string|array, version: string}>|null 수집 실패 시 null(「모름」 — 화면은 모두 켜짐으로 읽는다)
     */
    private function collectActiveModulesMeta(): ?array
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
        } catch (\Throwable $e) {
            Log::warning('Failed to collect active modules meta: '.$e->getMessage());

            return null;
        }

        return $meta;
    }

    /**
     * 활성 플러그인 메타 배열 수집
     *
     * @return array<int, array{identifier: string, display_name: string|array, version: string}>|null 수집 실패 시 null(「모름」 — 화면은 모두 켜짐으로 읽는다)
     */
    private function collectActivePluginsMeta(): ?array
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
        } catch (\Throwable $e) {
            Log::warning('Failed to collect active plugins meta: '.$e->getMessage());

            return null;
        }

        return $meta;
    }
}
