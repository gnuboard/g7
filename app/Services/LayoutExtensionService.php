<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Exceptions\ConcurrentModificationException;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\LayoutExtension;
use App\Models\TemplateLayoutExtensionVersion;
use Composer\Semver\Semver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 확장 서비스
 *
 * 모듈/플러그인이 기존 레이아웃에 UI를 동적으로 주입하는 기능을 처리합니다.
 */
class LayoutExtensionService
{
    use ComputesLayoutContentHash;

    /**
     * 활성화된 모듈 식별자 목록 (캐시)
     *
     * @var array<string>|null
     */
    private ?array $activeModules = null;

    /**
     * 활성화된 플러그인 식별자 목록 (캐시)
     *
     * @var array<string>|null
     */
    private ?array $activePlugins = null;

    /**
     * 버전 비호환 오버라이드 목록
     *
     * @var array<array{extension_id: int, source: string, target: string, constraint: string, current_version: string}>
     */
    private array $incompatibleOverrides = [];

    /**
     * 확장 표시명 해석 캐시 (요청 단위) — 동일 식별자 반복 조회 회피.
     *
     * @var array<string, string|null>
     */
    private array $extensionNameCache = [];

    /**
     * 미리보기 확장 content 오버라이드
     *
     * applyExtensions 실행 시, 이 키에 해당하는 extension_id 의 확장은
     * DB content 대신 여기 저장된 content 로 치환됩니다.
     * 레이아웃 확장 미리보기에서 편집 중인 content 를 임시 적용할 때 사용합니다.
     *
     * @var array<int, array>
     */
    private array $previewContentOverrides = [];

    /**
     * 미리보기 확장 content 오버라이드를 설정합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  array  $content  치환할 확장 정의 JSON
     */
    public function setPreviewContentOverride(int $extensionId, array $content): void
    {
        $this->previewContentOverrides[$extensionId] = $content;
    }

    /**
     * 미리보기 확장 content 오버라이드를 모두 제거합니다.
     */
    public function clearPreviewContentOverrides(): void
    {
        $this->previewContentOverrides = [];
    }

    /**
     * 확장 컬렉션에 미리보기 content 오버라이드를 적용합니다.
     *
     * @param  Collection<int, LayoutExtension>  $extensions  확장 컬렉션
     * @return Collection<int, LayoutExtension>
     */
    private function applyPreviewOverrides(Collection $extensions): Collection
    {
        if (empty($this->previewContentOverrides)) {
            return $extensions;
        }

        foreach ($extensions as $extension) {
            if (isset($this->previewContentOverrides[$extension->id])) {
                $extension->content = $this->previewContentOverrides[$extension->id];
            }
        }

        return $extensions;
    }

    /**
     * 생성자
     *
     * 순환 의존성 방지를 위해 ModuleManager, PluginManager는 생성자에서 주입받지 않고
     * 필요한 시점에 app() 헬퍼로 가져옵니다.
     */
    public function __construct(
        private LayoutExtensionRepositoryInterface $repository,
        private LayoutExtensionVersionRepositoryInterface $versionRepository,
        private LayoutRepositoryInterface $layoutRepository,
        private CacheInterface $cache,
        private PluginRepositoryInterface $pluginRepository,
        private ModuleRepositoryInterface $moduleRepository,
    ) {}

    /**
     * 레이아웃에 확장 적용
     *
     * @param  array  $layout  원본 레이아웃
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $withSourceMeta  편집 모드 출처 메타 부여 여부
     * @return array 확장이 적용된 레이아웃
     */
    public function applyExtensions(array $layout, int $templateId, bool $withSourceMeta = false): array
    {
        $layoutName = $layout['layout_name'] ?? '';

        // overlay 매칭 대상 = 서빙 레이아웃명 + 상속 체인(base 레이아웃들).
        // home(extends _user_base) 같은 자식 서빙 시, base(_user_base)를 target_layout 으로 하는
        // overlay(헤더 통화 슬롯 주입 등)도 매칭되도록 LayoutService 가 부여한 __extends_chain 을
        // 포함한다. Extension Point 는 병합 트리를 스캔하므로(체인 무관) 영향 없다.
        $extendsChain = is_array($layout['__extends_chain'] ?? null) ? $layout['__extends_chain'] : [];
        $overlayTargetNames = array_values(array_unique(array_filter(
            array_merge([$layoutName], $extendsChain),
            fn ($name) => is_string($name) && $name !== ''
        )));

        // 비호환 오버라이드 목록 초기화
        $this->incompatibleOverrides = [];

        // 활성화된 모듈/플러그인 목록 캐시 초기화
        $this->initializeActiveExtensions();

        // 훅: 확장 적용 전
        HookManager::doAction('core.layout_extension.before_apply', $layout, $templateId);

        // scripts 배열 초기화 (기존 scripts 유지)
        $scripts = $layout['scripts'] ?? [];

        // 1. Extension Point 처리
        $layout = $this->applyExtensionPoints($layout, $templateId, $scripts, $withSourceMeta);

        // 2. Overlay 처리
        $modals = $layout['modals'] ?? [];
        $layout = $this->applyOverlays($layout, $templateId, $overlayTargetNames, $scripts, $modals, $withSourceMeta);
        if (! empty($modals)) {
            $layout['modals'] = $modals;
        }

        // 3. scripts 병합 결과 적용
        if (! empty($scripts)) {
            $layout['scripts'] = $scripts;
        }

        // 4. 비호환 오버라이드 경고를 warnings 필드에 추가
        if (! empty($this->incompatibleOverrides)) {
            $layout['warnings'] = $this->formatWarningsForFrontend();
        }

        // 상속 체인 매칭 메타는 내부 전용 — 최종 응답(일반 렌더 + 편집 모드 둘 다)에서 제거한다.
        unset($layout['__extends_chain']);

        // 훅: 확장 적용 후
        $layout = HookManager::applyFilters('core.layout_extension.after_apply', $layout, $templateId);

        return $layout;
    }

    /**
     * 확장의 호스트 레이아웃 후보 목록을 반환합니다.
     *
     * 확장 편집 모드 캔버스가 "호스트 레이아웃 전체를 렌더하고 그 안에서 확장 조각만 편집"
     * 하기 위한 대상 레이아웃. overlay 는 `content.target_layout` 1개로 확정되며,
     * extension_point 는 그 확장점을 포함하는 레이아웃이 여럿일 수 있어 모두 반환한다
     * (클라이언트가 1개면 즉시 진입, 복수면 대표 호스트 선택 picker 를 띄운다).
     *
     * @param  LayoutExtension  $extension  확장 모델
     * @return array<int, string> 호스트 레이아웃 이름 목록
     */
    public function getExtensionHostLayouts(LayoutExtension $extension): array
    {
        $content = is_array($extension->content) ? $extension->content : [];

        // 판별 기준: 호스트 인정 = "그 호스트에 주입이
        // **성립**하는가" 수준만 백엔드가 판정한다. 주입이 성립하면 트리에 노출하되, 시각 편집
        // 가능 여부의 정밀 판정(게이트/모달 뒤 노출, 조각 0건)은 프론트 진입 시
        // editability(useExtensionDocument 1단계) + 렌더 검증(PreviewCanvas 2단계) + 폴백 안내로
        // 일원화한다(숨김이 아니라 디그레이드). 종전 "components 유무" 게이트는 부정확
        // (components 가 있어도 게이트 뒤면 미노출, modals 주입도 편집기 모달 표시 메커니즘으로
        // 노출 가능)하여 폐기.
        if ($extension->extension_type === LayoutExtensionType::Overlay) {
            $target = $content['target_layout'] ?? null;
            if (! is_string($target) || $target === '') {
                return [];
            }

            // overlay 주입 성립 조건 (하나라도 충족 시 호스트 인정):
            //  (a) target_id 가 호스트에 실재하는 injection 존재 — components 주입 또는
            //  inject_props(속성 병합 — 속성 모달에서 편집). target_id 부재 injection
            //  은 applyExtensions no-op 이므로 제외.
            //  (b) content.modals / data_sources / scripts 주입 — 호스트에 무언가 병합됨.
            //      시각 조각이 없으면 프론트가 'no-injection' 디그레이드 안내(코드 편집 유도)로
            //      처리한다(완전 빈 content 만 백엔드가 제외).
            $injections = is_array($content['injections'] ?? null) ? $content['injections'] : [];
            foreach ($injections as $inj) {
                if (! is_array($inj)) {
                    continue;
                }
                $tid = $inj['target_id'] ?? null;
                if (! is_string($tid) || $tid === '') {
                    continue;
                }
                if ($this->layoutRepository->layoutContainsNodeId($extension->template_id, $target, $tid)) {
                    return [$target];
                }
            }

            // initActions(표준 camelCase) 와 init_actions(deprecated snake) 둘 다 부수 주입으로 인정.
            foreach (['modals', 'data_sources', 'scripts', 'initActions', 'init_actions'] as $key) {
                if (is_array($content[$key] ?? null) && $content[$key] !== []) {
                    return [$target];
                }
            }

            // 실재 target injection 도, 부수 주입(modals/ds/scripts/initActions)도 없음 — 주입 불성립.
            return [];
        }

        // extension_point — 그 확장점을 포함하는 레이아웃 전체. 주입 성립 조건: content 에
        // 병합할 무언가(components/modals/data_sources/scripts)가 있어야 한다. components 가
        // 없어도(예: 토스페이먼츠 — modals 만) 트리에는 노출하고, 클릭 시 프론트가 시각 조각
        // 유무를 판정해 디그레이드 안내한다.
        $hasAnyPayload = false;
        foreach (['components', 'modals', 'data_sources', 'scripts', 'initActions', 'init_actions'] as $key) {
            if (is_array($content[$key] ?? null) && $content[$key] !== []) {
                $hasAnyPayload = true;
                break;
            }
        }
        if (! $hasAnyPayload) {
            return [];
        }

        $extensionPoint = $content['extension_point'] ?? $extension->target_name;
        if (! is_string($extensionPoint) || $extensionPoint === '') {
            return [];
        }

        return $this->layoutRepository->findLayoutNamesWithExtensionPoint(
            $extension->template_id,
            $extensionPoint
        );
    }

    /**
     * 컴포넌트 트리에 확장 출처 메타를 재귀적으로 부여합니다.
     *
     * 확장이 주입한 노드와 그 자식 모두에 `__source.kind = 'extension'` + `extensionId` 를
     * 부여하여 편집기 잠금 판정 이 가능하게 합니다.
     *
     * `$extension` 이 전달되면 출처 타입/식별자/표시명(`extensionSourceType`/
     * `extensionIdentifier`/`extensionName`)도 함께 부여하여, 편집기 오버레이가
     * "어느 확장인지"를 로케일 표시명 + 식별자로 표시할 수 있게 합니다.
     *
     * 출처 메타는 확장 1건당 한 번만 계산(표시명 조회 포함)하여 트리 전체에 전파한다.
     *
     * @param  array  $components  주입된 컴포넌트 배열
     * @param  int  $extensionId  확장 PK
     * @param  LayoutExtension|null  $extension  출처 라벨 부여용 확장 모델
     * @return array 메타가 부여된 컴포넌트 배열
     *
     * @since engine-v1.50.0
     */
    private function markExtensionSource(array $components, int $extensionId, ?LayoutExtension $extension = null): array
    {
        return $this->applySourceMetaRecursively($components, $this->buildExtensionSourceMeta($extension, $extensionId));
    }

    /**
     * 확장 출처 `__source` 메타를 구성합니다 (컴포넌트·data_source 공용).
     *
     * `kind = 'extension'` + 출처 타입(module/plugin)/식별자/표시명. 편집기가 "어느 확장이
     * 주입했는지"를 모듈/플러그인 + 식별자 + 로케일 표시명으로 표시하는 데 쓴다.
     *
     * @param  LayoutExtension|null  $extension  출처 확장 모델
     * @param  int|null  $extensionId  확장 PK (미지정 시 `$extension->id`)
     * @return array<string, mixed> `__source` 메타
     *
     * @since engine-v1.50.0
     */
    private function buildExtensionSourceMeta(?LayoutExtension $extension, ?int $extensionId = null): array
    {
        $sourceMeta = ['kind' => 'extension', 'extensionId' => $extensionId ?? $extension?->id];

        if ($extension !== null) {
            $sourceMeta['extensionSourceType'] = $extension->source_type->value;
            $sourceMeta['extensionIdentifier'] = $extension->source_identifier;
            $name = $this->resolveExtensionDisplayName($extension);
            if ($name !== null) {
                $sourceMeta['extensionName'] = $name;
            }
        }

        return $sourceMeta;
    }

    /**
     * 사전 계산된 출처 메타를 컴포넌트 트리에 재귀 부여합니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $sourceMeta  부여할 `__source` 메타
     * @return array 메타가 부여된 컴포넌트 배열
     */
    private function applySourceMetaRecursively(array $components, array $sourceMeta): array
    {
        $result = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                $result[] = $component;

                continue;
            }

            $resultComponent = $component;
            $resultComponent['__source'] = $sourceMeta;

            if (isset($component['children']) && is_array($component['children'])) {
                $resultComponent['children'] = $this->applySourceMetaRecursively($component['children'], $sourceMeta);
            }

            $result[] = $resultComponent;
        }

        return $result;
    }

    /**
     * 확장의 현재 로케일 표시명을 해석합니다(편집기 오버레이 라벨용).
     *
     * 모듈/플러그인은 매니저의 다국어 표시명을, 템플릿 오버라이드는 오버라이드 대상
     * 모듈/플러그인의 표시명을 사용합니다. 해석 실패 시 null(식별자만 표시).
     * 동일 식별자 반복 조회를 피하기 위해 요청 단위로 캐시합니다.
     *
     * @param  LayoutExtension  $extension  확장 모델
     * @return string|null 로케일 표시명 (해석 불가 시 null)
     */
    private function resolveExtensionDisplayName(LayoutExtension $extension): ?string
    {
        // 템플릿 오버라이드는 오버라이드 대상(모듈/플러그인)이 실제 확장 출처.
        $sourceType = $extension->source_type;
        $identifier = $extension->override_target ?: $extension->source_identifier;

        if ($identifier === '' || $identifier === null) {
            return null;
        }

        $cacheKey = $sourceType->value.':'.$identifier;
        if (array_key_exists($cacheKey, $this->extensionNameCache)) {
            return $this->extensionNameCache[$cacheKey];
        }

        $name = $this->lookupExtensionDisplayName($sourceType, $identifier);
        $this->extensionNameCache[$cacheKey] = $name;

        return $name;
    }

    /**
     * 매니저에서 모듈/플러그인 표시명을 조회합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  확장 식별자
     * @return string|null 표시명 (없으면 null)
     */
    private function lookupExtensionDisplayName(LayoutSourceType $sourceType, string $identifier): ?string
    {
        // 템플릿 자체 확장(override_target 없음)은 모듈/플러그인 어느 쪽도 아니므로
        // 식별자만 노출(표시명 없음).
        $info = match ($sourceType) {
            LayoutSourceType::Module => app(ModuleManager::class)->getModuleInfo($identifier),
            LayoutSourceType::Plugin => app(PluginManager::class)->getPluginInfo($identifier),
            LayoutSourceType::Template => app(ModuleManager::class)->getModuleInfo($identifier)
                ?? app(PluginManager::class)->getPluginInfo($identifier),
        };

        $name = $info['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * 활성화된 모듈/플러그인 목록 초기화
     *
     * 순환 의존성 방지를 위해 app() 헬퍼로 매니저를 가져옵니다.
     */
    private function initializeActiveExtensions(): void
    {
        if ($this->activeModules === null) {
            $moduleManager = app(ModuleManager::class);
            $this->activeModules = array_keys($moduleManager->getActiveModules());
        }

        if ($this->activePlugins === null) {
            $pluginManager = app(PluginManager::class);
            $this->activePlugins = array_keys($pluginManager->getActivePlugins());
        }
    }

    /**
     * 확장의 출처가 활성화되어 있는지 확인
     *
     * 템플릿 오버라이드의 경우, 오버라이드 대상 모듈/플러그인이 활성화되어 있는지 확인합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $sourceIdentifier  출처 식별자
     * @param  string|null  $overrideTarget  오버라이드 대상 (템플릿 오버라이드인 경우)
     * @return bool 활성화 여부
     */
    private function isExtensionSourceActive(
        LayoutSourceType $sourceType,
        string $sourceIdentifier,
        ?string $overrideTarget = null
    ): bool {
        // 템플릿 오버라이드인 경우, 오버라이드 대상의 활성화 여부 확인
        if ($sourceType === LayoutSourceType::Template && $overrideTarget !== null) {
            return $this->isModuleOrPluginActive($overrideTarget);
        }

        // 모듈/플러그인 직접 확장인 경우
        return match ($sourceType) {
            LayoutSourceType::Module => in_array($sourceIdentifier, $this->activeModules, true),
            LayoutSourceType::Plugin => in_array($sourceIdentifier, $this->activePlugins, true),
            LayoutSourceType::Template => true, // 템플릿 자체 확장은 항상 활성화
        };
    }

    /**
     * 모듈 또는 플러그인이 활성화되어 있는지 확인
     *
     * 메모리 캐시에 없으면 DB에서 직접 조회합니다.
     * (파일 시스템에 없지만 DB에만 존재하는 경우 대응)
     *
     * @param  string  $identifier  모듈/플러그인 식별자
     * @return bool 활성화 여부
     */
    private function isModuleOrPluginActive(string $identifier): bool
    {
        // 먼저 메모리 캐시 확인 (빠른 경로)
        if (in_array($identifier, $this->activeModules, true)
            || in_array($identifier, $this->activePlugins, true)) {
            return true;
        }

        // 캐시에 없으면 DB에서 직접 확인
        $moduleManager = app(ModuleManager::class);
        if ($moduleManager->getModuleVersion($identifier) !== null) {
            $module = app(ModuleRepositoryInterface::class)
                ->findActiveByIdentifier($identifier);
            if ($module) {
                return true;
            }
        }

        $pluginManager = app(PluginManager::class);
        if ($pluginManager->getPluginVersion($identifier) !== null) {
            $plugin = app(PluginRepositoryInterface::class)
                ->findActiveByIdentifier($identifier);
            if ($plugin) {
                return true;
            }
        }

        return false;
    }

    private function applyExtensionPoints(array $layout, int $templateId, array &$scripts, bool $withSourceMeta = false): array
    {
        if (! isset($layout['components'])) {
            return $layout;
        }

        $dataSources = $layout['data_sources'] ?? [];
        $modals = $layout['modals'] ?? [];

        // components 트리를 순회하며 type: extension_point 찾기
        $layout['components'] = $this->processExtensionPointsRecursive(
            $layout['components'],
            $templateId,
            $dataSources,
            $scripts,
            $modals,
            $withSourceMeta
        );

        // modals 트리도 순회하며 extension_point 처리
        // 모달 내부에 정의된 extension_point에도 플러그인/모듈 컴포넌트가 주입되도록 함
        if (! empty($modals)) {
            $modals = $this->processExtensionPointsRecursive(
                $modals,
                $templateId,
                $dataSources,
                $scripts,
                $modals,
                $withSourceMeta
            );
        }

        $layout['data_sources'] = $dataSources;

        if (! empty($modals)) {
            $layout['modals'] = $modals;
        }

        return $layout;
    }

    /**
     * 재귀적으로 Extension Point 처리
     *
     * 오버라이드 해석 결과를 사용하여 템플릿 우선순위가 적용된 확장만 주입합니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  int  $templateId  템플릿 ID
     * @param  array  &$dataSources  데이터 소스 배열 (참조)
     * @param  array  &$scripts  스크립트 배열 (참조)
     * @param  array  &$modals  모달 배열 (참조)
     * @return array 처리된 컴포넌트 배열
     */
    private function processExtensionPointsRecursive(array $components, int $templateId, array &$dataSources, array &$scripts, array &$modals, bool $withSourceMeta = false): array
    {
        foreach ($components as $index => &$component) {
            // Extension Point 발견
            if (($component['type'] ?? '') === 'extension_point' && isset($component['name'])) {
                $extensionPointName = $component['name'];

                // 오버라이드를 고려한 확장 조회 (템플릿 > 플러그인 > 모듈)
                $extensions = $this->applyPreviewOverrides(
                    $this->repository->getResolvedExtensionPoints($templateId, $extensionPointName)
                );

                $injectedComponents = $component['default'] ?? [];

                foreach ($extensions as $extension) {
                    // 모듈/플러그인 활성화 상태 확인
                    if (! $this->isExtensionSourceActive(
                        $extension->source_type,
                        $extension->source_identifier,
                        $extension->override_target
                    )) {
                        Log::debug('Extension Point 확장 스킵 (비활성화된 출처)', [
                            'extension_point' => $extensionPointName,
                            'source_type' => $extension->source_type->value,
                            'source_identifier' => $extension->source_identifier,
                            'override_target' => $extension->override_target,
                        ]);

                        continue;
                    }

                    // 버전 호환성 검사 (템플릿 오버라이드인 경우)
                    if (! $this->checkVersionCompatibility($extension)) {
                        Log::info('버전 비호환으로 Extension Point 오버라이드 스킵', [
                            'extension_id' => $extension->id,
                            'source' => $extension->source_identifier,
                            'extension_point' => $extensionPointName,
                        ]);

                        continue;
                    }

                    $content = $extension->content;
                    $mode = $content['mode'] ?? 'append';

                    // 컴포넌트 추가 (extension_point props/callbacks를 주입 컴포넌트에 전달)
                    if (isset($content['components'])) {
                        $extensionPointProps = $component['props'] ?? [];
                        $extensionPointCallbacks = $component['callbacks'] ?? [];
                        $componentsWithProps = array_map(function ($injectedComponent) use ($extensionPointProps, $extensionPointCallbacks) {
                            if (! empty($extensionPointProps)) {
                                $injectedComponent['extensionPointProps'] = $extensionPointProps;
                            }
                            if (! empty($extensionPointCallbacks)) {
                                $injectedComponent['extensionPointCallbacks'] = $extensionPointCallbacks;
                            }

                            return $injectedComponent;
                        }, $content['components']);

                        // 편집 모드 출처 메타 부여 — 주입 노드와 그 자식 모두에 extension 메타
                        // @since engine-v1.50.0
                        if ($withSourceMeta) {
                            $componentsWithProps = $this->markExtensionSource($componentsWithProps, $extension->id, $extension);
                        }

                        $injectedComponents = match ($mode) {
                            'replace' => $componentsWithProps,
                            'prepend' => array_merge($componentsWithProps, $injectedComponents),
                            default => array_merge($injectedComponents, $componentsWithProps),
                        };
                    }

                    // 데이터 소스 병합 — 편집 모드에서는 주입한 data_source 에 확장 출처 메타
                    // (`__source`)를 부여해, 편집기 데이터 소스 모달이 "어느 확장이 주입했는지"
                    // (모듈/플러그인 + 식별자/표시명)를 표시할 수 있게 한다.
                    // 일반 렌더는 메타 미부여(운영 화면 영향 0). 컴포넌트 markExtensionSource 와 동형.
                    if (isset($content['data_sources']) && is_array($content['data_sources'])) {
                        $injectedDataSources = $content['data_sources'];
                        if ($withSourceMeta) {
                            $dsSourceMeta = $this->buildExtensionSourceMeta($extension);
                            $injectedDataSources = array_map(function ($ds) use ($dsSourceMeta) {
                                if (is_array($ds) && ! isset($ds['__source'])) {
                                    $ds['__source'] = $dsSourceMeta;
                                }

                                return $ds;
                            }, $injectedDataSources);
                        }
                        $dataSources = array_merge($dataSources, $injectedDataSources);
                    }

                    // 스크립트 병합 (중복 제거)
                    if (isset($content['scripts']) && is_array($content['scripts'])) {
                        $scripts = $this->mergeScripts($scripts, $content['scripts']);
                    }

                    // 모달 병합
                    if (isset($content['modals']) && is_array($content['modals'])) {
                        $modals = array_merge($modals, $content['modals']);
                    }

                    Log::debug('Extension Point 확장 적용', [
                        'extension_point' => $extensionPointName,
                        'source_type' => $extension->source_type->value,
                        'source_identifier' => $extension->source_identifier,
                        'is_override' => $extension->source_type === LayoutSourceType::Template,
                    ]);
                }

                // Extension Point를 컨테이너로 변환하고 주입된 컴포넌트를 children으로 설정
                $component['children'] = $injectedComponents;
            }

            // 자식 컴포넌트 재귀 처리
            if (isset($component['children']) && is_array($component['children'])) {
                $component['children'] = $this->processExtensionPointsRecursive(
                    $component['children'],
                    $templateId,
                    $dataSources,
                    $scripts,
                    $modals,
                    $withSourceMeta
                );
            }
        }

        return $components;
    }

    /**
     * Overlay 기반 컴포넌트 주입
     *
     * 템플릿 오버라이드를 우선 적용하고, 오버라이드되지 않은 모듈/플러그인 확장을 병합합니다.
     *
     * @param  array  $layout  레이아웃 배열
     * @param  int  $templateId  템플릿 ID
     * @param  array<int, string>  $targetNames  overlay 매칭 대상 레이아웃명 목록 (서빙 레이아웃 + 상속 체인 base)
     * @param  array  &$scripts  스크립트 배열 (참조)
     * @param  array  &$modals  모달 배열 (참조)
     * @return array 확장이 적용된 레이아웃
     */
    private function applyOverlays(array $layout, int $templateId, array $targetNames, array &$scripts, array &$modals, bool $withSourceMeta = false): array
    {
        if (empty($targetNames) || ! isset($layout['components'])) {
            return $layout;
        }

        // 로그용 대표 레이아웃명(서빙 레이아웃)
        $layoutName = $targetNames[0];

        // 버전 호환성을 고려한 오버레이 조회 — 서빙 레이아웃 + 상속 체인 base 전체에서 수집.
        // 각 target 별 getVersionAwareOverlays 결과(템플릿 오버라이드 해석 포함)를 id 기준으로
        // 합집합한다. 같은 overlay 가 두 target 에 중복 매칭되는 일은 없지만(각 행은 단일
        // target_name), priority 정렬을 위해 합친 뒤 재정렬한다.
        $overlays = collect($targetNames)
            ->flatMap(fn ($name) => $this->getVersionAwareOverlays($templateId, $name)->all())
            ->unique(fn ($overlay) => $overlay->id)
            ->sortBy('priority')
            ->values();

        if ($overlays->isEmpty()) {
            return $layout;
        }

        $dataSources = $layout['data_sources'] ?? [];

        foreach ($overlays as $overlay) {
            // 모듈/플러그인 활성화 상태 확인
            if (! $this->isExtensionSourceActive(
                $overlay->source_type,
                $overlay->source_identifier,
                $overlay->override_target
            )) {
                Log::debug('Overlay 확장 스킵 (비활성화된 출처)', [
                    'layout' => $layoutName,
                    'source_type' => $overlay->source_type->value,
                    'source_identifier' => $overlay->source_identifier,
                    'override_target' => $overlay->override_target,
                ]);

                continue;
            }

            $content = $overlay->content;
            $injections = $content['injections'] ?? [];

            Log::debug('Overlay 확장 적용', [
                'layout' => $layoutName,
                'source_type' => $overlay->source_type->value,
                'source_identifier' => $overlay->source_identifier,
                'is_override' => $overlay->source_type === LayoutSourceType::Template,
                'injection_count' => count($injections),
            ]);

            foreach ($injections as $injection) {
                $targetId = $injection['target_id'] ?? null;
                $position = $injection['position'] ?? 'append_child';

                if (! $targetId) {
                    continue;
                }

                if ($position === 'inject_props') {
                    // Props 주입: components 대신 props 필드 사용
                    $propsToInject = $injection['props'] ?? [];
                    if (empty($propsToInject)) {
                        continue;
                    }

                    // 편집 모드 출처 메타 — inject_props 대상 호스트 노드에
                    // `__injectedProps` 메타를 기록해 편집기 속성 모달이 "어느 확장이 이 props 를
                    // 주입했는지"를 출처 섹션으로 보여주고 교차 저장(확장 행 PUT)할 수 있게 한다.
                    $injectMeta = $withSourceMeta
                        ? $this->buildInjectedPropsMeta($overlay, $propsToInject)
                        : null;

                    $injected = $this->injectPropsAtTarget(
                        $layout['components'],
                        $targetId,
                        $propsToInject,
                        $injectMeta
                    );
                } else {
                    // 기존 컴포넌트 주입 로직
                    $components = $injection['components'] ?? [];
                    if (empty($components)) {
                        continue;
                    }

                    // 편집 모드 출처 메타 부여 — 주입 노드와 그 자식 모두에 extension 메타
                    // @since engine-v1.50.0
                    if ($withSourceMeta) {
                        $components = $this->markExtensionSource($components, $overlay->id, $overlay);
                    }

                    $injected = $this->injectAtTarget(
                        $layout['components'],
                        $targetId,
                        $position,
                        $components
                    );
                }

                if (! $injected) {
                    Log::warning('Layout extension target not found', [
                        'layout' => $layoutName,
                        'target_id' => $targetId,
                        'position' => $position,
                        'source' => $overlay->source_identifier,
                    ]);
                }
            }

            // 데이터 소스 병합 — 편집 모드에서는 확장 출처 메타(`__source`) 부여.
            if (isset($content['data_sources']) && is_array($content['data_sources'])) {
                $injectedDataSources = $content['data_sources'];
                if ($withSourceMeta) {
                    $dsSourceMeta = $this->buildExtensionSourceMeta($overlay);
                    $injectedDataSources = array_map(function ($ds) use ($dsSourceMeta) {
                        if (is_array($ds) && ! isset($ds['__source'])) {
                            $ds['__source'] = $dsSourceMeta;
                        }

                        return $ds;
                    }, $injectedDataSources);
                }
                $dataSources = array_merge($dataSources, $injectedDataSources);
            }

            // 스크립트 병합 (중복 제거)
            if (isset($content['scripts']) && is_array($content['scripts'])) {
                $scripts = $this->mergeScripts($scripts, $content['scripts']);
            }

            // computed 병합
            if (isset($content['computed']) && is_array($content['computed'])) {
                $layout['computed'] = array_merge($layout['computed'] ?? [], $content['computed']);
            }

            // state 병합
            if (isset($content['state']) && is_array($content['state'])) {
                $layout['state'] = array_merge($layout['state'] ?? [], $content['state']);
            }

            // initActions 병합 — 호스트 initActions 뒤에 확장 init 단계를 추가한다.
            // 확장이 _global 초기화(예: 모듈별 표시 통화 복원)를 기여할 수 있게 한다.
            // 호스트는 추가 단계의 존재/모듈을 모른다(확장 주입 인프라 일관).
            //
            // 표준 키는 `initActions`(camelCase). `init_actions`(snake)는 deprecated 별칭이다
            // (docs/frontend/layout-json.md). 호스트/확장 양쪽 모두 두 키를 입력으로 허용하되
            // 결과는 표준 `initActions` 단일 배열로 통합한다. 두 키를 따로 쌓으면 한 레이아웃에
            // 두 키가 공존하게 되고, 엔진(TemplateApp)의 `initActions || init_actions` OR 단락이
            // 한쪽(snake)을 통째로 무시 → 확장이 기여한 init_actions 가 실행되지 않는다.
            // (자체 initActions 를 가진 자식 레이아웃에서만 헤더 통화/배송국가 셀렉터가
            //  미표시되던 회귀의 근본 원인 — Chrome MCP 실측으로 확인)
            $extensionInitActions = $content['initActions'] ?? $content['init_actions'] ?? null;
            if (is_array($extensionInitActions) && $extensionInitActions !== []) {
                $hostInitActions = $layout['initActions'] ?? $layout['init_actions'] ?? [];
                $layout['initActions'] = array_merge($hostInitActions, $extensionInitActions);
                // deprecated 별칭 키가 잔존하면 OR 단락으로 통합 결과가 무시되므로 제거한다.
                unset($layout['init_actions']);
            }

            // modals 병합
            if (isset($content['modals']) && is_array($content['modals'])) {
                $modals = array_merge($modals, $content['modals']);
            }
        }

        $layout['data_sources'] = $dataSources;

        return $layout;
    }

    /**
     * 타겟 ID를 찾아 컴포넌트 주입
     *
     * @param  array  &$components  컴포넌트 배열 (참조)
     * @param  string  $targetId  타겟 컴포넌트 ID
     * @param  string  $position  주입 위치
     * @param  array  $newComponents  주입할 컴포넌트들
     * @return bool 주입 성공 여부
     */
    private function injectAtTarget(array &$components, string $targetId, string $position, array $newComponents): bool
    {
        foreach ($components as $index => &$component) {
            // 타겟 ID 발견
            if (($component['id'] ?? '') === $targetId) {
                $this->injectComponents($components, $component, $index, $position, $newComponents);

                return true;
            }

            // 자식에서 재귀 탐색
            if (isset($component['children']) && is_array($component['children'])) {
                if ($this->injectAtTarget($component['children'], $targetId, $position, $newComponents)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * position에 따라 컴포넌트 삽입
     *
     * @param  array  &$siblings  형제 컴포넌트 배열
     * @param  array  &$target  타겟 컴포넌트
     * @param  int  $targetIndex  타겟 인덱스
     * @param  string  $position  주입 위치
     * @param  array  $newComponents  주입할 컴포넌트들
     */
    private function injectComponents(
        array &$siblings,
        array &$target,
        int $targetIndex,
        string $position,
        array $newComponents
    ): void {
        switch ($position) {
            case 'prepend':
                // 타겟 앞에 형제로 삽입
                array_splice($siblings, $targetIndex, 0, $newComponents);
                break;

            case 'append':
                // 타겟 뒤에 형제로 삽입
                array_splice($siblings, $targetIndex + 1, 0, $newComponents);
                break;

            case 'prepend_child':
                // 타겟 children 맨 앞에 삽입
                if (! isset($target['children'])) {
                    $target['children'] = [];
                }
                $target['children'] = array_merge($newComponents, $target['children']);
                break;

            case 'append_child':
                // 타겟 children 맨 뒤에 삽입
                if (! isset($target['children'])) {
                    $target['children'] = [];
                }
                $target['children'] = array_merge($target['children'], $newComponents);
                break;

            case 'replace':
                // 타겟 완전 교체 (첫 번째 컴포넌트로)
                if (! empty($newComponents)) {
                    $siblings[$targetIndex] = $newComponents[0];
                    // 나머지는 뒤에 추가
                    if (count($newComponents) > 1) {
                        array_splice($siblings, $targetIndex + 1, 0, array_slice($newComponents, 1));
                    }
                }
                break;
        }
    }

    /**
     * 타겟 ID를 찾아 Props 주입
     *
     * @param  array  &$components  컴포넌트 배열 (참조)
     * @param  string  $targetId  타겟 컴포넌트 ID
     * @param  array  $propsToInject  주입할 Props 정의
     * @param  array|null  $injectMeta  편집 모드 출처 메타 — 전달 시 호스트 노드에
     *                                  `__injectedProps[]` 로 누적 기록(편집기 출처 섹션용)
     * @return bool 주입 성공 여부
     */
    private function injectPropsAtTarget(array &$components, string $targetId, array $propsToInject, ?array $injectMeta = null): bool
    {
        foreach ($components as &$component) {
            if (($component['id'] ?? '') === $targetId) {
                $this->injectProps($component, $propsToInject);

                // 편집 모드 — 이 호스트 노드에 주입된 확장 출처를 누적 기록(다중 확장 주입 대비).
                if ($injectMeta !== null) {
                    if (! isset($component['__injectedProps']) || ! is_array($component['__injectedProps'])) {
                        $component['__injectedProps'] = [];
                    }
                    $component['__injectedProps'][] = $injectMeta;
                }

                return true;
            }

            if (isset($component['children']) && is_array($component['children'])) {
                if ($this->injectPropsAtTarget($component['children'], $targetId, $propsToInject, $injectMeta)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * inject_props 호스트 노드 출처 메타를 구성합니다.
     *
     * 편집기 속성 모달이 "확장이 주입한 속성" 섹션을 출처 배지와 함께 렌더하고, 값 편집 시
     * 그 확장 행으로 교차 저장하는 데 쓴다. `props` 는 주입 정의 원본(편집 대상).
     *
     * @param  LayoutExtension  $extension  주입 확장
     * @param  array  $props  주입한 props 정의
     * @return array<string, mixed> `__injectedProps[]` 한 항목
     */
    private function buildInjectedPropsMeta(LayoutExtension $extension, array $props): array
    {
        $meta = [
            'extensionId' => $extension->id,
            'extensionSourceType' => $extension->source_type->value,
            'extensionIdentifier' => $extension->source_identifier,
            'props' => $props,
        ];

        $name = $this->resolveExtensionDisplayName($extension);
        if ($name !== null) {
            $meta['extensionName'] = $name;
        }

        return $meta;
    }

    /**
     * 컴포넌트의 props에 값을 주입
     *
     * 병합 전략:
     * - _append: 배열 끝에 추가
     * - _prepend: 배열 앞에 추가
     * - _merge: 객체 병합 (shallow)
     * - (직접 값): 스칼라 덮어쓰기 또는 객체 통째로 대체
     *
     * @param  array  &$component  대상 컴포넌트 (참조)
     * @param  array  $propsToInject  주입할 Props 정의
     */
    private function injectProps(array &$component, array $propsToInject): void
    {
        if (! isset($component['props'])) {
            $component['props'] = [];
        }

        foreach ($propsToInject as $propKey => $propValue) {
            if (! is_array($propValue)) {
                // 스칼라: 직접 대체
                $component['props'][$propKey] = $propValue;

                continue;
            }

            // 병합 전략 감지
            if (isset($propValue['_append'])) {
                $existing = $component['props'][$propKey] ?? [];
                if (is_string($existing)) {
                    Log::warning('inject_props _append 대상이 표현식 문자열', [
                        'component_id' => $component['id'] ?? 'unknown',
                        'prop_key' => $propKey,
                        'existing_value' => $existing,
                    ]);

                    continue;
                }
                $component['props'][$propKey] = array_merge(
                    is_array($existing) ? $existing : [],
                    $propValue['_append']
                );
            } elseif (isset($propValue['_prepend'])) {
                $existing = $component['props'][$propKey] ?? [];
                if (is_string($existing)) {
                    Log::warning('inject_props _prepend 대상이 표현식 문자열', [
                        'component_id' => $component['id'] ?? 'unknown',
                        'prop_key' => $propKey,
                        'existing_value' => $existing,
                    ]);

                    continue;
                }
                $component['props'][$propKey] = array_merge(
                    $propValue['_prepend'],
                    is_array($existing) ? $existing : []
                );
            } elseif (isset($propValue['_merge'])) {
                $existing = $component['props'][$propKey] ?? [];
                if (is_string($existing)) {
                    Log::warning('inject_props _merge 대상이 표현식 문자열', [
                        'component_id' => $component['id'] ?? 'unknown',
                        'prop_key' => $propKey,
                        'existing_value' => $existing,
                    ]);

                    continue;
                }
                $component['props'][$propKey] = array_merge(
                    is_array($existing) ? $existing : [],
                    $propValue['_merge']
                );
            } else {
                // 전략 키 없으면 객체 통째로 대체
                $component['props'][$propKey] = $propValue;
            }
        }
    }

    /**
     * 확장 등록
     *
     * updateOrCreate를 사용하여 중복 등록을 방지합니다.
     * 재활성화 시 기존 레코드가 있으면 복원 및 업데이트하고, 없으면 새로 생성합니다.
     *
     * $preserveModified 가 true 이면, 관리자가 편집한 확장(현재 content 해시가
     * original_content_hash 와 다른 경우)은 content/priority 갱신을 건너뛰고
     * is_active 만 갱신합니다 ('skipped' 반환).
     *
     * 대상 템플릿 적용 가능 여부(target_layout / extension_point 존재)는 호출 측
     * (RefreshesLayoutExtensions trait)이 사전 판별한다 — isApplicableToTemplate() 참고.
     *
     * @param  array  $content  확장 파일 내용
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $preserveModified  사용자 수정분 보존 여부
     * @return string|null 'created', 'updated', 'skipped', 또는 null (처리하지 않음)
     */
    public function registerExtension(
        array $content,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        bool $preserveModified = false
    ): ?string {
        $result = null;

        // Extension Point 타입
        if (isset($content['extension_point'])) {
            $result = $this->registerExtensionRecord(
                $content,
                LayoutExtensionType::ExtensionPoint,
                $content['extension_point'],
                $sourceType,
                $identifier,
                $templateId,
                $preserveModified
            );
        }

        // Overlay 타입
        if (isset($content['target_layout'])) {
            $result = $this->registerExtensionRecord(
                $content,
                LayoutExtensionType::Overlay,
                $content['target_layout'],
                $sourceType,
                $identifier,
                $templateId,
                $preserveModified
            );
        }

        return $result;
    }

    /**
     * 단일 확장 레코드를 등록/갱신합니다.
     *
     * preserveModified 전략 (TemplateManager::refreshTemplateLayouts 패턴과 동일):
     * - 신규 생성 → content + original_content_hash/size 함께 저장 → 'created'
     * - 기존 존재 + preserveModified + 사용자 수정 감지 → content/priority SKIP,
     *   is_active 만 갱신 → 'skipped'
     * - 그 외 → content 덮어쓰기 + original_content_hash/size 새 파일 기준 갱신 → 'updated'
     *
     * @param  array  $content  확장 파일 내용
     * @param  LayoutExtensionType  $extensionType  확장 타입
     * @param  string  $targetName  타겟 이름
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $preserveModified  사용자 수정분 보존 여부
     * @return string 'created', 'updated', 또는 'skipped'
     */
    private function registerExtensionRecord(
        array $content,
        LayoutExtensionType $extensionType,
        string $targetName,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        bool $preserveModified
    ): string {
        $attributes = [
            'template_id' => $templateId,
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
        ];

        $existing = LayoutExtension::withTrashed()->where($attributes)->first();

        // 기존 확장 존재 + 사용자 수정분 보존 전략
        if ($existing && $preserveModified) {
            $currentHash = $this->computeContentHash($existing->content);
            $originalHash = $existing->original_content_hash;

            // 사용자가 편집한 확장 → content/priority 덮어쓰기 SKIP, is_active 만 갱신
            if ($originalHash && $currentHash !== $originalHash) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $existing->update(['is_active' => true]);

                Log::info('레이아웃 확장 갱신 SKIP (사용자 수정 보존)', [
                    'extension_id' => $existing->id,
                    'target_name' => $targetName,
                    'source' => $identifier,
                ]);

                return 'skipped';
            }
        }

        $normalizedContent = $this->computeContentHash($content);

        $values = [
            'content' => $content,
            'priority' => $content['priority'] ?? 100,
            'is_active' => true,
            'original_content_hash' => $normalizedContent,
            'original_content_size' => $this->computeContentSize($content),
        ];

        $model = $this->repository->updateOrCreate($attributes, $values);

        return $model->wasRecentlyCreated ? 'created' : 'updated';
    }

    /**
     * 확장 정의가 해당 템플릿에 적용 가능한지(대상이 존재하는지) 판별합니다.
     *
     * - Overlay: content.target_layout 이 그 템플릿의 레이아웃명으로 존재해야 함
     * - Extension Point: content.extension_point(확장점명)이 그 템플릿의 어느 레이아웃에 정의되어 있어야 함
     *
     * 모듈/플러그인 확장이 admin/user 등 모든 활성 템플릿에 일괄 등록될 때,
     * 대상이 실제로 존재하지 않는 템플릿(예: admin 레이아웃 대상 확장 ↔ user 템플릿)에는
     * 등록하지 않기 위한 사전 판별이다. RefreshesLayoutExtensions trait 이 호출한다.
     *
     * @param  array  $content  확장 정의 (extension_point 또는 target_layout 키 보유)
     * @param  int  $templateId  템플릿 ID
     * @return bool 적용 가능 여부
     */
    public function isExtensionApplicableToTemplate(array $content, int $templateId): bool
    {
        if (isset($content['extension_point'])) {
            return $this->layoutRepository->hasExtensionPoint($templateId, $content['extension_point']);
        }

        if (isset($content['target_layout'])) {
            return $this->layoutRepository->exists($templateId, $content['target_layout']);
        }

        // extension_point/target_layout 둘 다 없는 정의는 처리 대상이 아님
        return false;
    }

    /**
     * 대상이 존재하지 않게 된 확장 행을 정리(soft delete)합니다.
     *
     * 과거 무차별 등록으로 잘못된 템플릿에 생성된 확장 행을 제거합니다.
     * RefreshesLayoutExtensions trait 이 적용 불가 판정 시 호출한다.
     *
     * @param  array  $content  확장 정의
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @return bool 정리된 행이 있으면 true
     */
    public function removeInapplicableExtension(
        array $content,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId
    ): bool {
        $extensionType = isset($content['extension_point'])
            ? LayoutExtensionType::ExtensionPoint
            : (isset($content['target_layout']) ? LayoutExtensionType::Overlay : null);

        if ($extensionType === null) {
            return false;
        }

        $targetName = $content['extension_point'] ?? $content['target_layout'];

        $existing = $this->repository->findByAttributes([
            'template_id' => $templateId,
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
        ]);

        if (! $existing) {
            return false;
        }

        // cross-template 가드 — "이 템플릿엔 부재" 만으로 삭제하지 않는다.
        //
        // 이 확장점/오버레이를 담는 호스트 레이아웃이 *다른 확장(템플릿)* 소유인 경우
        // (예: admin_dashboard_commerce 확장점은 ecommerce 모듈이 주입하지만 호스트인
        // admin_dashboard 레이아웃은 admin 템플릿 소유), 업데이트 순서에 따라 호스트
        // 레이아웃이 아직 구버전(슬롯 부재)일 수 있다. 그 순간 모든 등록 템플릿에서 동시에
        // "부재" 가 되는데, 이는 오등록이 아니라 단지 호스트 content 가 아직 stale 인 것이다.
        //
        // 따라서 동일 확장(같은 source/type/target)이 *다른* 템플릿에서 적용 가능할 때만
        // 삭제한다(그 다른 템플릿이 정답 타입 → 이 행은 타입 불일치 오등록). 어느 템플릿
        // 에서도 적용 불가(전부 stale)면 보존하여 정상 확장 전멸을 막는다.
        if (! $this->isApplicableInAnyOtherTemplate($content, $extensionType, $targetName, $sourceType, $identifier, $templateId)) {
            return false;
        }

        $existing->delete();

        Log::info('오등록 레이아웃 확장 제거 (다른 템플릿엔 적용 가능)', [
            'extension_id' => $existing->id,
            'template_id' => $templateId,
            'target_name' => $targetName,
            'source' => $identifier,
        ]);

        return true;
    }

    /**
     * 동일 확장(같은 source/type/target)이 후보 템플릿이 아닌 *다른* 템플릿에서
     * 적용 가능한지(호스트 레이아웃/확장점이 그 템플릿에 존재) 판별합니다.
     *
     * true → 그 다른 템플릿이 이 확장의 정답 타입 → 후보 행은 타입 불일치 오등록.
     * false → 어느 템플릿에서도 적용 불가(전부 stale 또는 단독 부재) → 삭제 보류(보존).
     *
     * @param  array  $content  확장 정의
     * @param  LayoutExtensionType  $extensionType  확장 타입
     * @param  string  $targetName  대상 이름
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $candidateTemplateId  부재로 판정된 후보 행의 템플릿 ID
     * @return bool 다른 템플릿에서 적용 가능한 동일 확장이 존재하는지
     */
    private function isApplicableInAnyOtherTemplate(
        array $content,
        LayoutExtensionType $extensionType,
        string $targetName,
        LayoutSourceType $sourceType,
        string $identifier,
        int $candidateTemplateId
    ): bool {
        $siblings = $this->repository->getAllByAttributesAcrossTemplates([
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
        ]);

        foreach ($siblings as $sibling) {
            if ($sibling->template_id === $candidateTemplateId) {
                continue;
            }

            if ($this->isExtensionApplicableToTemplate($content, $sibling->template_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 특정 템플릿의 모든 레이아웃 확장을 출처별로 그룹핑하여 조회합니다.
     *
     * 관리자 레이아웃 편집 화면의 좌측 트리에서 사용합니다.
     *
     * 템플릿 오버라이드 행(source_type=template)은 자기 자신(템플릿)이 아니라
     * 오버라이드 대상 모듈/플러그인(override_target) 기준으로 그룹핑한다 —
     * 트리에서 일반 확장과 동일하게 "어느 플러그인/모듈의 확장인지" 로 묶이고,
     * 오버라이드 여부는 각 항목의 is_override 플래그로 별도 표시한다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return array<array{source_identifier: string, source_type: string, source_label: string, extensions: array<LayoutExtension>}>
     */
    public function getExtensionsByTemplateId(int $templateId): array
    {
        HookManager::doAction('core.layout_extension.before_index', $templateId);

        // 템플릿 오버라이드에 가려진 확장은 화면에 적용되지 않으므로 트리에서 제외 —
        // 렌더링 경로(getResolvedExtensionPoints/getResolvedOverlays)와 동일한 가시성 기준.
        $extensions = $this->repository->getResolvedByTemplateId($templateId);

        // 확장별 현재(최신) 저장 버전 부착 — 라우트 트리 확장 노드
        // 버전 배지 데이터. 이력이 없는(원본) 확장은 맵에 없어 null → 배지 미표시.
        $currentVersions = $this->versionRepository->getCurrentVersionsByExtensionIds(
            $extensions->pluck('id')->all()
        );
        foreach ($extensions as $extension) {
            $extension->setAttribute('current_version', $currentVersions[$extension->id] ?? null);
        }

        // 출처별 그룹핑 — 오버라이드 행은 override_target(대상 확장) 기준으로 묶는다.
        $groups = [];
        foreach ($extensions as $extension) {
            $isOverride = $extension->source_type === LayoutSourceType::Template;

            // 그룹 출처: 오버라이드는 대상 모듈/플러그인, 그 외는 자기 source.
            [$groupType, $groupIdentifier] = $isOverride
                ? $this->resolveOverrideGroupSource($extension)
                : [$extension->source_type, $extension->source_identifier];

            $key = $groupType->value.':'.$groupIdentifier;

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'source_identifier' => $groupIdentifier,
                    'source_type' => $groupType->value,
                    'source_label' => $this->resolveSourceLabel($groupType, $groupIdentifier),
                    'extensions' => [],
                ];
            }

            // 리소스 직렬화 시 읽을 수 있도록 모델에 부착
            $extension->source_label = $groups[$key]['source_label'];
            $extension->is_override = $isOverride;

            $groups[$key]['extensions'][] = $extension;
        }

        $result = array_values($groups);

        return HookManager::applyFilters('core.layout_extension.after_index', $result, $templateId);
    }

    /**
     * 템플릿 오버라이드 행이 묶일 그룹 출처(타입, 식별자)를 해석합니다.
     *
     * override_target 이 가리키는 모듈/플러그인을 그룹 출처로 사용한다.
     * 매니저로 모듈/플러그인 여부를 판정하며, 어느 쪽에도 없으면
     * (대상 확장이 제거된 경우 등) 오버라이드 행 자신의 출처로 fallback 한다.
     *
     * @param  LayoutExtension  $extension  템플릿 오버라이드 확장
     * @return array{0: LayoutSourceType, 1: string} [그룹 타입, 그룹 식별자]
     */
    private function resolveOverrideGroupSource(LayoutExtension $extension): array
    {
        $target = $extension->override_target;

        if (is_string($target) && $target !== '') {
            try {
                if (app(ModuleManager::class)->getModule($target)) {
                    return [LayoutSourceType::Module, $target];
                }
                if (app(PluginManager::class)->getPlugin($target)) {
                    return [LayoutSourceType::Plugin, $target];
                }
            } catch (\Throwable $e) {
                Log::debug('오버라이드 대상 출처 해석 실패', [
                    'extension_id' => $extension->id,
                    'override_target' => $target,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 대상을 식별할 수 없으면 오버라이드 행 자신의 출처로 fallback
        return [$extension->source_type, $extension->source_identifier];
    }

    /**
     * 출처(모듈/플러그인/템플릿)의 표시명을 해석합니다.
     *
     * 매니저에서 표시명을 가져오고, 없으면 식별자를 그대로 반환합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return string 표시명
     */
    private function resolveSourceLabel(LayoutSourceType $sourceType, string $identifier): string
    {
        try {
            if ($sourceType === LayoutSourceType::Module) {
                // DB 레코드의 name 을 우선 사용 — 설치/동기화 시 manifest.translations 필터가
                // 활성 언어팩(ja 등)을 병합해 저장하므로 매니페스트 객체(ko/en 만)보다 로케일 커버리지가 넓다.
                $dbName = $this->moduleRepository->findByIdentifier($identifier)?->getRawOriginal('name');
                $name = $this->decodeMultilingualName($dbName)
                    ?? app(ModuleManager::class)->getModule($identifier)?->getName();

                return $this->normalizeSourceLabel($name, $identifier);
            }

            if ($sourceType === LayoutSourceType::Plugin) {
                $dbName = $this->pluginRepository->findByIdentifier($identifier)?->getRawOriginal('name');
                $name = $this->decodeMultilingualName($dbName)
                    ?? app(PluginManager::class)->getPlugin($identifier)?->getName();

                return $this->normalizeSourceLabel($name, $identifier);
            }

            if ($sourceType === LayoutSourceType::Template) {
                // 템플릿 오버라이드 출처 — getTemplate() 은 메타데이터 배열을 반환
                $template = app(TemplateManager::class)->getTemplate($identifier);

                return $this->normalizeSourceLabel($template['name'] ?? null, $identifier);
            }
        } catch (\Throwable $e) {
            Log::debug('확장 출처 표시명 해석 실패', [
                'source_type' => $sourceType->value,
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);
        }

        return $identifier;
    }

    /**
     * 확장 표시명(string|array)을 현재 로케일 문자열로 정규화합니다.
     *
     * getName() 은 다국어 배열을 반환할 수 있으므로, 현재 로케일 → 첫 항목 →
     * 식별자 순으로 fallback 합니다.
     *
     * @param  string|array|null  $name  표시명 (다국어 배열 가능)
     * @param  string  $identifier  fallback 식별자
     * @return string 정규화된 표시명
     */
    private function normalizeSourceLabel(string|array|null $name, string $identifier): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if (is_array($name) && ! empty($name)) {
            $locale = app()->getLocale();

            return (string) ($name[$locale] ?? reset($name) ?: $identifier);
        }

        return $identifier;
    }

    /**
     * DB 의 다국어 name(`getRawOriginal('name')`) 을 배열로 디코딩합니다.
     *
     * 컬럼은 JSON 문자열(`{"ko":..,"ja":..}`)로 저장되므로 디코딩해 로케일 배열을 얻습니다.
     * 이미 배열이면 그대로, 비었거나 디코딩 실패(평문 등)면 null 을 반환해 호출부가
     * 매니페스트 객체 폴백을 타도록 합니다.
     *
     * @param  string|array|null  $raw  DB 원본 name 값
     * @return array<string, string>|null 로케일 배열 또는 null
     */
    private function decodeMultilingualName(string|array|null $raw): ?array
    {
        if (is_array($raw)) {
            return ! empty($raw) ? $raw : null;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) && ! empty($decoded) ? $decoded : null;
        }

        return null;
    }

    /**
     * ID로 단일 레이아웃 확장을 조회합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @return LayoutExtension 확장 모델
     *
     * @throws ModelNotFoundException 확장을 찾을 수 없는 경우
     */
    public function getExtensionById(int $extensionId): LayoutExtension
    {
        $extension = $this->repository->findById($extensionId);

        if (! $extension) {
            throw new ModelNotFoundException("Layout extension not found: id={$extensionId}");
        }

        return $extension;
    }

    /**
     * 레이아웃 확장의 content/priority 를 업데이트합니다.
     *
     * 관리자가 확장 레이아웃 JSON 을 편집할 때 사용합니다.
     * original_content_hash 는 변경하지 않습니다 — 모듈/플러그인 업데이트 시
     * 사용자 수정 감지(preserveModified)가 동작하려면 원본 해시가 유지되어야 합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  array  $data  업데이트 데이터 (content, priority)
     * @return LayoutExtension 업데이트된 확장 모델
     *
     * @throws ModelNotFoundException 확장을 찾을 수 없는 경우
     */
    public function updateExtension(int $extensionId, array $data): LayoutExtension
    {
        HookManager::doAction('core.layout_extension.before_update', $extensionId, $data);

        $data = HookManager::applyFilters('core.layout_extension.filter_update_data', $data, $extensionId);

        $extension = $this->getExtensionById($extensionId);

        // 낙관적 잠금 — expected_lock_version 검증
        $expectedVersion = isset($data['expected_lock_version'])
            ? (int) $data['expected_lock_version']
            : null;
        $currentVersion = (int) ($extension->lock_version ?? 0);

        if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
            throw new ConcurrentModificationException(
                currentVersion: $currentVersion,
                expectedVersion: $expectedVersion,
                resource: "template_layout_extensions:{$extension->id}",
            );
        }

        // content 키가 있으면 추출 (UpdateLayoutExtensionContentRequest 사용 시)
        $newContent = $data['content'] ?? null;
        $oldContent = $extension->content;

        $updateData = [];
        if ($newContent !== null) {
            $updateData['content'] = $newContent;
        }
        if (isset($data['priority'])) {
            $updateData['priority'] = $data['priority'];
        }

        $extension = $this->repository->updateWithLock($extensionId, $updateData, $currentVersion + 1);

        // 버전 히스토리 저장 — 저장 시점 content 스냅샷. changes_summary 는 직전(oldContent)
        // 대비 이번 저장본(newContent)의 변경을 기록한다(레이아웃 본체와 동일 정책 — 종전 2건
        // 저장 + 자기 비교로 최신 버전 변경 요약이 0 이던 결함 수정).
        if ($newContent !== null) {
            // 첫 수정 시 수정 전 원본을 baseline 버전으로 먼저 백업한다(이력이 하나도 없을 때만).
            // 이게 없으면 첫 수정본만 남아 "수정 전 상태"로 복원할 수 없다(레이아웃 본체와 동일).
            $hasHistory = $this->versionRepository->getNextVersion($extensionId) > 1;
            if (! $hasHistory) {
                $this->versionRepository->saveVersion($extensionId, $oldContent, null);
            }

            $savedVersion = $this->versionRepository->saveVersion($extensionId, $newContent, $oldContent);

            // 저장 응답에 현재(최신) 버전 번호 동봉 — 편집기 라우트
            // 트리 확장 노드 버전 배지가 저장 직후 재fetch 없이 동기화되도록 transient 부착
            // (LayoutExtensionResource 가 current_version 으로 직렬화 — DB 컬럼 아님).
            $extension->setAttribute('current_version', $savedVersion->version);
        }

        // 캐시 무효화
        $this->invalidateExtensionCache(
            $extension->template_id,
            $extension->target_name,
            $extension->extension_type
        );

        HookManager::doAction('core.layout_extension.after_update', $extension, $extensionId, $data);

        return $extension;
    }

    /**
     * 특정 확장의 모든 버전을 조회합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @return EloquentCollection<int, TemplateLayoutExtensionVersion>
     *
     * @throws ModelNotFoundException 확장을 찾을 수 없는 경우
     */
    public function getExtensionVersions(int $extensionId): EloquentCollection
    {
        HookManager::doAction('core.layout_extension.before_versions_index', $extensionId);

        // 확장 존재 검증
        $this->getExtensionById($extensionId);

        $versions = $this->versionRepository->getVersions($extensionId);

        return HookManager::applyFilters('core.layout_extension.after_versions_index', $versions, $extensionId);
    }

    /**
     * 특정 확장의 특정 버전을 조회합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  int  $version  버전 번호
     * @return TemplateLayoutExtensionVersion 버전 모델
     *
     * @throws ModelNotFoundException 확장 또는 버전을 찾을 수 없는 경우
     */
    public function getExtensionVersion(int $extensionId, int $version): TemplateLayoutExtensionVersion
    {
        HookManager::doAction('core.layout_extension.before_version_show', $extensionId, $version);

        // 확장 존재 검증
        $this->getExtensionById($extensionId);

        $extensionVersion = $this->versionRepository->getVersions($extensionId)
            ->firstWhere('version', $version);

        if (! $extensionVersion) {
            throw new ModelNotFoundException(
                "Layout extension version not found: extension_id={$extensionId}, version={$version}"
            );
        }

        return HookManager::applyFilters('core.layout_extension.after_version_show', $extensionVersion, $extensionId, $version);
    }

    /**
     * 확장을 특정 버전으로 복원합니다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  int  $versionId  복원할 버전 ID
     * @return TemplateLayoutExtensionVersion 복원 후 생성된 새 버전
     *
     * @throws ModelNotFoundException 확장 또는 버전을 찾을 수 없는 경우
     */
    public function restoreExtensionVersion(int $extensionId, int $versionId): TemplateLayoutExtensionVersion
    {
        HookManager::doAction('core.layout_extension.before_version_restore', $extensionId, $versionId);

        $extension = $this->getExtensionById($extensionId);

        // 버전 복원 (트랜잭션으로 처리됨)
        $newVersion = $this->versionRepository->restoreVersion($extensionId, $versionId);

        // 캐시 무효화
        $this->invalidateExtensionCache(
            $extension->template_id,
            $extension->target_name,
            $extension->extension_type
        );

        HookManager::doAction('core.layout_extension.after_version_restore', $newVersion, $extensionId, $versionId);

        return $newVersion;
    }

    /**
     * 특정 템플릿에서 관리자가 수정한 확장 목록을 반환합니다.
     *
     * 현재 content 해시가 original_content_hash 와 다른 확장을 수정된 것으로 판단합니다.
     * hasModifiedLayouts 와 대칭 메서드입니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return array<array{id: int, target_name: string, source_identifier: string}> 수정된 확장 목록
     */
    public function hasModifiedExtensions(int $templateId): array
    {
        $modified = [];

        foreach ($this->repository->getByTemplateId($templateId) as $extension) {
            $originalHash = $extension->original_content_hash;

            if (! $originalHash) {
                continue;
            }

            $currentHash = $this->computeContentHash($extension->content);

            if ($currentHash !== $originalHash) {
                $modified[] = [
                    'id' => $extension->id,
                    'target_name' => $extension->target_name,
                    'source_identifier' => $extension->source_identifier,
                ];
            }
        }

        return $modified;
    }

    /**
     * 특정 출처(모듈/플러그인)의 수정된 확장 목록을 반환합니다.
     *
     * 모듈/플러그인 업데이트 커맨드의 overwrite 전략 경고에 사용합니다.
     * 모든 활성 템플릿에 걸쳐 해당 출처의 확장을 스캔합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return array<array{id: int, target_name: string, source_identifier: string}> 수정된 확장 목록
     */
    public function getModifiedExtensionsBySource(LayoutSourceType $sourceType, string $identifier): array
    {
        $modified = [];

        $extensions = $this->repository->getBySource($sourceType, $identifier);

        foreach ($extensions as $extension) {
            $originalHash = $extension->original_content_hash;

            if (! $originalHash) {
                continue;
            }

            if ($this->computeContentHash($extension->content) !== $originalHash) {
                $modified[] = [
                    'id' => $extension->id,
                    'target_name' => $extension->target_name,
                    'source_identifier' => $extension->source_identifier,
                ];
            }
        }

        return $modified;
    }

    /**
     * 레이아웃 확장 캐시를 무효화합니다.
     *
     * - overlay → target_name 이 레이아웃명 → 의존 레이아웃 캐시 무효화
     * - extension_point → target_name 은 확장점명 → ext.cache_version 전역 갱신
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $targetName  타겟 이름
     * @param  LayoutExtensionType  $type  확장 타입
     */
    public function invalidateExtensionCache(int $templateId, string $targetName, LayoutExtensionType $type): void
    {
        if ($type === LayoutExtensionType::Overlay) {
            // overlay 의 target_name 은 레이아웃명 → 의존 레이아웃 캐시 무효화
            app(LayoutService::class)->clearDependentLayoutsCache($templateId, $targetName);
        }

        // extension_point 는 여러 레이아웃에서 사용될 수 있으므로 전역 캐시 버전 갱신
        // overlay 도 프론트엔드 브라우저 캐시 무효화를 위해 동일하게 갱신
        $this->cache->put('ext.cache_version', time());
    }

    /**
     * 출처별 확장 제거
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function unregisterBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return $this->repository->softDeleteBySource($sourceType, $identifier);
    }

    /**
     * 출처별 확장 복원
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 복원된 레코드 수
     */
    public function restoreBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return $this->repository->restoreBySource($sourceType, $identifier);
    }

    /**
     * 출처별 확장 영구 삭제
     *
     * 모듈/플러그인 삭제 시 사용합니다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return int 삭제된 레코드 수
     */
    public function forceDeleteBySource(LayoutSourceType $sourceType, string $identifier): int
    {
        return $this->repository->forceDeleteBySource($sourceType, $identifier);
    }

    /**
     * 활성화된 모든 모듈/플러그인의 레이아웃 확장을 특정 템플릿에 등록합니다.
     *
     * 템플릿 활성화 시 호출하여, 이미 활성화된 모듈/플러그인의 확장이
     * 새 템플릿에도 적용되도록 합니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return array{modules: int, plugins: int} 소스 타입별 등록된 확장 수
     */
    public function registerAllActiveExtensionsToTemplate(int $templateId): array
    {
        $stats = ['modules' => 0, 'plugins' => 0];

        // 활성 모듈의 레이아웃 확장 등록
        $moduleManager = app(ModuleManager::class);
        foreach ($moduleManager->getActiveModules() as $module) {
            $stats['modules'] += $this->registerExtensionFilesToTemplate(
                $module->getLayoutExtensions(),
                LayoutSourceType::Module,
                $module->getIdentifier(),
                $templateId
            );
        }

        // 활성 플러그인의 레이아웃 확장 등록
        $pluginManager = app(PluginManager::class);
        foreach ($pluginManager->getActivePlugins() as $plugin) {
            $stats['plugins'] += $this->registerExtensionFilesToTemplate(
                $plugin->getLayoutExtensions(),
                LayoutSourceType::Plugin,
                $plugin->getIdentifier(),
                $templateId
            );
        }

        $total = $stats['modules'] + $stats['plugins'];
        if ($total > 0) {
            Log::info("템플릿에 활성 확장 등록 완료: 모듈 {$stats['modules']}건, 플러그인 {$stats['plugins']}건", [
                'template_id' => $templateId,
            ]);
        }

        return $stats;
    }

    /**
     * 확장 JSON 파일 목록을 특정 템플릿에 등록합니다.
     *
     * @param  array<string>  $extensionFiles  JSON 파일 경로 목록
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @return int 등록된 확장 수
     */
    private function registerExtensionFilesToTemplate(
        array $extensionFiles,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId
    ): int {
        if (empty($extensionFiles)) {
            return 0;
        }

        $registered = 0;

        foreach ($extensionFiles as $extensionFile) {
            try {
                $content = File::get($extensionFile);
                $extensionData = json_decode($content, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    Log::error("레이아웃 확장 JSON 파싱 실패: {$extensionFile}", [
                        'source' => $identifier,
                        'error' => json_last_error_msg(),
                    ]);

                    continue;
                }

                $this->registerExtension($extensionData, $sourceType, $identifier, $templateId);
                $registered++;
            } catch (\Exception $e) {
                Log::error("레이아웃 확장 등록 실패: {$extensionFile}", [
                    'source' => $identifier,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $registered;
    }

    /**
     * 템플릿 오버라이드 Extension 등록
     *
     * 템플릿이 모듈/플러그인의 Extension을 오버라이드하는 경우 사용합니다.
     * updateOrCreate를 사용하여 중복 등록을 방지합니다.
     *
     * @param  array  $content  확장 파일 내용
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string  $overrideTarget  오버라이드 대상 모듈/플러그인 식별자
     * @param  int  $templateId  템플릿 ID
     * @return string|null 'created', 'updated', 또는 null (처리하지 않음)
     */
    public function registerTemplateOverride(
        array $content,
        string $templateIdentifier,
        string $overrideTarget,
        int $templateId
    ): ?string {
        $result = null;

        // Extension Point 타입 오버라이드
        if (isset($content['extension_point'])) {
            $model = $this->repository->updateOrCreate(
                [
                    'template_id' => $templateId,
                    'extension_type' => LayoutExtensionType::ExtensionPoint,
                    'target_name' => $content['extension_point'],
                    'source_type' => LayoutSourceType::Template,
                    'source_identifier' => $templateIdentifier,
                    'override_target' => $overrideTarget,
                ],
                [
                    'content' => $content,
                    'priority' => $content['priority'] ?? 100,
                    'is_active' => true,
                ]
            );
            $result = $model->wasRecentlyCreated ? 'created' : 'updated';

            Log::info('템플릿 Extension Point 오버라이드 등록', [
                'template' => $templateIdentifier,
                'extension_point' => $content['extension_point'],
                'override_target' => $overrideTarget,
            ]);
        }

        // Overlay 타입 오버라이드
        if (isset($content['target_layout'])) {
            $model = $this->repository->updateOrCreate(
                [
                    'template_id' => $templateId,
                    'extension_type' => LayoutExtensionType::Overlay,
                    'target_name' => $content['target_layout'],
                    'source_type' => LayoutSourceType::Template,
                    'source_identifier' => $templateIdentifier,
                    'override_target' => $overrideTarget,
                ],
                [
                    'content' => $content,
                    'priority' => $content['priority'] ?? 100,
                    'is_active' => true,
                ]
            );
            $result = $model->wasRecentlyCreated ? 'created' : 'updated';

            Log::info('템플릿 Overlay 오버라이드 등록', [
                'template' => $templateIdentifier,
                'target_layout' => $content['target_layout'],
                'override_target' => $overrideTarget,
            ]);
        }

        return $result;
    }

    /**
     * 버전 호환성을 고려한 오버레이 목록을 반환합니다.
     *
     * 1. 모든 오버레이 조회 (모듈/플러그인 + 템플릿 오버라이드)
     * 2. 템플릿 오버라이드가 있고 버전이 호환되면 → 원본 제외, 템플릿 오버라이드 사용
     * 3. 템플릿 오버라이드가 있지만 버전이 비호환이면 → 템플릿 오버라이드 제외, 원본 사용
     * 4. 템플릿 오버라이드가 없으면 → 원본 사용
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     * @return Collection<int, LayoutExtension>
     */
    private function getVersionAwareOverlays(int $templateId, string $layoutName): Collection
    {
        // 모든 오버레이 조회 (모듈 + 플러그인 + 템플릿)
        $allOverlays = $this->applyPreviewOverrides(
            $this->repository->getOverlaysByLayout($templateId, $layoutName)
        );

        if ($allOverlays->isEmpty()) {
            return $allOverlays;
        }

        // 템플릿 오버라이드 분리 (override_target이 있는 Template 소스)
        $templateOverrides = $allOverlays->filter(
            fn ($overlay) => $overlay->source_type === LayoutSourceType::Template
                && ! empty($overlay->override_target)
        );

        // 템플릿 오버라이드가 없으면 모든 오버레이 반환
        if ($templateOverrides->isEmpty()) {
            return $allOverlays;
        }

        // 타겟별 상태 추적: 'compatible' | 'incompatible'
        // 동일 타겟에 대해 여러 오버라이드가 있을 경우, 첫 번째 호환되는 것만 사용
        $targetStatus = [];
        $compatibleOverrideIds = [];

        foreach ($templateOverrides as $override) {
            $targetIdentifier = $override->override_target;

            // 모든 오버라이드에 대해 버전 호환성 검사 수행 (경고 수집을 위해)
            $isCompatible = $this->checkVersionCompatibility($override);

            if ($isCompatible) {
                // 이미 이 타겟에 대해 호환되는 오버라이드를 찾았으면 ID 추가 스킵
                if (! isset($targetStatus[$targetIdentifier]) || $targetStatus[$targetIdentifier] !== 'compatible') {
                    $targetStatus[$targetIdentifier] = 'compatible';
                    $compatibleOverrideIds[] = $override->id;
                }
            } else {
                // 아직 호환되는 오버라이드가 없는 경우에만 비호환 상태 설정
                if (! isset($targetStatus[$targetIdentifier])) {
                    $targetStatus[$targetIdentifier] = 'incompatible';
                }

                Log::info('버전 비호환으로 원본 모듈 UI 사용', [
                    'template_override_id' => $override->id,
                    'source' => $override->source_identifier,
                    'target' => $targetIdentifier,
                    'layout' => $layoutName,
                ]);
            }
        }

        // 호환되는 오버라이드의 타겟 목록 생성
        $compatibleOverrideTargets = array_keys(array_filter(
            $targetStatus,
            fn ($status) => $status === 'compatible'
        ));

        // 최종 오버레이 목록 필터링
        return $allOverlays->filter(function ($overlay) use ($compatibleOverrideTargets, $compatibleOverrideIds) {
            // 템플릿 오버라이드인 경우
            if ($overlay->source_type === LayoutSourceType::Template && ! empty($overlay->override_target)) {
                // 호환되는 오버라이드 ID 목록에 있는 것만 포함
                // (동일 타겟에 여러 오버라이드가 있어도 첫 번째 호환 것만 사용)
                return in_array($overlay->id, $compatibleOverrideIds, true);
            }

            // 모듈/플러그인 원본인 경우
            // 호환되는 템플릿 오버라이드가 있으면 원본 제외
            // 비호환 오버라이드만 있으면 원본 사용
            return ! in_array($overlay->source_identifier, $compatibleOverrideTargets, true);
        })->values();
    }

    /**
     * 오버라이드의 버전 호환성을 검사합니다.
     *
     * version_constraint가 없으면 항상 호환으로 간주합니다.
     * 템플릿 오버라이드인 경우에만 버전 검사를 수행합니다.
     *
     * @param  LayoutExtension  $extension  레이아웃 확장 모델
     * @return bool 호환 여부 (true: 호환, false: 비호환)
     */
    private function checkVersionCompatibility(LayoutExtension $extension): bool
    {
        $content = $extension->content;

        // version_constraint가 없으면 항상 호환
        if (! isset($content['version_constraint'])) {
            return true;
        }

        // 템플릿 오버라이드가 아니면 버전 검사 스킵
        if ($extension->source_type !== LayoutSourceType::Template) {
            return true;
        }

        // 오버라이드 대상이 없으면 스킵
        if (empty($extension->override_target)) {
            return true;
        }

        $constraint = $content['version_constraint'];
        $targetIdentifier = $extension->override_target;

        // 모듈/플러그인 버전 조회
        $version = $this->getExtensionSourceVersion($targetIdentifier);

        if (! $version) {
            Log::warning('버전 정보 없음', [
                'extension' => $extension->id,
                'target' => $targetIdentifier,
            ]);

            // 버전 정보 없으면 적용 (하위 호환성)
            return true;
        }

        // Composer Semver로 검증
        try {
            $compatible = Semver::satisfies($version, $constraint);
        } catch (\Exception $e) {
            Log::error('버전 제약 조건 파싱 실패', [
                'extension' => $extension->id,
                'constraint' => $constraint,
                'error' => $e->getMessage(),
            ]);

            // 파싱 실패 시 적용
            return true;
        }

        if (! $compatible) {
            $this->incompatibleOverrides[] = [
                'extension_id' => $extension->id,
                'source' => $extension->source_identifier,
                'target' => $targetIdentifier,
                'constraint' => $constraint,
                'current_version' => $version,
            ];
        }

        return $compatible;
    }

    /**
     * 모듈 또는 플러그인의 버전을 조회합니다.
     *
     * @param  string  $identifier  모듈/플러그인 식별자
     * @return string|null 버전 문자열 또는 null (빈 문자열도 null 반환)
     */
    private function getExtensionSourceVersion(string $identifier): ?string
    {
        // 모듈에서 먼저 조회
        $moduleManager = app(ModuleManager::class);
        $version = $moduleManager->getModuleVersion($identifier);

        // 빈 문자열도 null로 처리
        if ($version !== null && $version !== '') {
            return $version;
        }

        // 플러그인에서 조회
        $pluginManager = app(PluginManager::class);
        $version = $pluginManager->getPluginVersion($identifier);

        // 빈 문자열도 null로 처리
        return ($version !== null && $version !== '') ? $version : null;
    }

    /**
     * 비호환 오버라이드 목록을 프론트엔드용 warnings 형식으로 변환합니다.
     *
     * 프론트엔드에서 렌더링할 수 있도록 표준화된 warning 객체 배열을 반환합니다.
     * 각 warning은 고유 ID를 가지며, 프론트엔드에서 세션 기반 dismiss 처리에 사용됩니다.
     *
     * @return array<array{id: string, type: string, level: string, message: string, source: string, target: string, constraint: string, current_version: string}>
     */
    private function formatWarningsForFrontend(): array
    {
        return array_map(fn ($override) => [
            'id' => 'compatibility_'.$override['extension_id'],
            'type' => 'compatibility',
            'level' => 'warning',
            'message' => __('layout_extension.version_incompatible', [
                'source' => $override['source'],
                'constraint' => $override['constraint'],
                'current_version' => $override['current_version'],
            ]),
            'source' => $override['source'],
            'target' => $override['target'],
            'constraint' => $override['constraint'],
            'current_version' => $override['current_version'],
        ], $this->incompatibleOverrides);
    }

    /**
     * 비호환 오버라이드 목록을 반환합니다.
     *
     * @return array<array{extension_id: int, source: string, target: string, constraint: string, current_version: string}>
     */
    public function getIncompatibleOverrides(): array
    {
        return $this->incompatibleOverrides;
    }

    /**
     * 스크립트 배열 병합 (중복 제거)
     *
     * 동일한 id를 가진 스크립트는 중복 추가하지 않습니다.
     * 프론트엔드에서 스크립트를 로드할 때 id를 기준으로 중복 체크합니다.
     *
     * @param  array  $existingScripts  기존 스크립트 배열
     * @param  array  $newScripts  추가할 스크립트 배열
     * @return array 병합된 스크립트 배열
     */
    private function mergeScripts(array $existingScripts, array $newScripts): array
    {
        // 기존 스크립트 ID 목록 추출
        $existingIds = array_column($existingScripts, 'id');

        foreach ($newScripts as $script) {
            $scriptId = $script['id'] ?? null;

            // id가 없거나 이미 존재하면 스킵
            if ($scriptId === null || in_array($scriptId, $existingIds, true)) {
                continue;
            }

            $existingScripts[] = $script;
            $existingIds[] = $scriptId;
        }

        return $existingScripts;
    }
}
