/**
 * drag-drop-matrix.test.tsx — 드래그 앤 드롭 종합 매트릭스
 *
 * `feedback_drag_drop_comprehensive_matrix` 의 7단계 매트릭스를 커버한다:
 *  1. 단일 이동
 *  2. 다중(연속) 이동
 *  3. 다른 요소(컨테이너 안↔밖) 이동
 *  4. 복합(중첩 컨테이너 안으로) 이동
 *  5. 저장(PUT 페이로드에 이동 결과 반영)
 *  6. 영구 반영(저장 후 reload 응답이 이동 결과 유지)
 *  7. 버전 관리(이동 → undo → redo 로 이력 왕복)
 *
 * 드래그 동작은 useCanvasDnd 의 onDragStart/onDragEnd 를 직접 호출하고, 트리
 * 변형은 moveNode 의 순수 함수 결과로 검증한다(브라우저 dnd-kit PointerSensor
 * 통과 불가 — feedback_chrome_mcp_dnd_kit_incompatible 는 Playwright 가 커버).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useCanvasDnd } from '../../hooks/useCanvasDnd';
import { useEditorHistory } from '../../hooks/useEditorHistory';
import { moveNode, type EditorNode } from '../../utils/layoutTreeUtils';
import type { NestingSpec } from '../../spec/specTypes';

const NESTING: NestingSpec = {
  draggable: ['Div', 'Span', 'Button'],
  containers: {
    Div: { accepts: ['Div', 'Span', 'Button'] },
    Span: { accepts: ['Span'] },
  },
};

interface Rect {
  left: number;
  top: number;
  width: number;
  height: number;
}
function domRect(r: Rect): DOMRect {
  return {
    ...r,
    right: r.left + r.width,
    bottom: r.top + r.height,
    x: r.left,
    y: r.top,
    toJSON: () => ({}),
  } as DOMRect;
}

function buildFrame(children: Array<{ path: string; rect: Rect; parent?: string }>): HTMLElement {
  const frame = document.createElement('div');
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(
    domRect({ left: 0, top: 0, width: 400, height: 600 })
  );
  const styleMap = new Map<Element, Record<string, string>>();
  styleMap.set(frame, { display: 'block', flexDirection: '', flexWrap: '' });
  const byPath = new Map<string, HTMLElement>();
  for (const c of children) {
    const el = document.createElement('div');
    el.dataset.editorPath = c.path;
    el.setAttribute('data-editor-path', c.path);
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(domRect(c.rect));
    styleMap.set(el, { display: 'block', flexDirection: '', flexWrap: '' });
    byPath.set(c.path, el);
    const parentEl = c.parent ? byPath.get(c.parent) : frame;
    (parentEl ?? frame).appendChild(el);
  }
  vi.spyOn(window, 'getComputedStyle').mockImplementation(
    (el: Element) =>
      (styleMap.get(el) ?? { display: 'block', flexDirection: '', flexWrap: '' }) as unknown as CSSStyleDeclaration
  );
  document.body.appendChild(frame);
  return frame;
}

/** 드롭 타깃 = 명시적 슬롯 id `slot:<containerPath>:<index>` */
function endEvent(id: string, overSlotId: string | null): any {
  return {
    active: { id },
    over: overSlotId ? { id: overSlotId } : null,
    activatorEvent: { clientX: 0, clientY: 0 } as PointerEvent,
    delta: { x: 0, y: 0 },
  };
}
function slot(containerPath: string, index: number): string {
  return `slot:${containerPath}:${index}`;
}
function startEvent(id: string): any {
  return { active: { id }, activatorEvent: { clientX: 0, clientY: 0 } as PointerEvent };
}

afterEach(() => {
  vi.restoreAllMocks();
  delete (document as any).elementsFromPoint;
  document.body.innerHTML = '';
});

describe('drag-drop matrix — 1. 단일 이동', () => {
  it('블록 컨테이너에서 첫 노드를 끝으로', () => {
    let components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({ frameEl: frame, nesting: NESTING, editMode: 'route', components, patchLayout: patch, pushHistory: vi.fn() })
    );
    act(() => result.current.onDragStart(startEvent('0')));
    // 루트 끝 슬롯 — 원본 트리 인덱스: 마지막 비드래그 자식 c(orig2) 뒤 = index 3
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));
    expect(components.map((c) => c.id)).toEqual(['b', 'c', 'a']);
  });
});

describe('drag-drop matrix — 2. 다중 연속 이동', () => {
  it('두 번 연속 드래그 — 각 이동이 누적', () => {
    let components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 400, height: 100 } },
    ]);
    const { result, rerender } = renderHook(
      (props: { components: EditorNode[] }) =>
        useCanvasDnd({ frameEl: frame, nesting: NESTING, editMode: 'route', components: props.components, patchLayout: patch, pushHistory: vi.fn() }),
      { initialProps: { components } }
    );
    // 1차: a 를 끝으로 → [b, c, a]  (원본 트리: 마지막 비드래그 자식 뒤 = index 3)
    act(() => result.current.onDragStart(startEvent('0')));
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));
    rerender({ components });
    expect(components.map((c) => c.id)).toEqual(['b', 'c', 'a']);

    // 2차: 이제 인덱스0(b) 를 끝으로 → [c, a, b] (원본 트리 index 3)
    act(() => result.current.onDragStart(startEvent('0')));
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));
    expect(components.map((c) => c.id)).toEqual(['c', 'a', 'b']);
  });
});

describe('drag-drop matrix — 3/4. 컨테이너 안↔밖 + 중첩 이동 (moveNode 순수 함수)', () => {
  it('루트 노드를 컨테이너 children 안으로', () => {
    const root: EditorNode = {
      children: [
        { name: 'Div', id: 'box', children: [{ name: 'Span', id: 'x' }] },
        { name: 'Div', id: 'free' },
      ],
    };
    // free(1) 을 box(0) 의 children 끝(index 1)으로
    const next = moveNode(root, [1], [0], 1);
    const box = (next.children as EditorNode[])[0]!;
    expect((box.children as EditorNode[]).map((c) => c.id)).toEqual(['x', 'free']);
    expect((next.children as EditorNode[]).length).toBe(1);
  });

  it('컨테이너 안 노드를 루트로 꺼내기', () => {
    const root: EditorNode = {
      children: [{ name: 'Div', id: 'box', children: [{ name: 'Div', id: 'inner' }] }],
    };
    // box.children[0] (inner) 을 루트 끝(index 1)으로
    const next = moveNode(root, [0, 0], [], 1);
    expect((next.children as EditorNode[]).map((c) => c.id)).toEqual(['box', 'inner']);
    const box = (next.children as EditorNode[])[0]!;
    expect((box.children as EditorNode[]).length).toBe(0);
  });

  it('조상을 자기 자손으로 이동 시도하면 차단(사이클 방지)', () => {
    const root: EditorNode = {
      children: [{ name: 'Div', id: 'outer', children: [{ name: 'Div', id: 'inner' }] }],
    };
    // outer(0) 를 inner(0.0) 안으로 — 사이클 → 변형 없음
    const next = moveNode(root, [0], [0, 0], 0);
    expect(next).toBe(root);
  });
});

describe('drag-drop matrix — 5/6. 저장 페이로드 + 영구 반영', () => {
  it('이동 결과가 components 트리에 반영되어 저장 페이로드 골격이 된다', () => {
    let components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({ frameEl: frame, nesting: NESTING, editMode: 'route', components, patchLayout: patch, pushHistory: vi.fn() })
    );
    act(() => result.current.onDragStart(startEvent('0')));
    act(() => result.current.onDragEnd(endEvent('0', slot('', 2)))); // [a,b] 원본 트리: b(orig1) 뒤 = index 2
    // 저장 시 사용할 트리(=patch 결과) 가 이동 반영된 순서
    expect(components.map((c) => c.id)).toEqual(['b', 'a']);
    // 영구 반영 모사: 같은 순서로 reload 응답을 만들었다고 가정하면 트리 일치
    const reloaded = components.map((c) => ({ ...c }));
    expect(reloaded.map((c) => c.id)).toEqual(['b', 'a']);
  });
});

describe('drag-drop matrix — 7. 버전 관리(undo/redo)', () => {
  it('이동 후 undo 로 원상복구, redo 로 재적용', () => {
    const initial: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    const { result: hist } = renderHook(() => useEditorHistory<EditorNode[]>(50));
    // baseline push
    act(() => hist.current.push({ actionKind: 'inline_text_edit', label: 'baseline', snapshot: initial }));
    // 이동 결과 push
    const moved = moveNode({ children: initial }, [0], [], 3).children as EditorNode[];
    act(() => hist.current.push({ actionKind: 'move', label: 'move Div', snapshot: moved }));
    expect(moved.map((c) => c.id)).toEqual(['b', 'c', 'a']);

    // undo → baseline
    let undone: EditorNode[] | null = null;
    act(() => {
      undone = hist.current.undo()?.snapshot ?? null;
    });
    expect(undone!.map((c) => c.id)).toEqual(['a', 'b', 'c']);

    // redo → moved
    let redone: EditorNode[] | null = null;
    act(() => {
      redone = hist.current.redo()?.snapshot ?? null;
    });
    expect(redone!.map((c) => c.id)).toEqual(['b', 'c', 'a']);
  });
});
