<?php

// audit:allow api-doc-coverage reason: 요청/응답 계약 불변 — 설치 판정 조회만 manifest 전용(get*ManifestInfo)으로 바꿈, 읽는 필드(status·is_installed·name·version·dependencies) 동일 (#159)

namespace App\Http\Controllers\Concerns;

use App\Enums\ExtensionStatus;
use App\Extension\Helpers\IntegrationNotices;
use App\Extension\Vendor\VendorMode;
use App\Services\Extension\CascadeInstallAuthorizer;
use App\Services\Extension\CascadeInstallOrchestrator;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Support\ExtensionDependencyOrder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 확장 설치 cascade 오케스트레이션 trait.
 *
 * 본 확장 install 전에 사용자가 선택한 의존 모듈/플러그인을 먼저 설치하고,
 * 본 확장 install 후에 동반 번들 언어팩을 설치(자동 활성화)합니다.
 *
 * 의존성 단계는 strict — 1건 실패 시 RuntimeException 으로 abort.
 * 선택 연동(`integrations`) 단계는 의존 다음·본체 이전에 돌고 best-effort — 실패는 본체 설치를 막지 않고
 * 응답의 `integration_notices` 로 알린다 (7.1.4, 설치 창 「함께 쓰면 연동되는 확장(선택)」).
 * 언어팩 단계는 best-effort — 항목별 실패는 응답의 `language_pack_failures` 에 누적.
 * 두 단계 모두 항목별 권한을 먼저 확인한다(`findUnauthorizedCascadeSelection`) — 본 확장의
 * install 권한만으로 다른 종류의 확장·언어팩을 설치·활성화할 수 없다.
 */
trait OrchestratesCascadeInstall
{
    /**
     * 이번 요청에서 설치·활성화한 의존 확장 — 본 확장이 실패하면 실패 사유에 덧붙인다.
     *
     * @var array{installed: array<int, string>, activated: array<int, string>, skipped: array<int, string>}
     */
    private array $cascadeDependencyResult = ['installed' => [], 'activated' => [], 'skipped' => []];

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
                'module' => app(ModuleService::class)->getModuleManifestInfo($identifier),
                'plugin' => app(PluginService::class)->getPluginManifestInfo($identifier),
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
     * 본문은 관리자 화면·artisan 공통 서비스(CascadeInstallOrchestrator)가 소유한다 — 의존 확장이 먼저 오도록
     * 정렬하고(대상을 주면), 본 확장과 같은 vendor 설치 방식을 쓰며, 활성화 결과를 확인해 실패하면 그 확장 이름과
     * 실제 사유로 멈춘다.
     *
     * 상태별 처리: 미설치 → install + activate · 설치됨 + 비활성 → activate · 설치됨 + 활성 → skip.
     *
     * @param  array<int, array{type: string, identifier: string}>  $dependencies  사용자가 고른 의존 확장 목록 (종류·식별자)
     * @param  VendorMode  $vendorMode  본 확장과 같은 vendor 설치 방식
     * @param  string|null  $targetType  본 확장 종류 (module|plugin|template) — 주면 의존 순서로 정렬
     * @param  string|null  $targetIdentifier  본 확장 식별자
     * @return array{installed: array<int, string>, activated: array<int, string>, skipped: array<int, string>} 처리 결과
     *
     * @throws RuntimeException 의존 확장 설치 또는 활성화 실패 시
     */
    protected function installSelectedDependencies(array $dependencies, VendorMode $vendorMode = VendorMode::Auto, ?string $targetType = null, ?string $targetIdentifier = null): array
    {
        $this->cascadeDependencyResult = ['installed' => [], 'activated' => [], 'skipped' => []];

        if (empty($dependencies)) {
            return $this->cascadeDependencyResult;
        }

        /** @var CascadeInstallOrchestrator $orchestrator */
        $orchestrator = app(CascadeInstallOrchestrator::class);

        if ($targetType !== null && $targetIdentifier !== null) {
            $dependencies = $orchestrator->orderSelectedDependencies($targetType, $targetIdentifier, $dependencies);
        }

        return $this->cascadeDependencyResult = $orchestrator->installDependencies($dependencies, $vendorMode);
    }

    /**
     * 본 확장 설치 실패 사유 뒤에 이미 설치·활성화해 남겨 둔 의존 확장 안내를 붙인다(되돌리지 않는 현행 — D14).
     *
     * @param  string  $reason  실패 사유
     * @return string 안내를 붙인 사유 (남긴 의존 확장이 없으면 그대로)
     */
    protected function withKeptDependencies(string $reason): string
    {
        return CascadeInstallOrchestrator::appendKeptNotice($reason, $this->cascadeDependencyResult);
    }

    /**
     * 사용자가 고른 선택 연동 확장을 설치·활성화합니다 (best-effort, 7.1.4).
     *
     * 의존 확장 다음·본체 이전에 부른다 — 템플릿을 켤 때 연동 모듈의 레이아웃·확장 조각이 함께 등록되도록 먼저 켠다.
     * 상태별 처리는 {@see installSelectedDependencies()} 와 같고(미설치 → 설치+활성화, 비활성 → 활성화, 활성 → 건너뜀),
     * 실패는 예외를 던지지 않고 식별자별 사유로 돌려준다 — 연동은 「없어도 동작」이므로 본체 설치를 막지 않는다.
     *
     * 연동 대상 자신의 필수 의존(#153 D5)은 관리자 연쇄 설치와 같은 계획으로 먼저 설치·활성화한다 — 연동끼리 같은 의존은
     * 한 번만, 연동끼리의 순서도 서로의 의존 순서로 맞춘다. 계획을 세울 수 없거나 필요한 확장을 설치할 권한이 없거나 그 설치가
     * 실패하면 그 연동만 사유와 함께 건너뛴다(설치 창이 같은 판정으로 선택을 막는다 — 요청을 직접 만든 경우의 방어).
     *
     * @param  array<int, array{type: string, identifier: string}>  $integrations  사용자가 고른 연동 확장 목록 (종류·식별자)
     * @return array<string, string> 실패한 항목 `"{type}:{identifier}" => 사유`
     */
    protected function installSelectedIntegrations(array $integrations): array
    {
        if (empty($integrations)) {
            return [];
        }

        /** @var ModuleService $moduleService */
        $moduleService = app(ModuleService::class);
        /** @var PluginService $pluginService */
        $pluginService = app(PluginService::class);
        /** @var CascadeInstallOrchestrator $orchestrator */
        $orchestrator = app(CascadeInstallOrchestrator::class);
        /** @var CascadeInstallAuthorizer $authorizer */
        $authorizer = app(CascadeInstallAuthorizer::class);
        $failures = [];

        // 연동마다 필수 의존 계획을 세우고, 연동끼리는 서로의 의존 순서로 정렬한다
        $nodes = [];
        $plans = [];
        foreach ($integrations as $item) {
            $type = (string) ($item['type'] ?? '');
            $identifier = (string) ($item['identifier'] ?? '');
            if ($identifier === '' || ! in_array($type, ['module', 'plugin'], true)) {
                continue;
            }
            $key = "{$type}:{$identifier}";
            $plans[$key] = $orchestrator->resolveDependencyPlan($type, $identifier);
            $nodes[] = ['key' => $key, 'type' => $type, 'identifier' => $identifier];
        }
        $ordered = ExtensionDependencyOrder::order(
            $nodes,
            fn (string $key): array => array_map(fn (array $step): string => "{$step['type']}:{$step['identifier']}", $plans[$key]['steps'] ?? [])
        )['ordered'];

        $doneSteps = [];
        $failedSteps = [];
        foreach ($ordered as $node) {
            ['key' => $key, 'type' => $type, 'identifier' => $identifier] = $node;

            try {
                if (($plans[$key]['errors'] ?? []) !== []) {
                    throw new RuntimeException((string) $plans[$key]['errors'][0]);
                }

                foreach ($plans[$key]['steps'] as $step) {
                    $stepKey = "{$step['type']}:{$step['identifier']}";
                    if (isset($failedSteps[$stepKey])) {
                        throw new RuntimeException($failedSteps[$stepKey]);
                    }
                    if (isset($doneSteps[$stepKey])) {
                        continue;
                    }
                    if (! $authorizer->canSelectDependency($step['type'], $step['action'] === 'activate')) {
                        $failedSteps[$stepKey] = __('extensions.integrations.requires_no_permission', ['identifier' => $step['identifier']]);

                        throw new RuntimeException($failedSteps[$stepKey]);
                    }
                    try {
                        $orchestrator->installDependencies([$step]);
                    } catch (\Throwable $e) {
                        $failedSteps[$stepKey] = $e->getMessage();

                        throw $e;
                    }
                    $doneSteps[$stepKey] = true;
                }

                $info = $type === 'module' ? $moduleService->getModuleManifestInfo($identifier) : $pluginService->getPluginManifestInfo($identifier);
                if ($info === null) {
                    throw new RuntimeException(__('extensions.errors.not_found', ['identifier' => $identifier]));
                }

                if (($info['status'] ?? null) === ExtensionStatus::Active->value) {
                    continue;
                }

                if (! (bool) ($info['is_installed'] ?? false)) {
                    $installed = $type === 'module'
                        ? $moduleService->installModule($identifier, VendorMode::Auto, false, $installFailure)
                        : $pluginService->installPlugin($identifier, VendorMode::Auto, false, $installFailure);
                    if (! $installed) {
                        throw new RuntimeException((string) ($installFailure ?? __('extensions.errors.internal_error')));
                    }
                }

                $result = $type === 'module'
                    ? $moduleService->activateModule($identifier)
                    : $pluginService->activatePlugin($identifier);

                // 계획 밖 사정으로 필수 의존이 모자라면 활성화가 경고로 끝난다 — 강제로 켜지 않고 사유만 남긴다
                if (is_array($result) && ($result['success'] ?? false) !== true) {
                    throw new RuntimeException((string) ($result['message'] ?? $result['reason'] ?? __('extensions.errors.internal_error')));
                }
            } catch (\Throwable $e) {
                // 서비스는 번역한 사유를 검증 예외에 담는다 — 예외 기본 문구 대신 그 사유를 쓴다
                $reason = $e instanceof ValidationException
                    ? (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage())
                    : $e->getMessage();

                Log::warning('cascade integration install failed', [
                    'type' => $type,
                    'identifier' => $identifier,
                    'error' => $reason,
                ]);
                $failures[$key] = $reason;
            }

            unset($installFailure);
        }

        return $failures;
    }

    /**
     * 본체 설치 뒤 연동 안내를 만듭니다 — 함께 설치하다 실패한 항목은 그 사유를 문구와 `failure_reason` 에 싣습니다.
     *
     * 설치 창 토스트는 항목마다 「이름 (상태)」 로 그리므로, 사유가 현지화 문장(`message`) 안에만 있으면 화면에 나오지 않는다.
     * 사유는 `failure_reason` 으로 따로 싣고(실패하지 않은 항목은 null), 화면이 상태 뒤에 붙인다.
     *
     * @param  string  $type  본체 확장 유형 (module|plugin|template)
     * @param  string  $identifier  본체 확장 식별자
     * @param  array<string, string>  $failures  {@see installSelectedIntegrations()} 결과
     * @return list<array<string, mixed>> `integration_notices` 항목
     */
    protected function buildIntegrationNotices(string $type, string $identifier, array $failures = []): array
    {
        $notices = IntegrationNotices::forExtension($type, $identifier);

        foreach ($notices as $index => $notice) {
            $reason = $failures[$notice['type'].':'.$notice['identifier']] ?? null;
            $notices[$index]['failure_reason'] = $reason;
            if ($reason !== null) {
                $notices[$index]['message'] = __('extensions.integrations.cascade_failed', [
                    'name' => $notice['name'],
                    'identifier' => $notice['identifier'],
                    // 사유는 대개 마침표로 끝나는 완결 문장이다 — 안내 문구는 다른 항목처럼 마침표 없이 끝내야, 요약 한 줄
                    // (「… — :list. …」)에 이을 때 마침표가 겹치지 않는다
                    'message' => preg_replace('/[\s.。]+$/u', '', $reason) ?? $reason,
                ]);
            }
        }

        return $notices;
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
        return app(CascadeInstallOrchestrator::class)->installLanguagePacks($bundledIdentifiers, auth()->id(), $awaitingHost);
    }
}
