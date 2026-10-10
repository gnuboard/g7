<?php

namespace App\Http\Controllers\Concerns;

use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackStatus;
use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallAuthorizer;
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
 * 두 단계 모두 항목별 권한을 먼저 확인한다(`findUnauthorizedCascadeSelection`) — 본 확장의
 * install 권한만으로 다른 종류의 확장·언어팩을 설치·활성화할 수 없다.
 */
trait OrchestratesCascadeInstall
{
    /**
     * 함께 고른 의존 확장·언어팩 가운데 현재 사용자가 처리할 권한이 없는 첫 항목을 찾습니다.
     *
     * 설치를 시작하기 전에 전부 확인해, 일부만 설치된 뒤 권한 오류로 멈추는 일을 막는다.
     * 판정은 설치 창 미리보기의 `can_select` 와 같은 CascadeInstallAuthorizer 를 쓴다.
     * 이미 활성인 의존 확장은 아무것도 하지 않으므로(installSelectedDependencies 의 skip) 권한을 묻지 않는다.
     *
     * @param  array<int, array{type: string, identifier: string}>  $dependencies  사용자가 고른 의존 확장 목록
     * @param  array<int, string>  $bundledIdentifiers  사용자가 고른 번들 언어팩 식별자 목록
     * @return array{type: string, identifier: string}|null 권한 없는 첫 항목 (모두 허용이면 null)
     */
    protected function findUnauthorizedCascadeSelection(array $dependencies, array $bundledIdentifiers): ?array
    {
        /** @var CascadeInstallAuthorizer $authorizer */
        $authorizer = app(CascadeInstallAuthorizer::class);

        foreach ($dependencies as $dep) {
            $type = $dep['type'] ?? '';
            $identifier = $dep['identifier'] ?? '';
            if ($identifier === '') {
                continue;
            }

            $info = match ($type) {
                'module' => app(ModuleService::class)->getModuleInfo($identifier),
                'plugin' => app(PluginService::class)->getPluginInfo($identifier),
                default => null,
            };

            if (($info['status'] ?? null) === ExtensionStatus::Active->value) {
                continue;
            }

            if (! $authorizer->canSelectDependency($type, (bool) ($info['is_installed'] ?? false))) {
                return ['type' => $type, 'identifier' => $identifier];
            }
        }

        if (! empty($bundledIdentifiers) && ! $authorizer->canSelectLanguagePack()) {
            return ['type' => 'language_pack', 'identifier' => (string) reset($bundledIdentifiers)];
        }

        return null;
    }

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
     * @param  array<int, array{type: string, identifier: string}>  $dependencies  사용자가 고른 의존 확장 목록 (종류·식별자)
     * @return void 반환값 없음
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
                    $isActive = ($info['status'] ?? null) === ExtensionStatus::Active->value;

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
                    $isActive = ($info['status'] ?? null) === ExtensionStatus::Active->value;

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
     * 방금 설치한 확장은 비활성이라, 그 확장 자신의 언어팩은 꺼진 채 설치되고 「대상 켜짐 대기」로 남는다 —
     * 그 확장을 켤 때 함께 켜진다. 이런 팩은 실패가 아니므로 `$awaitingHost` 로 따로 돌려준다.
     *
     * @param  array<int, string>  $bundledIdentifiers  번들 언어팩 식별자 목록
     * @param  array<int, string>|null  $awaitingHost  (출력) 꺼진 채 설치돼 대상 확장을 켤 때 함께 켜질 언어팩 식별자
     * @return array<int, array{identifier: string, reason: string}> 실패 항목 reason 배열
     */
    protected function installSelectedLanguagePacks(array $bundledIdentifiers, ?array &$awaitingHost = null): array
    {
        $awaitingHost = [];

        if (empty($bundledIdentifiers)) {
            return [];
        }

        /** @var LanguagePackService $service */
        $service = app(LanguagePackService::class);
        $failures = [];

        foreach ($bundledIdentifiers as $identifier) {
            try {
                $pack = $service->installFromBundled(
                    $identifier,
                    autoActivate: true,
                    installedBy: auth()->id(),
                );

                if ($pack->status === LanguagePackStatus::Installed->value
                    && $pack->deactivated_reason === LanguagePackDeactivationReason::AwaitingHost->value) {
                    $awaitingHost[] = $identifier;
                }
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
