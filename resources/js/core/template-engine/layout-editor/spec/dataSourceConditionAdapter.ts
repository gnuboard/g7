/**
 * dataSourceConditionAdapter.ts — ConditionBuilder ↔ data_source 어댑터
 *
 * 데이터소스의 조건부 로딩(`if`/`conditions`)을 표시조건 빌더(`ConditionBuilder`, node 기반)로
 * 편집하기 위한 양방향 변환. ConditionBuilder 는 `EditorNode` 의 최상위 `node.if` 를 읽고
 * `onPatchNode(patched)` 로 패치하므로, data_source 를 **가짜 EditorNode**(`if` 만 노출)로
 * 감싸 그대로 재사용한다(신규 빌더 0).
 *
 * 데이터칩 후보에서 `_local.*` 는 제외 대상이다 — 데이터소스는 렌더 전(초기 fetch 시점)에
 * 평가되므로 `_local`(렌더 후 상태)은 아직 없다. 후보 필터는 호출자(DataSourcesPanel)가
 * `_local` 스코프를 빼고 전달한다(본 어댑터는 if 식 변환만 — 후보 게이팅은 UI 책임).
 *
 * 순수 함수 — 입력을 변경하지 않는다.
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from '../utils/layoutTreeUtils';

/** data_source 의 조건 관련 필드(편집 대상) */
export interface DataSourceConditionShape {
  /** 조건부 로딩 식 — `{{ }}` 한 쌍(표시조건과 동형) */
  if?: unknown;
  /** 레거시 conditions 배열(있으면 보존 — 빌더는 if 만 편집) */
  conditions?: unknown;
  [key: string]: unknown;
}

/**
 * data_source 를 ConditionBuilder 가 읽을 가짜 EditorNode 로 변환합니다.
 *
 * `node.if` 에 data_source 의 `if`(또는 conditions 보존)를 얹는다. ConditionBuilder 가
 * 이 노드의 `if` 를 역해석해 절 빌더를 그린다. 가짜 노드라 components/children 은 비운다.
 *
 * @param ds data_source 객체(if/conditions 보유 가능)
 * @return ConditionBuilder 용 EditorNode (if 만 의미 있음)
 */
export function dataSourceToConditionNode(ds: DataSourceConditionShape): EditorNode {
  const node: EditorNode = {} as EditorNode;
  // data_source 의 조건식을 node 최상위 if 로 노출(ConditionBuilder 가 읽는 위치).
  if (typeof ds.if === 'string' && ds.if.trim().length > 0) {
    (node as Record<string, unknown>).if = ds.if;
  }
  return node;
}

/**
 * ConditionBuilder 가 패치한 노드의 `if` 를 data_source 에 역적용합니다.
 *
 * 빈 식(빌더가 모든 절 제거)이면 `if` 키를 제거한 사본. `conditions`(레거시 배열)는
 * 빌더가 다루지 않으므로 원본을 보존한다. data_source 의 다른 키(id/endpoint/...)는 불변.
 *
 * @param node ConditionBuilder 가 onPatchNode 로 돌려준 노드
 * @param ds 원본 data_source
 * @return if 가 반영된 data_source 사본
 */
export function applyConditionNodeToDataSource(
  node: EditorNode,
  ds: DataSourceConditionShape,
): DataSourceConditionShape {
  const next: DataSourceConditionShape = { ...ds };
  const nextIf = (node as Record<string, unknown>).if;
  if (typeof nextIf === 'string' && nextIf.trim().length > 0) {
    next.if = nextIf;
  } else {
    delete next.if;
  }
  return next;
}
