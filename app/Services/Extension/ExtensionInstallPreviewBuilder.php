<?php

namespace App\Services\Extension;

use App\Enums\LanguagePackScope;
use App\Extension\Helpers\DependencyEnricher;
use App\Services\LanguagePackService;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
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
     * @param  ModuleService  $moduleService
     * @param  PluginService  $pluginService
     * @param  TemplateService  $templateService
     * @param  LanguagePackService  $languagePackService
     */
    public function __construct(
        private readonly ModuleService $moduleService,
        private readonly PluginService $pluginService,
        private readonly TemplateService $templateService,
        private readonly LanguagePackService $languagePackService,
    ) {}

    /**
     * 설치 cascade 프리뷰를 빌드합니다.
     *
     * @param  LanguagePackScope  $scope  대상 스코프 (module/plugin/template)
     * @param  string  $identifier  대상 확장 식별자
     * @return array<string, mixed> {target, dependencies[], language_packs[]}
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
        $dependencies = $this->buildDependencies($rawDependencies);
        $languagePacks = $this->buildLanguagePacks($scope, $identifier, $dependencies);

        return [
            'target' => [
                'identifier' => $info['identifier'] ?? $identifier,
                'name' => $info['name'] ?? null,
                'version' => $info['version'] ?? null,
            ],
            'dependencies' => $dependencies,
            'language_packs' => $languagePacks,
        ];
    }

    /**
     * 스코프별 Service 에 위임하여 대상 확장 메타데이터를 조회합니다.
     *
     * @param  LanguagePackScope  $scope
     * @param  string  $identifier
     * @return array<string, mixed>|null
     */
    private function resolveExtensionInfo(LanguagePackScope $scope, string $identifier): ?array
    {
        return match ($scope) {
            LanguagePackScope::Module => $this->moduleService->getModuleInfo($identifier),
            LanguagePackScope::Plugin => $this->pluginService->getPluginInfo($identifier),
            LanguagePackScope::Template => $this->templateService->getTemplateInfo($identifier),
            default => null,
        };
    }

    /**
     * 의존성 enrichment 결과를 cascade 선택 UI 용 메타로 변환합니다.
     *
     * `DependencyEnricher` 가 생산하는 enriched 항목(identifier/name/type/required_version/
     * installed_version/is_active/is_met) 를 받아 `is_installed`, `default_selected`,
     * `available` 필드를 추가합니다.
     *
     * @param  array<int, array<string, mixed>>  $enriched  enriched 의존성 목록
     * @return array<int, array<string, mixed>>
     */
    private function buildDependencies(array $enriched): array
    {
        $result = [];
        foreach ($enriched as $dep) {
            $isInstalled = ! empty($dep['installed_version']);
            $isMet = (bool) ($dep['is_met'] ?? false);

            $result[] = [
                'type' => $dep['type'] ?? 'module',
                'identifier' => $dep['identifier'] ?? '',
                'name' => $dep['name'] ?? null,
                'required_version' => $dep['required_version'] ?? null,
                'installed_version' => $dep['installed_version'] ?? null,
                'is_installed' => $isInstalled,
                'is_active' => (bool) ($dep['is_active'] ?? false),
                'is_met' => $isMet,
                // 미충족 의존성만 cascade 후보 — 충족 의존성은 추가 설치 불필요
                'available' => ! $isMet,
                // 미충족 + 미설치 항목은 기본 선택 (체크리스트 prefill)
                'default_selected' => ! $isMet && ! $isInstalled,
            ];
        }

        return $result;
    }

    /**
     * 본 확장 + 의존 확장에 귀속된 미설치 번들 언어팩 후보를 수집합니다.
     *
     * `lang-packs/_bundled/{identifier}/language-pack.json` manifest 를 직접 스캔하여
     * (a) 본 확장용 (b) 의존 확장용 항목을 모두 포함합니다. DB 에 이미 설치된 슬롯은
     * 제외 (LanguagePackService::getUninstalledBundledPacks 의 슬롯 머지 로직 활용).
     *
     * @param  LanguagePackScope  $scope  본 확장 스코프
     * @param  string  $identifier  본 확장 식별자
     * @param  array<int, array<string, mixed>>  $dependencies  의존성 메타
     * @return array<int, array<string, mixed>>
     */
    private function buildLanguagePacks(LanguagePackScope $scope, string $identifier, array $dependencies): array
    {
        $bundledRoot = base_path('lang-packs/_bundled');
        if (! File::isDirectory($bundledRoot)) {
            return [];
        }

        $allUninstalled = $this->languagePackService->getUninstalledBundledPacks([]);

        $depIndex = [];
        foreach ($dependencies as $dep) {
            $depIndex[$dep['identifier']] = $dep['type'];
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

            $dependsOn = $matchesSelf ? null : $packTarget;

            $result[] = [
                'bundled_identifier' => $pack->identifier,
                'locale' => $pack->locale,
                'locale_native_name' => $pack->locale_native_name,
                'locale_name' => $pack->locale_name,
                'version' => $pack->version,
                'depends_on_extension' => $dependsOn,
                'available' => true,
                'default_selected' => true,
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
