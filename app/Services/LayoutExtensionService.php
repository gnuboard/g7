<?php

namespace App\Services;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Enums\LayoutSyncMode;
use App\Exceptions\ConcurrentModificationException;
use App\Extension\Helpers\LayoutFragmentSourceHelper;
use App\Extension\HookManager;
use App\Extension\Layout\LayoutRowSynchronizer;
use App\Extension\Layout\LayoutSyncReport;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Extension\TemplateManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Extension\Traits\CollectsFragmentSources;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Models\LayoutExtension;
use App\Models\TemplateLayoutExtensionVersion;
use App\Support\LayoutEmptyObjects;
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
    use ClearsTemplateCaches;
    use CollectsFragmentSources;
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
     * 템플릿 ID → 식별자 캐시 (이 서비스 인스턴스 수명) — 보고용 식별자 반복 조회 회피.
     *
     * @var array<int, string>
     */
    private array $templateIdentifiers = [];

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
     * @return void 반환값 없음
     */
    public function setPreviewContentOverride(int $extensionId, array $content): void
    {
        $this->previewContentOverrides[$extensionId] = $content;
    }

    /**
     * 미리보기 확장 content 오버라이드를 모두 제거합니다.
     *
     * @return void 반환값 없음
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
     *
     * @param  LayoutExtensionRepositoryInterface  $repository
     * @param  LayoutExtensionVersionRepositoryInterface  $versionRepository
     * @param  LayoutRepositoryInterface  $layoutRepository
     * @param  PluginRepositoryInterface  $pluginRepository
     * @param  ModuleRepositoryInterface  $moduleRepository
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 저장소 (보고용 식별자·등록 대상 조회)
     * @param  LayoutRowSynchronizer  $layoutSynchronizer  파일 → DB 행 쓰기 단일 진입점 (상태 없음)
     */
    public function __construct(
        private LayoutExtensionRepositoryInterface $repository,
        private LayoutExtensionVersionRepositoryInterface $versionRepository,
        private LayoutRepositoryInterface $layoutRepository,
        private PluginRepositoryInterface $pluginRepository,
        private ModuleRepositoryInterface $moduleRepository,
        private TemplateRepositoryInterface $templateRepository,
        private LayoutRowSynchronizer $layoutSynchronizer,
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
     * @param  int|null  $injectionIndex  overlay `injections[]` 순번 — 편집기가 저장 시 노드를 원래 injection 으로 되돌리는 열쇠. extension_point 주입은 null
     * @return array 메타가 부여된 컴포넌트 배열
     *
     * @since engine-v1.50.0
     */
    private function markExtensionSource(array $components, int $extensionId, ?LayoutExtension $extension = null, ?int $injectionIndex = null): array
    {
        $meta = $this->buildExtensionSourceMeta($extension, $extensionId);
        if ($injectionIndex !== null) {
            $meta['injectionIndex'] = $injectionIndex;
        }

        return $this->applySourceMetaRecursively($components, $meta);
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
     *
     * @return void 반환값 없음
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

    /**
     * 레이아웃의 확장 지점(`type: extension_point`)에 등록된 조각을 주입합니다.
     *
     * `components` 뿐 아니라 **`modals` 트리도 함께** 순회한다 — 모달 안에 둔 확장 지점이
     * 빈 채로 남으면 그 자리를 채우던 확장이 화면에서 조용히 사라진다(모달은 열려야 보이므로
     * 목록 화면만 보고는 알 수 없다).
     *
     * 주입된 조각이 스크립트를 동반하면 `$scripts` 에 누적한다 — 호출부가 문서 전체의 스크립트를
     * 한 번에 모아 중복 없이 내보내기 위해서다.
     *
     * @param  array<string, mixed>  $layout  레이아웃 JSON
     * @param  int  $templateId  이 레이아웃이 속한 템플릿 ID (조각 조회 범위)
     * @param  array<int, mixed>  $scripts  주입 조각이 동반한 스크립트 누적함 (참조)
     * @param  bool  $withSourceMeta  편집기용 출처 메타(`__source`)를 노드에 남길지
     * @return array<string, mixed> 확장 지점이 채워진 레이아웃 JSON
     */
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
     * @param  bool  $withSourceMeta
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

                    // 주입 조각도 값 자리의 빈 객체 `{}` 를 보존한다(LayoutService 병합과 같은 기준)
                    $content = LayoutEmptyObjects::decode($extension->getAttributes()['content'] ?? null, $extension->content ?? []);
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

            // 반복 렌더 템플릿(셀·확장 행·하위 행) 안의 확장점 처리
            if (isset($component['props']) && is_array($component['props'])) {
                $component['props'] = $this->processEmbeddedTemplateExtensionPoints(
                    $component['props'],
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
     * 컴포넌트 props 안에 박힌 반복 렌더 템플릿의 확장점을 처리합니다.
     *
     * DataGrid·CardGrid 의 셀 템플릿(`columns[].cellChildren`·`cardColumns[].cellChildren`)과
     * 확장 행·하위 행 템플릿(`expandChildren`·`subRowChildren`)은 `children` 이 아니라 props 에 실린다.
     * `children` 만 따라 내려가면 그 안에 둔 확장점이 채워지지 않은 빈 자리로 남아, 주입한 조각과
     * `default` 폴백이 함께 화면에서 사라진다. 템플릿은 행마다 렌더되므로 주입 노드는 `row` 문맥을
     * 그대로 받는다(주입 데이터 소스는 레이아웃에 한 번만 병합된다).
     *
     * @param  array<string, mixed>  $props  컴포넌트 props
     * @param  int  $templateId  템플릿 ID
     * @param  array  &$dataSources  데이터 소스 배열 (참조)
     * @param  array  &$scripts  스크립트 배열 (참조)
     * @param  array  &$modals  모달 배열 (참조)
     * @param  bool  $withSourceMeta  편집기용 출처 메타를 남길지
     * @return array<string, mixed> 확장점이 채워진 props
     */
    private function processEmbeddedTemplateExtensionPoints(array $props, int $templateId, array &$dataSources, array &$scripts, array &$modals, bool $withSourceMeta = false): array
    {
        foreach (['columns', 'cardColumns'] as $columnsKey) {
            if (! isset($props[$columnsKey]) || ! is_array($props[$columnsKey])) {
                continue;
            }

            foreach ($props[$columnsKey] as $index => $column) {
                if (! is_array($column) || ! isset($column['cellChildren']) || ! is_array($column['cellChildren'])) {
                    continue;
                }

                $props[$columnsKey][$index]['cellChildren'] = $this->processExtensionPointsRecursive(
                    $column['cellChildren'],
                    $templateId,
                    $dataSources,
                    $scripts,
                    $modals,
                    $withSourceMeta
                );
            }
        }

        foreach (['expandChildren', 'subRowChildren'] as $templateKey) {
            if (isset($props[$templateKey]) && is_array($props[$templateKey]) && array_is_list($props[$templateKey])) {
                $props[$templateKey] = $this->processExtensionPointsRecursive(
                    $props[$templateKey],
                    $templateId,
                    $dataSources,
                    $scripts,
                    $modals,
                    $withSourceMeta
                );
            }
        }

        return $props;
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
     * @param  bool  $withSourceMeta
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

            // 주입 조각도 값 자리의 빈 객체 `{}` 를 보존한다(LayoutService 병합과 같은 기준)
            $content = LayoutEmptyObjects::decode($overlay->getAttributes()['content'] ?? null, $overlay->content ?? []);
            $injections = $content['injections'] ?? [];

            Log::debug('Overlay 확장 적용', [
                'layout' => $layoutName,
                'source_type' => $overlay->source_type->value,
                'source_identifier' => $overlay->source_identifier,
                'is_override' => $overlay->source_type === LayoutSourceType::Template,
                'injection_count' => count($injections),
            ]);

            // injection 순번을 함께 순회한다 — 편집기 확장 편집 모드가 호스트 병합 트리에서 이 확장의
            // 노드를 추출해 `injections[].components` 로 되돌릴 때 어느 injection 인지 알 수 있어야
            // 한다. 순번이 메타에 없으면 재조립이 모든 노드를 버려 저장본의 injections 가 통째로
            // 비워진다(무변경 저장으로도 발생, 예외·경고 없음).
            foreach ($injections as $injectionIndex => $injection) {
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
                        $components = $this->markExtensionSource(
                            $components,
                            $overlay->id,
                            $overlay,
                            is_int($injectionIndex) ? $injectionIndex : null
                        );
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
     * @return void 반환값 없음
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
     * - _patch: **목록 안의 특정 항목만** 교체 (아래 참고)
     * - (직접 값): 스칼라 덮어쓰기 또는 객체 통째로 대체
     *
     * `_patch` 는 「목록 중 한 항목만 바꾸고 싶다」를 위한 것이다. 그것이 없으면 확장은
     * 목록 **전체를 복사**해야 하고, 그 복사본은 만든 날짜에 얼어붙는다 — 호스트가 나중에
     * 항목을 더해도 확장의 낡은 복사본이 그것을 덮어써 **조용히 지운다**. 실제로 결제
     * 플러그인 3종이 주문목록에 배지 하나를 달려고 컬럼 11칸을 통째로 복사했고, 뒤에
     * 추가된 클레임 컬럼이 화면에서 사라졌다(#599 S2-D13). 그중 실제로 다른 칸은
     * **하나뿐**이었고 나머지 10칸은 원본과 완전히 같았다.
     *
     * 형식 — `_patch.by` 로 항목을 식별할 키를, `_patch.items` 로 바꿀 항목만 적는다:
     *
     * ```json
     * "columns": {
     *   "_patch": {
     *     "by": "field",
     *     "items": [{ "field": "payment_method", "cellChildren": [ … ] }]
     *   }
     * }
     * ```
     *
     * 규칙:
     * - 기존 항목과 `by` 값이 같으면 그 항목에 **shallow 병합**한다(적은 키만 덮어씀).
     * - `by` 값이 기존에 없으면 **끝에 추가**한다(확장이 새 항목을 더할 수 있다).
     * - 기존 목록의 **순서와 개수는 보존**된다 — 손대지 않은 항목은 그대로 남는다.
     * - 항목 안의 값이 `{"_append": [ … ]}` 이면 그 키를 덮어쓰지 않고 **기존 배열 뒤에
     *   덧붙인다.** 여러 확장이 같은 항목의 같은 키(예: `cellChildren`)를 함께 쓸 때
     *   서로 지우지 않는 유일한 방법이다 — 덮어쓰기면 priority 가 가장 높은 하나만 남는다.
     *
     * @param  array  &$component  대상 컴포넌트 (참조)
     * @param  array  $propsToInject  주입할 Props 정의
     * @return void 반환값 없음
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
            } elseif (isset($propValue['_patch'])) {
                $existing = $component['props'][$propKey] ?? [];

                if (is_string($existing)) {
                    Log::warning('inject_props _patch 대상이 표현식 문자열', [
                        'component_id' => $component['id'] ?? 'unknown',
                        'prop_key' => $propKey,
                        'existing_value' => $existing,
                    ]);

                    continue;
                }

                $component['props'][$propKey] = $this->patchListItems(
                    is_array($existing) ? $existing : [],
                    $propValue['_patch'],
                    $component['id'] ?? 'unknown',
                    $propKey
                );
            } else {
                // 전략 키 없으면 객체 통째로 대체
                $component['props'][$propKey] = $propValue;
            }
        }
    }

    /**
     * 목록에서 **지정한 항목만** 교체합니다 (`_patch` 전략의 실제 동작).
     *
     * 확장이 목록 전체를 복사하지 않고 한 항목만 손댈 수 있게 하는 것이 목적이다 —
     * 복사본은 만든 시점에 얼어붙어 호스트가 나중에 더한 항목을 조용히 지운다(S2-D13).
     *
     * 손대지 않은 항목은 **원본 그대로** 남고 순서도 보존한다. `by` 값이 기존에 없으면
     * 빈 항목에 병합해(항목 안의 지시자를 풀어) 새 항목으로 끝에 붙인다.
     *
     * @param  array  $existing  호스트의 현재 목록
     * @param  mixed  $patch  `{by: string, items: array}` 형태의 패치 정의
     * @param  string  $componentId  경고 로그용 컴포넌트 id
     * @param  string  $propKey  경고 로그용 prop 이름
     * @return array 패치가 적용된 목록
     */
    private function patchListItems(array $existing, mixed $patch, string $componentId, string $propKey): array
    {
        $by = is_array($patch) ? ($patch['by'] ?? null) : null;
        $items = is_array($patch) ? ($patch['items'] ?? null) : null;

        // 형식이 어긋나면 **원본을 그대로 돌려준다.** 잘못 쓴 패치가 목록을 비우면
        // 그 화면이 통째로 사라지는데, 그것이 바로 이 전략이 막으려던 사고다.
        if (! is_string($by) || $by === '' || ! is_array($items)) {
            Log::warning('inject_props _patch 형식 오류 — by(문자열)와 items(배열)가 필요합니다', [
                'component_id' => $componentId,
                'prop_key' => $propKey,
            ]);

            return $existing;
        }

        // 기존 항목의 by 값 → 인덱스 (첫 항목 우선)
        $indexByKey = [];

        foreach ($existing as $i => $item) {
            if (is_array($item) && isset($item[$by]) && is_scalar($item[$by])) {
                $key = (string) $item[$by];

                if (! array_key_exists($key, $indexByKey)) {
                    $indexByKey[$key] = $i;
                }
            }
        }

        foreach ($items as $patchItem) {
            if (! is_array($patchItem) || ! isset($patchItem[$by]) || ! is_scalar($patchItem[$by])) {
                Log::warning('inject_props _patch 항목에 식별 키가 없습니다', [
                    'component_id' => $componentId,
                    'prop_key' => $propKey,
                    'by' => $by,
                ]);

                continue;
            }

            $key = (string) $patchItem[$by];

            if (array_key_exists($key, $indexByKey)) {
                $target = $indexByKey[$key];
                $existing[$target] = $this->mergePatchItem(
                    is_array($existing[$target]) ? $existing[$target] : [],
                    $patchItem,
                    $componentId,
                    $propKey
                );

                continue;
            }

            // 기존에 없는 키 — 새 항목으로 끝에 붙인다. 원문을 그대로 붙이면 항목 안의
            // `_append`·중첩 `_patch` 지시자 객체가 리터럴로 남아 렌더러가 배열 자리에서 객체를
            // 받는다(#599 validate). 빈 항목에 병합해 지시자를 푼다.
            $existing[] = $this->mergePatchItem([], $patchItem, $componentId, $propKey);
            $indexByKey[$key] = array_key_last($existing);
        }

        return $existing;
    }

    /**
     * 패치가 지목한 항목 **하나**를 병합합니다 (`_patch` 의 항목 단위 동작).
     *
     * 기본은 shallow 덮어쓰기다 — 적은 키만 바뀌고 나머지는 보존된다. 다만 값이
     * `{"_append": [ … ]}` 이면 **그 키의 기존 배열 뒤에 덧붙인다.**
     *
     * 덧붙이기가 없으면 같은 항목의 같은 키를 여러 확장이 쓸 때 **마지막 하나만 남는다.**
     * 확장은 priority 순서로 적용되므로 앞선 확장이 넣은 것을 뒤의 확장이 통째로 지운다.
     * 실제로 결제 플러그인 3종이 주문목록 「결제수단」 칸의 `cellChildren` 에 각자 배지를
     * 달았는데 우선순위가 가장 높은 하나만 살아남았고, 그래서 그 하나가 나머지 두 결제사
     * 몫까지 대신 그려 주는 계단식 우회가 생겼다(#599). 그 우회는 남의 플러그인이 선언한
     * 데이터소스를 참조해야 해서, 그 플러그인이 없는 사이트에서는 미정의 참조가 된다.
     *
     * `_append` 가 있으면 각 확장이 **자기 것만** 덧붙이면 되므로 그 의존이 사라진다 —
     * 새 결제 플러그인이 추가돼도 기존 플러그인을 고칠 일이 없다.
     *
     * 값이 `{"_patch": { … }}` 이면 그 키의 목록으로 **한 겹 더 들어간다.** 셀 안의 래퍼
     * 노드처럼 목록이 중첩된 자리를 위한 것이다 — 호스트가 래퍼를 소유하고 확장은 그 안에만
     * 덧붙이므로, 확장이 몇 개든 래퍼와 그 className 은 하나로 유지된다.
     *
     * ```json
     * "columns": {
     *   "_patch": {
     *     "by": "field",
     *     "items": [{
     *       "field": "payment_method",
     *       "cellChildren": {
     *         "_patch": {
     *           "by": "name",
     *           "items": [{ "name": "Div", "children": { "_append": [ … ] } }]
     *         }
     *       }
     *     }]
     *   }
     * }
     * ```
     *
     * @param  array  $target  호스트의 현재 항목
     * @param  array  $patchItem  확장이 적은 패치 항목
     * @param  string  $componentId  경고 로그용 컴포넌트 id
     * @param  string  $propKey  경고 로그용 prop 경로
     * @return array 병합된 항목
     */
    private function mergePatchItem(array $target, array $patchItem, string $componentId, string $propKey): array
    {
        foreach ($patchItem as $key => $value) {
            // 대상이 표현식 문자열이면 덮어쓰지 않고 건너뛴다 — 최상위 `_append`·`_patch` 와 같은 규칙.
            // 배열로 덮으면 호스트가 런타임에 채우던 목록이 조용히 사라진다.
            if (is_array($value) && (array_key_exists('_append', $value) || array_key_exists('_patch', $value))
                && is_string($target[$key] ?? null)) {
                Log::warning('inject_props _patch 항목의 지시자 대상이 표현식 문자열', [
                    'component_id' => $componentId,
                    'prop_key' => $propKey.'.'.$key,
                    'existing_value' => $target[$key],
                ]);

                continue;
            }

            if (is_array($value) && array_key_exists('_append', $value) && is_array($value['_append'])) {
                $current = is_array($target[$key] ?? null) ? $target[$key] : [];

                $target[$key] = array_merge($current, $value['_append']);

                continue;
            }

            // 중첩 `_patch` — 셀 안의 래퍼 노드처럼 한 겹 더 들어가야 닿는 자리를 위한 것이다.
            // 호스트가 래퍼를 소유하고 확장은 그 안에만 덧붙이므로, 래퍼와 그 className 은
            // 확장이 몇 개든 하나로 유지된다.
            if (is_array($value) && array_key_exists('_patch', $value)) {
                $target[$key] = $this->patchListItems(
                    is_array($target[$key] ?? null) ? $target[$key] : [],
                    $value['_patch'],
                    $componentId,
                    $propKey.'.'.$key
                );

                continue;
            }

            $target[$key] = $value;
        }

        return $target;
    }

    /**
     * 확장 등록
     *
     * updateOrCreate를 사용하여 중복 등록을 방지합니다.
     * 재활성화 시 기존 레코드가 있으면 복원 및 업데이트하고, 없으면 새로 생성합니다.
     *
     * 행 쓰기는 {@see LayoutRowSynchronizer} 를 지난다. $preserveModified 가 true(keep)이면 관리자가 편집한 확장은
     * 3자 병합한다 — 고친 부분은 두고 손대지 않은 최상위 키만 새 판으로 바꾸며, 겹치면 관리자 판을 남긴다('skipped').
     * 결제 플러그인 리스너처럼 확장이 직접 부르는 호환 경로라 공개 시그니처는 그대로 두고 인자만 뒤에 더했다.
     *
     * 대상 템플릿 적용 가능 여부(target_layout / extension_point 존재)는 호출 측
     * (RefreshesLayoutExtensions trait)이 사전 판별한다 — isApplicableToTemplate() 참고.
     *
     * @param  array  $content  확장 파일 내용
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $preserveModified  사용자 수정분 보존 여부
     * @param  string|null  $fragmentKey  조각 식별자 (소스 파일 기본 이름 — {@see LayoutFragmentSourceHelper::fragmentKey()}).
     *                                    코어 등록 경로는 반드시 넘긴다. null 은 키를 모르는 호환 호출(확장 Listener 등) —
     *                                    같은 대상의 기존 행이 정확히 하나면 그 키를 잇고, 없으면 대상 이름, 둘 이상이면 처리하지 않는다
     * @param  array<string, mixed>|null  $backupOriginal  업데이트 백업을 컴파일한 이전 판 (원본 본문이 없는 행의 비교 기준)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return string|null 'created', 'updated', 'skipped', 또는 null (처리하지 않음)
     */
    public function registerExtension(
        array $content,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        bool $preserveModified = false,
        ?string $fragmentKey = null,
        ?array $backupOriginal = null,
        ?LayoutSyncReport $report = null
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
                $preserveModified,
                $fragmentKey,
                $backupOriginal,
                $report
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
                $preserveModified,
                $fragmentKey,
                $backupOriginal,
                $report
            );
        }

        return $result;
    }

    /**
     * 단일 확장 레코드를 등록/갱신합니다.
     *
     * 쓰기 규칙은 {@see LayoutRowSynchronizer::syncExtension()} 이 정한다:
     * - 신규 생성 → content + 원본 본문·해시·크기 → 'created'
     * - keep + 관리자 수정 → 3자 병합(겹치면 관리자 판) → 새 판이 들어가면 'updated', 아니면 'skipped'
     * - 그 외 → 새 판으로 덮어쓰기 + 원본 정보 새 파일 기준 → 'updated'
     *
     * @param  array  $content  확장 파일 내용
     * @param  LayoutExtensionType  $extensionType  확장 타입
     * @param  string  $targetName  타겟 이름
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $preserveModified  사용자 수정분 보존 여부
     * @param  string|null  $fragmentKey  조각 식별자 (null 이면 호환 해석 — {@see resolveLegacyFragmentKey()})
     * @param  array<string, mixed>|null  $backupOriginal  업데이트 백업을 컴파일한 이전 판
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return string|null 'created', 'updated', 'skipped', 또는 null (키를 정할 수 없어 처리하지 않음)
     */
    private function registerExtensionRecord(
        array $content,
        LayoutExtensionType $extensionType,
        string $targetName,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        bool $preserveModified,
        ?string $fragmentKey = null,
        ?array $backupOriginal = null,
        ?LayoutSyncReport $report = null
    ): ?string {
        $scope = [
            'template_id' => $templateId,
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
        ];

        $fragmentKey = $this->resolveLegacyFragmentKey($scope, $fragmentKey, $targetName);

        if ($fragmentKey === null) {
            return null;
        }

        // 고유 키 = 대상 5축 + 조각 식별자. 같은 확장이 같은 대상에 조각 둘을 보내면 종전 5축 키는 뒤가 앞을
        // 덮었다(ecommerce 통화 필드 조각 소실, C13).
        $entry = $this->layoutSynchronizer->syncExtension(
            $scope + ['fragment_key' => $fragmentKey],
            $content,
            LayoutSyncMode::fromPreserve($preserveModified),
            $this->templateIdentifierOf($templateId),
            $backupOriginal
        );
        $report?->add($entry);

        return match ($entry->verdict) {
            'created' => 'created',
            'updated', 'took_theirs', 'merged', 'unchanged' => 'updated',
            default => 'skipped',
        };
    }

    /**
     * 템플릿 ID 의 식별자를 읽습니다 (보고용, 서비스 인스턴스 수명 캐시).
     *
     * @param  int  $templateId  템플릿 ID
     * @return string 템플릿 식별자 (없으면 ID 문자열)
     */
    private function templateIdentifierOf(int $templateId): string
    {
        return $this->templateIdentifiers[$templateId] ??= (string) ($this->templateRepository->findById($templateId)?->identifier ?? $templateId);
    }

    /**
     * 키를 넘기지 않은 호출의 조각 식별자를 정합니다 (호환 경로).
     *
     * 코어 등록 루프는 소스 파일에서 키를 파생해 넘기지만, 확장이 직접 `registerExtension()` 을 부르는
     * 자리(업데이트 뒤 overlay 를 복원하는 결제 플러그인 Listener 등)는 파일 경로를 모른다. 그 호출을
     * 깨지 않되 옛 5축 키로 **새 행을 만들지 않도록**: 같은 대상의 기존 행이 정확히 하나면 그 행의 키를
     * 잇고(갱신), 없으면 대상 이름을 키로 만든다(갱신 경로가 파일 키로 정정한다), 둘 이상이면 어느 조각인지
     * 알 수 없으므로 경고만 남기고 처리하지 않는다.
     *
     * @param  array<string, mixed>  $scope  대상 5축 조회 조건
     * @param  string|null  $fragmentKey  호출부가 넘긴 키
     * @param  string  $targetName  대상 이름
     * @return string|null 조각 식별자, 정할 수 없으면 null
     */
    private function resolveLegacyFragmentKey(array $scope, ?string $fragmentKey, string $targetName): ?string
    {
        if ($fragmentKey !== null && $fragmentKey !== '') {
            return $fragmentKey;
        }

        $all = LayoutExtension::withTrashed()->where($scope)->get(['id', 'fragment_key', 'deleted_at']);

        // 살아 있는 행을 우선 본다 — 갱신 경로가 옛 키 행을 soft delete 하고 파일 키 행을 만든 뒤 Listener 가
        // 키 없이 다시 부르면, 지워진 옛 행까지 세어 "둘 이상" 으로 판정하거나 옛 행을 되살리게 된다.
        $rows = $all->whereNull('deleted_at')->values();

        if ($rows->isEmpty()) {
            $rows = $all;
        }

        $keys = $rows->pluck('fragment_key')->unique()->values();

        if ($keys->count() > 1) {
            Log::warning('레이아웃 확장 등록 건너뜀 — 조각 식별자 없이 호출됐고 같은 대상에 조각이 둘 이상입니다', [
                'target_name' => $targetName,
                'source' => $scope['source_identifier'],
                'template_id' => $scope['template_id'],
                'fragment_keys' => $keys->all(),
            ]);

            return null;
        }

        $existing = $keys->first();

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        // 키가 비어 있는 행(마이그레이션 백필 이전에 만들어졌거나 직접 삽입된 행)은 대상 이름을 키로 삼아 잇는다 —
        // 그대로 두면 아래 조회가 그 행을 못 찾아 옆에 새 행을 만든다.
        if ($rows->count() === 1) {
            LayoutExtension::withTrashed()->whereKey($rows->first()->id)->update(['fragment_key' => $targetName]);
        }

        return $targetName;
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
            // 모듈 레이아웃은 `{모듈}.{name}` 으로 저장되고 overlay 는 논리 이름을 가리키므로 정확 일치(`exists`)로는
            // 모듈 화면 대상 조각이 어디서도 "부재" 가 된다 — 서빙과 같은 해석으로 본다.
            return $this->layoutRepository->existsForOverlay($templateId, $content['target_layout']);
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
     * @param  string|null  $fragmentKey  조각 식별자 (있으면 그 조각의 행만 본다)
     * @return bool 정리된 행이 있으면 true
     */
    public function removeInapplicableExtension(
        array $content,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        ?string $fragmentKey = null
    ): bool {
        $extensionType = isset($content['extension_point'])
            ? LayoutExtensionType::ExtensionPoint
            : (isset($content['target_layout']) ? LayoutExtensionType::Overlay : null);

        if ($extensionType === null) {
            return false;
        }

        $targetName = $content['extension_point'] ?? $content['target_layout'];

        $existing = $this->repository->findByAttributes(array_filter([
            'template_id' => $templateId,
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
            'fragment_key' => $fragmentKey,
        ], static fn ($value): bool => $value !== null));

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
        if (! $this->isApplicableInAnyOtherTemplate($content, $extensionType, $targetName, $sourceType, $identifier, $templateId, $fragmentKey)) {
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
     * @param  string|null  $fragmentKey  조각 식별자 (있으면 그 조각의 형제 행만 본다)
     * @return bool 다른 템플릿에서 적용 가능한 동일 확장이 존재하는지
     */
    private function isApplicableInAnyOtherTemplate(
        array $content,
        LayoutExtensionType $extensionType,
        string $targetName,
        LayoutSourceType $sourceType,
        string $identifier,
        int $candidateTemplateId,
        ?string $fragmentKey = null
    ): bool {
        $siblings = $this->repository->getAllByAttributesAcrossTemplates(array_filter([
            'extension_type' => $extensionType,
            'target_name' => $targetName,
            'source_type' => $sourceType,
            'source_identifier' => $identifier,
            'fragment_key' => $fragmentKey,
        ], static fn ($value): bool => $value !== null));

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
     * 특정 확장의 최근 버전 목록을 조회합니다 (최신순).
     *
     * 버전 행은 저장할 때마다 쌓이고 정리되지 않으므로 조회 건수에 상한이 있습니다.
     *
     * @param  int  $extensionId  확장 ID
     * @param  int  $limit  조회할 최대 버전 수 (미지정 시 100)
     * @return EloquentCollection<int, TemplateLayoutExtensionVersion>
     *
     * @throws ModelNotFoundException 확장을 찾을 수 없는 경우
     */
    public function getExtensionVersions(int $extensionId, int $limit = 100): EloquentCollection
    {
        HookManager::doAction('core.layout_extension.before_versions_index', $extensionId);

        // 확장 존재 검증
        $this->getExtensionById($extensionId);

        $versions = $this->versionRepository->getVersions($extensionId, $limit);

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

        // 목록용 getVersions() 를 재사용하지 않는다 — 목록은 경량 조회라 확장 본문(content)을
        // 담지 않고 건수 상한도 있어, 재사용하면 버전 비교가 "전 항목 삭제"로 표시되고 상한 밖의
        // 오래된 버전이 404 가 된다.
        $extensionVersion = $this->versionRepository->findVersionByNumber($extensionId, $version);

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
     * 판정은 {@see LayoutRowSynchronizer::isOperatorModified()} 한 곳이 한다(원본 본문이 있으면 정규화 비교,
     * 없으면 해시). hasModifiedLayouts 와 대칭 메서드입니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @return array<array{id: int, target_name: string, fragment_key: string|null, source_identifier: string}> 수정된 확장 목록
     */
    public function hasModifiedExtensions(int $templateId): array
    {
        $modified = [];

        foreach ($this->repository->getByTemplateId($templateId) as $extension) {
            if ($this->layoutSynchronizer->isOperatorModified($extension)) {
                $modified[] = [
                    'id' => $extension->id,
                    'target_name' => $extension->target_name,
                    'fragment_key' => $extension->fragment_key,
                    'source_identifier' => $extension->source_identifier,
                ];
            }
        }

        return $modified;
    }

    /**
     * 특정 출처(모듈/플러그인)의 수정된 확장 목록을 반환합니다.
     *
     * 모든 활성 템플릿에 걸쳐 해당 출처의 확장을 스캔합니다. 종전에는 모듈/플러그인 업데이트 커맨드의 overwrite 경고가
     * 이 목록을 썼지만, 7.1.0 부터 경고는 `{Module,Plugin}Manager::hasModifiedLayouts()` 의 목록(레이아웃·확장 조각이
     * `kind` 로 함께 담긴다)을 쓴다 — 코어 안 호출처는 없다.
     *
     * @deprecated 7.1.0 — 다음 메이저에서 제거 예정. 대신 `ModuleManagerInterface::hasModifiedLayouts()` /
     *             `PluginManagerInterface::hasModifiedLayouts()` 의 `modified_layouts` 중 `kind === 'extension'` 항목을 쓴다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @return array<array{id: int, target_name: string, fragment_key: string|null, source_identifier: string}> 수정된 확장 목록
     */
    public function getModifiedExtensionsBySource(LayoutSourceType $sourceType, string $identifier): array
    {
        $modified = [];

        $extensions = $this->repository->getBySource($sourceType, $identifier);

        foreach ($extensions as $extension) {
            if ($this->layoutSynchronizer->isOperatorModified($extension)) {
                $modified[] = [
                    'id' => $extension->id,
                    'target_name' => $extension->target_name,
                    'fragment_key' => $extension->fragment_key,
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
     * @return void 반환값 없음
     */
    public function invalidateExtensionCache(int $templateId, string $targetName, LayoutExtensionType $type): void
    {
        if ($type === LayoutExtensionType::Overlay) {
            // overlay 의 target_name 은 레이아웃명 → 의존 레이아웃 캐시 무효화
            app(LayoutService::class)->clearDependentLayoutsCache($templateId, $targetName);
        }

        // extension_point 는 여러 레이아웃에서 사용될 수 있으므로 전역 캐시 버전 갱신
        // overlay 도 프론트엔드 브라우저 캐시 무효화를 위해 동일하게 갱신.
        // 쓰기는 트레이트 단일 지점(고정 CoreCacheDriver + 메모이즈 스토어) 경유 —
        // 주입 CacheInterface 직접 put 은 재바인딩/스토어 상이 시 read 와 어긋난다.
        $this->incrementExtensionCacheVersion();
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
     * 재활성화가 부른다. 조각 키 집합을 넘기면 **그 키의 행만** 되살린다 — 비활성화가 지운 행과 함께, 이전에
     * 고아 정리(`removeOrphanFragments`)가 지운 행(소스에 없는 조각·백필된 옛 키 행)까지 되살리면 같은 조각이
     * 두 행으로 살아 두 번 주입된다 (#703). 되살린 행 가운데 붙을 자리가 없는 행은 뒤따르는 등록
     * ({@see syncSourceFragmentsToTemplate()})이 다시 정리한다.
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  array<int, string>|null  $liveKeys  소스 디렉토리에 실재하는 조각 키 집합 (null 이면 전부 복원 — 호환)
     * @return int 복원된 레코드 수
     */
    public function restoreBySource(LayoutSourceType $sourceType, string $identifier, ?array $liveKeys = null): int
    {
        return $this->repository->restoreBySource($sourceType, $identifier, $liveKeys);
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
     * 붙을 자리(호스트 레이아웃·확장점)가 없는 템플릿에는 조각을 등록하지 않는다 (C10). 조각 컴파일은
     * 템플릿과 무관하다(JSON 과 동일). 확장 활성화·업데이트도 자기 등록을 마친 뒤 이 메서드를 전 활성 템플릿에 다시 불러,
     * 호스트가 나중에 설치된 조각을 메운다(멱등).
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return array{modules: int, plugins: int, skipped: int} 소스 타입별 등록 수 + 대상 부재로 건너뛴 수
     */
    public function registerAllActiveExtensionsToTemplate(int $templateId): array
    {
        $stats = ['modules' => 0, 'plugins' => 0, 'skipped' => 0];

        // 템플릿이 없으면 등록할 곳도 없다. 식별자는 정의를 읽는 문맥과 실패 로그에 싣는다(조각 컴파일 자체는 템플릿과 무관).
        $template = $this->templateRepository->findById($templateId);

        if ($template === null) {
            return $stats;
        }

        $templateIdentifier = (string) $template->identifier;

        // 활성 모듈의 레이아웃 확장 등록
        $moduleManager = app(ModuleManager::class);
        foreach ($moduleManager->getActiveModules() as $module) {
            [$registered, $skipped] = $this->registerExtensionFilesToTemplate(
                $this->loadFragmentDefinitions(
                    $module->getExtensionsPath(),
                    $module->getLayoutExtensions(),
                    ['source' => $module->getIdentifier(), 'template' => $templateIdentifier],
                    $templateIdentifier
                ),
                $this->fragmentSourceKeys($module->getExtensionsPath(), $module->getLayoutExtensions()),
                LayoutSourceType::Module,
                $module->getIdentifier(),
                $templateId
            );
            $stats['modules'] += $registered;
            $stats['skipped'] += $skipped;
        }

        // 활성 플러그인의 레이아웃 확장 등록
        $pluginManager = app(PluginManager::class);
        foreach ($pluginManager->getActivePlugins() as $plugin) {
            [$registered, $skipped] = $this->registerExtensionFilesToTemplate(
                $this->loadFragmentDefinitions(
                    $plugin->getExtensionsPath(),
                    $plugin->getLayoutExtensions(),
                    ['source' => $plugin->getIdentifier(), 'template' => $templateIdentifier],
                    $templateIdentifier
                ),
                $this->fragmentSourceKeys($plugin->getExtensionsPath(), $plugin->getLayoutExtensions()),
                LayoutSourceType::Plugin,
                $plugin->getIdentifier(),
                $templateId
            );
            $stats['plugins'] += $registered;
            $stats['skipped'] += $skipped;
        }

        $total = $stats['modules'] + $stats['plugins'];
        if ($total > 0) {
            Log::info("템플릿에 활성 확장 등록 완료: 모듈 {$stats['modules']}건, 플러그인 {$stats['plugins']}건", [
                'template_id' => $templateId,
                'skipped' => $stats['skipped'],
            ]);
        }

        return $stats;
    }

    /**
     * 읽어 둔 fragment 정의를 특정 템플릿에 등록합니다 — 붙을 자리가 없는 조각은 건너뜁니다.
     *
     * 소스 선택·읽기는 호출처가 {@see CollectsFragmentSources::loadFragmentDefinitions()}
     * 로 끝내고 여기는 판별·등록만 한다 — 종전에는 경로 배열을 받아 여기서 `File::get`+`json_decode` 로
     * 직행했고, 그래서 **템플릿 활성화**만 소스(`.g7.html`)을 보지 못하는 네 번째 갈래로 남아 있었다.
     *
     * 적용 필터(C10): 호스트 레이아웃·확장점이 이 템플릿에 없으면 등록하지 않는다 — 종전엔 모든 활성 템플릿에
     * 무조건 등록해 사용자 템플릿에 관리자 대상 조각(죽은 행)이 쌓였다. 호스트가 나중에 설치되는 경우는
     * 확장 활성화·업데이트가 이 메서드를 전 템플릿에 다시 불러 메운다.
     *
     * 이 경로는 **다른 확장**의 활성화·업데이트나 템플릿 활성화가 활성 확장 전부를 다시 훑을 때 탄다. 그래서 운영자
     * 수정본을 보존하고(`preserveModified=true` — 종전 `false` 는 무관한 확장의 수정본과 원본 해시까지 덮어썼다),
     * 등록 뒤 소스에 없는 조각 행을 정리해 옛 키 행이 남아 두 번 주입되지 않게 한다 (#703).
     *
     * @param  array<int, array{path: string, data: array<string, mixed>, key: string}>  $definitions  fragment 정의 목록
     * @param  array<int, string>  $liveKeys  소스 디렉토리에 실재하는 조각 키 집합 ({@see CollectsFragmentSources::fragmentSourceKeys()})
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @return array{0: int, 1: int} `[등록 수, 건너뛴 수]`
     */
    private function registerExtensionFilesToTemplate(
        array $definitions,
        array $liveKeys,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId
    ): array {
        $stats = $this->syncSourceFragmentsToTemplate($definitions, $liveKeys, $sourceType, $identifier, $templateId, true);

        return [$stats['registered'], $stats['inapplicable']];
    }

    /**
     * 한 출처(모듈·플러그인)의 조각을 한 템플릿에서 소스 파일 한 벌로 수렴시킵니다.
     *
     * 조각 행을 쓰는 경로(확장 활성화·업데이트·갱신, 다른 확장이 부르는 재등록, 템플릿 활성화)가 모두 이 한 곳을
     * 탄다. 종전에는 갱신 경로만 고아 정리를 했고, 나머지는 새 키로 행을 만들기만 해 7.0.11 에서 올라온 설치의
     * 백필 행(키 = 대상 이름)이 새 행과 함께 살아 같은 조각이 두 번 주입됐다 (#703).
     *
     *  1. 백필된 옛 행을 파일 키로 옮긴다({@see adoptLegacyFragmentRows()}) — 운영자 수정본을 지닌 채 이어진다.
     *  2. 붙을 자리가 있는 조각은 등록하고, 없으면 오등록 행을 정리한다(교차 템플릿 가드 포함).
     *  3. 소스에 없는 조각의 행을 정리한다(판정 기준은 소스 존재 — 컴파일 실패 조각의 행은 보존).
     *
     * @param  array<int, array{path: string, data: array<string, mixed>, key: string}>  $definitions  이 템플릿용으로 읽은 fragment 정의
     * @param  array<int, string>  $liveKeys  소스 디렉토리에 실재하는 조각 키 집합
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  bool  $preserveModified  운영자 수정본 보존(keep — 3자 병합) 여부. keep 이면 새 버전에서 빠진 조각도 운영자가 고쳤으면 남긴다
     * @param  array<string, array<string, mixed>>  $backupFragments  업데이트 백업을 컴파일한 이전 판 조각 (조각 키 → 본문, 원본 본문이 없는 행의 비교 기준)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return array{registered: int, inapplicable: int, created: int, updated: int, skipped: int, deleted: int} 통계
     */
    public function syncSourceFragmentsToTemplate(
        array $definitions,
        array $liveKeys,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId,
        bool $preserveModified,
        array $backupFragments = [],
        ?LayoutSyncReport $report = null
    ): array {
        $stats = ['registered' => 0, 'inapplicable' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'deleted' => 0];

        $this->adoptLegacyFragmentRows($definitions, $liveKeys, $sourceType, $identifier, $templateId);

        foreach ($definitions as $definition) {
            try {
                if (! $this->isExtensionApplicableToTemplate($definition['data'], $templateId)) {
                    $stats['inapplicable']++;
                    Log::info("레이아웃 확장 등록 건너뜀 (대상 부재): {$definition['path']}", [
                        'source' => $identifier,
                        'template_id' => $templateId,
                    ]);

                    // 과거 무차별 등록·재활성화 복원으로 살아난 오등록 행 정리 (다른 템플릿에서 적용 가능할 때만)
                    if ($this->removeInapplicableExtension($definition['data'], $sourceType, $identifier, $templateId, $definition['key'])) {
                        $stats['deleted']++;
                    }

                    continue;
                }

                // 다른 확장 활성화·템플릿 전환 경로는 늘 keep 으로 부른다 — 파일이 안 바뀐 조각의 관리자 수정은
                // "파일 = 원본" 판정으로 그대로 남는다(L5 — 종전엔 false 고정이라 무관한 모듈 조각 수정까지 지웠다).
                $result = $this->registerExtension(
                    $definition['data'],
                    $sourceType,
                    $identifier,
                    $templateId,
                    $preserveModified,
                    $definition['key'],
                    $backupFragments[$definition['key']] ?? null,
                    $report
                );
                $stats['registered']++;

                if ($result === 'created' || $result === 'updated' || $result === 'skipped') {
                    $stats[$result]++;
                }
            } catch (\Exception $e) {
                Log::error("레이아웃 확장 등록 실패: {$definition['path']}", [
                    'source' => $identifier,
                    'template_id' => $templateId,
                    'fragment_key' => $definition['key'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $stats['deleted'] += $this->removeOrphanFragments($sourceType, $identifier, $templateId, $liveKeys, LayoutSyncMode::fromPreserve($preserveModified), $report);

        return $stats;
    }

    /**
     * 조각 식별자 마이그레이션이 대상 이름으로 백필한 옛 행을 소스 파일 키로 옮깁니다.
     *
     * 7.0.11 까지 행은 대상(확장점·레이아웃) 단위로 하나였고, 마이그레이션은 그 키를 대상 이름으로 채웠다. 등록은
     * 파일 이름을 키로 쓰므로 그대로 두면 새 행이 생기고 옛 행은 고아가 된다 — 운영자가 옛 행을 고쳤다면 keep
     * 전략도 그 수정본을 보지 못한 채 지워진다. 그래서 **옛 행이 어느 조각이었는지 확실할 때만** 그 행의 키를
     * 파일 키로 바꿔 잇는다(행 ID·수정본·판본 이력 유지). 확실하다는 것은:
     *
     *  - 이 출처에서 그 대상을 겨냥한 조각 파일이 정확히 하나이고,
     *  - 대상 이름이 그 자체로 살아 있는 조각 파일 이름이 아니며(그러면 옛 행은 그 파일의 행이다),
     *  - 파일 키 행이 아직 없다(있으면 옛 행은 고아 — 정리가 지운다).
     *
     * 한 대상에 조각이 둘 이상이면 옛 행(7.0.11 에서 뒤 조각이 앞 조각을 덮은 결과)이 어느 조각인지 알 수 없어
     * 옮기지 않는다 — 정리가 지우고 파일 키 행들이 새로 선다.
     *
     * @param  array<int, array{path: string, data: array<string, mixed>, key: string}>  $definitions  fragment 정의
     * @param  array<int, string>  $liveKeys  소스 디렉토리에 실재하는 조각 키 집합
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @return int 옮긴 행 수
     */
    private function adoptLegacyFragmentRows(
        array $definitions,
        array $liveKeys,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId
    ): int {
        $live = array_fill_keys($liveKeys, true);
        $groups = [];

        foreach ($definitions as $definition) {
            $data = $definition['data'];

            // 두 방식을 한 파일에 함께 선언했거나 둘 다 없는 정의는 대상이 하나로 정해지지 않아 판정에서 뺀다.
            if (isset($data['extension_point']) === isset($data['target_layout'])) {
                continue;
            }

            $type = isset($data['extension_point']) ? LayoutExtensionType::ExtensionPoint : LayoutExtensionType::Overlay;
            $target = (string) ($data['extension_point'] ?? $data['target_layout']);
            $groups[$type->value."\0".$target][] = (string) $definition['key'];
        }

        $adopted = 0;

        foreach ($groups as $groupKey => $keys) {
            $keys = array_values(array_unique($keys));

            if (count($keys) !== 1) {
                continue;
            }

            [$typeValue, $target] = explode("\0", $groupKey, 2);
            $fileKey = $keys[0];

            if ($fileKey === $target || isset($live[$target])) {
                continue;
            }

            $scope = [
                'template_id' => $templateId,
                'extension_type' => LayoutExtensionType::from($typeValue),
                'target_name' => $target,
                'source_type' => $sourceType,
                'source_identifier' => $identifier,
            ];

            if ($this->repository->findWithTrashedByAttributes([...$scope, 'fragment_key' => $fileKey]) !== null) {
                continue;
            }

            $legacy = $this->repository->findWithTrashedByAttributes([...$scope, 'fragment_key' => $target]);

            if ($legacy === null) {
                continue;
            }

            $this->repository->saveFragmentKey($legacy, $fileKey);
            $adopted++;

            Log::info('백필된 옛 조각 행을 파일 키로 이음', [
                'extension_id' => $legacy->id,
                'template_id' => $templateId,
                'target_name' => $target,
                'fragment_key' => $fileKey,
                'source' => $identifier,
            ]);
        }

        return $adopted;
    }

    /**
     * 파일에 더는 없는 조각의 행을 정리합니다 (같은 출처·같은 템플릿, soft delete).
     *
     * 갱신 경로가 소스 디렉토리의 조각 키 집합을 넘긴다. 키가 그 집합에 없는 행은 소스가 사라졌거나 이름이
     * 바뀐 조각이다(옛 5축 키로 만들어진 `target_name` 키 행도 여기서 파일 키로 정정된다 — 등록이 새 키로
     * 행을 만들고 이 정리가 옛 행을 지운다).
     *
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  array<int, string>  $liveKeys  소스 디렉토리에 실재하는 조각 키 집합
     * @param  LayoutSyncMode  $mode  동기화 모드 — keep 이면 관리자가 고친 조각은 새 버전에서 빠졌어도 남긴다(D4·L10 — 템플릿 레이아웃과 같은 규칙)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return int 정리된 행 수
     */
    public function removeOrphanFragments(LayoutSourceType $sourceType, string $identifier, int $templateId, array $liveKeys, LayoutSyncMode $mode = LayoutSyncMode::Overwrite, ?LayoutSyncReport $report = null): int
    {
        $live = array_fill_keys($liveKeys, true);
        $deleted = 0;

        foreach ($this->repository->getBySource($sourceType, $identifier) as $row) {
            if ((int) $row->template_id !== $templateId || isset($live[(string) $row->fragment_key])) {
                continue;
            }

            $entry = $this->layoutSynchronizer->removeOrphanExtension($row, $this->templateIdentifierOf($templateId), $mode);
            $report?->add($entry);

            if ($entry->verdict !== 'deleted') {
                continue;
            }

            $deleted++;

            Log::info('고아 레이아웃 확장 행 정리 (소스에 없는 조각)', [
                'extension_id' => $row->id,
                'template_id' => $templateId,
                'target_name' => $row->target_name,
                'fragment_key' => $row->fragment_key,
                'source' => $identifier,
            ]);
        }

        return $deleted;
    }

    /**
     * 템플릿 오버라이드 행 가운데 소스에 더는 없는 것을 정리합니다 (soft delete).
     *
     * 템플릿 갱신이 `extensions/{대상}/` 의 조각 키 집합을 대상별로 넘긴다. 키가 그 집합에 없는 행 —
     * 조각 파일이 사라졌거나, 템플릿이 그 대상을 더는 오버라이드하지 않거나, 조각 식별자 마이그레이션이
     * 대상 이름으로 백필한 옛 행(등록은 파일 이름 키로 새 행을 만든다) — 은 지운다. 모듈·플러그인 갱신의
     * `removeOrphanFragments` 와 같은 역할이다. 이 정리가 없어 7.0.11 에서 올라온 설치에서 같은 조각이 두 행으로
     * 남아 두 번 주입됐다(#703 실측 — 주문서 「주소 검색」 버튼 중복).
     *
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  array<string, array<int, string>>  $liveKeysByTarget  오버라이드 대상 식별자 → 소스에 실재하는 조각 키 목록
     * @return int 정리된 행 수
     */
    public function removeOrphanTemplateOverrides(string $templateIdentifier, int $templateId, array $liveKeysByTarget): int
    {
        $live = array_map(static fn (array $keys): array => array_fill_keys($keys, true), $liveKeysByTarget);
        $deleted = 0;

        foreach ($this->repository->getBySource(LayoutSourceType::Template, $templateIdentifier) as $row) {
            // 오버라이드가 아닌 템플릿 출처 행은 이 정리의 대상이 아니다.
            if ((int) $row->template_id !== $templateId || $row->override_target === null) {
                continue;
            }

            if (isset($live[$row->override_target][(string) $row->fragment_key])) {
                continue;
            }

            $row->delete();
            $deleted++;

            Log::info('고아 템플릿 오버라이드 행 정리 (소스에 없는 조각)', [
                'extension_id' => $row->id,
                'template' => $templateIdentifier,
                'override_target' => $row->override_target,
                'target_name' => $row->target_name,
                'fragment_key' => $row->fragment_key,
            ]);
        }

        return $deleted;
    }

    /**
     * 템플릿 오버라이드 Extension 등록
     *
     * 템플릿이 모듈/플러그인의 Extension을 오버라이드하는 경우 사용합니다.
     * 행 쓰기는 {@see LayoutRowSynchronizer} 를 지나 원본 해시·원본 본문을 남기고, keep 이면 운영자 수정을 병합한다
     * (W6 — 종전엔 해시 없이 `updateOrCreate` 로 덮어써 keep 이 무효였다).
     *
     * @param  array  $content  확장 파일 내용
     * @param  string  $templateIdentifier  템플릿 식별자
     * @param  string  $overrideTarget  오버라이드 대상 모듈/플러그인 식별자
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $fragmentKey  조각 식별자 (null 이면 대상 이름)
     * @param  LayoutSyncMode  $mode  동기화 모드 (설치는 Keep, 갱신은 그 갱신의 모드)
     * @param  LayoutSyncReport|null  $report  결과를 모을 보고
     * @return string|null 'created', 'updated', 또는 null (처리하지 않음)
     */
    public function registerTemplateOverride(
        array $content,
        string $templateIdentifier,
        string $overrideTarget,
        int $templateId,
        ?string $fragmentKey = null,
        LayoutSyncMode $mode = LayoutSyncMode::Keep,
        ?LayoutSyncReport $report = null
    ): ?string {
        $result = null;
        $targets = [];

        // Extension Point 타입 오버라이드
        if (isset($content['extension_point'])) {
            $targets[] = [LayoutExtensionType::ExtensionPoint, $content['extension_point']];
        }

        // Overlay 타입 오버라이드
        if (isset($content['target_layout'])) {
            $targets[] = [LayoutExtensionType::Overlay, $content['target_layout']];
        }

        foreach ($targets as [$extensionType, $targetName]) {
            $entry = $this->layoutSynchronizer->syncExtension(
                [
                    'template_id' => $templateId,
                    'extension_type' => $extensionType,
                    'target_name' => $targetName,
                    'fragment_key' => $fragmentKey ?? $targetName,
                    'source_type' => LayoutSourceType::Template,
                    'source_identifier' => $templateIdentifier,
                    'override_target' => $overrideTarget,
                ],
                $content,
                $mode,
                $templateIdentifier
            );
            $report?->add($entry);
            $result = $entry->verdict === 'created' ? 'created' : 'updated';

            Log::info($extensionType === LayoutExtensionType::ExtensionPoint ? '템플릿 Extension Point 오버라이드 등록' : '템플릿 Overlay 오버라이드 등록', [
                'template' => $templateIdentifier,
                'target' => $targetName,
                'override_target' => $overrideTarget,
                'verdict' => $entry->verdict,
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
