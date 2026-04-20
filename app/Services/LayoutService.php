<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Exceptions\CircularReferenceException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

class LayoutService
{
    /**
     * LayoutRepository, LayoutVersionRepository, TemplateRepository, LayoutResolverService 및 LayoutExtensionService 주입
     */
    public function __construct(
        private LayoutRepositoryInterface $layoutRepository,
        private LayoutVersionRepositoryInterface $versionRepository,
        private TemplateRepositoryInterface $templateRepository,
        private LayoutResolverService $layoutResolverService,
        private LayoutExtensionService $layoutExtensionService,
        private CacheInterface $cache
    ) {}

    /**
     * 최대 레이아웃 중첩 깊이를 반환합니다.
     */
    private function getMaxDepth(): int
    {
        return config('template.layout.max_inheritance_depth', 10);
    }

    /**
     * 병합된 레이아웃 캐시 TTL을 반환합니다.
     *
     * g7_core_settings('cache.layout_ttl') 우선, 없으면 config('template.layout.cache_ttl').
     */
    private function getCacheTtl(): int
    {
        return (int) g7_core_settings('cache.layout_ttl', config('template.layout.cache_ttl', 3600));
    }

    /**
     * 순환 참조 방지를 위한 로드 스택
     */
    private array $loadStack = [];

    /**
     * 캐시 히트/미스 카운터
     */
    private array $cacheStats = [
        'hits' => 0,
        'misses' => 0,
    ];

    /**
     * 부모-자식 레이아웃을 병합합니다.
     *
     * @param  array  $parentLayout  부모 레이아웃 데이터
     * @param  array  $childLayout  자식 레이아웃 데이터
     *
     * @throws \Exception 병합 중 오류 발생 시
     */
    public function mergeLayouts(array $parentLayout, array $childLayout): array
    {
        // Before 훅 - 병합 전 데이터 검증/변환
        HookManager::doAction('core.layout.before_merge', $parentLayout, $childLayout);

        // 필터 훅 - 부모 레이아웃 데이터 변형
        $parentLayout = HookManager::applyFilters('core.layout.filter_parent_data', $parentLayout, $childLayout);

        // 필터 훅 - 자식 레이아웃 데이터 변형
        $childLayout = HookManager::applyFilters('core.layout.filter_child_data', $childLayout, $parentLayout);

        // 1. meta 병합 (자식 우선)
        $mergedMeta = $this->mergeMeta($parentLayout['meta'] ?? [], $childLayout['meta'] ?? []);

        // 2. data_sources 병합 (부모 + 자식, ID 중복 불가)
        $mergedDataSources = $this->mergeDataSources(
            $parentLayout['data_sources'] ?? [],
            $childLayout['data_sources'] ?? []
        );

        // 3. components 병합 (부모의 slot을 자식 slots로 교체)
        $mergedComponents = $this->mergeComponents(
            $parentLayout['components'] ?? [],
            $childLayout['slots'] ?? []
        );

        // 4. modals 병합 (자식 우선, 부모와 자식 모두 포함)
        $mergedModals = $this->mergeModals(
            $parentLayout['modals'] ?? [],
            $childLayout['modals'] ?? []
        );

        // 5. init_actions/initActions 병합 (부모 먼저, 자식 나중에 실행)
        // initActions와 init_actions 둘 다 지원 (하위 호환)
        $parentInitActions = $parentLayout['initActions'] ?? $parentLayout['init_actions'] ?? [];
        $childInitActions = $childLayout['initActions'] ?? $childLayout['init_actions'] ?? [];
        $mergedInitActions = $this->mergeInitActions($parentInitActions, $childInitActions);

        // 6. defines 병합 (부모 + 자식, 자식 우선)
        $mergedDefines = $this->mergeDefines(
            $parentLayout['defines'] ?? [],
            $childLayout['defines'] ?? []
        );

        // 7. computed 병합 (부모 + 자식, 자식 우선)
        $mergedComputed = $this->mergeComputed(
            $parentLayout['computed'] ?? [],
            $childLayout['computed'] ?? []
        );

        // 8. initLocal 병합 (부모 + 자식, 자식 우선, 얕은 병합)
        // state는 initLocal의 deprecated alias
        $parentInitLocal = $parentLayout['initLocal'] ?? $parentLayout['state'] ?? [];
        $childInitLocal = $childLayout['initLocal'] ?? $childLayout['state'] ?? [];
        $mergedInitLocal = $this->mergeShallow($parentInitLocal, $childInitLocal);

        // 9. initGlobal 병합 (부모 + 자식, 자식 우선, 얕은 병합)
        $mergedInitGlobal = $this->mergeShallow(
            $parentLayout['initGlobal'] ?? [],
            $childLayout['initGlobal'] ?? []
        );

        // 10. initIsolated 병합 (부모 + 자식, 자식 우선, 얕은 병합)
        $mergedInitIsolated = $this->mergeShallow(
            $parentLayout['initIsolated'] ?? [],
            $childLayout['initIsolated'] ?? []
        );

        // 11. scripts 병합 (부모 + 자식, ID 기반 자식 우선)
        $mergedScripts = $this->mergeScripts(
            $parentLayout['scripts'] ?? [],
            $childLayout['scripts'] ?? []
        );

        // 12. permissions 병합 (중복 제거하여 합집합)
        $mergedPermissions = $this->mergePermissions(
            $parentLayout['permissions'] ?? [],
            $childLayout['permissions'] ?? []
        );

        // 13. globalHeaders 병합 (pattern 기준으로 자식 우선)
        $mergedGlobalHeaders = $this->mergeGlobalHeaders(
            $parentLayout['globalHeaders'] ?? [],
            $childLayout['globalHeaders'] ?? []
        );

        // 14. named_actions 병합 (부모 + 자식, 자식 우선)
        $mergedNamedActions = $this->mergeNamedActions(
            $parentLayout['named_actions'] ?? [],
            $childLayout['named_actions'] ?? []
        );

        // 15. errorHandling 병합 (에러 코드 기반, 자식 우선 오버라이드)
        // array_merge는 숫자형 문자열 키("401","403")를 정수로 변환하므로 array_replace 사용
        $mergedErrorHandling = array_replace(
            $parentLayout['errorHandling'] ?? [],
            $childLayout['errorHandling'] ?? []
        );

        // 16. 병합 결과 생성 (자식 레이아웃의 version, layout_name 포함)
        $result = [
            'version' => $childLayout['version'] ?? $parentLayout['version'] ?? '1.0.0',
            'layout_name' => $childLayout['layout_name'] ?? $parentLayout['layout_name'] ?? '',
            'meta' => $mergedMeta,
            'data_sources' => $mergedDataSources,
            'components' => $mergedComponents,
            'modals' => $mergedModals,
        ];

        // initActions가 있으면 추가 (새 이름 사용)
        if (! empty($mergedInitActions)) {
            $result['initActions'] = $mergedInitActions;
        }

        // defines가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedDefines)) {
            $result['defines'] = $mergedDefines;
        }

        // computed가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedComputed)) {
            $result['computed'] = $mergedComputed;
        }

        // initLocal이 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedInitLocal)) {
            $result['initLocal'] = $mergedInitLocal;
        }

        // initGlobal이 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedInitGlobal)) {
            $result['initGlobal'] = $mergedInitGlobal;
        }

        // initIsolated가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedInitIsolated)) {
            $result['initIsolated'] = $mergedInitIsolated;
        }

        // scripts가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedScripts)) {
            $result['scripts'] = $mergedScripts;
        }

        // permissions가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedPermissions)) {
            $result['permissions'] = $mergedPermissions;
        }

        // globalHeaders가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedGlobalHeaders)) {
            $result['globalHeaders'] = $mergedGlobalHeaders;
        }

        // named_actions가 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedNamedActions)) {
            $result['named_actions'] = $mergedNamedActions;
        }

        // errorHandling이 있으면 추가 (빈 배열이 아닌 경우만)
        if (! empty($mergedErrorHandling)) {
            $result['errorHandling'] = $mergedErrorHandling;
        }

        // transition_overlay 병합 (shallow merge — 자식 키가 부모 키를 override)
        // @since engine-v1.23.0
        // @since engine-v1.30.0 — shallow merge 도입. 자식이 wait_for 만 명시해도 부모의
        //                        enabled/style/target/spinner 가 보존되어 자식이 부분 override 가능.
        //                        부모 또는 자식 어느 한쪽이라도 transition_overlay 를 정의하면 결과에 포함.
        $parentOverlay = $parentLayout['transition_overlay'] ?? null;
        $childOverlay = $childLayout['transition_overlay'] ?? null;
        if ($parentOverlay !== null || $childOverlay !== null) {
            // boolean 또는 그 외 비배열 케이스는 shallow merge 가 의미 없으므로 자식 우선/부모 폴백
            if (! is_array($parentOverlay) || ! is_array($childOverlay)) {
                $result['transition_overlay'] = $childOverlay ?? $parentOverlay;
            } else {
                $result['transition_overlay'] = array_merge($parentOverlay, $childOverlay);
            }
        }

        // 5. 불필요한 필드 제거 (extends, slots, slot)
        $result = $this->removeUnnecessaryFields($result);

        // 필터 훅 - 병합 결과 변형
        $result = HookManager::applyFilters('core.layout.filter_merged', $result, $parentLayout, $childLayout);

        // After 훅 - 병합 후 후처리
        HookManager::doAction('core.layout.after_merge', $result, $parentLayout, $childLayout);

        return $result;
    }

    /**
     * meta 병합 - 자식 값이 우선 (seo 키는 deep merge)
     *
     * @param  array  $parentMeta  부모 메타 데이터
     * @param  array  $childMeta  자식 메타 데이터
     * @return array 병합된 메타 데이터
     */
    private function mergeMeta(array $parentMeta, array $childMeta): array
    {
        // seo 키는 deep merge (부모 기본값 보존 + 자식 오버라이드)
        if (isset($parentMeta['seo']) && isset($childMeta['seo'])) {
            $childMeta['seo'] = $this->mergeSeo($parentMeta['seo'], $childMeta['seo']);
        }

        return array_merge($parentMeta, $childMeta);
    }

    /**
     * SEO 설정을 병합합니다.
     *
     * 연관 키(vars, og, structured_data 등)는 deep merge,
     * data_sources는 합집합(union) 병합 (permissions와 동일 전략).
     *
     * @param  array  $parentSeo  부모 SEO 설정
     * @param  array  $childSeo  자식 SEO 설정
     * @return array 병합된 SEO 설정
     */
    private function mergeSeo(array $parentSeo, array $childSeo): array
    {
        // data_sources는 숫자 인덱스 배열 → 합집합 병합 (인덱스 교체 방지)
        $parentDs = $parentSeo['data_sources'] ?? [];
        $childDs = $childSeo['data_sources'] ?? [];
        unset($parentSeo['data_sources'], $childSeo['data_sources']);

        // 연관 키는 deep merge (og, vars, structured_data 등)
        $merged = array_replace_recursive($parentSeo, $childSeo);

        // data_sources 합집합 (중복 제거)
        $mergedDs = array_values(array_unique(array_merge($parentDs, $childDs)));
        if (! empty($mergedDs)) {
            $merged['data_sources'] = $mergedDs;
        }

        return $merged;
    }

    /**
     * data_sources 병합 - ID 중복 체크 후 부모와 자식 모두 포함
     *
     * @throws \Exception ID 중복 시
     */
    private function mergeDataSources(array $parentDataSources, array $childDataSources): array
    {
        $merged = $parentDataSources;
        $existingIds = array_column($parentDataSources, 'id');

        foreach ($childDataSources as $childDataSource) {
            if (in_array($childDataSource['id'], $existingIds, true)) {
                throw new \Exception(
                    __('exceptions.layout.duplicate_data_source_id', ['id' => $childDataSource['id']])
                );
            }

            $merged[] = $childDataSource;
            $existingIds[] = $childDataSource['id'];
        }

        return $merged;
    }

    /**
     * components 병합 - 부모의 slot 속성을 자식 slots 데이터로 교체
     */
    private function mergeComponents(array $parentComponents, array $childSlots): array
    {
        return $this->replaceSlots($parentComponents, $childSlots);
    }

    /**
     * modals 병합 - ID 기반으로 자식이 부모를 오버라이드
     *
     * @param  array  $parentModals  부모 레이아웃의 modals 배열
     * @param  array  $childModals  자식 레이아웃의 modals 배열
     * @return array 병합된 modals 배열
     */
    private function mergeModals(array $parentModals, array $childModals): array
    {
        // ID를 키로 하는 맵 생성 (부모 먼저)
        $modalsById = [];
        foreach ($parentModals as $modal) {
            if (isset($modal['id'])) {
                $modalsById[$modal['id']] = $modal;
            }
        }

        // 자식 모달로 오버라이드 또는 추가
        foreach ($childModals as $modal) {
            if (isset($modal['id'])) {
                $modalsById[$modal['id']] = $modal;
            }
        }

        return array_values($modalsById);
    }

    /**
     * init_actions 병합 - 부모 액션 먼저, 자식 액션 나중에 실행
     *
     * @param  array  $parentActions  부모 레이아웃의 init_actions 배열
     * @param  array  $childActions  자식 레이아웃의 init_actions 배열
     * @return array 병합된 init_actions 배열
     */
    private function mergeInitActions(array $parentActions, array $childActions): array
    {
        return array_merge($parentActions, $childActions);
    }

    /**
     * defines 병합 - 자식 값이 부모를 오버라이드
     *
     * 정적 상수 정의를 병합합니다.
     * 동일한 키가 있으면 자식 레이아웃의 값이 우선합니다.
     *
     * @param  array  $parentDefines  부모 레이아웃의 defines 객체
     * @param  array  $childDefines  자식 레이아웃의 defines 객체
     * @return array 병합된 defines 객체
     */
    private function mergeDefines(array $parentDefines, array $childDefines): array
    {
        // 자식 값이 부모를 오버라이드 (array_merge는 동일 키 시 뒤의 값이 우선)
        return array_merge($parentDefines, $childDefines);
    }

    /**
     * computed 병합 - 자식 값이 부모를 오버라이드
     *
     * 파생 상태 표현식을 병합합니다.
     * 동일한 키가 있으면 자식 레이아웃의 표현식이 우선합니다.
     *
     * @param  array  $parentComputed  부모 레이아웃의 computed 객체
     * @param  array  $childComputed  자식 레이아웃의 computed 객체
     * @return array 병합된 computed 객체
     */
    private function mergeComputed(array $parentComputed, array $childComputed): array
    {
        // 자식 값이 부모를 오버라이드 (array_merge는 동일 키 시 뒤의 값이 우선)
        return array_merge($parentComputed, $childComputed);
    }

    /**
     * named_actions 병합 - 자식 값이 부모를 오버라이드
     *
     * 재사용 가능한 액션 정의를 병합합니다. 동일한 키가 있으면 자식 값이 우선합니다.
     *
     * @param  array  $parentNamedActions  부모 레이아웃의 named_actions 객체
     * @param  array  $childNamedActions  자식 레이아웃의 named_actions 객체
     * @return array 병합된 named_actions 객체
     */
    private function mergeNamedActions(array $parentNamedActions, array $childNamedActions): array
    {
        // 자식 값이 부모를 오버라이드 (array_merge는 동일 키 시 뒤의 값이 우선)
        return array_merge($parentNamedActions, $childNamedActions);
    }

    /**
     * 얕은 병합 (Shallow Merge) - 자식 값이 부모를 오버라이드
     *
     * 1단계 키만 병합합니다. 동일한 키가 있으면 자식 값이 우선합니다.
     * initLocal, initGlobal, initIsolated 등 상태 초기값 병합에 사용됩니다.
     *
     * @param  array  $parent  부모 레이아웃의 객체
     * @param  array  $child  자식 레이아웃의 객체
     * @return array 병합된 객체
     */
    private function mergeShallow(array $parent, array $child): array
    {
        // 자식 값이 부모를 오버라이드 (array_merge는 동일 키 시 뒤의 값이 우선)
        return array_merge($parent, $child);
    }

    /**
     * scripts 병합 - ID 기반으로 자식이 부모를 오버라이드
     *
     * 외부 스크립트 목록을 병합합니다.
     * 동일한 ID가 있으면 자식 레이아웃의 스크립트가 우선합니다.
     *
     * @param  array  $parentScripts  부모 레이아웃의 scripts 배열
     * @param  array  $childScripts  자식 레이아웃의 scripts 배열
     * @return array 병합된 scripts 배열
     */
    private function mergeScripts(array $parentScripts, array $childScripts): array
    {
        // ID를 키로 하는 맵 생성 (부모 먼저)
        $scriptsById = [];
        foreach ($parentScripts as $script) {
            if (isset($script['id'])) {
                $scriptsById[$script['id']] = $script;
            }
        }

        // 자식 스크립트로 오버라이드 또는 추가
        foreach ($childScripts as $script) {
            if (isset($script['id'])) {
                $scriptsById[$script['id']] = $script;
            }
        }

        return array_values($scriptsById);
    }

    /**
     * permissions 병합 - 중복 제거하여 합집합 반환
     *
     * 부모와 자식 레이아웃의 권한을 병합합니다.
     * 두 배열을 합치고 중복을 제거합니다.
     *
     * @param  array  $parentPermissions  부모 레이아웃의 permissions 배열
     * @param  array  $childPermissions  자식 레이아웃의 permissions 배열
     * @return array 병합된 permissions 배열
     */
    private function mergePermissions(array $parentPermissions, array $childPermissions): array
    {
        $parentIsFlat = empty($parentPermissions) || array_is_list($parentPermissions);
        $childIsFlat = empty($childPermissions) || array_is_list($childPermissions);

        // 둘 다 flat array → 기존 합집합 (하위 호환)
        if ($parentIsFlat && $childIsFlat) {
            return array_values(array_unique(array_merge($parentPermissions, $childPermissions)));
        }

        // 구조화 포함 시 AND 결합
        return ['and' => [$parentPermissions, $childPermissions]];
    }

    /**
     * globalHeaders 병합 - pattern 기준으로 자식이 부모를 오버라이드
     *
     * 부모와 자식 레이아웃의 globalHeaders를 병합합니다.
     * 동일한 pattern에 대해서는 headers를 병합하며, 자식의 헤더가 우선합니다.
     *
     * @param  array  $parentHeaders  부모 레이아웃의 globalHeaders 배열
     * @param  array  $childHeaders  자식 레이아웃의 globalHeaders 배열
     * @return array 병합된 globalHeaders 배열
     */
    private function mergeGlobalHeaders(array $parentHeaders, array $childHeaders): array
    {
        // pattern을 키로 하는 맵 생성
        $merged = [];

        // 부모 헤더 먼저 추가
        foreach ($parentHeaders as $rule) {
            $pattern = $rule['pattern'] ?? '*';
            $merged[$pattern] = $rule;
        }

        // 자식 헤더로 덮어쓰기 (동일 pattern은 headers 병합)
        foreach ($childHeaders as $rule) {
            $pattern = $rule['pattern'] ?? '*';
            if (isset($merged[$pattern])) {
                // 동일 pattern: headers 병합 (자식 우선)
                $merged[$pattern]['headers'] = array_merge(
                    $merged[$pattern]['headers'] ?? [],
                    $rule['headers'] ?? []
                );
            } else {
                $merged[$pattern] = $rule;
            }
        }

        return array_values($merged);
    }

    /**
     * 컴포넌트 트리를 재귀적으로 탐색하여 slot 교체
     *
     * slot 속성이 있는 컴포넌트를 찾아서:
     * - 해당 slot에 대한 데이터가 있으면 슬롯 컴포넌트의 children에 슬롯 내용 삽입
     *   (슬롯 래퍼 컴포넌트의 id, name, props 등은 유지됨)
     * - 해당 slot에 대한 데이터가 없으면 slot 속성 유지 (다음 상속에서 사용)
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $slots  슬롯 데이터 (슬롯명 => 컴포넌트 배열)
     * @return array 슬롯이 교체된 컴포넌트 배열
     */
    private function replaceSlots(array $components, array $slots): array
    {
        $result = [];

        foreach ($components as $component) {
            // slot 속성이 있고 해당 slot 데이터가 존재하면 children에 삽입
            if (isset($component['slot']) && isset($slots[$component['slot']])) {
                // 슬롯 래퍼 컴포넌트 복사 (id, name, props 등 유지)
                $resultComponent = $component;

                // 슬롯 래퍼는 _fromBase 미마킹 — 페이지 콘텐츠 진입점이므로
                // remount되어야 localDynamicState가 초기화됨
                // @since engine-v1.24.8

                // slot 속성 제거 (병합 완료)
                unset($resultComponent['slot']);

                // 슬롯 내용을 children에 삽입 (슬롯 children은 _fromBase 미마킹 → remount 보장)
                $slotComponents = $slots[$component['slot']];

                // 배열인지 확인 (단일 컴포넌트 vs 복수 컴포넌트)
                if (isset($slotComponents[0]) && is_array($slotComponents[0])) {
                    // 복수 컴포넌트 - children 배열로 설정
                    $resultComponent['children'] = $slotComponents;
                } else {
                    // 단일 컴포넌트 - 배열로 감싸서 children에 설정
                    $resultComponent['children'] = [$slotComponents];
                }

                $result[] = $resultComponent;
            } else {
                // slot이 없거나 교체할 데이터가 없으면 그대로 유지 (base 컴포넌트)
                $resultComponent = $component;

                // base 컴포넌트 마킹 — extends 사용 시 base에서 온 컴포넌트 식별
                // @since engine-v1.24.8
                $resultComponent['_fromBase'] = true;

                // children이 있으면 재귀적으로 처리
                if (isset($component['children']) && is_array($component['children'])) {
                    $resultComponent['children'] = $this->replaceSlots($component['children'], $slots);
                }

                $result[] = $resultComponent;
            }
        }

        return $result;
    }

    /**
     * 병합 결과에서 불필요한 필드 제거 (extends, slots)
     *
     * 주의: slot 속성은 replaceSlots에서 사용된 것만 제거되므로
     * 여기서는 사용되지 않은 slot을 유지합니다 (다음 상속에서 사용될 수 있음)
     */
    private function removeUnnecessaryFields(array $layout): array
    {
        // 최상위 레벨에서 제거 (extends, slots만 - slot은 유지)
        unset($layout['extends'], $layout['slots']);

        return $layout;
    }

    /**
     * 레이아웃을 로드하고 상속 구조를 병합합니다. (캐싱 적용)
     *
     * @throws CircularReferenceException 순환 참조 감지 시
     * @throws \Exception 최대 깊이 초과 시
     */
    public function loadAndMergeLayout(int $templateId, string $layoutName): array
    {
        // Before 훅 - 로드 전
        HookManager::doAction('core.layout.before_load', $templateId, $layoutName);

        $cacheEnabled = (bool) g7_core_settings('cache.layout_enabled', true);

        // 캐시 비활성 시 매번 병합 실행
        if (! $cacheEnabled) {
            $merged = $this->loadAndMergeLayoutInternal($templateId, $layoutName);
            HookManager::doAction('core.layout.after_load', $merged, $templateId, $layoutName, false);

            return $merged;
        }

        $cacheKey = $this->getMergedLayoutCacheKey($templateId, $layoutName);

        // 캐시에서 시도
        $cached = $this->cache->get($cacheKey);

        if ($cached !== null) {
            $this->cacheStats['hits']++;

            Log::debug('레이아웃 캐시 히트', [
                'template_id' => $templateId,
                'layout_name' => $layoutName,
                'cache_key' => $cacheKey,
            ]);

            // After 훅 - 캐시에서 로드 완료
            HookManager::doAction('core.layout.after_load', $cached, $templateId, $layoutName, true);

            return $cached;
        }

        // 캐시 미스
        $this->cacheStats['misses']++;

        Log::debug('레이아웃 캐시 미스', [
            'template_id' => $templateId,
            'layout_name' => $layoutName,
            'cache_key' => $cacheKey,
        ]);

        // 병합된 레이아웃 생성
        $mergedLayout = $this->loadAndMergeLayoutInternal($templateId, $layoutName);

        // 캐시에 저장
        $cacheTtl = $this->getCacheTtl();
        $this->cache->put($cacheKey, $mergedLayout, $cacheTtl);

        Log::info('레이아웃 병합 및 캐싱 완료', [
            'template_id' => $templateId,
            'layout_name' => $layoutName,
            'cache_key' => $cacheKey,
            'ttl' => $cacheTtl,
        ]);

        // After 훅 - DB에서 로드 및 병합 완료
        HookManager::doAction('core.layout.after_load', $mergedLayout, $templateId, $layoutName, false);

        return $mergedLayout;
    }

    /**
     * 레이아웃을 로드하고 상속 구조를 병합합니다. (내부 메서드, 캐싱 없음)
     *
     * @throws CircularReferenceException 순환 참조 감지 시
     * @throws \Exception 최대 깊이 초과 시
     */
    private function loadAndMergeLayoutInternal(int $templateId, string $layoutName): array
    {
        // 1. 순환 참조 감지
        if (in_array($layoutName, $this->loadStack, true)) {
            Log::error('레이아웃 순환 참조 감지', [
                'stack' => $this->loadStack,
                'current' => $layoutName,
            ]);

            throw new CircularReferenceException($this->loadStack, $layoutName);
        }

        // 2. 스택 깊이 제한 검증
        $maxDepth = $this->getMaxDepth();
        if (count($this->loadStack) >= $maxDepth) {
            Log::error('레이아웃 중첩 깊이 초과', [
                'stack' => $this->loadStack,
                'current' => $layoutName,
                'max_depth' => $maxDepth,
            ]);

            throw new \Exception(
                __('exceptions.max_depth_exceeded', ['max' => $maxDepth])
            );
        }

        // 3. 현재 레이아웃을 스택에 추가
        $this->loadStack[] = $layoutName;

        try {
            // 4. 레이아웃 데이터 로드 (LayoutResolver를 통해 우선순위 적용)
            $layout = $this->resolveLayout($templateId, $layoutName);

            if (! $layout) {
                throw new ModelNotFoundException(
                    "Layout not found: template_id={$templateId}, name={$layoutName}"
                );
            }

            $layoutData = $layout->content;

            // 5. 상속 처리 (extends가 있는 경우)
            if (isset($layoutData['extends'])) {
                $parentLayoutName = $layoutData['extends'];

                // 부모 레이아웃 존재 여부 먼저 확인
                $parentExists = $this->resolveLayout($templateId, $parentLayoutName);

                if (! $parentExists) {
                    Log::error('부모 레이아웃을 찾을 수 없음', [
                        'template_id' => $templateId,
                        'parent_layout' => $parentLayoutName,
                        'child_layout' => $layoutName,
                    ]);

                    throw new ModelNotFoundException(
                        __('exceptions.layout.parent_not_found', [
                            'parent' => $parentLayoutName,
                            'child' => $layoutName,
                        ])
                    );
                }

                // 부모 레이아웃 재귀적으로 로드 및 병합 (캐싱 버전 호출)
                $parentLayout = $this->loadAndMergeLayout($templateId, $parentLayoutName);

                // 부모와 자식 병합 (기존 mergeLayouts 메서드 재사용)
                $mergedLayout = $this->mergeLayouts($parentLayout, $layoutData);
            } else {
                // 상속이 없으면 현재 레이아웃 그대로 반환
                $mergedLayout = $layoutData;
            }

            return $mergedLayout;
        } finally {
            // 6. 스택에서 현재 레이아웃 제거 (finally로 예외 발생 시에도 실행 보장)
            array_pop($this->loadStack);
        }
    }

    /**
     * 레이아웃을 해석하여 실제 로드할 레이아웃 반환
     *
     * 모듈 레이아웃 이름 패턴인 경우 LayoutResolverService를 통해
     * 오버라이드 여부를 확인하고 적절한 레이아웃을 반환합니다.
     * 일반 레이아웃은 기존 방식대로 처리합니다.
     */
    private function resolveLayout(int $templateId, string $layoutName): ?TemplateLayout
    {
        // 모듈 레이아웃 이름 패턴 확인 (vendor-module_path_path 형식)
        // 예: sirsoft-ecommerce_admin_products_index
        if ($this->isModuleLayoutName($layoutName)) {
            // LayoutResolverService를 통해 우선순위 적용 (오버라이드 > 모듈 기본)
            $resolved = $this->layoutResolverService->resolve($layoutName, $templateId);

            if ($resolved) {
                Log::debug('레이아웃 해석 완료', [
                    'template_id' => $templateId,
                    'layout_name' => $layoutName,
                    'resolved_id' => $resolved->id,
                    'source_type' => $resolved->source_type?->value,
                ]);

                return $resolved;
            }
        }

        // 일반 레이아웃은 기존 방식으로 조회
        return $this->layoutRepository->findByName($templateId, $layoutName);
    }

    /**
     * 모듈 레이아웃 이름 패턴인지 확인
     *
     * 모듈 레이아웃 이름은 vendor-module.path 또는 vendor-module_path 형식입니다.
     * 예: sirsoft-ecommerce.admin_products_index (DOT 포맷)
     *     sirsoft-ecommerce_admin_products_index (UNDERSCORE 포맷, 하위 호환)
     */
    private function isModuleLayoutName(string $layoutName): bool
    {
        // 모듈 레이아웃 이름 패턴: vendor-module.path 또는 vendor-module_path
        // - vendor와 module 사이에 하이픈(-)
        // - module과 path 사이에 DOT(.) 또는 UNDERSCORE(_)
        // 예: sirsoft-ecommerce.admin_products_index (DOT - 표준 포맷)
        //     sirsoft-ecommerce_admin_products_index (UNDERSCORE - 하위 호환)
        return (bool) preg_match('/^[a-z0-9]+-[a-z0-9]+[._]/', $layoutName);
    }

    /**
     * 병합된 레이아웃 캐시 키 생성
     *
     * 모듈 레이아웃의 경우 소스 해시를 포함하여 오버라이드 정보를 반영합니다.
     */
    private function getMergedLayoutCacheKey(int $templateId, string $layoutName): string
    {
        // 모듈 레이아웃인 경우 소스 정보를 캐시 키에 포함
        if ($this->isModuleLayoutName($layoutName)) {
            // 해석 결과를 기반으로 소스 해시 생성
            $resolved = $this->layoutResolverService->resolve($layoutName, $templateId);

            if ($resolved) {
                $sourceHash = md5($resolved->source_type?->value.$resolved->source_identifier);

                return "template.{$templateId}.layout.{$layoutName}.{$sourceHash}";
            }
        }

        return "template.{$templateId}.layout.{$layoutName}";
    }

    /**
     * 특정 레이아웃의 캐시를 무효화합니다.
     */
    public function clearLayoutCache(int $templateId, string $layoutName): void
    {
        // Before 훅 - 캐시 무효화 전
        HookManager::doAction('core.layout.before_cache_clear', $templateId, $layoutName);

        $cacheKey = $this->getMergedLayoutCacheKey($templateId, $layoutName);
        $this->cache->forget($cacheKey);

        // PublicLayoutController 서빙 캐시도 무효화
        // PublicLayoutController::serve()에서 "layout.{identifier}.{name}.v{version}" 키로 별도 캐싱
        $this->clearPublicServingCache($templateId, $layoutName);

        // 모듈 레이아웃인 경우 LayoutResolver 캐시도 무효화
        if ($this->isModuleLayoutName($layoutName)) {
            $this->layoutResolverService->clearResolutionCache($layoutName, $templateId);
        }

        Log::info('레이아웃 캐시 무효화', [
            'template_id' => $templateId,
            'layout_name' => $layoutName,
            'cache_key' => $cacheKey,
        ]);

        // After 훅 - 캐시 무효화 후
        HookManager::doAction('core.layout.after_cache_clear', $templateId, $layoutName, $cacheKey);
    }

    /**
     * PublicLayoutController의 서빙 캐시를 무효화합니다.
     *
     * PublicLayoutController::serve()는 "layout.{identifier}.{name}.v{version}" 키로
     * 별도 캐싱하므로, 레이아웃 수정 시 이 캐시도 함께 삭제해야 합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  레이아웃 이름
     */
    private function clearPublicServingCache(int $templateId, string $layoutName): void
    {
        $template = $this->templateRepository->findById($templateId);

        if (! $template) {
            return;
        }

        $identifier = $template->identifier;
        $cacheVersion = (int) $this->cache->get('ext.cache_version', 0);
        $this->cache->forget("layout.{$identifier}.{$layoutName}.v{$cacheVersion}");
    }

    /**
     * 특정 레이아웃을 extends하는 모든 자식 레이아웃의 캐시를 재귀적으로 무효화합니다.
     */
    public function clearDependentLayoutsCache(int $templateId, string $layoutName): void
    {
        // Before 훅 - 재귀적 캐시 무효화 전
        HookManager::doAction('core.layout.before_dependent_cache_clear', $templateId, $layoutName);

        // 1. 현재 레이아웃 캐시 삭제
        $this->clearLayoutCache($templateId, $layoutName);

        // 2. 이 레이아웃을 extends하는 자식 레이아웃 찾기
        $children = $this->layoutRepository->getChildrenByExtends($templateId, $layoutName);

        // 3. 자식 레이아웃들의 캐시 재귀적으로 삭제
        foreach ($children as $child) {
            $this->clearDependentLayoutsCache($templateId, $child->name);
        }

        if ($children->isNotEmpty()) {
            Log::info('자식 레이아웃 캐시 재귀적 무효화', [
                'template_id' => $templateId,
                'parent_layout' => $layoutName,
                'children_count' => $children->count(),
            ]);
        }

        // After 훅 - 재귀적 캐시 무효화 후
        HookManager::doAction('core.layout.after_dependent_cache_clear', $templateId, $layoutName, $children->count());
    }

    /**
     * 캐시 히트율 통계 조회
     *
     * @return array{hits: int, misses: int, total: int, hit_rate: float}
     */
    public function getCacheStats(): array
    {
        $total = $this->cacheStats['hits'] + $this->cacheStats['misses'];
        $hitRate = $total > 0 ? round(($this->cacheStats['hits'] / $total) * 100, 2) : 0.0;

        return [
            'hits' => $this->cacheStats['hits'],
            'misses' => $this->cacheStats['misses'],
            'total' => $total,
            'hit_rate' => $hitRate,
        ];
    }

    /**
     * 캐시 통계 초기화
     */
    public function resetCacheStats(): void
    {
        $this->cacheStats = [
            'hits' => 0,
            'misses' => 0,
        ];
    }

    /**
     * 템플릿 identifier와 레이아웃 이름으로 병합된 레이아웃 조회
     *
     * @throws ModelNotFoundException 템플릿을 찾을 수 없거나 비활성화된 경우
     */
    public function getLayout(string $templateIdentifier, string $layoutName): array
    {
        // Before 훅 - 레이아웃 조회 전
        HookManager::doAction('core.layout.before_get', $templateIdentifier, $layoutName);

        // 템플릿 조회
        $template = $this->templateRepository->findByIdentifier($templateIdentifier);

        if (! $template) {
            throw new ModelNotFoundException(
                __('exceptions.template_not_found', ['identifier' => $templateIdentifier])
            );
        }

        // 활성화된 템플릿만 허용
        if ($template->status !== ExtensionStatus::Active->value) {
            throw new ModelNotFoundException(
                __('exceptions.template_not_active', [
                    'identifier' => $templateIdentifier,
                    'status' => $template->status,
                ])
            );
        }

        // 레이아웃 로드 및 병합 (캐싱 포함)
        $layout = $this->loadAndMergeLayout($template->id, $layoutName);

        // 모듈/플러그인 Extension 적용 (Overlay, Extension Point)
        $layout = $this->layoutExtensionService->applyExtensions($layout, $template->id);

        // After 훅 - 레이아웃 조회 후
        HookManager::doAction('core.layout.after_get', $layout, $templateIdentifier, $layoutName, $template);

        return $layout;
    }

    /**
     * 특정 템플릿의 모든 레이아웃 조회
     */
    public function getLayoutsByTemplateId(int $templateId)
    {
        // Before 훅 - 레이아웃 목록 조회 전
        HookManager::doAction('core.layout.before_index', $templateId);

        $layouts = $this->layoutRepository->getByTemplateId($templateId);

        // After 훅 - 레이아웃 목록 조회 후
        HookManager::doAction('core.layout.after_index', $layouts, $templateId);

        return $layouts;
    }

    /**
     * 특정 레이아웃 조회 (이름으로)
     */
    public function getLayoutByName(int $templateId, string $name): ?TemplateLayout
    {
        // Before 훅 - 레이아웃 조회 전
        HookManager::doAction('core.layout.before_show', $templateId, $name);

        $layout = $this->layoutRepository->findByName($templateId, $name);

        // After 훅 - 레이아웃 조회 후
        HookManager::doAction('core.layout.after_show', $layout, $templateId, $name);

        return $layout;
    }

    /**
     * 레이아웃 업데이트
     *
     * @throws ModelNotFoundException 레이아웃을 찾을 수 없을 때
     */
    public function updateLayout(int $templateId, string $name, array $data): TemplateLayout
    {
        // Before 훅 - 레이아웃 업데이트 전
        HookManager::doAction('core.layout.before_update', $templateId, $name, $data);

        // 필터 훅 - 데이터 변형
        $data = HookManager::applyFilters('core.layout.filter_update_data', $data, $templateId, $name);

        // 레이아웃 조회
        $layout = $this->layoutRepository->findByName($templateId, $name);

        if (! $layout) {
            throw new ModelNotFoundException(
                "Layout not found: template_id={$templateId}, name={$name}"
            );
        }

        // content 키가 있으면 추출 (UpdateLayoutContentRequest 사용 시)
        $updateData = $data['content'] ?? $data;

        // 버전 저장 (user 템플릿인 경우에만)
        $template = $layout->template;
        $oldContent = $layout->content;

        // 레이아웃 업데이트
        $layout = $this->layoutRepository->update($layout->id, $updateData);

        // user 템플릿인 경우 버전 히스토리 저장 (이전 버전 + 현재 저장 버전)
        if ($template->type === 'user') {
            // 1. 이전 버전 저장 (롤백용)
            $this->versionRepository->saveVersion($layout->id, $oldContent, $updateData);

            // 2. 현재 저장 버전 저장 (최신 상태 기록)
            $this->versionRepository->saveVersion($layout->id, $updateData, $updateData);
        }

        // 캐시 무효화
        $this->clearDependentLayoutsCache($templateId, $name);

        // 프론트엔드 브라우저 캐시 무효화를 위해 ext.cache_version 증가
        // PublicLayoutController가 ?v={version} 기반 HTTP 캐시를 사용하므로
        // 버전 변경 시 브라우저가 새 URL로 인식하여 캐시를 우회합니다.
        $this->cache->put('ext.cache_version', time());

        // After 훅 - 레이아웃 업데이트 후
        HookManager::doAction('core.layout.after_update', $layout, $templateId, $name, $data);

        return $layout;
    }

    /**
     * 특정 레이아웃의 모든 버전 조회
     */
    public function getLayoutVersions(int $templateId, string $name)
    {
        // Before 훅 - 버전 목록 조회 전
        HookManager::doAction('core.layout.before_versions_index', $templateId, $name);

        // 레이아웃 조회
        $layout = $this->layoutRepository->findByName($templateId, $name);

        if (! $layout) {
            throw new ModelNotFoundException(
                "Layout not found: template_id={$templateId}, name={$name}"
            );
        }

        // 버전 목록 조회
        $versions = $this->layoutRepository->getVersionsByLayoutId($layout->id);

        // After 훅 - 버전 목록 조회 후
        HookManager::doAction('core.layout.after_versions_index', $versions, $templateId, $name);

        return $versions;
    }

    /**
     * 특정 버전의 레이아웃 조회
     */
    public function getLayoutVersion(int $templateId, string $name, int $version)
    {
        // Before 훅 - 버전 조회 전
        HookManager::doAction('core.layout.before_version_show', $templateId, $name, $version);

        // 레이아웃 조회
        $layout = $this->layoutRepository->findByName($templateId, $name);

        if (! $layout) {
            throw new ModelNotFoundException(
                "Layout not found: template_id={$templateId}, name={$name}"
            );
        }

        // 특정 버전 조회
        $layoutVersion = $this->layoutRepository->findVersionByNumber($layout->id, $version);

        if (! $layoutVersion) {
            throw new ModelNotFoundException(
                "Layout version not found: layout_id={$layout->id}, version={$version}"
            );
        }

        // After 훅 - 버전 조회 후
        HookManager::doAction('core.layout.after_version_show', $layoutVersion, $templateId, $name, $version);

        return $layoutVersion;
    }

    /**
     * 버전 복원
     *
     * @throws ModelNotFoundException 레이아웃 또는 버전을 찾을 수 없을 때
     */
    public function restoreVersion(int $templateId, string $name, int $versionId)
    {
        // Before 훅 - 버전 복원 전
        HookManager::doAction('core.layout.before_version_restore', $templateId, $name, $versionId);

        // 레이아웃 조회
        $layout = $this->layoutRepository->findByName($templateId, $name);

        if (! $layout) {
            throw new ModelNotFoundException(
                "Layout not found: template_id={$templateId}, name={$name}"
            );
        }

        // 버전 복원 (트랜잭션으로 처리됨)
        $newVersion = $this->versionRepository->restoreVersion($layout->id, $versionId);

        // 캐시 무효화
        $this->clearDependentLayoutsCache($templateId, $name);

        // 프론트엔드 브라우저 캐시 무효화
        $this->cache->put('ext.cache_version', time());

        // After 훅 - 버전 복원 후
        HookManager::doAction('core.layout.after_version_restore', $newVersion, $templateId, $name, $versionId);

        return $newVersion;
    }

    /**
     * 레이아웃 JSON에서 XSS 및 악의적인 코드를 제거합니다.
     */
    public function sanitizeLayoutJson(array $layout): array
    {
        // Before 훅 - sanitize 전
        HookManager::doAction('core.layout.before_sanitize', $layout);

        // 1. components 배열의 각 컴포넌트를 재귀적으로 sanitize
        if (isset($layout['components']) && is_array($layout['components'])) {
            $layout['components'] = $this->sanitizeComponents($layout['components']);
        }

        // 2. data_sources의 endpoint를 sanitize
        if (isset($layout['data_sources']) && is_array($layout['data_sources'])) {
            $layout['data_sources'] = $this->sanitizeDataSources($layout['data_sources']);
        }

        // After 훅 - sanitize 후
        HookManager::doAction('core.layout.after_sanitize', $layout);

        return $layout;
    }

    /**
     * 컴포넌트 배열을 재귀적으로 sanitize합니다.
     */
    private function sanitizeComponents(array $components): array
    {
        return array_map(function ($component) {
            if (! is_array($component)) {
                return $component;
            }

            // props를 sanitize
            if (isset($component['props']) && is_array($component['props'])) {
                $component['props'] = $this->sanitizeProps($component['props']);
            }

            // children이 있으면 재귀적으로 sanitize
            if (isset($component['children']) && is_array($component['children'])) {
                $component['children'] = $this->sanitizeComponents($component['children']);
            }

            return $component;
        }, $components);
    }

    /**
     * 컴포넌트의 props를 sanitize합니다.
     *
     * @param  array  $props  컴포넌트 속성 배열
     * @return array sanitized props
     */
    private function sanitizeProps(array $props): array
    {
        $sanitized = [];

        foreach ($props as $key => $value) {
            // 배열인 경우 재귀 처리
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProps($value);

                continue;
            }

            // 문자열인 경우 sanitize
            if (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value);

                continue;
            }

            // 그 외 타입은 그대로 유지
            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * 문자열에서 위험한 패턴을 제거합니다.
     */
    private function sanitizeString(string $value): string
    {
        // 1. script, iframe, object, embed 태그 제거
        $value = preg_replace('/<\s*(script|iframe|object|embed)[^>]*>.*?<\/\s*\1\s*>/is', '', $value);

        // 2. 인라인 이벤트 핸들러 제거 (onclick, onerror, onload 등)
        $value = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $value);

        // 3. javascript: 프로토콜 제거
        $value = preg_replace('/javascript\s*:/i', '', $value);

        // 4. data: 프로토콜 제거 (base64 인코딩된 악성 코드 방지)
        $value = preg_replace('/data\s*:/i', '', $value);

        // 5. vbscript: 프로토콜 제거
        $value = preg_replace('/vbscript\s*:/i', '', $value);

        // 6. HTML entities 변환 (htmlspecialchars)
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);

        return $value;
    }

    /**
     * data_sources 배열을 sanitize합니다.
     */
    private function sanitizeDataSources(array $dataSources): array
    {
        return array_map(function ($dataSource) {
            if (! is_array($dataSource)) {
                return $dataSource;
            }

            // endpoint가 있으면 sanitize
            if (isset($dataSource['endpoint']) && is_string($dataSource['endpoint'])) {
                $dataSource['endpoint'] = $this->sanitizeUrl($dataSource['endpoint']);
            }

            return $dataSource;
        }, $dataSources);
    }

    /**
     * URL을 sanitize합니다. (안전한 프로토콜만 허용)
     */
    private function sanitizeUrl(string $url): string
    {
        // 1. 안전한 프로토콜 화이트리스트 (http, https만 허용)
        if (! preg_match('/^https?:\/\//i', $url)) {
            // 상대 경로는 그대로 허용
            if (! str_starts_with($url, '/')) {
                return '';
            }
        }

        // 2. javascript:, data:, vbscript: 프로토콜 차단
        if (preg_match('/^(javascript|data|vbscript)\s*:/i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * 사용자 권한에 따라 레이아웃의 컴포넌트를 필터링합니다.
     *
     * 각 컴포넌트의 permissions 배열을 확인하여, 사용자가 모든 권한을 보유하지 않으면
     * 해당 컴포넌트(및 하위 children)를 제거합니다. (AND 조건)
     * 필터링 후 permissions 속성 자체도 제거하여 클라이언트에 노출되지 않도록 합니다.
     *
     * @param  array  $layout  병합된 레이아웃 데이터
     * @param  User|null  $user  현재 사용자 (null이면 guest)
     * @return array 필터링된 레이아웃 데이터
     */
    public function filterComponentsByPermissions(array $layout, ?User $user = null): array
    {
        // components 필터링
        if (! empty($layout['components'])) {
            $layout['components'] = $this->filterComponentTree($layout['components'], $user);
        }

        // modals 필터링
        if (! empty($layout['modals'])) {
            $layout['modals'] = $this->filterModals($layout['modals'], $user);
        }

        // defines 필터링
        if (! empty($layout['defines'])) {
            $layout['defines'] = $this->filterDefines($layout['defines'], $user);
        }

        return $layout;
    }

    /**
     * 컴포넌트 트리를 재귀적으로 필터링합니다.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  User|null  $user  현재 사용자
     * @return array 필터링된 컴포넌트 배열
     */
    private function filterComponentTree(array $components, ?User $user): array
    {
        $filtered = [];

        foreach ($components as $component) {
            // permissions 속성 확인
            $permissions = $component['permissions'] ?? [];

            if (! empty($permissions)) {
                // 권한 체크 (AND/OR 구조 지원) — 권한 없으면 컴포넌트 전체 제거
                if (! PermissionHelper::checkWithLogic($permissions, $user)) {
                    continue;
                }

                // 권한 있으면 permissions 속성 제거 (클라이언트 노출 방지)
                unset($component['permissions']);
            }

            // children 재귀 필터링
            if (! empty($component['children'])) {
                $component['children'] = $this->filterComponentTree($component['children'], $user);
            }

            $filtered[] = $component;
        }

        return $filtered;
    }

    /**
     * 모달 배열을 필터링합니다.
     *
     * 모달 자체에 permissions가 있으면 모달 전체 제거.
     * 모달 내부 컴포넌트도 재귀 필터링.
     *
     * @param  array  $modals  모달 배열
     * @param  User|null  $user  현재 사용자
     * @return array 필터링된 모달 배열
     */
    private function filterModals(array $modals, ?User $user): array
    {
        $filtered = [];

        foreach ($modals as $modal) {
            // 모달 자체에 permissions가 있으면 전체 제거 여부 판단
            $permissions = $modal['permissions'] ?? [];

            if (! empty($permissions)) {
                if (! PermissionHelper::checkWithLogic($permissions, $user)) {
                    continue;
                }

                unset($modal['permissions']);
            }

            // 모달 내부 컴포넌트 필터링
            if (! empty($modal['components'])) {
                $modal['components'] = $this->filterComponentTree($modal['components'], $user);
            }

            $filtered[] = $modal;
        }

        return $filtered;
    }

    /**
     * defines(재사용 컴포넌트 정의)를 필터링합니다.
     *
     * @param  array  $defines  defines 배열
     * @param  User|null  $user  현재 사용자
     * @return array 필터링된 defines 배열
     */
    private function filterDefines(array $defines, ?User $user): array
    {
        $filtered = [];

        foreach ($defines as $define) {
            // define 자체에 permissions가 있으면 전체 제거 여부 판단
            $permissions = $define['permissions'] ?? [];

            if (! empty($permissions)) {
                if (! PermissionHelper::checkWithLogic($permissions, $user)) {
                    continue;
                }

                unset($define['permissions']);
            }

            // define 내부 컴포넌트 필터링
            if (! empty($define['components'])) {
                $define['components'] = $this->filterComponentTree($define['components'], $user);
            }

            // define 내부 children 필터링
            if (! empty($define['children'])) {
                $define['children'] = $this->filterComponentTree($define['children'], $user);
            }

            $filtered[] = $define;
        }

        return $filtered;
    }
}
