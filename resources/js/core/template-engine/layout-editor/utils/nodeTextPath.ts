/**
 * nodeTextPath.ts — 노드 안 "편집 대상 텍스트 노드" 탐색/패치 공용 헬퍼
 *
 *
 * 목록 항목(Li)·표 셀(Td/Th) 등은 직접 `text` 를 갖기도 하지만, 흔히 자식 구조
 * (`Li>[Span("•"),Span("텍스트")]`, `Td>[Icon, Span("라벨")]`)로 텍스트를 담거나
 * **임의 HTML/컴포넌트**(A/Img/Button/Div 등)를 품는다. 사용자는 그 컨테이너의
 * "텍스트"를 편집하려는 의도이므로, 직접 text 가 없으면 **의미 있는 텍스트를 가진 첫
 * 자손**(장식용 단일 문자 `•`/`-` 등은 후순위)을 편집 대상으로 삼는다. 텍스트 자손이
 * 전혀 없는 순수 구조 노드(아이콘·이미지만)는 텍스트 편집 비대상 — 구조 자체 편집은
 * 캔버스/children 에디터에 위임한다.
 *
 * ChildrenListControl·TableEditor 가 공유하며, 부록7 7-a 의 다국어
 * 공통 모듈도 동일 경로 추상을 재사용해 ko/en/ja 펼침 폼으로 승격할 수 있다.
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from './layoutTreeUtils';

/** 자식 노드 배열 안전 추출. */
function childArray(node: EditorNode): EditorNode[] {
  return Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
}

/**
 * 컨테이너 노드 안의 **편집 대상 텍스트 노드 경로**를 찾는다(container 기준 상대 index 경로).
 *
 * - 직접 `text`(문자열) 보유 → `[]`(container 자신).
 * - 없으면 BFS 로 의미 있는 텍스트 자손 탐색(공백 제외 2글자 이상 우선, 장식 1글자는 폴백).
 * - 텍스트 자손이 전혀 없으면 `null`(순수 구조 — 라벨만, 텍스트 편집 비대상).
 *
 * @param container 항목/셀 노드
 * @return 텍스트 노드 상대 경로(`[]`=자신, `[i,...]`=자손) 또는 null
 */
export function findTextNodePath(container: EditorNode): number[] | null {
  if (typeof container.text === 'string') return [];

  let decorativeFallback: number[] | null = null;
  const visit = (node: EditorNode, path: number[]): number[] | null => {
    const kids = childArray(node);
    for (let i = 0; i < kids.length; i++) {
      const child = kids[i]!;
      const here = [...path, i];
      if (typeof child.text === 'string') {
        const trimmed = child.text.trim();
        if (trimmed.length <= 1) {
          if (decorativeFallback === null) decorativeFallback = here;
        } else {
          return here;
        }
      }
      const deep = visit(child, here);
      if (deep) return deep;
    }
    return null;
  };
  return visit(container, []) ?? decorativeFallback;
}

/**
 * container 기준 상대 경로의 노드를 반환(없으면 null).
 *
 * @param container 항목/셀 노드
 * @param path 상대 경로(findTextNodePath 결과)
 * @return 대상 노드 또는 null
 */
export function nodeAtTextPath(container: EditorNode, path: number[]): EditorNode | null {
  let cur: EditorNode | null = container;
  for (const idx of path) {
    if (!cur) return null;
    cur = childArray(cur)[idx] ?? null;
  }
  return cur;
}

/**
 * container 기준 상대 경로 노드의 `text` 만 교체한 container 사본 반환(immutable).
 * 형제·구조는 보존 — 텍스트 노드만 치환한다.
 *
 * @param container 항목/셀 노드
 * @param path 상대 경로(findTextNodePath 결과)
 * @param nextText 새 text(평문 또는 `$t:custom.*` 토큰)
 * @return 패치된 container 사본
 */
export function patchTextAtPath(container: EditorNode, path: number[], nextText: string): EditorNode {
  if (path.length === 0) return { ...container, text: nextText };
  const [head, ...rest] = path;
  const kids = childArray(container);
  const nextKids = kids.map((k, i) => (i === head ? patchTextAtPath(k, rest!, nextText) : k));
  return { ...container, children: nextKids };
}

/**
 * 컨테이너 노드 안의 **지정 prop 보유 노드 경로**를 찾는다 (
 * 항목 목록의 편집 필드는 capability `nodeEditor.params.itemFields` 스펙 선언으로 발효).
 *
 * 코어는 prop 이름을 모른다(메커니즘만 — 어떤 prop 을 항목 편집에 노출할지는 템플릿
 * editor-spec 이 `{ kind: "prop", prop: "placeholder" }` 처럼 선언). 항목(폼 필드 행
 * Div 등) 안에서 `props[propKey]` 가 **문자열**인 첫 자손(DFS, 자신 포함)을 편집
 * 대상으로 삼는다. `{{...}}` 바인딩 값은 경로는 반환하되 I18nTextField 가 읽기전용
 * 디그레이드, prop 이 아예 없으면 null(편집란 미노출).
 *
 * @param container 항목 노드
 * @param propKey 스펙이 선언한 prop 키
 * @return prop 보유 노드 상대 경로(`[]`=자신) 또는 null
 */
export function findPropNodePath(container: EditorNode, propKey: string): number[] | null {
  const hasProp = (node: EditorNode): boolean =>
    typeof (node.props as Record<string, unknown> | undefined)?.[propKey] === 'string';

  if (hasProp(container)) return [];
  const visit = (node: EditorNode, path: number[]): number[] | null => {
    const kids = childArray(node);
    for (let i = 0; i < kids.length; i++) {
      const child = kids[i]!;
      const here = [...path, i];
      if (hasProp(child)) return here;
      const deep = visit(child, here);
      if (deep) return deep;
    }
    return null;
  };
  return visit(container, []);
}

/**
 * container 기준 상대 경로 노드의 `props[propKey]` 만 교체한 container 사본 반환(immutable).
 *
 * @param container 항목 노드
 * @param path 상대 경로(findPropNodePath 결과)
 * @param propKey 스펙이 선언한 prop 키
 * @param nextValue 새 값(평문 또는 `$t:custom.*` 토큰)
 * @return 패치된 container 사본
 */
export function patchPropAtPath(
  container: EditorNode,
  path: number[],
  propKey: string,
  nextValue: string,
): EditorNode {
  if (path.length === 0) {
    return {
      ...container,
      props: { ...((container.props as Record<string, unknown>) ?? {}), [propKey]: nextValue },
    };
  }
  const [head, ...rest] = path;
  const kids = childArray(container);
  const nextKids = kids.map((k, i) =>
    i === head ? patchPropAtPath(k, rest!, propKey, nextValue) : k,
  );
  return { ...container, children: nextKids };
}
