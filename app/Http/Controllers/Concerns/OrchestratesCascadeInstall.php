<?php

namespace App\Http\Controllers\Concerns;

use App\Extension\Vendor\VendorMode;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 확장 설치 cascade 오케스트레이션 trait.
 *
 * 본 확장 install 전에 사용자가 선택한 의존 모듈/플러그인을 먼저 설치하고,
 * 본 확장 install 후에 동반 번들 언어팩을 설치(자동 활성화)합니다.
 *
 * 의존성 단계는 strict — 1건 실패 시 RuntimeException 으로 abort.
 * 언어팩 단계는 best-effort — 항목별 실패는 응답의 `language_pack_failures` 에 누적.
 */
trait OrchestratesCascadeInstall
{
    /**
     * 사용자가 선택한 의존 확장을 사전 설치하고 활성화합니다.
     *
     * cascade 흐름은 일반 설치(웹/CLI 단독 설치)와 달리, 본 확장 install 직후
     * `checkDependencies` 가 의존 확장의 active 상태를 요구하므로 활성화까지 자동 수행합니다.
     * 일반 설치 프로세스(ModuleController/PluginController::install)는 활성화하지 않는
     * 정책을 그대로 유지하며, 본 helper 만 cascade 한정으로 install + activate 를 묶습니다.
     *
     * 상태별 처리:
     * - 미설치 → install + activate
     * - 설치됨 + 비활성 → activate (재설치 회피)
     * - 설치됨 + 활성 → skip (already met)
     *
     * @param  array<int, array{type: string, identifier: string}>  $dependencies
     * @return void
     *
     * @throws RuntimeException 의존 확장 설치 또는 활성화 실패 시
     */
    protected function installSelectedDependencies(array $dependencies): void
    {
        if (empty($dependencies)) {
            return;
        }

        /** @var ModuleService $moduleService */
        $moduleService = app(ModuleService::class);
        /** @var PluginService $pluginService */
        $pluginService = app(PluginService::class);

        foreach ($dependencies as $dep) {
            $type = $dep['type'] ?? '';
            $identifier = $dep['identifier'] ?? '';
            if ($identifier === '') {
                continue;
            }

            try {
                if ($type === 'module') {
                    $info = $moduleService->getModuleInfo($identifier);
                    $isInstalled = (bool) ($info['is_installed'] ?? false);
                    $isActive = ($info['status'] ?? null) === \App\Enums\ExtensionStatus::Active->value;

                    if ($isActive) {
                        continue;
                    }
                    if (! $isInstalled) {
                        $moduleService->installModule($identifier, VendorMode::Auto);
                    }
                    $moduleService->activateModule($identifier);
                } elseif ($type === 'plugin') {
                    $info = $pluginService->getPluginInfo($identifier);
                    $isInstalled = (bool) ($info['is_installed'] ?? false);
                    $isActive = ($info['status'] ?? null) === \App\Enums\ExtensionStatus::Active->value;

                    if ($isActive) {
                        continue;
                    }
                    if (! $isInstalled) {
                        $pluginService->installPlugin($identifier, VendorMode::Auto);
                    }
                    $pluginService->activatePlugin($identifier);
                }
            } catch (\Throwable $e) {
                throw new RuntimeException(__('extensions.errors.cascade_dependency_failed', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    /**
     * 동반 선택된 번들 언어팩을 설치 + 자동 활성화 합니다 (best-effort).
     *
     * @param  array<int, string>  $bundledIdentifiers  번들 언어팩 식별자 목록
     * @return array<int, array{identifier: string, reason: string}> 실패 항목 reason 배열
     */
    protected function installSelectedLanguagePacks(array $bundledIdentifiers): array
    {
        if (empty($bundledIdentifiers)) {
            return [];
        }

        /** @var LanguagePackService $service */
        $service = app(LanguagePackService::class);
        $failures = [];

        foreach ($bundledIdentifiers as $identifier) {
            try {
                $service->installFromBundled(
                    $identifier,
                    autoActivate: true,
                    installedBy: auth()->id(),
                );
            } catch (\Throwable $e) {
                Log::warning('cascade language pack install failed', [
                    'identifier' => $identifier,
                    'error' => $e->getMessage(),
                ]);
                $failures[] = [
                    'identifier' => $identifier,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return $failures;
    }
}
