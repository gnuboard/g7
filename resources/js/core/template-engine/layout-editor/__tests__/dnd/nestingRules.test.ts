/**
 * nestingRules.test.ts — 드래그/추가 nesting 평가 (10.11.4 DataProvider)
 *
 *
 */

import { describe, it, expect } from 'vitest';
import {
  canDrop,
  isDraggableNode,
  isContainerComponent,
  resolveGlobalInsertionTarget,
} from '../../dnd/nestingRules';
import type { NestingSpec } from '../../spec/specTypes';

const FULL_SPEC: NestingSpec = {
  draggable: ['Div', 'Span', 'P', 'Img', 'Card'],
  containers: {
    Div: { accepts: ['Div', 'P', 'Span', 'Img', 'Card'] },
    P: { accepts: ['Span', 'Img'] },
    Span: { accepts: ['Span', 'Img'] },
    Img: { accepts: [] },
    Card: { accepts: [] }, // composite — 자식 거부
    Table: { accepts: ['Div', 'P'] },
  },
};

describe('nestingRules — isDraggableNode', () => {
  it('draggable 목록에 있으면 true', () => {
    expect(isDraggableNode('Div', FULL_SPEC)).toBe(true);
    expect(isDraggableNode('Card', FULL_SPEC)).toBe(true);
  });

  it('draggable 목록에 없으면 false', () => {
    expect(isDraggableNode('Form', FULL_SPEC)).toBe(false);
    expect(isDraggableNode('Unknown', FULL_SPEC)).toBe(false);
  });

  it('nesting 자체가 없으면 false', () => {
    expect(isDraggableNode('Div', null)).toBe(false);
    expect(isDraggableNode('Div', undefined)).toBe(false);
    expect(isDraggableNode('Div', {})).toBe(false);
  });
});

describe('nestingRules — canDrop (DataProvider)', () => {
  const cases: Array<{
    label: string;
    dragged: string;
    target: string;
    expected: boolean;
  }> = [
    // 이슈 예시: Div → Img 거부 (Img.accepts=[])
    { label: 'Div → Img 거부 (Img.accepts=[])', dragged: 'Div', target: 'Img', expected: false },

    // composite 내부 거부 (Card.accepts=[])
    { label: 'Div → Card 거부 (composite 내부)', dragged: 'Div', target: 'Card', expected: false },

    // 정상 케이스
    { label: 'Div → Div 허용', dragged: 'Div', target: 'Div', expected: true },
    { label: 'Span → Div 허용', dragged: 'Span', target: 'Div', expected: true },
    { label: 'Img → Span 허용', dragged: 'Img', target: 'Span', expected: true },
    { label: 'P → Table 허용', dragged: 'P', target: 'Table', expected: true },
    { label: 'Card → Div 허용 (composite 도 통째 이동)', dragged: 'Card', target: 'Div', expected: true },

    // draggable 외 컴포넌트는 불가
    { label: 'Form → Div 거부 (Form 은 draggable 외)', dragged: 'Form', target: 'Div', expected: false },

    // 컨테이너 정의 자체가 없는 경우
    { label: 'Div → Unknown 거부 (containers 엔트리 없음)', dragged: 'Div', target: 'Unknown', expected: false },

    // accepts 가 dragged 를 포함하지 않는 경우
    { label: 'Div → P 거부 (P.accepts 에 Div 없음)', dragged: 'Div', target: 'P', expected: false },
  ];

  for (const c of cases) {
    it(c.label, () => {
      expect(
        canDrop({
          draggedComponentName: c.dragged,
          targetContainerName: c.target,
          nesting: FULL_SPEC,
        })
      ).toBe(c.expected);
    });
  }

  it('nesting 미제공 시 모두 거부 (폴백 없음 — 결정 3.3)', () => {
    expect(
      canDrop({ draggedComponentName: 'Div', targetContainerName: 'Div', nesting: null })
    ).toBe(false);
    expect(
      canDrop({ draggedComponentName: 'Div', targetContainerName: 'Div', nesting: undefined })
    ).toBe(false);
    expect(
      canDrop({ draggedComponentName: 'Div', targetContainerName: 'Div', nesting: {} })
    ).toBe(false);
  });
});

describe('nestingRules — isContainerComponent ', () => {
  it('accepts 가 비어있지 않으면 컨테이너', () => {
    expect(isContainerComponent('Div', FULL_SPEC)).toBe(true);
    expect(isContainerComponent('P', FULL_SPEC)).toBe(true);
  });

  it('accepts=[] 는 명시적 거부 → 컨테이너 아님', () => {
    expect(isContainerComponent('Img', FULL_SPEC)).toBe(false);
    expect(isContainerComponent('Card', FULL_SPEC)).toBe(false);
  });

  it('containers 엔트리 자체가 없으면 컨테이너 아님', () => {
    expect(isContainerComponent('Unknown', FULL_SPEC)).toBe(false);
  });
});

describe('nestingRules — resolveGlobalInsertionTarget', () => {
  it('선택 없음 → 루트 끝', () => {
    expect(
      resolveGlobalInsertionTarget(undefined, 0, null, FULL_SPEC, 3)
    ).toEqual({ parentPath: [], index: 3 });
    expect(
      resolveGlobalInsertionTarget('Div', 0, [], FULL_SPEC, 5)
    ).toEqual({ parentPath: [], index: 5 });
  });

  it('빈 컨테이너(자식 0) 선택 → 그 컨테이너 children 끝(index 0)에 삽입 — 부모 형제 다음 아님', () => {
    // 회귀: children 배열이 없어 isContainer=false 로 빠지던 결함.
    // 선택 경로 [0,2] 인 빈 Div → parentPath=[0,2], index=0 이어야 한다.
    expect(
      resolveGlobalInsertionTarget('Div', 0, [0, 2], FULL_SPEC, 4)
    ).toEqual({ parentPath: [0, 2], index: 0 });
  });

  it('자식 있는 컨테이너 선택 → children 끝', () => {
    expect(
      resolveGlobalInsertionTarget('Div', 3, [1], FULL_SPEC, 4)
    ).toEqual({ parentPath: [1], index: 3 });
  });

  it('비컨테이너(accepts=[]) 선택 → 형제 다음', () => {
    // Img(accepts=[]) 선택 [0,1] → 부모 [0], 형제 다음 index 2
    expect(
      resolveGlobalInsertionTarget('Img', 0, [0, 1], FULL_SPEC, 4)
    ).toEqual({ parentPath: [0], index: 2 });
  });

  it('leaf(composite Card, accepts=[]) 선택 → 형제 다음 (컨테이너로 오인 안 함)', () => {
    expect(
      resolveGlobalInsertionTarget('Card', 0, [2], FULL_SPEC, 4)
    ).toEqual({ parentPath: [], index: 3 });
  });
});
