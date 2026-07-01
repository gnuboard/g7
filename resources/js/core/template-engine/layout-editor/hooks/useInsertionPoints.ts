/**
 * useInsertionPoints.ts — 컨텍스트 + 버튼 위치/삽입 인덱스 계산
 *
 * 선택된 컴포넌트 노드와 부모 DOM 의 computed flow 를 받아, 외곽 + 버튼 4방향의
 * 활성/비활성 상태와 각 방향이 의미하는 `parentPath` + `insertionIndex` 를 계산.
 *
 * 결정 규칙:
 *  - block (부모 세로 흐름) → 상/하 2개 활성, 좌/우 비활성
 *  - flex-row 단일 행 (wrap 아님) → 좌/우 활성, 상/하 비활성
 *  - flex-column 단일 열 (wrap 아님) → 상/하 활성, 좌/우 비활성
 *  - flex-row wrap → 4방향 모두 활성, 상/하 = 줄 사이 위치(2D flow 인덱스 — Phase 3 S5b 의
 *  `dropZoneResolver` 와 합쳐 정밀화. S5a-2 는 상/하 = 부모 children 의 단순 prev/next 줄 인덱스)
 *  - flex-column wrap → 4방향 모두 활성, 좌/우 = 컬럼 사이 위치
 *  - 부모 미정 (선택 노드가 루트 children 1단계) → block 으로 취급
 *
 * 본 hook 은 React 의존 없는 순수 계산 — 테스트는 입력 axes 곱 검증.
 *
 * @since engine-v1.50.0
 */

import { useMemo } from 'react';
import { detectContainerLayoutFlow, type ContainerLayoutFlow } from '../utils/overlayGeometry';
import type { ComponentPath } from '../utils/layoutTreeUtils';
import { parseEditorPath } from './useElementSelection';

export type InsertionDirection = 'above' | 'below' | 'left' | 'right';

export interface InsertionPoint {
  direction: InsertionDirection;
  /** 비활성 여부 — 시각적으로는 표시하되 클릭 불가 */
  disabled: boolean;
  /** 활성일 때 삽입할 위치 — parentPath + index */
  insertion: { parentPath: ComponentPath; index: number } | null;
}

export interface UseInsertionPointsParams {
  /**
   * 선택 노드의 부모 DOM — getComputedStyle 기반 layout flow 판정에 사용.
   * SSR/테스트 환경에서는 명시 flow 를 옵션으로 주입 가능.
   */
  parentEl: Element | null;
  /** 선택 노드의 ComponentPath (부모 children 안 인덱스 포함) */
  selectedPath: ComponentPath | null;
  /**
   * 선택 노드의 실제 DOM 요소 — 시각 형제 사각형 분석에 사용.
   * 있을 때만 wrap/grid 케이스에서 위/아래/좌/우 의미가 시각 위치와 일치하도록
   * 자식 사각형 기반 계산을 수행한다. 없으면 단순 인덱스 매핑 폴백.
   */
  selectedEl?: Element | null;
  /**
   * 테스트/명시 주입용 — parentEl 없이 layout flow 를 직접 지정.
   * 둘 다 주어지면 explicitFlow 가 우선.
   */
  explicitFlow?: ContainerLayoutFlow['kind'];
}

export interface UseInsertionPointsReturn {
  /** 4 방향 + 활성 여부 + 삽입 정보 */
  points: InsertionPoint[];
  /** 판정된 부모 layout flow */
  flow: ContainerLayoutFlow['kind'];
}

/**
 * 컨텍스트 + 버튼 4방향 계산.
 *
 * selectedEl 이 주어진 wrap/grid_2d 케이스에서는 **시각 부모 기반 자식 사각형
 * 분석** 으로 위/아래/좌/우 의미를 시각 위치와 일치시킨다 (
 * memory: feedback_no_complexity_excuse). `display:contents` wrapper 가 트리에
 * 끼어 있어도 시각 부모(첫 비-`contents` 조상) 의 자식 사각형을 보고 같은 행/
 * 다른 행 형제를 식별, 그 형제의 `data-editor-path` 로 컴포넌트 트리 path 를
 * 역참조한 뒤 `insertion.parentPath/index` 를 계산한다.
 */
export function useInsertionPoints(params: UseInsertionPointsParams): UseInsertionPointsReturn {
  const { parentEl, selectedPath, selectedEl, explicitFlow } = params;

  return useMemo(() => {
    if (!selectedPath || selectedPath.length === 0) {
      return { points: [], flow: 'unknown' as const };
    }

    const flow = explicitFlow ?? detectContainerLayoutFlow(parentEl).kind;

    const parentPath = selectedPath.slice(0, -1);
    const selfIndex = selectedPath[selectedPath.length - 1] ?? 0;

    // 시각 자식 분석 가능 케이스 — wrap/grid_2d 에서만 시각 행/열 매핑 사용.
    // 단일 행/열 (flex_*_single/grid_single_*) 은 단순 인덱스로 충분, block 도 동일.
    const needsVisualMapping =
      (flow === 'flex_row_wrap' || flow === 'flex_column_wrap' || flow === 'grid_2d') &&
      !!selectedEl;

    if (needsVisualMapping) {
      const visualPoints = buildPointsVisual(flow, selectedEl!, parentPath, selfIndex);
      if (visualPoints) {
        return { points: visualPoints, flow };
      }
    }

    const points = buildPoints(flow, parentPath, selfIndex);
    return { points, flow };
  }, [parentEl, selectedPath, selectedEl, explicitFlow]);
}

/**
 * 시각 부모 기반 4방향 인덱스 계산 — wrap/grid_2d 케이스에서 selectedEl 의
 * 시각 형제 사각형을 보고 같은 행 / 다른 행 형제를 식별, 각 형제의
 * `data-editor-path` 로 컴포넌트 트리 path 를 역참조한다.
 *
 * `display:contents` wrapper 가 트리 부모이면 컴포넌트 트리 parentPath 와 시각
 * 부모가 다를 수 있다. 이 함수는 **시각 형제의 트리 path** 를 사용해 그 형제의
 * 컴포넌트 트리 부모(즉 wrapper) 와 형제 내 인덱스로 삽입 위치를 산출한다.
 *
 * 반환 null = 시각 형제 식별 실패(예: visual parent 미존재 / data-editor-path
 * 부재) → 호출자가 폴백 buildPoints 사용.
 */
function buildPointsVisual(
  _flow: ContainerLayoutFlow['kind'],
  selectedEl: Element,
  fallbackParentPath: ComponentPath,
  fallbackSelfIndex: number
): InsertionPoint[] | null {
  // 1) 시각 부모 = 첫 비-`contents` 조상
  let visualParent: Element | null = selectedEl.parentElement;
  while (visualParent && getDisplay(visualParent) === 'contents') {
    visualParent = visualParent.parentElement;
  }
  if (!visualParent) return null;

  // 2) 시각 자식 = visualParent 의 자식 중 `data-editor-path` 보유한 노드.
  //    `display:contents` wrapper 의 children 도 grandparent 의 시각 자식으로
  //    승격하므로 wrapper 의 children 까지 평탄화하여 수집.
  const visualChildren: Array<{ el: Element; rect: DOMRect; path: ComponentPath }> = [];
  collectVisualChildren(visualParent, visualChildren);

  if (visualChildren.length === 0) return null;

  // 3) selectedEl 기준 같은 행 / 위 행 / 아래 행 형제 식별
  const selfRect = selectedEl.getBoundingClientRect();
  const selfCenterY = selfRect.top + selfRect.height / 2;

  // 행 그룹핑 — 자식의 top 이 selfRect 와 겹치는지 (top/bottom 범위 교차) 로 같은 행 판정.
  // 행 간 정렬은 top 오름차순.
  type SiblingInfo = { rect: DOMRect; path: ComponentPath };
  const sameRow: SiblingInfo[] = [];
  const aboveRow: SiblingInfo[] = [];
  const belowRow: SiblingInfo[] = [];

  for (const child of visualChildren) {
    const r = child.rect;
    const cCenterY = r.top + r.height / 2;
    // 같은 행 판정 — 두 사각형의 y 범위가 일정 비율 이상 겹침
    const overlap = Math.min(selfRect.bottom, r.bottom) - Math.max(selfRect.top, r.top);
    const minH = Math.min(selfRect.height, r.height) || 1;
    const overlapsHorizontally = overlap / minH > 0.4;

    if (overlapsHorizontally) {
      sameRow.push({ rect: r, path: child.path });
    } else if (cCenterY < selfCenterY) {
      aboveRow.push({ rect: r, path: child.path });
    } else {
      belowRow.push({ rect: r, path: child.path });
    }
  }

  // 같은 행은 left 오름차순 정렬
  sameRow.sort((a, b) => a.rect.left - b.rect.left);
  aboveRow.sort((a, b) => a.rect.top - b.rect.top);
  belowRow.sort((a, b) => a.rect.top - b.rect.top);

  const selfIdxInSameRow = sameRow.findIndex((s) => Math.abs(s.rect.left - selfRect.left) < 1 && Math.abs(s.rect.top - selfRect.top) < 1);

  // 4) 4방향 매핑
  const above: InsertionPoint = { direction: 'above', disabled: true, insertion: null };
  const below: InsertionPoint = { direction: 'below', disabled: true, insertion: null };
  const left: InsertionPoint = { direction: 'left', disabled: true, insertion: null };
  const right: InsertionPoint = { direction: 'right', disabled: true, insertion: null };

  // 좌 = sameRow 의 selfIdxInSameRow-1 직전. 없으면 sameRow 시작.
  if (selfIdxInSameRow > 0) {
    const leftSib = sameRow[selfIdxInSameRow - 1]!;
    const ins = treePathToInsertionAfter(leftSib.path);
    if (ins) {
      left.disabled = false;
      left.insertion = ins;
    }
  } else if (sameRow.length > 0) {
    const firstSib = sameRow[0]!;
    const ins = treePathToInsertionBefore(firstSib.path);
    if (ins) {
      left.disabled = false;
      left.insertion = ins;
    }
  }

  // 우 = sameRow 의 selfIdxInSameRow+1 직전. 없으면 sameRow 끝.
  if (selfIdxInSameRow >= 0 && selfIdxInSameRow < sameRow.length - 1) {
    const rightSib = sameRow[selfIdxInSameRow + 1]!;
    const ins = treePathToInsertionBefore(rightSib.path);
    if (ins) {
      right.disabled = false;
      right.insertion = ins;
    }
  } else if (sameRow.length > 0) {
    const lastSib = sameRow[sameRow.length - 1]!;
    const ins = treePathToInsertionAfter(lastSib.path);
    if (ins) {
      right.disabled = false;
      right.insertion = ins;
    }
  }

  // 위 = aboveRow 가 있으면 aboveRow 의 마지막 형제 다음. 없으면 (첫 행) 시각 자식
  // 전체의 첫 형제 앞 (= 시각 부모의 시작). 시각 부모가 grid 면 그 첫 자식 앞 셀에
  // 새 자식이 들어가고 나머지가 한 칸씩 밀린다.
  if (aboveRow.length > 0) {
    const lastAbove = aboveRow[aboveRow.length - 1]!;
    const ins = treePathToInsertionAfter(lastAbove.path);
    if (ins) {
      above.disabled = false;
      above.insertion = ins;
    }
  } else if (visualChildren.length > 0) {
    const firstChild = visualChildren[0]!;
    const ins = treePathToInsertionBefore(firstChild.path);
    if (ins) {
      above.disabled = false;
      above.insertion = ins;
    }
  }

  // 아래 = belowRow 가 있으면 belowRow 의 첫 형제 앞. 없으면 (마지막 행) 시각 자식
  // 전체의 **마지막 형제 다음** (= 시각 부모의 끝). 시각 부모가 grid 면 그 마지막
  // 자식 다음 셀(다음 행 첫 칸)에 새 자식이 auto-place 된다.
  if (belowRow.length > 0) {
    const firstBelow = belowRow[0]!;
    const ins = treePathToInsertionBefore(firstBelow.path);
    if (ins) {
      below.disabled = false;
      below.insertion = ins;
    }
  } else if (visualChildren.length > 0) {
    const lastChild = visualChildren[visualChildren.length - 1]!;
    const ins = treePathToInsertionAfter(lastChild.path);
    if (ins) {
      below.disabled = false;
      below.insertion = ins;
    }
  } else {
    // visualChildren 자체가 비어있는 경우만 트리 부모 끝으로 폴백
    below.disabled = false;
    below.insertion = { parentPath: fallbackParentPath, index: fallbackSelfIndex + 1 };
  }

  // visualMapping 실패 케이스(모두 비활성) 가 발생할 수 없도록 보장: 최소
  // 좌/우 또는 fallback 매핑이 1건은 활성이어야 한다. 그렇지 않으면 null 로 폴백.
  if (above.disabled && below.disabled && left.disabled && right.disabled) {
    return null;
  }

  return [above, below, left, right];
}

function getDisplay(el: Element): string {
  if (typeof window === 'undefined') return '';
  return (window.getComputedStyle(el).display ?? '').toLowerCase();
}

/**
 * visualParent 의 자식을 평탄화 수집 — `display:contents` 자식은 그 children 까지
 * 펼쳐서 시각 형제로 취급. `data-editor-path` 미보유 노드는 제외.
 *
 * **iteration 자식 차단**: DOM 의 `data-editor-path` 가
 * `...iteration.{N}` 형태인 자식은 반복 매니페스트의 시각 인스턴스라 컴포넌트
 * 트리에 형제 삽입이 의미 없다. 시각 형제 목록에서 제외하면 부모의 + 버튼이
 * iteration 인스턴스 옆 위치로 매핑되지 않는다. (iteration 노드 자체 — `iteration`
 * key 가 있는 컴포넌트 노드 — 는 트리에 들어있고 그 형제로 삽입 가능하지만,
 * 본 DOM 평탄화 결과의 시각 형제는 *반복된 인스턴스* 라 별도 취급.)
 */
function collectVisualChildren(
  visualParent: Element,
  out: Array<{ el: Element; rect: DOMRect; path: ComponentPath }>
): void {
  const children = Array.from(visualParent.children);
  for (const c of children) {
    const display = getDisplay(c);
    if (display === 'contents') {
      // 평탄화 — 자기 자식을 시각 형제로 승격
      collectVisualChildren(c, out);
      continue;
    }
    const editorPath = c.getAttribute('data-editor-path');
    // iteration 인스턴스 자식은 시각 형제에서 제외 
    if (editorPath && /\.iteration\.\d+(?:$|\.)/.test(editorPath)) {
      continue;
    }
    if (!editorPath) continue;
    const rect = c.getBoundingClientRect();
    if (rect.width === 0 && rect.height === 0) continue;
    out.push({ el: c, rect, path: parseEditorPath(editorPath) });
  }
}

/**
 * 트리 path 의 `직전` 위치 삽입 = parentPath + path.last() 인덱스.
 */
function treePathToInsertionBefore(
  path: ComponentPath
): { parentPath: ComponentPath; index: number } | null {
  if (!path || path.length === 0) return null;
  const last = path[path.length - 1]!;
  // 실제 노드 path 의 마지막 세그먼트는 항상 number(슬롯 인덱스). responsive 세그먼트로
  // 끝나는 path 는 삽입 기준이 아니다.
  if (typeof last !== 'number') return null;
  return { parentPath: path.slice(0, -1), index: last };
}

/**
 * 트리 path 의 `직후` 위치 삽입 = parentPath + (path.last()+1) 인덱스.
 */
function treePathToInsertionAfter(
  path: ComponentPath
): { parentPath: ComponentPath; index: number } | null {
  if (!path || path.length === 0) return null;
  const last = path[path.length - 1]!;
  if (typeof last !== 'number') return null;
  return { parentPath: path.slice(0, -1), index: last + 1 };
}

/**
 * flow 별 4방향 활성/비활성 + 삽입 인덱스 계산.
 *
 * 본 함수는 hook 외부 호출(테스트) 도 가능하도록 export.
 */
export function buildPoints(
  flow: ContainerLayoutFlow['kind'],
  parentPath: ComponentPath,
  selfIndex: number
): InsertionPoint[] {
  const above: InsertionPoint = {
    direction: 'above',
    disabled: true,
    insertion: null,
  };
  const below: InsertionPoint = {
    direction: 'below',
    disabled: true,
    insertion: null,
  };
  const left: InsertionPoint = {
    direction: 'left',
    disabled: true,
    insertion: null,
  };
  const right: InsertionPoint = {
    direction: 'right',
    disabled: true,
    insertion: null,
  };

  const insertAt = (index: number): { parentPath: ComponentPath; index: number } => ({
    parentPath,
    index,
  });

  switch (flow) {
    case 'block':
    case 'unknown': {
      // 부모 세로 흐름 → 상/하만
      above.disabled = false;
      above.insertion = insertAt(selfIndex);
      below.disabled = false;
      below.insertion = insertAt(selfIndex + 1);
      // 좌/우 비활성
      break;
    }
    case 'flex_row_single':
    case 'grid_single_row': {
      // 단일 행 (flex-row 또는 grid 한 줄 가로 배치) → 좌/우 활성, 상/하 비활성
      left.disabled = false;
      left.insertion = insertAt(selfIndex);
      right.disabled = false;
      right.insertion = insertAt(selfIndex + 1);
      break;
    }
    case 'flex_column_single':
    case 'grid_single_column': {
      // 단일 열 (flex-column 또는 grid 한 칸 세로 배치) → 상/하 활성, 좌/우 비활성
      above.disabled = false;
      above.insertion = insertAt(selfIndex);
      below.disabled = false;
      below.insertion = insertAt(selfIndex + 1);
      break;
    }
    case 'flex_row_wrap':
    case 'grid_2d': {
      // 2D 흐름 (wrap row 또는 grid 다중 행·열) → 4방향 모두 활성
      // 좌/우 = 형제 prev/next
      left.disabled = false;
      left.insertion = insertAt(selfIndex);
      right.disabled = false;
      right.insertion = insertAt(selfIndex + 1);
      // 상/하 = 줄 사이 (S5a-2 단순화: 상=배열 시작, 하=배열 끝.
      // S5b dropZoneResolver 가 렌더 사각형으로 정밀 인덱스 계산)
      above.disabled = false;
      above.insertion = insertAt(0);
      below.disabled = false;
      below.insertion = insertAt(-1); // 호출자가 children.length 로 클램프
      break;
    }
    case 'flex_column_wrap': {
      // wrap column — 대칭
      above.disabled = false;
      above.insertion = insertAt(selfIndex);
      below.disabled = false;
      below.insertion = insertAt(selfIndex + 1);
      left.disabled = false;
      left.insertion = insertAt(0);
      right.disabled = false;
      right.insertion = insertAt(-1);
      break;
    }
  }

  return [above, below, left, right];
}
