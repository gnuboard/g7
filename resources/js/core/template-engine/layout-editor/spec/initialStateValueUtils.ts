/**
 * initialStateValueUtils.ts — [초기 상태] 값 편집 순수 유틸
 *
 * `initLocal`/`initGlobal`/`initIsolated` 정적 값(문자/숫자/불리언/null/목록/묶음)을 재귀
 * 편집하는 데 필요한 순수 변환:
 *  - `inferValueKind`: 값 → 종류 추론(표현식 분기 없음 — 최상위 init 은 정적값만).
 *  - `defaultForKind`: 종류 전환 시 기본값.
 *  - `setAtPath`/`removeAtPath`: 점/인덱스 경로(`filter.status`, `items.0`, `a.b.0.c`) 불변 set/delete.
 *  - `classifyKeyOrigin`: own/merged 비교로 자기/상속 출처 판정(shallow merge).
 *  - `normalizeLegacyState`: `state`(deprecated) → `initLocal` 정규화 이관.
 *
 * 모든 함수 순수 — 입력을 변경하지 않는다.
 *
 * @since engine-v1.50.0
 */

/** 값 종류 — 6종 */
export type ValueKind = 'string' | 'number' | 'boolean' | 'null' | 'list' | 'object';

/**
 * 값에서 종류를 추론한다(표현식 분기 없음).
 *
 * 손으로 작성된 `{{...}}` 문자열도 종류='string'(일반 문자열, 무손실 — 엔진이 그대로 주입).
 *
 * @param value 값
 * @return 추론된 종류
 */
export function inferValueKind(value: unknown): ValueKind {
  if (value === null || value === undefined) return 'null';
  if (Array.isArray(value)) return 'list';
  switch (typeof value) {
    case 'number':
      return 'number';
    case 'boolean':
      return 'boolean';
    case 'string':
      return 'string';
    default:
      return 'object';
  }
}

/**
 * 종류 전환 시 기본값을 반환한다.
 *
 * @param kind 새 종류
 * @return 기본값(문자→"" / 숫자→0 / 예아니오→false / 없음→null / 목록→[] / 묶음→{})
 */
export function defaultForKind(kind: ValueKind): unknown {
  switch (kind) {
    case 'string':
      return '';
    case 'number':
      return 0;
    case 'boolean':
      return false;
    case 'null':
      return null;
    case 'list':
      return [];
    case 'object':
      return {};
    default:
      return null;
  }
}

/**
 * 상태 키(이름)가 유효한 식별자인지 — 영문자/`_`/`$` 로 시작, 이후 영문·숫자·`_`·`$`.
 *
 * 초기 상태 키는 런타임에 `_local.키`/`_global.키` 및 표현식 바인딩(`{{키}}`)의 식별자로 쓰인다.
 * 한글·공백·하이픈·숫자 시작 등은 식별자로 해석되지 못해 죽은 값이 되므로 입력 시점에 막는다.
 *
 *
 * @param name 키 이름
 * @return 유효하면 true
 */
export function isValidStateKey(name: string): boolean {
  return /^[A-Za-z_$][A-Za-z0-9_$]*$/.test(name);
}

/** 점/인덱스 경로를 세그먼트 배열로 — `a.b.0.c` → ['a','b','0','c'] */
function pathSegments(path: string): string[] {
  return path.split('.').filter((s) => s.length > 0);
}

/** 한 컨테이너(객체/배열)를 얕게 복제 */
function shallowClone(container: unknown): Record<string, unknown> | unknown[] {
  if (Array.isArray(container)) return container.slice();
  if (container && typeof container === 'object') return { ...(container as Record<string, unknown>) };
  return {};
}

/**
 * 점/인덱스 경로에 값을 불변 set 한다(존재 안 하는 중간 경로 생성).
 *
 * 다음 세그먼트가 숫자면 배열, 아니면 객체로 중간 경로를 만든다.
 *
 * @param root 루트 객체
 * @param path 점/인덱스 경로
 * @param value 설정할 값
 * @return 새 루트(불변)
 */
export function setAtPath(root: Record<string, unknown>, path: string, value: unknown): Record<string, unknown> {
  const segs = pathSegments(path);
  if (segs.length === 0) return root;
  const next = { ...root };
  let cursor: Record<string, unknown> | unknown[] = next;
  for (let i = 0; i < segs.length - 1; i++) {
    const seg = segs[i];
    const nextSeg = segs[i + 1];
    const cur = (cursor as Record<string, unknown>)[seg];
    const cloned = cur && typeof cur === 'object' ? shallowClone(cur) : (/^\d+$/.test(nextSeg) ? [] : {});
    (cursor as Record<string, unknown>)[seg] = cloned;
    cursor = cloned;
  }
  (cursor as Record<string, unknown>)[segs[segs.length - 1]] = value;
  return next;
}

/**
 * 점/인덱스 경로의 값을 불변 delete 한다(배열이면 splice).
 *
 * @param root 루트 객체
 * @param path 점/인덱스 경로
 * @return 새 루트(불변)
 */
export function removeAtPath(root: Record<string, unknown>, path: string): Record<string, unknown> {
  const segs = pathSegments(path);
  if (segs.length === 0) return root;
  const next = { ...root };
  let cursor: Record<string, unknown> | unknown[] = next;
  const parents: Array<{ container: Record<string, unknown> | unknown[]; seg: string }> = [];
  for (let i = 0; i < segs.length - 1; i++) {
    const seg = segs[i];
    const cur = (cursor as Record<string, unknown>)[seg];
    if (!cur || typeof cur !== 'object') return next; // 경로 없음 — 무변경.
    const cloned = shallowClone(cur);
    (cursor as Record<string, unknown>)[seg] = cloned;
    parents.push({ container: cloned, seg });
    cursor = cloned;
  }
  const last = segs[segs.length - 1];
  if (Array.isArray(cursor)) {
    (cursor as unknown[]).splice(Number(last), 1);
  } else {
    delete (cursor as Record<string, unknown>)[last];
  }
  return next;
}

/** 키 출처 — 자기 선언 / 부모 상속 */
export type KeyOrigin = 'self' | 'inherited';

/**
 * 한 키의 출처를 own/merged 비교로 판정한다(shallow merge).
 *
 * - own ∋ key → 자기(덮은 경우 포함).
 * - own ∌ key ∧ merged ∋ key → 상속.
 *
 * @param mergedKeys 병합본 키 집합
 * @param ownKeys 자기 선언 키 집합
 * @param key 판정 대상 키
 * @return 'self' | 'inherited'
 */
export function classifyKeyOrigin(mergedKeys: string[], ownKeys: string[], key: string): KeyOrigin {
  if (ownKeys.includes(key)) return 'self';
  if (mergedKeys.includes(key)) return 'inherited';
  return 'self';
}

/**
 * legacy `state` 를 `initLocal` 로 정규화한다.
 *
 * `state` 만 있으면 그대로 initLocal 로 이관(state 키 제거). 둘 다 있으면 initLocal 우선
 * 병합(state 의 미충돌 키만 추가, initLocal 키가 이김). 반환은 정규화된 initLocal 과
 * `migrated`(이관 발생 여부 — dirty 트리거).
 *
 * @param raw 레이아웃 raw(initLocal/state 보유 가능)
 * @return { initLocal, migrated }
 */
export function normalizeLegacyState(raw: {
  initLocal?: Record<string, unknown>;
  state?: Record<string, unknown>;
}): { initLocal: Record<string, unknown>; migrated: boolean } {
  const hasState = raw.state && typeof raw.state === 'object' && Object.keys(raw.state).length > 0;
  const initLocal = raw.initLocal && typeof raw.initLocal === 'object' ? raw.initLocal : undefined;
  if (!hasState) {
    return { initLocal: initLocal ?? {}, migrated: false };
  }
  // state 보유 → 정규화 이관(initLocal 우선).
  const merged: Record<string, unknown> = { ...(raw.state as Record<string, unknown>) };
  if (initLocal) {
    for (const [k, v] of Object.entries(initLocal)) merged[k] = v; // initLocal 이 덮음.
  }
  return { initLocal: merged, migrated: true };
}
