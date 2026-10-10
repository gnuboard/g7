/**
 * `_localInit` 슬롯 관리 (생산부 ↔ 소비부 공용)
 *
 * `_localInit` 은 데이터소스의 `initLocal` 옵션이 만든 로컬 상태 초기화 payload 를
 * 렌더 컨텍스트에 실어 나르는 단일 키다. 생산부(TemplateApp → updateTemplateData)와
 * 소비부(DynamicRenderer 의 useEffect)가 이 키 하나를 공유한다.
 *
 * 문제: 소비는 React commit 이후(useEffect)에 일어난다. progressive 데이터소스가
 * 둘 이상이면 각자 독립적으로 `updateTemplateData({ _localInit })` 를 호출하는데,
 * 두 호출이 같은 commit 사이에 들어오면 나중 payload 가 슬롯을 통째로 교체하여
 * 먼저 도착한 소스의 초기값이 **한 번도 관측되지 않은 채** 사라진다.
 *
 * 해결: 아직 어떤 렌더러도 관측하지 않은(unconsumed) 슬롯에 한해 누적 병합한다.
 * 이미 소비된 슬롯은 지금까지처럼 교체한다 — 그래야 사용자가 화면을 쓰는 도중
 * `refetchDataSource` 가 발생해도 소비가 끝난 과거 payload 가 재적용되어
 * 폼 편집 결과를 되돌리는 일이 없다.
 *
 * 소비 여부는 소비부(DynamicRenderer)가 관측한 슬롯의 **참조**를 기록해 판정한다.
 * 해시 계산식을 생산부에 복제하지 않으므로 두 곳의 판정 기준이 어긋날 수 없다.
 *
 * @since engine-v1.52.2
 */

/** `_localInit` 전역 추적 레지스트리 */
export interface LocalInitTracking {
    /** 마지막으로 적용된 payload 의 추적 키 (`해시:_forceLocalInit`) */
    hash: string;
    /** 마지막으로 적용된 `_forceLocalInit` 타임스탬프 */
    timestamp?: number;
    /**
     * 마지막으로 렌더러가 관측한 슬롯 객체의 참조.
     * 생산부가 이 참조로 "소비 완료" 여부를 판정한다.
     */
    consumed?: unknown;
}

/** window 전역 키 (기존 이름 유지 — 디버깅 스크립트 호환) */
const TRACKING_KEY = '__g7LocalInitTracking';

/**
 * `_localInit` 전역 추적 레지스트리를 반환합니다. 없으면 생성합니다.
 *
 * @returns 전역 추적 레지스트리
 */
export function getLocalInitTracking(): LocalInitTracking {
    const target = globalThis as Record<string, any>;

    if (!target[TRACKING_KEY]) {
        target[TRACKING_KEY] = { hash: '', timestamp: undefined, consumed: undefined };
    }

    return target[TRACKING_KEY] as LocalInitTracking;
}

/**
 * 추적 레지스트리를 초기화합니다.
 *
 * SPA 네비게이션으로 레이아웃이 바뀌면 `_local` 이 통째로 리셋되므로
 * (`TemplateApp.loadRoute` 의 레이아웃 전환 감지), 추적 해시도 함께 비워야
 * 새 레이아웃의 `_localInit` 이 이전 레이아웃과 payload 가 우연히 같더라도
 * 정상 적용된다.
 *
 * @returns void
 */
export function resetLocalInitTracking(): void {
    const target = globalThis as Record<string, any>;
    target[TRACKING_KEY] = { hash: '', timestamp: undefined, consumed: undefined };
}

/**
 * 렌더러가 관측한 `_localInit` 슬롯을 "소비 완료"로 표시합니다.
 *
 * 적용(shouldApply)/건너뜀(동일 해시) 여부와 무관하게 호출한다.
 * 건너뛴 경우에도 그 payload 는 이미 `_local` 에 반영되어 있으므로 소비된 것이다.
 *
 * @param slot 관측한 `_localInit` 슬롯 객체
 * @returns void
 */
export function markLocalInitConsumed(slot: unknown): void {
    getLocalInitTracking().consumed = slot;
}

/**
 * 해당 슬롯이 이미 렌더러에게 관측되었는지 판정합니다.
 *
 * @param slot 판정할 `_localInit` 슬롯 객체
 * @returns 관측 완료 시 true
 */
export function isLocalInitConsumed(slot: unknown): boolean {
    return getLocalInitTracking().consumed === slot;
}

/**
 * `_localInit` payload 에 대해 한 렌더러 인스턴스가 취할 동작.
 *
 * - `apply`: 이 payload 를 아직 아무도 적용하지 않았다 → 저장소 A·B 를 모두 갱신
 * - `prune`: 다른 인스턴스가 이미 적용했다 → **이 인스턴스의 저장소 A 에서 payload 키 공간만 제거**
 *   (engine-v1.77.2: payload 가 변경 추적 폼의 데이터를 덮으면 `hasChanges` 도 함께 제거 — `shouldResetHasChanges`)
 * - `skip`: 이 인스턴스가 이미 처리했다 → 아무것도 하지 않음
 *
 * @since engine-v1.54.7
 */
export type LocalInitAction = 'apply' | 'prune' | 'skip';

/**
 * `_localInit` payload 에 대해 이 렌더러 인스턴스가 취할 동작을 판정합니다.
 *
 * 전역 해시(`__g7LocalInitTracking.hash`)는 "이 payload 를 처음 관측했는가"만 판정한다.
 * 그러나 리셋 대상인 `localDynamicState`(저장소 A)는 **인스턴스별**이므로, 전역 1회 소비만으로
 * 끝내면 나머지 루트 렌더러의 저장소 A 에 stale leaf 가 영구 잔존한다. 그 잔존값은
 * `deepMergeState(dataContext._local, dynamicState)` 에서 A 우선이라 신선한 저장소 B 를 덮는다.
 *
 * 건너뛴 인스턴스가 payload 를 **다시 적용**해서는 안 된다 — 늦게 마운트된 인스턴스가 이미
 * 소비된 과거 payload 를 저장소 B 에 되쓰면서 그 사이의 사용자 편집을 되돌린다
 * (`mergeLocalInitSlot` 의 `consumed → 교체` 규칙이 막고 있는 바로 그 회귀). 그래서 `prune` 은
 * 값을 도입하지 않고 제거만 한다. 제거는 stale 값을 되살릴 수 없고, 제거된 자리에는 이미
 * 갱신된 저장소 B 가 그대로 비쳐 보인다.
 *
 * 배경: `troubleshooting-state-advanced.md` 사례 13(engine-v1.18.3)이 세운
 * "동일 commit 내 복수 root 의 실행 순서에 의존 금지" 규칙의 미적용 구간이었다.
 *
 * @param params.globalTrackedKey 전역 레지스트리에 기록된 추적 키
 * @param params.instanceHandledKey 이 인스턴스가 마지막으로 처리한 추적 키 (미처리 시 null)
 * @param params.trackingKey 이번 payload 의 추적 키 (`해시:_forceLocalInit`)
 * @returns 이 인스턴스가 취할 동작
 */
export function resolveLocalInitAction(params: {
    globalTrackedKey: string;
    instanceHandledKey: string | null;
    trackingKey: string;
}): LocalInitAction {
    if (params.globalTrackedKey !== params.trackingKey) {
        return 'apply';
    }

    return params.instanceHandledKey === params.trackingKey ? 'skip' : 'prune';
}

/**
 * `_forceLocalInit` 타임스탬프를 병합합니다.
 *
 * `refetchOnMount: true` 인 소스만 이 값을 갖는다. 두 payload 를 합칠 때
 * 어느 한쪽에만 있으면 그 값을 보존해야 강제 초기화가 유실되지 않는다.
 *
 * @param prev 기존 슬롯의 타임스탬프
 * @param next 새 payload 의 타임스탬프
 * @returns 병합된 타임스탬프 (양쪽 모두 없으면 undefined)
 */
function mergeForceFlag(prev: unknown, next: unknown): unknown {
    if (typeof prev === 'number' && typeof next === 'number') {
        return Math.max(prev, next);
    }

    return next ?? prev;
}

/**
 * `_localInit` 슬롯에 새 payload 를 반영합니다.
 *
 * - 새 payload 가 없으면 기존 슬롯을 그대로 반환 (참조 동일성 유지 — 소비부 effect 재발화 방지)
 * - 기존 슬롯이 없거나 이미 소비되었으면 새 payload 로 교체
 * - 기존 슬롯이 아직 소비되지 않았으면 누적 병합 (나중 payload 우선)
 *
 * @param prev 현재 데이터 컨텍스트의 `_localInit` 슬롯
 * @param next 이번 업데이트가 실어 온 `_localInit` payload
 * @returns 슬롯에 담길 값
 */
export function mergeLocalInitSlot(prev: unknown, next: unknown): unknown {
    if (next === undefined) {
        return prev;
    }

    const isMergeable =
        prev !== null &&
        typeof prev === 'object' &&
        next !== null &&
        typeof next === 'object' &&
        !isLocalInitConsumed(prev);

    if (!isMergeable) {
        return next;
    }

    const { _forceLocalInit: prevForce, ...prevData } = prev as Record<string, any>;
    const { _forceLocalInit: nextForce, ...nextData } = next as Record<string, any>;

    const merged: Record<string, any> = { ...prevData, ...nextData };
    const force = mergeForceFlag(prevForce, nextForce);

    if (force !== undefined) {
        merged._forceLocalInit = force;
    }

    return merged;
}

/**
 * 변경 추적 폼(`data-key` + `data-track-changes`)의 dataKey 첫 키 등록부 (키 → 마운트 수).
 *
 * `_localInit` 적용·정리는 `hasChanges`(폼 「변경 있음」)를 초기화한다. 그런데 폼과 무관한
 * 데이터(예: 알림 목록의 페이지 정보만 싣는 `initLocal`)가 다시 들어올 때도 초기화하면, 폼의
 * 편집값은 그대로인데 저장 버튼만 꺼진다. 그래서 들어온 payload 가 **변경 추적 폼의 데이터를
 * 덮을 때만** 초기화하도록, 마운트된 폼의 dataKey 첫 키를 여기에 모은다.
 *
 * @since engine-v1.77.2
 */
const TRACKED_ROOTS_KEY = '__g7TrackChangesRoots';

/**
 * 등록부를 반환합니다 (window 전역 — 여러 번들 사본이 같은 등록부를 보게 한다).
 *
 * @returns dataKey 첫 키 → 마운트 수
 */
function getTrackedRoots(): Map<string, number> {
    const w = typeof window !== 'undefined' ? (window as any) : (globalThis as any);
    if (!(w[TRACKED_ROOTS_KEY] instanceof Map)) {
        w[TRACKED_ROOTS_KEY] = new Map<string, number>();
    }

    return w[TRACKED_ROOTS_KEY];
}

/**
 * 변경 추적 폼의 dataKey 를 등록합니다. 반환한 함수로 해제합니다(언마운트).
 *
 * `_global.`·`_isolated.` 로 시작하는 dataKey 는 `hasChanges` 를 `_local` 이 아닌 그 저장소에
 * 쓰므로 등록하지 않는다.
 *
 * @param dataKey 폼의 dataKey (예: "form", "form.basic")
 * @returns 등록 해제 함수
 * @since engine-v1.77.2
 */
export function registerTrackChangesRoot(dataKey: string): () => void {
    if (!dataKey || dataKey.startsWith('_global.') || dataKey.startsWith('_isolated.')) {
        return () => {};
    }

    const root = dataKey.split('.')[0];
    const roots = getTrackedRoots();
    roots.set(root, (roots.get(root) ?? 0) + 1);

    return () => {
        const count = (roots.get(root) ?? 0) - 1;
        if (count > 0) {
            roots.set(root, count);
        } else {
            roots.delete(root);
        }
    };
}

/**
 * 이번 `_localInit` payload 가 `hasChanges` 를 초기화해야 하는지 판정합니다.
 *
 * - 마운트된 변경 추적 폼이 없으면 종전대로 초기화한다(레이아웃이 `hasChanges` 를 직접
 *   관리하는 화면의 기존 동작 보존).
 * - 있으면 payload 의 최상위 키 중 하나가 그 폼의 dataKey 첫 키일 때만 초기화한다.
 *
 * @param payload `_localInit` 데이터 (`_forceLocalInit`·`_merge` 는 무시)
 * @returns 초기화 여부
 * @since engine-v1.77.2
 */
export function shouldResetHasChanges(payload: Record<string, unknown>): boolean {
    const roots = getTrackedRoots();
    if (roots.size === 0) {
        return true;
    }

    return Object.keys(payload).some(key => roots.has(key));
}

/**
 * 등록부를 비웁니다 (테스트 격리용).
 *
 * @since engine-v1.77.2
 */
export function resetTrackChangesRoots(): void {
    getTrackedRoots().clear();
}
