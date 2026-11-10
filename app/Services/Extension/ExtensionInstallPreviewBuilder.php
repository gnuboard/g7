<?php

namespace App\Services\Extension;

use App\Enums\LanguagePackScope;
use App\Extension\Helpers\DependencyEnricher;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use App\Support\LocalizedValue;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * 확장(모듈/플러그인/템플릿) 설치 cascade 프리뷰 빌더.
 *
 * 인스톨 모달이 열릴 때 호출되어 (a) 의존 확장 트리, (b) 동반 가능 번들 언어팩 트리를
 * 함께 반환합니다. 클라이언트는 이 응답을 기반으로 사용자에게 체크리스트를 노출하고,
 * 선택 결과를 install API 의 `dependencies[]`, `language_packs[]` 페이로드로 전송합니다.
 *
 * `manifest-preview` (POST + ZIP 업로드 manifest 검증) 와는 목적이 다릅니다 —
 * 본 API 는 GET + 식별자 기반 + 기설치/번들 메타 트리 조회.
 */
class ExtensionInstallPreviewBuilder
{
    /**
     * 이 설치로는 풀리지 않는 번들 언어팩 설치 차단 사유 — 후보 행을 고를 수 없게(`available=false`) 표시한다.
     *
     * `core_locale_missing`: 같은 로케일 코어 언어팩이 꺼져 있다(코어 언어팩은 이 설치의 후보가 아니다).
     * `target_version_too_old`: 이미 설치된 대상 확장의 버전이 언어팩 요구보다 낮다(함께 설치는 설치된 확장을 업데이트하지 않는다).
     * `target_not_installed` 는 본 확장·의존 확장을 함께 설치하면서 풀리므로 넣지 않는다. 설치 차단 사유는 꺼진 대상을
     * 허용해 판정하므로 `target_inactive` 는 이 후보에 붙지 않는다.
     *
     * @var array<int, string>
     */
    private const UNRESOLVABLE_LANGUAGE_PACK_BLOCKS = ['core_locale_missing', 'target_version_too_old'];

    /**
     * 프리뷰가 쓰는 확장·언어팩 서비스를 주입받습니다.
     *
     * @param  ModuleService  $moduleService  모듈 서비스
     * @param  PluginService  $pluginService  플러그인 서비스
     * @param  TemplateService  $templateService  템플릿 서비스
     * @param  LanguagePackService  $languagePackService  언어팩 서비스
     * @param  CascadeInstallAuthorizer  $authorizer  동반 설치 항목별 권한 판정
     * @param  CascadeInstallOrchestrator|null  $orchestrator  연동 대상의 필수 의존 계획 (없으면 컨테이너에서 꺼낸다)
     */
    public function __construct(
        private readonly ModuleService $moduleService,
        private readonly PluginService $pluginService,
        private readonly TemplateService $templateService,
        private readonly LanguagePackService $languagePackService,
        private readonly CascadeInstallAuthorizer $authorizer,
        private readonly ?CascadeInstallOrchestrator $orchestrator = null,
    ) {}

    /**
     * 설치 cascade 프리뷰를 빌드합니다.
     *
     * @param  LanguagePackScope  $scope  대상 스코프 (module/plugin/template)
     * @param  string  $identifier  대상 확장 식별자
     * @return array<string, mixed> {target, dependencies[], integrations[], language_packs[]}
     *
     * @throws RuntimeException 대상 확장을 찾을 수 없을 때
     */
    public function build(LanguagePackScope $scope, string $identifier): array
    {
        $info = $this->resolveExtensionInfo($scope, $identifier);
        if (! $info) {
            throw new RuntimeException(__('extensions.errors.not_found', ['identifier' => $identifier]));
        }

        // 모듈/플러그인은 ModuleService/PluginService 가 이미 enriched 형태(평면 배열)로 반환하지만,
        // 템플릿은 TemplateService 가 manifest 의 raw `{modules, plugins}` shape 를 그대로 반환한다
        // (TemplateResource 가 raw 를 기대해서 바꾸기 어려움). 여기서 템플릿 한정으로 enrich.
        $rawDependencies = $info['dependencies'] ?? [];
        if ($scope === LanguagePackScope::Template
            && (isset($rawDependencies['modules']) || isset($rawDependencies['plugins']))) {
            $rawDependencies = DependencyEnricher::enrich($rawDependencies);
        }
        $dependencies = $this->appendTransitiveDependencies(
            $scope,
            $identifier,
            $this->buildDependencies($rawDependencies)
        );
        $languagePacks = $this->buildLanguagePacks($scope, $identifier, $dependencies);
        $integrations = $this->buildIntegrations($info);

        return [
            'target' => [
                'identifier' => $info['identifier'] ?? $identifier,
                'name' => $info['name'] ?? null,
                'version' => $info['version'] ?? null,
                // 설치 확인 창은 목록을 거치지 않고 이 응답만으로 그리므로 아이콘도 함께 낸다.
                'icon_url' => $info['icon_url'] ?? null,
                'icon_source' => $info['icon_source'] ?? 'none',
            ],
            'dependencies' => $dependencies,
            // 함께 쓰면 연동되는 확장(선택, 7.1.4) — 설치를 막지 않는다. 고른 항목은 install API 의 `integrations[]` 로 온다
            'integrations' => $integrations,
            'language_packs' => $languagePacks,
        ];
    }

    /**
     * 선택 연동 선언을 설치 창 체크목록용 메타로 변환합니다 (7.1.4, P12·D9).
     *
     * 미설치·비활성 항목은 기본 체크(`default_selected`)이고 해제해도 본체 설치는 진행된다. 대상 유형이 템플릿인 연동은
     * 표시만 하고 고를 수 없다(`can_select=false`) — 템플릿은 유형마다 하나만 켜므로 고르면 사용 중인 템플릿이 바뀐다.
     * 권한은 의존 확장과 같은 판정({@see CascadeInstallAuthorizer::canSelectDependency()})을 쓴다.
     *
     * @param  array<string, mixed>  $info  대상 확장 정보 (모듈·플러그인은 보강된 `integrations`, 템플릿은 `integration_constraints`)
     * @return array<int, array<string, mixed>> 연동 체크목록 항목
     */
    private function buildIntegrations(array $info): array
    {
        $raw = $info['integration_constraints'] ?? ($info['integrations'] ?? []);
        $enriched = is_array($raw) && array_is_list($raw) && isset($raw[0]['identifier'])
            ? $raw
            : DependencyEnricher::enrichIntegrations($raw);

        $result = [];
        foreach ($enriched as $item) {
            $type = (string) ($item['type'] ?? 'module');
            $isInstalled = (bool) ($item['is_installed'] ?? false);
            $isActive = (bool) ($item['is_active'] ?? false);
            $status = ! $isInstalled ? 'not_installed' : ($isActive ? 'active' : 'inactive');
            $canSelect = $type !== 'template' && $this->authorizer->canSelectDependency($type, $isInstalled);

            // 연동 대상 자신의 필수 의존(#153 D5) — 고르면 함께 먼저 설치·활성화된다. 계획을 세울 수 없거나(없음·버전 미달·순환)
            // 필요한 확장을 설치할 권한이 없으면 이 연동은 고를 수 없다.
            ['requires' => $requires, 'blocked_reason' => $blockedReason, 'blocked_by_permission' => $blockedByPermission]
                = $canSelect && $status !== 'active'
                    ? $this->integrationRequirements($type, (string) ($item['identifier'] ?? ''))
                    : ['requires' => [], 'blocked_reason' => null, 'blocked_by_permission' => false];
            if ($blockedReason !== null || $blockedByPermission) {
                $canSelect = false;
            }

            $result[] = [
                'type' => $type,
                'identifier' => (string) ($item['identifier'] ?? ''),
                'name' => $item['name'] ?? null,
                'status' => $status,
                'required_version' => $item['required_version'] ?? '*',
                'installed_version' => $item['installed_version'] ?? null,
                'is_installed' => $isInstalled,
                'is_active' => $isActive,
                'is_met' => (bool) ($item['is_met'] ?? false),
                'can_select' => $canSelect,
                'default_selected' => $status !== 'active' && $canSelect,
                'requires' => $requires,
                'blocked_reason' => $blockedReason,
                'blocked_by_permission' => $blockedByPermission,
            ];
        }

        return $result;
    }

    /**
     * 연동 대상을 함께 설치할 때 먼저 설치·활성화해야 하는 필수 의존 — 관리자 연쇄 설치와 같은 계획(의존의 의존까지, 먼저 올 순서).
     *
     * @param  string  $type  연동 대상 종류 (module|plugin)
     * @param  string  $identifier  연동 대상 식별자
     * @return array{requires: array<int, array{type: string, identifier: string, name: string, action: string}>, blocked_reason: string|null, blocked_by_permission: bool} 필요한 확장·막힌 사유
     */
    private function integrationRequirements(string $type, string $identifier): array
    {
        // 연동 대상 자신의 정보를 읽지 못하면 계획을 세우지 않는다 — 대상 없음은 연동 대상의 「필수 의존」 사유가 아니고,
        // 설치 단계가 종전과 같이 그 사유를 알린다.
        $target = $type === 'module' ? $this->moduleService->getModuleManifestInfo($identifier) : $this->pluginService->getPluginManifestInfo($identifier);
        if ($target === null) {
            return ['requires' => [], 'blocked_reason' => null, 'blocked_by_permission' => false];
        }

        $plan = ($this->orchestrator ?? app(CascadeInstallOrchestrator::class))->resolveDependencyPlan($type, $identifier);

        $requires = [];
        $blockedByPermission = false;
        foreach ($plan['steps'] as $step) {
            $info = $step['type'] === 'module'
                ? $this->moduleService->getModuleManifestInfo($step['identifier'])
                : $this->pluginService->getPluginManifestInfo($step['identifier']);
            $requires[] = [
                'type' => $step['type'],
                'identifier' => $step['identifier'],
                'name' => (string) ($info['name'] ?? $step['identifier']),
                'action' => $step['action'],
            ];
            if (! $this->authorizer->canSelectDependency($step['type'], $step['action'] === 'activate')) {
                $blockedByPermission = true;
            }
        }

        return [
            'requires' => $requires,
            'blocked_reason' => $plan['errors'][0] ?? null,
            'blocked_by_permission' => $blockedByPermission,
        ];
    }

    /**
     * 스코프별 Service 에 위임하여 대상 확장 메타데이터를 조회합니다.
     *
     * @param  LanguagePackScope  $scope  대상 스코프
     * @param  string  $identifier  대상 확장 식별자
     * @return array<string, mixed>|null 확장 메타데이터 (없으면 null)
     */
    private function resolveExtensionInfo(LanguagePackScope $scope, string $identifier): ?array
    {
        return match ($scope) {
            LanguagePackScope::Module => $this->moduleService->getModuleManifestInfo($identifier),
            LanguagePackScope::Plugin => $this->pluginService->getPluginManifestInfo($identifier),
            LanguagePackScope::Template => $this->templateService->getTemplateInfo($identifier),
            default => null,
        };
    }

    /**
     * 의존성 enrichment 결과를 cascade 선택 UI 용 메타로 변환합니다.
     *
     * `DependencyEnricher` 가 생산하는 enriched 항목(identifier/name/type/required_version/
     * installed_version/is_active/is_met) 를 받아 `is_installed`, `default_selected`,
     * `available` 필드를 추가합니다. 충족되지 않은(미설치·꺼짐) 의존은 기본 선택이고, 현재 사용자가 그 항목을 함께 설치·활성화할 권한이 있는지는
     * `can_select` 로 싣고, 권한이 없으면 기본 선택에서 뺀다 — 설치 창이 체크박스를 잠그고
     * 설치 API 도 같은 판정으로 403 을 낸다.
     *
     * @param  array<int, array<string, mixed>>  $enriched  enriched 의존성 목록
     * @return array<int, array<string, mixed>> cascade 선택용 의존성 메타 목록
     */
    private function buildDependencies(array $enriched): array
    {
        $result = [];
        foreach ($enriched as $dep) {
            $isInstalled = ! empty($dep['installed_version']);
            $isMet = (bool) ($dep['is_met'] ?? false);
            $canSelect = $this->authorizer->canSelectDependency((string) ($dep['type'] ?? 'module'), $isInstalled);
            // 설치됐지만 판이 요구에 못 미치면 함께 설치·활성화로 풀 수 없다 — 선택하게 두면 건너뛴 뒤 본 설치가 실패한다(#152).
            // 의존 확장을 먼저 업데이트해야 하므로 고를 수 없게 막고 이유를 싣는다(설치 창이 「버전 불일치」 배지로 보인다)
            $versionBlocked = ! $isMet && $isInstalled
                && ! DependencyEnricher::checkVersionConstraint((string) $dep['installed_version'], $dep['required_version'] ?? null);

            $result[] = [
                'type' => $dep['type'] ?? 'module',
                'identifier' => $dep['identifier'] ?? '',
                'name' => $dep['name'] ?? null,
                'required_version' => $dep['required_version'] ?? null,
                'installed_version' => $dep['installed_version'] ?? null,
                'is_installed' => $isInstalled,
                'is_active' => (bool) ($dep['is_active'] ?? false),
                'is_met' => $isMet,
                'status' => $dep['status'] ?? null,
                // 미충족 의존성만 cascade 후보 — 충족 의존성은 추가 설치 불필요, 버전 미달은 함께 처리로 풀 수 없음
                'available' => ! $isMet && ! $versionBlocked,
                'blocked_reason' => $versionBlocked ? DependencyEnricher::STATUS_VERSION_MISMATCH : null,
                // 충족되지 않은 의존(미설치·꺼짐)은 기본 선택 — 설치는 의존이 켜져 있어야 진행되므로, 꺼진 의존을 기본 해제로 두면
                // 창의 기본값 그대로 설치할 때 반드시 실패한다. 버전 미달은 함께 처리로 풀 수 없어 고를 수 없다
                'default_selected' => ! $isMet && ! $versionBlocked && $canSelect,
                // 이 항목을 함께 설치·활성화할 권한 (미설치=install+activate, 설치됨=activate)
                'can_select' => $canSelect,
            ];
        }

        return $result;
    }

    /**
     * 의존의 의존(2단계 이상)을 직접 의존 뒤에 덧붙입니다 — 설치할 때 함께 설치·활성화해야 하는 확장.
     *
     * 해석은 설치가 실제로 쓰는 연쇄 설치 서비스와 같다(CascadeInstallOrchestrator::resolveDependencyPlan) —
     * 미리보기와 설치가 서로 다른 목록을 보지 않게. 직접 의존 행은 그대로 두고, 없던 행만 같은 모양으로 더한다.
     *
     * @param  LanguagePackScope  $scope  본 확장 스코프
     * @param  string  $identifier  본 확장 식별자
     * @param  array<int, array<string, mixed>>  $direct  직접 의존 행
     * @return array<int, array<string, mixed>> 직접 + 추이 의존 행
     */
    private function appendTransitiveDependencies(LanguagePackScope $scope, string $identifier, array $direct): array
    {
        $known = [];
        foreach ($direct as $dep) {
            $known[($dep['type'] ?? '').':'.($dep['identifier'] ?? '')] = true;
        }

        $plan = app(CascadeInstallOrchestrator::class)->resolveDependencyPlan($scope->value, $identifier);
        foreach ($plan['steps'] as $step) {
            if (isset($known[$step['type'].':'.$step['identifier']])) {
                continue;
            }

            $isInstalled = $step['action'] === 'activate';
            $canSelect = $this->authorizer->canSelectDependency($step['type'], $isInstalled);
            $direct[] = [
                'type' => $step['type'],
                'identifier' => $step['identifier'],
                'name' => $this->dependencyName($step['type'], $step['identifier']),
                'required_version' => $step['required_version'],
                'installed_version' => $step['installed_version'],
                'is_installed' => $isInstalled,
                'is_active' => false,
                'is_met' => false,
                'status' => $isInstalled ? DependencyEnricher::STATUS_INACTIVE : DependencyEnricher::STATUS_NOT_INSTALLED,
                // 버전 미달 의존은 계획 해석(resolveDependencyPlan)이 단계에서 빼고 오류로 보내므로 여기 오지 않는다
                'available' => true,
                'blocked_reason' => null,
                // 계획에 든 단계는 모두 미설치 또는 꺼짐이다 — 직접 의존과 같이 기본 선택(꺼진 의존을 해제하면 설치가 반드시 실패한다)
                'default_selected' => $canSelect,
                'can_select' => $canSelect,
                // 어느 의존 확장 때문에 필요한지 — 2단계 의존 행 구분용
                'required_by' => $step['required_by'],
            ];
        }

        return $direct;
    }

    /**
     * 의존 확장 표시 이름 (현재 로케일, 없으면 식별자).
     *
     * @param  string  $type  module|plugin
     * @param  string  $identifier  식별자
     * @return string 이름
     */
    private function dependencyName(string $type, string $identifier): string
    {
        $info = $type === 'module' ? $this->moduleService->getModuleManifestInfo($identifier) : $this->pluginService->getPluginManifestInfo($identifier);
        // 다국어 이름은 사이트 언어 대체 체인으로 고른다 — 'ko' 고정 대체는 다른 기본 언어 사이트에서 틀린다
        $name = LocalizedValue::pick($info['name'] ?? null);

        return $name !== null && $name !== '' ? $name : $identifier;
    }

    /**
     * 본 확장 + 의존 확장에 귀속된 미설치 번들 언어팩 후보를 수집합니다.
     *
     * `lang-packs/_bundled/{identifier}/language-pack.json` manifest 를 직접 스캔하여
     * (a) 본 확장용 (b) 의존 확장용 항목을 모두 포함합니다. DB 에 이미 설치된 슬롯은
     * 제외 (LanguagePackService::getUninstalledBundledPacks 의 슬롯 머지 로직 활용).
     * 이 설치로 풀리지 않는 사유가 있는 언어팩(같은 로케일 코어 언어팩 꺼짐 `core_locale_missing`, 이미 설치된 대상 확장의 버전이
     * 언어팩 요구보다 낮음 `target_version_too_old`)은 `available=false`·`blocked_reason` 이고 기본 선택이 아니다.
     * 사유 문장을 채울 수 있게 대상 확장 종류(`scope`)와 요구 대상 버전(`required_target_version`)을 함께 싣는다.
     *
     * @param  LanguagePackScope  $scope  본 확장 스코프
     * @param  string  $identifier  본 확장 식별자
     * @param  array<int, array<string, mixed>>  $dependencies  의존성 메타
     * @return array<int, array<string, mixed>> 함께 설치할 언어팩 후보 목록
     */
    private function buildLanguagePacks(LanguagePackScope $scope, string $identifier, array $dependencies): array
    {
        $bundledRoot = base_path('lang-packs/_bundled');
        if (! File::isDirectory($bundledRoot)) {
            return [];
        }

        $allUninstalled = $this->languagePackService->getUninstalledBundledPacks([]);
        // 언어팩은 설치 후 자동 활성화까지 하므로 설치·관리 권한을 함께 본다.
        $canSelectPack = $this->authorizer->canSelectLanguagePack();

        $depIndex = [];
        $metIndex = [];
        foreach ($dependencies as $dep) {
            $depIndex[$dep['identifier']] = $dep['type'];
            $metIndex[$dep['identifier']] = (bool) ($dep['is_met'] ?? false);
        }

        $result = [];
        foreach ($allUninstalled as $pack) {
            $packScope = $pack->scope;
            $packTarget = $pack->target_identifier;

            $matchesSelf = $packScope === $scope->value && $packTarget === $identifier;
            $matchesDep = $packTarget !== null
                && isset($depIndex[$packTarget])
                && $packScope === $depIndex[$packTarget];

            if (! $matchesSelf && ! $matchesDep) {
                continue;
            }

            // 화면은 depends_on_extension 이 있는 언어팩을 「그 의존 확장을 함께 설치하도록 골랐을 때」만 켠다.
            // 이미 설치·활성화되어 충족된 의존 확장은 고를 수 없는 항목이라, 묶어 두면 그 언어팩을 영영 고를 수 없다.
            $dependsOn = ($matchesSelf || ($metIndex[$packTarget] ?? false)) ? null : $packTarget;

            // 확인 창은 목록을 거치지 않고 이 응답만으로 그린다 — 언어팩 줄의 아이콘도 함께 낸다.
            // 판정(자기 선언 → 코어 → 대상 확장 → 없음)은 LanguagePackService 한 곳에 둔다.
            $this->languagePackService->annotateIcon($pack);

            // 이 설치로는 풀리지 않는 사유가 있으면 행은 남기되 고를 수 없는 후보로 표시하고 기본 선택에서 뺀다(기본값 그대로
            // 설치하면 반드시 실패한다). 판정은 LanguagePackService 의 설치 차단 사유를 그대로 쓴다.
            $blockedReason = in_array($pack->install_blocked_reason, self::UNRESOLVABLE_LANGUAGE_PACK_BLOCKS, true)
                ? $pack->install_blocked_reason
                : null;
            $requiredTargetVersion = $pack->manifest['requires']['target_version'] ?? null;

            $result[] = [
                'bundled_identifier' => $pack->identifier,
                'locale' => $pack->locale,
                'locale_native_name' => $pack->locale_native_name,
                'locale_name' => $pack->locale_name,
                'version' => $pack->version,
                'depends_on_extension' => $dependsOn,
                // 어느 확장의 언어팩인지 — 창이 「종속: …」 배지로 보인다(충족된 의존 확장의 언어팩도 구분되게).
                'target_identifier' => $packTarget,
                // 대상 확장 종류와 요구 대상 버전 — 설치할 수 없는 사유 문장(대상 :scope (:target) … :constraint)을 채운다
                'scope' => $packScope,
                'required_target_version' => is_scalar($requiredTargetVersion) && (string) $requiredTargetVersion !== ''
                    ? (string) $requiredTargetVersion
                    : null,
                'available' => $blockedReason === null,
                'blocked_reason' => $blockedReason,
                'default_selected' => $blockedReason === null && $canSelectPack,
                // 권한 판정 — 설치할 수 없는 사유는 available·blocked_reason 으로 따로 싣는다(의존 확장 행과 같은 관례)
                'can_select' => $canSelectPack,
                'icon_url' => $pack->icon_url,
                'icon_source' => $pack->icon_source ?? 'none',
            ];
        }

        // 정렬: 자기 확장 우선 → 의존성 식별자 → locale
        usort($result, function (array $a, array $b) {
            $selfA = $a['depends_on_extension'] === null ? 0 : 1;
            $selfB = $b['depends_on_extension'] === null ? 0 : 1;
            if ($selfA !== $selfB) {
                return $selfA <=> $selfB;
            }
            $depCmp = strcmp((string) $a['depends_on_extension'], (string) $b['depends_on_extension']);
            if ($depCmp !== 0) {
                return $depCmp;
            }

            return strcmp((string) $a['locale'], (string) $b['locale']);
        });

        return $result;
    }
}
