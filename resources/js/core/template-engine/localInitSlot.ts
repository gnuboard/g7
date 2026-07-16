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
