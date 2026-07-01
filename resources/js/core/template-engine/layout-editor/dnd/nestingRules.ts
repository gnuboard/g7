/**
 * nestingRules.ts — 드래그/추가 nesting 규칙 평가
 *
 * `editor-spec.json` 의 `nesting` 블록을 받아 다음을 판정한다:
 *  - `isDraggableNode(componentName, nesting)` — 그 컴포넌트가 드래그 가능한가
 *  - `canDrop(dragged, target, nesting)` — dragged 컴포넌트를 target 컨테이너에
 *    드롭/추가할 수 있는가
 *
 * 평가 규칙:
 *  - `nesting` 이 비어있거나 `draggable`/`containers` 가 미정의 → 모든 드래그/
 *    드롭 거부(폴백 없음 — 결정 3.3).
 *  - `draggable` 목록에 없는 컴포넌트는 드래그 불가.
 *  - target 컨테이너의 `containers[target].accepts` 가 dragged 를 포함해야 드롭
 *    허용. 엔트리 자체가 없으면 거부.
 *
 * 본 모듈은 sortable 속성 / 데이터 결정 영역 / base 출처 잠금 등은 평가하지
 * 않는다 — 이들은 호출자(`useCanvasDnd` 등)가 nesting 평가 *전에* 가드한다.
 *
 * @since engine-v1.50.0
 */

import type { NestingSpec } from '../spec/specTypes';
import type { ComponentPath } from '../utils/layoutTreeUtils';

export interface CanDropParams {
  /** 드래그/추가 중인 컴포넌트 이름 */
  draggedComponentName: string;
  /** 드롭 대상 컨테이너의 컴포넌트 이름 */
  targetContainerName: string;
  /** 평가에 사용할 nesting 스펙. 없으면 거부 */
  nesting: NestingSpec | undefined | null;
}

/**
 * 드래그 가능 여부 — `nesting.draggable` 목록 포함 여부.
 */
export function isDraggableNode(
  componentName: string,
  nesting: NestingSpec | undefined | null
): boolean {
  if (!nesting || !Array.isArray(nesting.draggable)) {
    return false;
  }
  return nesting.draggable.includes(componentName);
}

/**
 * 드롭 허용 여부 평가.
 *
 * 두 조건이 모두 충족되어야 true:
 *  1. dragged 가 `nesting.draggable` 에 포함
 *  2. target 컨테이너의 `containers[target].accepts` 가 dragged 포함
 */
export function canDrop({
  draggedComponentName,
  targetContainerName,
  nesting,
}: CanDropParams): boolean {
  if (!nesting) {
    return false;
  }

  if (!isDraggableNode(draggedComponentName, nesting)) {
    return false;
  }

  const containers = nesting.containers;
  if (!containers || typeof containers !== 'object') {
    return false;
  }

  const rule = containers[targetContainerName];
  if (!rule || !Array.isArray(rule.accepts)) {
    return false;
  }

  return rule.accepts.includes(draggedComponentName);
}

/**
 * 컨테이너 여부 — `nesting.containers[name].accepts` 가 비어있지 않으면 컨테이너.
 *
 * Note: `accepts: []` 는 명시적 자식 거부(leaf/composite)이며, 컨테이너성을 갖지
 * 않는다고 본다. 이는 의 "위치 미지정 시 컨테이너성 판정" 의 기준이다.
 */
export function isContainerComponent(
  componentName: string,
  nesting: NestingSpec | undefined | null
): boolean {
  if (!nesting?.containers) return false;
  const rule = nesting.containers[componentName];
  return Boolean(rule && Array.isArray(rule.accepts) && rule.accepts.length > 0);
}

/**
 * 글로벌 팔레트("+ 요소 추가") 의 삽입 위치 판정.
 *
 * 선택 노드가 **컨테이너이면 그 노드의 children 끝**에, 컨테이너가 아니면
 * **그 형제 다음**에 삽입한다. 컨테이너 여부는 `nesting.containers` 정의 기준
 * (`isContainerComponent`) — children 배열의 존재 여부가 아니다. 빈 컨테이너
 * (자식이 아직 없어 `children` 이 미정의/비배열인 Div/Form 등)도 컨테이너로
 * 인식되어야 첫 자식을 그 컨테이너 accepts 대로 추가할 수 있다(드롭 경로의
 * `isContainerComponent` 기반 nest 슬롯과 동일한 기준 — 경로 간 정합).
 *
 * @param selectedComponentName 선택 노드의 컴포넌트 이름 (`node.name`)
 * @param selectedChildrenCount 선택 노드의 현재 자식 수 (없으면 0)
 * @param selectedPathIndexes 선택 노드의 트리 인덱스 경로
 * @param nesting 평가에 사용할 nesting 스펙
 * @param rootChildrenCount 루트 children 수 (선택 없음 폴백용)
 * @returns 삽입할 부모 경로와 인덱스
 * @since engine-v1.50.0
 */
export function resolveGlobalInsertionTarget(
  selectedComponentName: string | undefined,
  selectedChildrenCount: number,
  selectedPathIndexes: ComponentPath | null | undefined,
  nesting: NestingSpec | undefined | null,
  rootChildrenCount: number
): { parentPath: ComponentPath; index: number } {
  if (!selectedPathIndexes || selectedPathIndexes.length === 0) {
    return { parentPath: [], index: rootChildrenCount };
  }
  if (selectedComponentName && isContainerComponent(selectedComponentName, nesting)) {
    // 컨테이너 → children 끝 (빈 컨테이너도 동일 — index 0)
    return { parentPath: selectedPathIndexes, index: selectedChildrenCount };
  }
  // 비컨테이너 → 형제 다음
  const parentPath = selectedPathIndexes.slice(0, -1);
  const lastIdx = selectedPathIndexes[selectedPathIndexes.length - 1] ?? 0;
  return { parentPath, index: lastIdx + 1 };
}
