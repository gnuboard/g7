<?php

namespace App\Services;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Enums\LayoutExtensionType;
use App\Enums\LayoutSourceType;
use App\Extension\HookManager;
use App\Extension\ModuleManager;
use App\Extension\PluginManager;
use App\Models\LayoutExtension;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * 레이아웃 확장 서비스
 *
 * 모듈/플러그인이 기존 레이아웃에 UI를 동적으로 주입하는 기능을 처리합니다.
 */
class LayoutExtensionService
{
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
     * 생성자
     *
     * 순환 의존성 방지를 위해 ModuleManager, PluginManager는 생성자에서 주입받지 않고
     * 필요한 시점에 app() 헬퍼로 가져옵니다.
     */
    public function __construct(
        private LayoutExtensionRepositoryInterface $repository
    ) {}

    /**
     * 레이아웃에 확장 적용
     *
     * @param  array  $layout  원본 레이아웃
     * @param  int  $templateId  템플릿 ID
     * @return array 확장이 적용된 레이아웃
     */
    public function applyExtensions(array $layout, int $templateId): array
    {
        $layoutName = $layout['layout_name'] ?? '';

        // 비호환 오버라이드 목록 초기화
        $this->incompatibleOverrides = [];

        // 활성화된 모듈/플러그인 목록 캐시 초기화
        $this->initializeActiveExtensions();

        // 훅: 확장 적용 전
        HookManager::doAction('core.layout_extension.before_apply', $layout, $templateId);

        // scripts 배열 초기화 (기존 scripts 유지)
        $scripts = $layout['scripts'] ?? [];

        // 1. Extension Point 처리
        $layout = $this->applyExtensionPoints($layout, $templateId, $scripts);

        // 2. Overlay 처리
        $modals = $layout['modals'] ?? [];
        $layout = $this->applyOverlays($layout, $templateId, $layoutName, $scripts, $modals);
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

        // 훅: 확장 적용 후
        $layout = HookManager::applyFilters('core.layout_extension.after_apply', $layout, $templateId);

        return $layout;
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
            $module = app(\App\Contracts\Repositories\ModuleRepositoryInterface::class)
                ->findActiveByIdentifier($identifier);
            if ($module) {
                return true;
            }
        }

        $pluginManager = app(PluginManager::class);
        if ($pluginManager->getPluginVersion($identifier) !== null) {
            $plugin = app(\App\Contracts\Repositories\PluginRepositoryInterface::class)
                ->findActiveByIdentifier($identifier);
            if ($plugin) {
                return true;
            }
        }

        return false;
    }

    private function applyExtensionPoints(array $layout, int $templateId, array &$scripts): array
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
            $modals
        );

        // modals 트리도 순회하며 extension_point 처리
        // 모달 내부에 정의된 extension_point에도 플러그인/모듈 컴포넌트가 주입되도록 함
        if (! empty($modals)) {
            $modals = $this->processExtensionPointsRecursive(
                $modals,
                $templateId,
                $dataSources,
                $scripts,
                $modals
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
    private function processExtensionPointsRecursive(array $components, int $templateId, array &$dataSources, array &$scripts, array &$modals): array
    {
        foreach ($components as $index => &$component) {
            // Extension Point 발견
            if (($component['type'] ?? '') === 'extension_point' && isset($component['name'])) {
                $extensionPointName = $component['name'];

                // 오버라이드를 고려한 확장 조회 (템플릿 > 플러그인 > 모듈)
                $extensions = $this->repository->getResolvedExtensionPoints($templateId, $extensionPointName);

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

                        $injectedComponents = match ($mode) {
                            'replace' => $componentsWithProps,
                            'prepend' => array_merge($componentsWithProps, $injectedComponents),
                            default => array_merge($injectedComponents, $componentsWithProps),
                        };
                    }

                    // 데이터 소스 병합
                    if (isset($content['data_sources'])) {
                        $dataSources = array_merge($dataSources, $content['data_sources']);
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
                    $modals
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
     * @param  string  $layoutName  레이아웃 이름
     * @param  array  &$scripts  스크립트 배열 (참조)
     * @param  array  &$modals  모달 배열 (참조)
     * @return array 확장이 적용된 레이아웃
     */
    private function applyOverlays(array $layout, int $templateId, string $layoutName, array &$scripts, array &$modals): array
    {
        if (empty($layoutName) || ! isset($layout['components'])) {
            return $layout;
        }

        // 버전 호환성을 고려한 오버레이 조회
        $overlays = $this->getVersionAwareOverlays($templateId, $layoutName);

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

                    $injected = $this->injectPropsAtTarget(
                        $layout['components'],
                        $targetId,
                        $propsToInject
                    );
                } else {
                    // 기존 컴포넌트 주입 로직
                    $components = $injection['components'] ?? [];
                    if (empty($components)) {
                        continue;
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

            // 데이터 소스 병합
            if (isset($content['data_sources'])) {
                $dataSources = array_merge($dataSources, $content['data_sources']);
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
     * @return bool 주입 성공 여부
     */
    private function injectPropsAtTarget(array &$components, string $targetId, array $propsToInject): bool
    {
        foreach ($components as &$component) {
            if (($component['id'] ?? '') === $targetId) {
                $this->injectProps($component, $propsToInject);

                return true;
            }

            if (isset($component['children']) && is_array($component['children'])) {
                if ($this->injectPropsAtTarget($component['children'], $targetId, $propsToInject)) {
                    return true;
                }
            }
        }

        return false;
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
     * @param  array  $content  확장 파일 내용
     * @param  LayoutSourceType  $sourceType  출처 타입
     * @param  string  $identifier  출처 식별자
     * @param  int  $templateId  템플릿 ID
     * @return string|null 'created', 'updated', 또는 null (처리하지 않음)
     */
    public function registerExtension(
        array $content,
        LayoutSourceType $sourceType,
        string $identifier,
        int $templateId
    ): ?string {
        $result = null;

        // Extension Point 타입
        if (isset($content['extension_point'])) {
            $model = $this->repository->updateOrCreate(
                [
                    'template_id' => $templateId,
                    'extension_type' => LayoutExtensionType::ExtensionPoint,
                    'target_name' => $content['extension_point'],
                    'source_type' => $sourceType,
                    'source_identifier' => $identifier,
                ],
                [
                    'content' => $content,
                    'priority' => $content['priority'] ?? 100,
                    'is_active' => true,
                ]
            );
            $result = $model->wasRecentlyCreated ? 'created' : 'updated';
        }

        // Overlay 타입
        if (isset($content['target_layout'])) {
            $model = $this->repository->updateOrCreate(
                [
                    'template_id' => $templateId,
                    'extension_type' => LayoutExtensionType::Overlay,
                    'target_name' => $content['target_layout'],
                    'source_type' => $sourceType,
                    'source_identifier' => $identifier,
                ],
                [
                    'content' => $content,
                    'priority' => $content['priority'] ?? 100,
                    'is_active' => true,
                ]
            );
            $result = $model->wasRecentlyCreated ? 'created' : 'updated';
        }

        return $result;
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
     * @return \Illuminate\Support\Collection<int, LayoutExtension>
     */
    private function getVersionAwareOverlays(int $templateId, string $layoutName): \Illuminate\Support\Collection
    {
        // 모든 오버레이 조회 (모듈 + 플러그인 + 템플릿)
        $allOverlays = $this->repository->getOverlaysByLayout($templateId, $layoutName);

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
        $version = $this->getExtensionVersion($targetIdentifier);

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
    private function getExtensionVersion(string $identifier): ?string
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
