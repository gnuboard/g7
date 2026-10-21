/**
 * apiCall 로딩 플래그(`loadingActions`) 헬퍼
 *
 * apiCall 은 시작할 때 발화한 컴포넌트 상태의 `loadingActions` 에 `[actionId]: true` 를 넣고 끝나면 뺀다.
 * 종전에는 키를 생략한 객체로 해제해, 깊은 병합 writer(렌더러 `handleLocalSetState` 기본 병합)가 끝난 키를
 * 지우지 못하고 호출마다 쌓였다. 그 누적이 렌더 문맥 사본을 거쳐 A 전용 키 보충(`addMissingLeafKeys`)으로
 * 저장소 B 에도 옮겨졌다(7.1.0 포함).
 *
 * 이 모듈은 진행 중인 id 를 들고 「끝난 플래그」 를 가려낸다. 끝난 플래그 = 엔진이 만든 id 모양이면서
 * 진행 중 집합에 없는 키다. 레이아웃·확장이 직접 넣은 다른 키(`save` 등)는 건드리지 않는다.
 *
 * @since engine-v1.81.2
 * @packageDocumentation
 */

/** 진행 중인 apiCall 로딩 id (디스패처 인스턴스 공용 — id 는 시각·난수라 겹치지 않는다) */
const inFlightLoadingIds = new Set<string>();

/** `ActionDispatcher.generateActionId` 가 apiCall 에 만드는 id 모양 — `apiCall_<대상>_<13자리 시각>_<난수>` */
const ENGINE_LOADING_ID = /^apiCall_[A-Za-z0-9_]*_\d{13}_[a-z0-9]{0,9}$/;

/**
 * 로딩 시작을 기록합니다.
 *
 * @param id apiCall 로딩 id
 * @return 없음
 */
export function beginLoadingFlag(id: string): void {
  inFlightLoadingIds.add(id);
}

/**
 * 로딩 끝을 기록합니다.
 *
 * @param id apiCall 로딩 id
 * @return 없음
 */
export function endLoadingFlag(id: string): void {
  inFlightLoadingIds.delete(id);
}

/**
 * 키가 끝난 apiCall 로딩 플래그인지 판정합니다.
 *
 * @param key `loadingActions` 의 키
 * @return 엔진이 만든 id 모양이고 진행 중이 아니면 true
 */
export function isFinishedLoadingFlag(key: string): boolean {
  return ENGINE_LOADING_ID.test(key) && !inFlightLoadingIds.has(key);
}

/**
 * `loadingActions` 에서 끝난 플래그를 뺍니다.
 *
 * @param flags `loadingActions` 값
 * @return 뺄 키가 없으면 같은 참조(일반 객체가 아니면 그대로), 있으면 뺀 새 객체
 */
export function pruneFinishedLoadingFlags<T>(flags: T): T {
  if (flags === null || typeof flags !== 'object' || Array.isArray(flags)) return flags;
  const keys = Object.keys(flags as Record<string, unknown>);
  if (!keys.some(isFinishedLoadingFlag)) return flags;
  const next: Record<string, unknown> = {};
  for (const key of keys) {
    if (!isFinishedLoadingFlag(key)) next[key] = (flags as Record<string, unknown>)[key];
  }

  return next as T;
}

/**
 * 저장소 A writer 에 넘길 로딩 플래그 함수형 갱신을 만듭니다.
 *
 * 최신 상태(prev)를 받아 `loadingActions` 한 키만 바꾼 상태를 돌려준다 — 끝난 플래그를 뺀 뒤 `on` 이면 id 를 넣는다.
 * 클릭 시점 사본(`context.state`)을 쓰지 않으므로 겹친 호출의 다른 키를 지우거나 되살리지 않는다.
 * `__g7MergeMode: 'shallow'` 표지로 렌더러가 결과를 다시 깊게 병합하지 않게 한다(engine-v1.81.2 계약) — 다시 병합하면
 * 뺀 키가 남는다. 전역 상태 writer(`setGlobalState`)의 함수형 갱신도 결과를 그대로 쓴다.
 *
 * @param id apiCall 로딩 id — null 이면 끝난 플래그 정리만 한다
 * @param on true 면 넣고 false 면 뺀다
 * @return 함수형 갱신
 */
export function makeLoadingFlagUpdater(
  id: string | null,
  on: boolean
): (prev: Record<string, any> | null | undefined) => Record<string, any> {
  const updater = (prev: Record<string, any> | null | undefined): Record<string, any> => {
    const base = prev && typeof prev === 'object' ? prev : {};
    const current = base.loadingActions && typeof base.loadingActions === 'object' && !Array.isArray(base.loadingActions)
      ? base.loadingActions
      : {};
    const pruned: Record<string, any> = { ...pruneFinishedLoadingFlags(current) };
    if (id !== null) {
      if (on) {
        pruned[id] = true;
      } else {
        delete pruned[id];
      }
    }

    return { ...base, loadingActions: pruned };
  };
  (updater as any).__g7MergeMode = 'shallow';

  return updater;
}
