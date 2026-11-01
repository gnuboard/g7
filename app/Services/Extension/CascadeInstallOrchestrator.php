<?php

namespace App\Services\Extension;

use App\Enums\ExtensionStatus;
use App\Enums\LanguagePackDeactivationReason;
use App\Enums\LanguagePackStatus;
use App\Extension\Helpers\DependencyEnricher;
use App\Extension\Vendor\VendorMode;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use App\Support\ExtensionDependencies;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * 확장 연쇄 설치 — 의존 확장 설치·활성화와 동반 언어팩 설치 (관리자 화면·artisan 명령 공통).
 *
 * 관리자 화면 설치(Module/Plugin/TemplateController)와 `module:install`·`plugin:install`·`template:install`
 * 의 `--with-dependencies`·`--with-language-packs` 가 같은 서비스를 쓴다.
 *
 * - 의존의 의존까지 해석한다(순환 감지, 버전 제약 확인) — 종전에는 직접 의존만 봐서 2단계 의존 확장이 실패했다.
 * - 의존 확장에도 본 확장과 같은 vendor 설치 방식을 쓴다 — 종전에는 auto 로 고정됐다.
 * - 활성화 결과를 확인해, 실패하면 그 확장 이름과 실제 사유로 멈춘다 — 종전에는 결과를 버려 엉뚱한
 *   「의존 확장이 활성이 아님」 오류로 이어졌다.
 * - 본 확장 설치가 실패해도 앞서 설치한 의존 확장은 되돌리지 않는다(현행 유지) — 결과에 목록을 싣는다.
 *
 * 권한 판정(누가 무엇을 함께 설치할 수 있는가)은 관리자 화면 컨트롤러에 남긴다. 터미널은 셸 접근이 곧 권한이다.
 */
class CascadeInstallOrchestrator
{
    /**
     * 확장·언어팩 서비스를 주입받습니다.
     *
     * @param  ModuleService  $moduleService  모듈 서비스
     * @param  PluginService  $pluginService  플러그인 서비스
     * @param  TemplateService  $templateService  템플릿 서비스
     * @param  LanguagePackService  $languagePackService  언어팩 서비스
     */
    public function __construct(
        private readonly ModuleService $moduleService,
        private readonly PluginService $pluginService,
        private readonly TemplateService $templateService,
        private readonly LanguagePackService $languagePackService,
    ) {}

    /**
     * 대상 확장의 의존 설치 계획 — 의존의 의존까지, 의존 확장이 먼저 오는 순서.
     *
     * 항목 action: install_activate(미설치) · activate(설치됨·비활성). 이미 활성이고 버전을 충족하면 계획에 없다.
     * 활성인데 버전 미달이면 설치로 고칠 수 없으므로(업데이트 필요) 오류다.
     *
     * @param  string  $type  module|plugin|template
     * @param  string  $identifier  대상 확장 식별자
     * @return array{steps: array<int, array{type: string, identifier: string, action: string, required_version: string, installed_version: string|null, available_version: string|null, required_by: string}>, errors: array<int, string>} 계획과 오류
     */
    public function resolveDependencyPlan(string $type, string $identifier): array
    {
        $steps = [];
        $errors = [];
        $done = [];
        $stack = [];

        $visit = function (string $depType, string $depId, string $constraint, string $requiredBy) use (&$visit, &$steps, &$errors, &$done, &$stack): void {
            $key = $depType.':'.$depId;
            if (isset($done[$key])) {
                return;
            }
            if (isset($stack[$key])) {
                $errors[] = __('extensions.errors.cascade_dependency_cycle', ['cycle' => implode(' → ', [...array_keys($stack), $key])]);

                return;
            }

            $info = $this->info($depType, $depId);
            if ($info === null) {
                $errors[] = __('extensions.errors.cascade_dependency_not_found', ['identifier' => $depId, 'required_by' => $requiredBy]);

                return;
            }

            $installed = (bool) ($info['is_installed'] ?? false);
            $active = ($info['status'] ?? null) === ExtensionStatus::Active->value;
            $version = (string) ($info['version'] ?? '');
            $satisfies = $constraint === '*' || $constraint === '' || DependencyEnricher::checkVersionConstraint($version, $constraint);

            if ($active) {
                if (! $satisfies) {
                    $errors[] = __('extensions.errors.cascade_dependency_version', ['identifier' => $depId, 'required' => $constraint, 'version' => $version, 'required_by' => $requiredBy]);
                }
                $done[$key] = true;

                return;
            }

            if (! $satisfies) {
                $errors[] = __('extensions.errors.cascade_dependency_version', ['identifier' => $depId, 'required' => $constraint, 'version' => $version, 'required_by' => $requiredBy]);
                $done[$key] = true;

                return;
            }

            $stack[$key] = true;
            foreach ($this->declaredDependencies($info) as $child) {
                $visit($child['type'], $child['identifier'], $child['constraint'], $depId);
            }
            unset($stack[$key]);

            $done[$key] = true;
            $steps[] = [
                'type' => $depType,
                'identifier' => $depId,
                'action' => $installed ? 'activate' : 'install_activate',
                'required_version' => $constraint,
                'installed_version' => $installed ? $version : null,
                'available_version' => $version,
                'required_by' => $requiredBy,
            ];
        };

        $target = $this->info($type, $identifier);
        if ($target === null) {
            return ['steps' => [], 'errors' => [__('extensions.errors.not_found', ['identifier' => $identifier])]];
        }

        $stack[$type.':'.$identifier] = true;
        foreach ($this->declaredDependencies($target) as $dep) {
            $visit($dep['type'], $dep['identifier'], $dep['constraint'], $identifier);
        }

        return ['steps' => $steps, 'errors' => $errors];
    }

    /**
     * 의존 확장을 계획 순서대로 설치·활성화한다 — 실패하면 그 확장 이름과 실제 사유로 멈추고, 앞서 남긴 의존 확장을 함께 알린다.
     *
     * @param  array<int, array{type: string, identifier: string}>  $dependencies  설치할 의존 확장 (계획 항목 또는 화면 선택)
     * @param  VendorMode  $vendorMode  본 확장과 같은 vendor 설치 방식
     * @return array{installed: array<int, string>, activated: array<int, string>, skipped: array<int, string>} 처리 결과
     *
     * @throws RuntimeException 의존 확장 설치 또는 활성화 실패 시
     */
    public function installDependencies(array $dependencies, VendorMode $vendorMode = VendorMode::Auto): array
    {
        $result = ['installed' => [], 'activated' => [], 'skipped' => []];

        foreach ($dependencies as $dep) {
            $type = (string) ($dep['type'] ?? '');
            $identifier = (string) ($dep['identifier'] ?? '');
            if ($identifier === '' || ! in_array($type, ['module', 'plugin'], true)) {
                continue;
            }

            $info = $this->info($type, $identifier);
            if (($info['status'] ?? null) === ExtensionStatus::Active->value) {
                $result['skipped'][] = $identifier;

                continue;
            }

            try {
                if (! ($info['is_installed'] ?? false)) {
                    $installed = $type === 'module'
                        ? $this->moduleService->installModule($identifier, $vendorMode)
                        : $this->pluginService->installPlugin($identifier, $vendorMode);
                    if ($installed === null) {
                        throw new RuntimeException(__('extensions.errors.unknown_error'));
                    }
                    $result['installed'][] = $identifier;
                }

                $activation = $type === 'module'
                    ? $this->moduleService->activateModule($identifier)
                    : $this->pluginService->activatePlugin($identifier);
            } catch (\Throwable $e) {
                // 종류는 현지화한 이름으로 싣는다 — 의존 미충족 문구(ChecksDependencyVersions)와 같은 extensions.dependency_types
                throw new RuntimeException(self::appendKeptNotice(__('extensions.errors.cascade_dependency_failed', [
                    'type' => __('extensions.dependency_types.'.$type),
                    'identifier' => $identifier,
                    'message' => $e->getMessage(),
                ]), $result), 0, $e);
            }

            if (! is_array($activation) || ($activation['success'] ?? false) !== true) {
                throw new RuntimeException(self::appendKeptNotice(__('extensions.errors.cascade_dependency_activation_failed', [
                    'identifier' => $identifier,
                    'reason' => $this->activationFailureReason(is_array($activation) ? $activation : []),
                ]), $result));
            }
            $result['activated'][] = $identifier;
        }

        return $result;
    }

    /**
     * 이미 설치·활성화해 남겨 둔 의존 확장 안내 — 실패해도 앞 단계를 되돌리지 않으므로 무엇이 남았는지 알린다(D14).
     *
     * @param  array{installed?: array<int, string>, activated?: array<int, string>}  $result  지금까지의 처리 결과
     * @return string|null 안내 문장 (남긴 것이 없으면 null)
     */
    public static function keptDependenciesNotice(array $result): ?string
    {
        $kept = array_values(array_unique([...($result['installed'] ?? []), ...($result['activated'] ?? [])]));

        return $kept === [] ? null : __('extensions.errors.cascade_dependencies_kept', ['list' => implode(', ', $kept)]);
    }

    /**
     * 오류 문장 뒤에 남겨 둔 의존 확장 안내를 붙인다.
     *
     * @param  string  $message  오류 문장
     * @param  array{installed?: array<int, string>, activated?: array<int, string>}  $result  지금까지의 처리 결과
     * @return string 안내를 붙인 문장
     */
    public static function appendKeptNotice(string $message, array $result): string
    {
        $notice = self::keptDependenciesNotice($result);

        return $notice === null ? $message : $message.' '.$notice;
    }

    /**
     * 화면에서 고른 의존 확장을 계획 순서(의존 확장 먼저)로 정렬한다 — 계획에 없는 항목은 뒤에 그대로 둔다.
     *
     * @param  string  $type  본 확장 종류
     * @param  string  $identifier  본 확장 식별자
     * @param  array<int, array{type: string, identifier: string}>  $selected  화면 선택
     * @return array<int, array{type: string, identifier: string}> 정렬된 선택
     */
    public function orderSelectedDependencies(string $type, string $identifier, array $selected): array
    {
        if (count($selected) < 2) {
            return $selected;
        }

        $position = [];
        foreach ($this->resolveDependencyPlan($type, $identifier)['steps'] as $index => $step) {
            $position[$step['type'].':'.$step['identifier']] = $index;
        }

        $indexed = array_values($selected);
        usort($indexed, static function (array $a, array $b) use ($position): int {
            return ($position[($a['type'] ?? '').':'.($a['identifier'] ?? '')] ?? PHP_INT_MAX)
                <=> ($position[($b['type'] ?? '').':'.($b['identifier'] ?? '')] ?? PHP_INT_MAX);
        });

        return $indexed;
    }

    /**
     * 번들 언어팩을 설치하고 자동 활성화한다 (best-effort — 항목별 실패는 모아 돌려준다).
     *
     * 방금 설치한 확장이 아직 비활성이면 그 확장의 언어팩은 꺼진 채 「대상 켜짐 대기」로 남는다 — 실패가 아니다.
     *
     * @param  array<int, string>  $bundledIdentifiers  번들 언어팩 식별자
     * @param  int|null  $installedBy  설치자 회원 ID (터미널은 null)
     * @param  array<int, string>|null  $awaitingHost  (출력) 대상 켜짐 대기로 설치된 언어팩
     * @return array<int, array{identifier: string, reason: string}> 실패 항목
     */
    public function installLanguagePacks(array $bundledIdentifiers, ?int $installedBy, ?array &$awaitingHost = null): array
    {
        $awaitingHost = [];
        $failures = [];

        foreach ($bundledIdentifiers as $identifier) {
            try {
                $pack = $this->languagePackService->installFromBundled($identifier, autoActivate: true, installedBy: $installedBy);

                if ($pack->status === LanguagePackStatus::Installed->value
                    && $pack->deactivated_reason === LanguagePackDeactivationReason::AwaitingHost->value) {
                    $awaitingHost[] = $identifier;
                }
            } catch (\Throwable $e) {
                Log::warning('cascade language pack install failed', ['identifier' => $identifier, 'error' => $e->getMessage()]);
                $failures[] = ['identifier' => $identifier, 'reason' => $e->getMessage()];
            }
        }

        return $failures;
    }

    /**
     * 확장 정보 (설치·번들 공통 — 미설치 번들 확장도 manifest 로 조회된다).
     *
     * @param  string  $type  module|plugin|template
     * @param  string  $identifier  식별자
     * @return array<string, mixed>|null 정보
     */
    private function info(string $type, string $identifier): ?array
    {
        return match ($type) {
            'module' => $this->moduleService->getModuleInfo($identifier),
            'plugin' => $this->pluginService->getPluginInfo($identifier),
            'template' => $this->templateService->getTemplateInfo($identifier),
            default => null,
        };
    }

    /**
     * 확장 정보의 의존 선언을 (종류, 식별자, 제약) 목록으로 — 보강형(평면)·원본형(modules/plugins) 모두 받는다.
     *
     * @param  array<string, mixed>  $info  확장 정보
     * @return array<int, array{type: string, identifier: string, constraint: string}> 의존 목록
     */
    private function declaredDependencies(array $info): array
    {
        $raw = $info['dependencies'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        if (isset($raw['modules']) || isset($raw['plugins'])) {
            // 원본형은 코어 정규화기로 읽는다 — 목록형·빈 제약은 '*'
            $constraints = ExtensionDependencies::constraints($raw);
            foreach (['modules' => 'module', 'plugins' => 'plugin'] as $key => $type) {
                foreach ($constraints[$key] as $id => $constraint) {
                    $out[] = ['type' => $type, 'identifier' => (string) $id, 'constraint' => $constraint];
                }
            }

            return $out;
        }

        foreach ($raw as $dep) {
            if (is_array($dep) && isset($dep['identifier'])) {
                $out[] = [
                    'type' => (string) ($dep['type'] ?? 'module'),
                    'identifier' => (string) $dep['identifier'],
                    'constraint' => is_string($dep['required_version'] ?? null) && $dep['required_version'] !== '' ? $dep['required_version'] : '*',
                ];
            }
        }

        return $out;
    }

    /**
     * 활성화 실패 결과에서 사람이 읽을 사유를 만든다.
     *
     * @param  array<string, mixed>  $activation  activateModule/activatePlugin 결과
     * @return string 사유
     */
    private function activationFailureReason(array $activation): string
    {
        $missing = array_merge($activation['missing_modules'] ?? [], $activation['missing_plugins'] ?? []);
        if ($missing !== []) {
            // 사유 원문(version_mismatch 등)을 내보내지 않고 사람이 읽는 문장으로 — 복구 실패·CLI 와 같은 포맷터(#152 D5)
            return __('extensions.errors.cascade_dependency_unmet_list', [
                'list' => DependencyEnricher::describeUnmet($missing),
            ]);
        }

        return (string) ($activation['reason'] ?? $activation['message'] ?? __('extensions.errors.unknown_error'));
    }
}
