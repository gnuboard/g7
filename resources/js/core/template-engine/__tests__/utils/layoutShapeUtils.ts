/**
 * 레이아웃 JSON 을 **모양이 아니라 뜻으로** 읽는 헬퍼 (S18.5 축 D · D67 ⓐ).
 *
 * ## 왜 필요한가
 *
 * 컴파일러는 같은 뜻을 한 가지 정본 형태로 낸다. 그 정본에는 **런타임이 등가로 받아들이는
 * 접기**가 들어 있다 — 콜백 단일 배열은 객체로(`onSuccess: [x]` → `onSuccess: x`), 빈 컨테이너는
 * 키 자체를 두지 않고, `state` 는 `initLocal` 로 접힌다. 엔진은 양쪽을 모두 읽으므로
 * (`Array.isArray(x) ? x : [x]` · `initLocal || state`) **화면은 어느 형태든 같다**.
 *
 * 문제는 테스트다. 레이아웃 JSON 을 구조로 단언하는 테스트가 접히기 **전** 형태를 문자 그대로
 * 외우고 있으면, 뜻이 같은데도 red 가 된다. S18.5 굽기가 코퍼스를 정본으로 재생성하자
 * 확장 5개에서 **109 단언 / 38 파일**이 그렇게 깨졌다.
 *
 * 값을 새 형태로 갈아끼우는 것은 답이 아니다 — 다음 정리에서 또 38파일이 깨진다. 테스트가
 * **컨테이너 모양에 둔감**해지도록 읽는 층을 하나 두고, 단언은 "어느 핸들러가 어떤 params 로
 * 도는가" 를 본다. 그것이 원래 그 테스트들이 지키려던 명제다.
 *
 * ## 쓰는 법
 *
 * ```ts
 * import { asArray, actionsOf, callbacksOf, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
 *
 * const save = findById(layout, 'save_button');
 * const api = actionsOf(save).find((a) => a.handler === 'apiCall');
 * expect(callbacksOf(api, 'onSuccess').some((c) => c.handler === 'toast')).toBe(true);
 * ```
 */

/** 레이아웃 노드·액션의 느슨한 형태 — 테스트는 구조를 탐색만 하므로 인덱스 시그니처면 충분하다. */
export type LayoutNode = Record<string, any>;

/**
 * 단일 값·배열·부재를 모두 배열로 편다.
 *
 * 컴파일러가 원소 하나짜리 배열을 객체로 접으므로, 배열 메서드를 쓰기 전에 이것을 통과시킨다.
 * `null`/`undefined` 는 빈 배열이다 — 키가 없는 것과 빈 것을 테스트가 구분할 이유는 거의 없고,
 * 구분이 필요하면 그 자리에서 명시적으로 확인하는 편이 읽기 쉽다.
 *
 * @param value 배열이거나, 단일 값이거나, 없을 수 있는 것
 * @returns 항상 배열
 */
export function asArray<T = any>(value: T | T[] | null | undefined): T[] {
  if (value === null || value === undefined) return [];

  return Array.isArray(value) ? value : [value];
}

/**
 * 노드가 가진 액션을 형태 무관하게 모은다.
 *
 * @param node 레이아웃 노드
 * @returns 액션 배열 (없으면 빈 배열)
 */
export function actionsOf(node: LayoutNode | null | undefined): LayoutNode[] {
  if (!node) return [];

  return asArray(node.actions);
}

/**
 * 액션의 콜백(`onSuccess`·`onError` 등)을 형태 무관하게 모은다.
 *
 * 제어 핸들러의 `actions`/`cases`/`conditions` 도 같은 접기를 겪으므로 같은 함수로 읽는다.
 *
 * @param action 액션 객체
 * @param key 콜백 키 (`onSuccess` · `onError` · `actions` 등)
 * @returns 콜백 배열 (없으면 빈 배열)
 */
export function callbacksOf(action: LayoutNode | null | undefined, key: string): LayoutNode[] {
  if (!action) return [];
  // 제어 핸들러는 `params` 안에 두던 것이 top-level 로 올라온다 — 양쪽을 본다.
  return asArray(action[key] ?? action.params?.[key]);
}

/**
 * 문서의 초기 로컬 상태 — `initLocal` 이 정본이고 `state` 는 deprecated 별칭이다.
 *
 * @param layout 레이아웃 문서
 * @returns 초기 상태 객체 (없으면 빈 객체)
 */
export function initStateOf(layout: LayoutNode | null | undefined): LayoutNode {
  return layout?.initLocal ?? layout?.state ?? {};
}

/**
 * 트리 전체를 평탄화한다 — 노드가 어느 컨테이너에 담겼든 모은다.
 *
 * `children`·`components`·`slots`·`modals`·`default`·`cellChildren` 처럼 노드가 들어가는 자리를
 * 열거하지 않는다. **객체·배열을 전부 내려가며** 노드처럼 생긴 것(`name` 또는 `type` 보유)을 담는다 —
 * 열거하면 새 컨테이너가 생길 때마다 사각이 는다(이 저장소가 `walkComponentNodes` 에서 겪은 형태).
 *
 * @param root 레이아웃 문서 또는 노드
 * @returns 노드처럼 생긴 모든 객체
 */
export function flattenNodes(root: unknown): LayoutNode[] {
  const out: LayoutNode[] = [];
  const seen = new Set<unknown>();

  const walk = (value: unknown): void => {
    if (!value || typeof value !== 'object') return;
    if (seen.has(value)) return;
    seen.add(value);

    if (!Array.isArray(value)) {
      const node = value as LayoutNode;
      if (typeof node.name === 'string' || typeof node.type === 'string') out.push(node);
    }

    for (const child of Object.values(value as Record<string, unknown>)) walk(child);
  };

  walk(root);

  return out;
}

/**
 * `id` 로 노드를 찾는다 — 컨테이너 종류를 가리지 않는다.
 *
 * @param root 레이아웃 문서 또는 노드
 * @param id 찾을 노드 id
 * @returns 노드 (없으면 null)
 */
export function findById(root: unknown, id: string): LayoutNode | null {
  const walk = (value: unknown): LayoutNode | null => {
    if (!value || typeof value !== 'object') return null;
    if (!Array.isArray(value) && (value as LayoutNode).id === id) return value as LayoutNode;
    for (const child of Object.values(value as Record<string, unknown>)) {
      const found = walk(child);
      if (found) return found;
    }

    return null;
  };

  return walk(root);
}

/**
 * `id` **접두어**로 노드를 찾는다 — 반복 행 노드를 지목할 때 쓴다.
 *
 * 반복(`data-each`) 안의 `data-ref` 는 행마다 유일해야 하므로 인덱스 표현식이 뒤에 붙는다
 * (`cookie_categories_rows_{{categoryIdx}}`). 테스트가 완성된 id 문자열을 박으면 **인덱스 변수명이
 * 바뀔 때 또 깨진다** — 안정된 명제는 "그 접두어를 가진 행 노드가 있다" 다.
 *
 * @param root 레이아웃 문서 또는 노드
 * @param prefix `id` 접두어
 * @returns 첫 일치 노드 (없으면 null)
 */
export function findByIdPrefix(root: unknown, prefix: string): LayoutNode | null {
  const walk = (value: unknown): LayoutNode | null => {
    if (!value || typeof value !== 'object') return null;
    if (!Array.isArray(value)) {
      const id = (value as LayoutNode).id;
      if (typeof id === 'string' && id.startsWith(prefix)) return value as LayoutNode;
    }
    for (const child of Object.values(value as Record<string, unknown>)) {
      const found = walk(child);
      if (found) return found;
    }

    return null;
  };

  return walk(root);
}

/**
 * 트리 어딘가에 주어진 조건의 액션이 있는지 본다.
 *
 * @param root 레이아웃 문서 또는 노드
 * @param predicate 액션 판정
 * @returns 조건을 만족하는 액션 전량
 */
export function findActions(root: unknown, predicate: (action: LayoutNode) => boolean): LayoutNode[] {
  const out: LayoutNode[] = [];
  const seen = new Set<unknown>();

  const walk = (value: unknown): void => {
    if (!value || typeof value !== 'object') return;
    if (seen.has(value)) return;
    seen.add(value);

    if (!Array.isArray(value)) {
      const node = value as LayoutNode;
      if (typeof node.handler === 'string' && predicate(node)) out.push(node);
    }

    for (const child of Object.values(value as Record<string, unknown>)) walk(child);
  };

  walk(root);

  return out;
}
