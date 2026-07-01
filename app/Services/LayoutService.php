<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Enums\LayoutSourceType;
use App\Exceptions\CircularReferenceException;
use App\Exceptions\ConcurrentModificationException;
use App\Exceptions\LayoutIncludeException;
use App\Extension\HookManager;
use App\Helpers\PermissionHelper;
use App\Models\TemplateLayout;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
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
     * @param  array|null  $sourceMeta  ['kind' => 'base', 'layout' => '부모 레이아웃명'] (편집 모드 전용)
     * @return array 부모-자식이 병합된 레이아웃 데이터
     *
     * @throws \Exception 병합 중 오류 발생 시
     *
     * @since engine-v1.50.0 `$sourceMeta` 옵션 추가 — 편집 모드 출처 메타 부여
     */
    public function mergeLayouts(array $parentLayout, array $childLayout, ?array $sourceMeta = null): array
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
            $childLayout['slots'] ?? [],
            $sourceMeta
        );

        // 4. modals 병합 (자식 우선, 부모와 자식 모두 포함)
        $mergedModals = $this->mergeModals(
            $parentLayout['modals'] ?? [],
            $childLayout['modals'] ?? []
        );

        // 5. init_actions/initActions 병합 (부모 먼저, 자식 나중에 실행)
        // initActions와 init_actions 둘 다 지원 (하위 호환)
        // 편집 모드($sourceMeta 비-null)면 항목별 __source 출처 부착.
        $childLayoutName = $childLayout['layout_name'] ?? $childLayout['name'] ?? null;
        $parentInitActions = $parentLayout['initActions'] ?? $parentLayout['init_actions'] ?? [];
        $childInitActions = $childLayout['initActions'] ?? $childLayout['init_actions'] ?? [];
        $mergedInitActions = $this->mergeInitActions(
            $parentInitActions,
            $childInitActions,
            $sourceMeta,
            $childLayoutName,
        );

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
        // 편집 모드($sourceMeta 비-null)면 키별 출처 맵을 레이아웃 최상위에 부착.
        $computedSourceMap = $sourceMeta !== null
            ? $this->buildComputedSourceMap(
                $parentLayout['computed'] ?? [],
                $childLayout['computed'] ?? []
            )
            : [];

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

        // 편집 모드 computed 출처 맵 — 레이아웃 최상위(computed 객체 외부)에 부착.
        // 운영 렌더는 $sourceMeta=null 이라 빈 맵 → 미부착(응답 형식 종전과 동일).
        if (! empty($computedSourceMap)) {
            $result['__computedSource'] = $computedSourceMap;
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
                throw new LayoutIncludeException(
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
     *
     * @param  array|null  $sourceMeta  ['kind' => 'base', 'layout' => '부모 레이아웃명'] 또는 null (편집 모드 전용)
     *
     * @since engine-v1.50.0 `$sourceMeta` 옵션 추가
     */
    private function mergeComponents(array $parentComponents, array $childSlots, ?array $sourceMeta = null): array
    {
        return $this->replaceSlots($parentComponents, $childSlots, $sourceMeta);
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
     * 편집 모드(`$sourceMeta` 비-null)에서는 각 항목에 `__source` 출처 메타를 부착한다
     * 부모 항목 = `$sourceMeta`(kind:'base'), 자식 항목
     * = `['kind'=>'route', 'layout'=>$childLayoutName]`. [화면 동작] 탭이 부모/자식
     * 동작을 출처 배지로 구분하고 부모 항목을 읽기전용으로 표시하는 근거가 된다.
     * dispatch 는 항목에서 화이트리스트 키만 추출(TemplateApp.ts:4014-4029)하므로
     * `__source` 부착은 런타임 무영향이고, 저장 시 `stripInheritedFromLayoutContent` 가
     * `__editor.original` 로 부모분·`__source` 를 제거(기존 경로). 운영 렌더
     * (`$sourceMeta` null)는 부착하지 않아 응답 형식이 종전과 100% 동일하다.
     *
     * @param  array  $parentActions  부모 레이아웃의 init_actions 배열
     * @param  array  $childActions  자식 레이아웃의 init_actions 배열
     * @param  array|null  $sourceMeta  부모(base) 출처 메타 — null 이면 운영 렌더(부착 안 함)
     * @param  string|null  $childLayoutName  자식 레이아웃명 — 자식 항목 `__source.layout` 에 사용
     * @return array 병합된 init_actions 배열
     */
    private function mergeInitActions(
        array $parentActions,
        array $childActions,
        ?array $sourceMeta = null,
        ?string $childLayoutName = null,
    ): array {
        if ($sourceMeta === null) {
            // 운영 렌더 경로 — 종전과 동일(부착 없음).
            return array_merge($parentActions, $childActions);
        }

        $childMeta = ['kind' => 'route', 'layout' => $childLayoutName];

        $stamp = static function (array $items, array $meta): array {
            $out = [];
            foreach ($items as $item) {
                if (is_array($item)) {
                    $item['__source'] = $meta;
                }
                $out[] = $item;
            }

            return $out;
        };

        return array_merge(
            $stamp($parentActions, $sourceMeta),
            $stamp($childActions, $childMeta),
        );
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
     * 파생 상태 표현식을 병합합니다. 동일한 키가 있으면 자식 레이아웃의 표현식이 우선합니다.
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
     * computed 키별 출처 맵을 만든다.
     *
     * 키 출처를 3종으로 구분한다:
     *   - 'base'           : 부모(공통)만 선언 — 〔공통〕배지
     *   - 'route'          : 자식(이 페이지)만 선언 — 무배지
     *   - 'route-override' : 부모+자식 동시 선언(자식이 부모를 덮음) — 〔이 페이지에서 덮음〕
     *                        승격 배지 + 공통값 되돌리기
     *
     * [자동 계산] 탭이 부모/자식 computed 를 배지로 구분하고 덮어쓰기 안내를 표시하는 근거.
     * 'route'(순수 자식)와 'route-override'(덮음)를 구분해야 ComputedForm 이 되돌리기 버튼을
     * 덮은 키에만 노출할 수 있다. 본 맵은 **레이아웃 최상위 `__computedSource`** 에 부착되며
     * (computed 객체 내부 아님), 그래야 calculateComputed/ActionDispatcher/DynamicRenderer 의
     * computed 키 순회가 메타 키를 표현식으로 오평가하지 않는다(편집 모드 한정 부착).
     *
     * @param  array  $parentComputed  부모 computed 객체
     * @param  array  $childComputed  자식 computed 객체
     * @return array<string,string> 키 → 'base'|'route'|'route-override'
     */
    private function buildComputedSourceMap(array $parentComputed, array $childComputed): array
    {
        $map = [];
        foreach (array_keys($parentComputed) as $key) {
            $map[$key] = 'base';
        }
        foreach (array_keys($childComputed) as $key) {
            // 부모에도 있던 키를 자식이 다시 선언 = 덮음(override).
            $map[$key] = isset($map[$key]) ? 'route-override' : 'route';
        }

        return $map;
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
     * $sourceMeta 가 전달되면 각 노드에 `__source` 출처 메타를 부여한다
     * 일반 사이트 렌더는 null 전달 → 메타 미부여.
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $slots  슬롯 데이터 (슬롯명 => 컴포넌트 배열)
     * @param  array|null  $sourceMeta  ['kind' => 'base'|'route', 'layout' => '레이아웃명'] 또는 null
     * @return array 슬롯이 교체된 컴포넌트 배열
     *
     * @since engine-v1.50.0 `$sourceMeta` 옵션 추가
     */
    private function replaceSlots(array $components, array $slots, ?array $sourceMeta = null): array
    {
        $result = [];

        foreach ($components as $component) {
            // 텍스트 노드 등 비-배열 children 은 그대로 보존 (component 가정 불가)
            if (! is_array($component)) {
                $result[] = $component;

                continue;
            }

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

                // 슬롯 래퍼 자체는 base 출처(레이아웃이 정의한 컴포넌트). slot children 은 route 출처
                if ($sourceMeta !== null) {
                    $resultComponent['__source'] = $sourceMeta;
                    $resultComponent['children'] = $this->markSourceMeta(
                        $resultComponent['children'],
                        ['kind' => 'route', 'layout' => $sourceMeta['layout'] ?? '']
                    );
                }

                $result[] = $resultComponent;
            } else {
                // slot이 없거나 교체할 데이터가 없으면 그대로 유지 (base 컴포넌트)
                $resultComponent = $component;

                // base 컴포넌트 마킹 — extends 사용 시 base에서 온 컴포넌트 식별
                // @since engine-v1.24.8
                $resultComponent['_fromBase'] = true;

                if ($sourceMeta !== null) {
                    $resultComponent['__source'] = $sourceMeta;
                }

                // children이 있으면 재귀적으로 처리
                if (isset($component['children']) && is_array($component['children'])) {
                    $resultComponent['children'] = $this->replaceSlots(
                        $component['children'],
                        $slots,
                        $sourceMeta
                    );
                }

                $result[] = $resultComponent;
            }
        }

        return $result;
    }

    /**
     * 컴포넌트 트리에 단일 출처 메타를 재귀적으로 부여합니다.
     *
     * `replaceSlots` 의 slot children(라우트 콘텐츠) 트리 전체에 같은 출처 메타를
     * 부착할 때 사용합니다. 이미 `__source` 가 설정된 노드는 보존(`applyExtensions`
     * 가 부여한 extension 메타 덮어쓰기 방지).
     *
     * @param  array  $components  컴포넌트 배열
     * @param  array  $sourceMeta  부여할 메타 (예: ['kind' => 'route', 'layout' => 'index'])
     * @return array 메타가 부여된 컴포넌트 배열
     *
     * @since engine-v1.50.0
     */
    private function markSourceMeta(array $components, array $sourceMeta): array
    {
        $result = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                $result[] = $component;

                continue;
            }

            $resultComponent = $component;

            // 이미 출처 메타가 있으면 보존 (확장 주입 노드 등)
            if (! isset($resultComponent['__source'])) {
                $resultComponent['__source'] = $sourceMeta;
            }

            if (isset($component['children']) && is_array($component['children'])) {
                $resultComponent['children'] = $this->markSourceMeta($component['children'], $sourceMeta);
            }

            $result[] = $resultComponent;
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
     * `$withSourceMeta` 가 true 면 각 노드에 `__source` 출처 메타를 부여하고,
     * 메타 포함/미포함 결과는 별도 캐시 키로 분리한다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $layoutName  대상 레이아웃 이름
     * @param  bool  $withSourceMeta  편집 모드 출처 메타 부여 여부
     * @return array 부모/자식 상속 병합이 적용된 레이아웃 데이터
     *
     * @throws CircularReferenceException 순환 참조 감지 시
     * @throws \Exception 최대 깊이 초과 시
     *
     * @since engine-v1.50.0 `$withSourceMeta` 옵션 추가
     */
    public function loadAndMergeLayout(int $templateId, string $layoutName, bool $withSourceMeta = false): array
    {
        // Before 훅 - 로드 전
        HookManager::doAction('core.layout.before_load', $templateId, $layoutName);

        $cacheEnabled = (bool) g7_core_settings('cache.layout_enabled', true);

        // 캐시 비활성 시 매번 병합 실행
        if (! $cacheEnabled) {
            $merged = $this->loadAndMergeLayoutInternal($templateId, $layoutName, $withSourceMeta);
            HookManager::doAction('core.layout.after_load', $merged, $templateId, $layoutName, false);

            return $merged;
        }

        $cacheKey = $this->getMergedLayoutCacheKey($templateId, $layoutName, $withSourceMeta);

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
        $mergedLayout = $this->loadAndMergeLayoutInternal($templateId, $layoutName, $withSourceMeta);

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
     *
     * @since engine-v1.50.0 `$withSourceMeta` 옵션 추가
     */
    private function loadAndMergeLayoutInternal(int $templateId, string $layoutName, bool $withSourceMeta = false): array
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

            throw new LayoutIncludeException(
                __('exceptions.max_depth_exceeded', ['max' => $maxDepth]),
                $layoutName,
                $this->loadStack,
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
                $parentLayout = $this->loadAndMergeLayout($templateId, $parentLayoutName, $withSourceMeta);

                // 부모와 자식 병합 (기존 mergeLayouts 메서드 재사용)
                // 편집 모드에서는 base 출처 메타를 부여한다 — 부모 레이아웃에서 온 노드 식별
                $sourceMeta = $withSourceMeta
                    ? ['kind' => 'base', 'layout' => $parentLayoutName]
                    : null;
                $mergedLayout = $this->mergeLayouts($parentLayout, $layoutData, $sourceMeta);

                // 상속 체인 보존 — 자식 서빙 시 base 를 target_layout 으로 하는 overlay
                // (예: 헤더 통화 슬롯 주입)가 매칭되도록 부모 이름을 비-렌더 메타로 남긴다.
                // `LayoutExtensionService::applyExtensions` 가 [layoutName] + __extends_chain 을
                // overlay 매칭 대상에 포함하고, 최종 응답 직전 이 키를 제거하므로 일반/편집 응답
                // 형식은 종전과 동일하다. 부모가 다단계 상속이면 부모 체인을 이어받아 누적한다.
                $parentChain = is_array($parentLayout['__extends_chain'] ?? null)
                    ? $parentLayout['__extends_chain']
                    : [];
                $mergedLayout['__extends_chain'] = array_values(array_unique(
                    array_merge([$parentLayoutName], $parentChain)
                ));

                // 편집 모드 응답에는 자식 원본(`$layoutData`) 을 별도 컨테이너로 보존한다
                // 저장 시 클라이언트가 머지된 트리에서 원본을
                // 추측 복원하지 않고, 본 메타에서 원본 그대로 가져다 PUT 한다.
                if ($withSourceMeta) {
                    $mergedLayout['__editor'] = [
                        'original' => $layoutData,
                    ];
                }
            } else {
                // 상속이 없으면 현재 레이아웃 그대로 반환
                $mergedLayout = $layoutData;

                // extends 가 없는 레이아웃의 컴포넌트는 모두 자기 출처 — 'route' 메타 부여
                // 편집 모드에서만, 일반 사이트 렌더는 메타 미부여
                if ($withSourceMeta && isset($mergedLayout['components']) && is_array($mergedLayout['components'])) {
                    $mergedLayout['components'] = $this->markSourceMeta(
                        $mergedLayout['components'],
                        ['kind' => 'route', 'layout' => $layoutName]
                    );

                    // 편집 모드: 독립 레이아웃도 원본 컨테이너를 보존해 저장 시 SSoT 로 사용.
                    // 합본/원본이 같지만 클라이언트의 저장 경로를 단일화하기 위해 항상 부착.
                    $mergedLayout['__editor'] = [
                        'original' => $layoutData,
                    ];
                }
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
     * `$withSourceMeta` 가 true 면 캐시 키에 `.with_source_meta` 접미사를 붙여
     * 일반 응답 캐시와 분리합니다.
     *
     * @since engine-v1.50.0 `$withSourceMeta` 옵션 추가
     */
    private function getMergedLayoutCacheKey(int $templateId, string $layoutName, bool $withSourceMeta = false): string
    {
        $metaSuffix = $withSourceMeta ? '.with_source_meta' : '';

        // 모듈 레이아웃인 경우 소스 정보를 캐시 키에 포함
        if ($this->isModuleLayoutName($layoutName)) {
            // 해석 결과를 기반으로 소스 해시 생성
            $resolved = $this->layoutResolverService->resolve($layoutName, $templateId);

            if ($resolved) {
                $sourceHash = md5($resolved->source_type?->value.$resolved->source_identifier);

                return "template.{$templateId}.layout.{$layoutName}.{$sourceHash}{$metaSuffix}";
            }
        }

        return "template.{$templateId}.layout.{$layoutName}{$metaSuffix}";
    }

    /**
     * 특정 레이아웃의 캐시를 무효화합니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $layoutName  대상 레이아웃 이름
     */
    public function clearLayoutCache(int $templateId, string $layoutName): void
    {
        // Before 훅 - 캐시 무효화 전
        HookManager::doAction('core.layout.before_cache_clear', $templateId, $layoutName);

        // 일반 응답과 편집 모드(`with_source_meta=1`) 응답 두 캐시 키 모두 무효화
        $cacheKey = $this->getMergedLayoutCacheKey($templateId, $layoutName, false);
        $this->cache->forget($cacheKey);
        $this->cache->forget($this->getMergedLayoutCacheKey($templateId, $layoutName, true));

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

        // PublicLayoutController::serve() 가 일반 응답과 편집 모드(`with_source_meta=1`) 응답을
        // 별도 캐시 키로 저장한다 (`.meta` 접미사). 본 PR Phase 3 S5a-1 에서 편집 모드 응답 캐시
        // 키가 추가되었으나 그 무효화는 누락 — 본 결함은 편집기가 저장 후 새로고침해도 stale
        // 응답을 받는 현상으로 표출된다. 일반/편집 모드 두 키 모두 무효화한다.
        $this->cache->forget("layout.{$identifier}.{$layoutName}.v{$cacheVersion}");
        $this->cache->forget("layout.{$identifier}.{$layoutName}.v{$cacheVersion}.meta");
    }

    /**
     * 특정 레이아웃을 extends하는 모든 자식 레이아웃의 캐시를 재귀적으로 무효화합니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $layoutName  변경된 부모 레이아웃 이름
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
     * `$withSourceMeta` 가 true 면 응답의 각 노드 및 data_source 에 `__source` 출처
     * 메타를 부여한다. 옵션 미사용 시 응답 형식은 종전과 100% 동일.
     *
     * @param  string  $templateIdentifier  템플릿 identifier
     * @param  string  $layoutName  레이아웃 이름
     * @param  bool  $withSourceMeta  편집 모드 출처 메타 부여 여부
     * @return array 병합 + 확장 + (옵션 시) 출처 메타가 부여된 레이아웃 데이터
     *
     * @throws ModelNotFoundException 템플릿을 찾을 수 없거나 비활성화된 경우
     *
     * @since engine-v1.50.0 `$withSourceMeta` 옵션 추가
     */
    public function getLayout(string $templateIdentifier, string $layoutName, bool $withSourceMeta = false): array
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
        $layout = $this->loadAndMergeLayout($template->id, $layoutName, $withSourceMeta);

        // 모듈/플러그인 Extension 적용 (Overlay, Extension Point)
        $layout = $this->layoutExtensionService->applyExtensions($layout, $template->id, $withSourceMeta);

        // 편집 모드 응답에 자식 레이아웃의 실제 DB lock_version 동봉.
        // PublicLayoutController 가 LayoutResource 를 거치지 않고 머지된 배열을 그대로 반환하므로,
        // 낙관적 잠금 흐름에 필요한 lock_version 을 응답 본문에 직접 부착해야 한다. 일반 사이트
        // 렌더(`withSourceMeta=false`)는 부착하지 않아 응답 형식이 종전과 100% 동일.
        if ($withSourceMeta) {
            $childRow = $this->layoutRepository->findByName($template->id, $layoutName);
            if ($childRow !== null) {
                $layout['lock_version'] = (int) ($childRow->lock_version ?? 0);
            }
        }

        // 편집 모드: data_sources 에 출처 메타(`__source`) 부여.
        // 같은 data_source id 를 여러 확장이 서로 다른 shape 로 정의할 때, 편집기 캔버스의
        // 샘플 데이터 해소(sampleDataProvider)가 그 데이터소스의 출처 스펙 샘플을 우선 선택해
        // 전역 id 충돌을 해소하도록 한다(계획서). 일반 렌더는 부여하지 않아
        // 운영 화면 영향 0. 이미 __source 가 있는 항목(확장 주입)은 보존.
        if ($withSourceMeta && isset($layout['data_sources']) && is_array($layout['data_sources'])) {
            $dsSourceMeta = $this->deriveDataSourceSourceMeta($layoutName, $template->id);
            foreach ($layout['data_sources'] as $idx => $dataSource) {
                if (is_array($dataSource) && ! isset($dataSource['__source'])) {
                    $layout['data_sources'][$idx]['__source'] = $dsSourceMeta;
                }
            }
        }

        // After 훅 - 레이아웃 조회 후
        HookManager::doAction('core.layout.after_get', $layout, $templateIdentifier, $layoutName, $template);

        return $layout;
    }

    /**
     * 편집 모드 data_source 출처 메타 판정.
     *
     * 확장 prefix(`{vendor-ext}.{layout}`)를 가진 레이아웃은 소유 확장의 실제
     * `source_type`(`LayoutResolverService::resolve`)으로 module/plugin 을 구분한다.
     * - 모듈 레이아웃 → `['kind' => 'module', 'identifier' => 'vendor-ext']`
     * - 플러그인 레이아웃 → `['kind' => 'plugin', 'identifier' => 'vendor-ext']`
     * - prefix 없음(템플릿 자체 레이아웃) → `['kind' => 'route', 'identifier' => null]`
     *
     * 엔진 `resolveSampleData.resolveSourceKey` 는 module/plugin 만 `{kind}:{identifier}` 로
     * bySource 를 조회한다. editorSpecLoader 도 동일하게 module 스펙은 `module:{id}`,
     * plugin 스펙은 `plugin:{id}` 키로 보존하므로, kind 가 정확해야 그 확장의 샘플이
     * 매칭된다. 이름 패턴만으로는 plugin 레이아웃이 모듈로 오분류되어(둘 다 `{ext}.{layout}`
     * 형태) `plugin:{id}` 스펙을 빗나가므로,
     * 해석 결과의 source_type 을 SSoT 로 삼는다. 해석 실패 시 이름 패턴으로 폴백.
     *
     * @param  string  $layoutName  레이아웃 이름 (예: 'sirsoft-gdpr.plugin_settings', 'admin_dashboard')
     * @param  int  $templateId  편집 대상 템플릿 ID (source_type 해석용)
     * @return array{kind: string, identifier: string|null} 출처 메타
     */
    private function deriveDataSourceSourceMeta(string $layoutName, int $templateId): array
    {
        // 확장 prefix 추출 — '{vendor-ext}.{layout}' 형태에서 첫 '.' 앞부분
        $dotPos = strpos($layoutName, '.');
        if ($dotPos === false) {
            // prefix 없음 → 템플릿 자체 레이아웃 (엔진에서 'template' 키로 폴백)
            return ['kind' => 'route', 'identifier' => null];
        }

        $extensionId = substr($layoutName, 0, $dotPos);

        // 소유 확장의 실제 source_type 으로 module/plugin 정확 분류 (이름 패턴은 둘을 구분 못 함).
        // `LayoutResolverService::resolve` 는 plugin 행을 반환하지 않으므로(fromModules 스코프가
        // source_type=Module 만 조회) 레이아웃 행을 직접 조회해 source_type 을 읽는다.
        $row = $this->layoutRepository->findByName($templateId, $layoutName);
        $sourceType = $row?->source_type;

        if ($sourceType === LayoutSourceType::Plugin) {
            return ['kind' => 'plugin', 'identifier' => $extensionId];
        }
        if ($sourceType === LayoutSourceType::Module) {
            return ['kind' => 'module', 'identifier' => $extensionId];
        }

        // 행/소스타입 부재 — 이름 패턴 폴백 (모듈 패턴이면 module, 아니면 plugin)
        $kind = $this->isModuleLayoutName($layoutName) ? 'module' : 'plugin';

        return ['kind' => $kind, 'identifier' => $extensionId];
    }

    /**
     * 특정 템플릿의 모든 레이아웃 조회
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return Collection<int, TemplateLayout> 템플릿 소속 레이아웃 컬렉션
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
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout|null 조회된 레이아웃 또는 null
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
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  array  $data  업데이트할 레이아웃 데이터 (content 키 또는 평면 배열)
     * @return TemplateLayout 업데이트된 레이아웃 모델
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

        // 낙관적 잠금 — expected_lock_version 검증
        // FormRequest 가 의무화하므로 누락 시 422 에서 차단되지만, 직접 호출 안전망으로 가드.
        $expectedVersion = isset($data['expected_lock_version'])
            ? (int) $data['expected_lock_version']
            : null;
        $currentVersion = (int) ($layout->lock_version ?? 0);

        if ($expectedVersion !== null && $expectedVersion !== $currentVersion) {
            throw new ConcurrentModificationException(
                currentVersion: $currentVersion,
                expectedVersion: $expectedVersion,
                resource: "template_layouts:{$layout->id}",
            );
        }

        // content 키가 있으면 추출 (UpdateLayoutContentRequest 사용 시)
        $updateData = $data['content'] ?? $data;

        $oldContent = $layout->content;

        // 레이아웃 업데이트 (lock_version 1 증가)
        $layout = $this->layoutRepository->updateContent($layout->id, $updateData, $currentVersion + 1);

        // 버전 히스토리 저장 — 저장 시점 content 스냅샷. 모든 템플릿 유형(admin/user)이 대상이다.
        // (종전엔 user 템플릿만 버전을 남겼으나, admin/user 구분 없이 모든 템플릿이 편집 가능해진
        //  현 정책에 맞춰 제한을 제거한다.)
        // changes_summary 는 직전 저장본 대비 이번 저장본의 변경을 기록한다.
        // (종전엔 버전 2건을 만들고 그중 하나가 자기 자신과 비교돼 changes_summary 가 항상 0 이었다 —
        //  최신 버전의 변경 요약이 0 으로 보여 수정 전/후 구분이 불가했던 결함 수정.)
        // 첫 수정 시 수정 전 원본을 baseline 버전으로 먼저 백업한다(이력이 하나도 없을 때만).
        // 이게 없으면 첫 수정본만 v1 으로 남아 "수정 전 상태"로 복원할 수 없다.
        // baseline 의 changes_summary 는 비교 대상이 없어 0(빈 요약) — 최초 원본 표식.
        $hasHistory = $this->versionRepository->getNextVersion($layout->id) > 1;
        if (! $hasHistory) {
            $this->versionRepository->saveVersion($layout->id, $oldContent, null);
        }

        // 이번 저장본을 새 버전으로 적재 — 직전(oldContent) 대비 변경 요약 기록.
        $savedVersion = $this->versionRepository->saveVersion($layout->id, $updateData, $oldContent);

        // 저장 응답에 현재(최신) 버전 번호 동봉 — 편집기 라우트 트리 버전
        // 배지가 저장 직후 재fetch 없이 동기화되도록 transient 속성으로 부착한다
        // (LayoutResource 가 current_version 으로 직렬화 — DB 컬럼 아님).
        $layout->setAttribute('current_version', $savedVersion->version);

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
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return Collection 버전 컬렉션
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
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  int  $version  버전 번호
     * @return mixed 조회된 버전 모델
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
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  int  $versionId  복원할 버전 ID
     * @return mixed 새로 저장된 버전 모델
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
     *
     * @param  array  $layout  검증 전 레이아웃 데이터
     * @return array sanitize 가 적용된 레이아웃 데이터
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
