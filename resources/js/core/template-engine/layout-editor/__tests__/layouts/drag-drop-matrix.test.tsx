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

import { describe, it, expect, afterEach, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import { useCanvasDnd } from '../../hooks/useCanvasDnd';
import { useEditorHistory } from '../../hooks/useEditorHistory';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
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
  vi.unstubAllGlobals();
  delete (document as any).elementsFromPoint;
  document.body.innerHTML = '';
});

describe('drag-drop matrix — 1. 단일 이동', () => {
  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_moves_node_within_container_and_pushes_history
   */
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
    const pushHistory = vi.fn();
    const { result } = renderHook(() =>
      useCanvasDnd({ frameEl: frame, nesting: NESTING, editMode: 'route', components, patchLayout: patch, pushHistory })
    );
    act(() => result.current.onDragStart(startEvent('0')));
    // 루트 끝 슬롯 — 원본 트리 인덱스: 마지막 비드래그 자식 c(orig2) 뒤 = index 3
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));
    expect(components.map((c) => c.id)).toEqual(['b', 'c', 'a']);
    // 드래그 1회 = 이력 1회. 넘긴 스냅샷이 이동 결과와 같은 순서다.
    expect(pushHistory).toHaveBeenCalledTimes(1);
    const [snapshot, label] = pushHistory.mock.calls[0] as [EditorNode[], string];
    expect(snapshot.map((c) => c.id)).toEqual(['b', 'c', 'a']);
    expect(label).toContain('move');
  });
});

describe('drag-drop matrix — 2. 다중 연속 이동', () => {
  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_consecutive_moves_accumulate
   */
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
  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_moves_root_node_into_container
   */
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

  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_moves_nested_node_out_to_root
   */
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

  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_blocks_ancestor_into_descendant
   */
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
  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_move_persists_in_save_payload_and_reload
   */
  it('이동 결과가 저장 PUT 페이로드에 실리고 reload 응답에서도 유지된다', async () => {
    // 서버 저장본 — PUT 이 content 로 갱신하고 GET 이 그대로 돌려준다.
    let serverContent: Record<string, unknown> = {
      components: [
        { name: 'Div', id: 'a' },
        { name: 'Div', id: 'b' },
      ],
    };
    let lockVersion = 1;
    const fetchSpy = vi.fn(async (_url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        serverContent = JSON.parse(JSON.parse(String(init.body)).content);
        lockVersion += 1;
        return { ok: true, status: 200, json: async () => ({ data: { lock_version: lockVersion } }) };
      }
      return { ok: true, status: 200, json: async () => ({ data: { ...serverContent, lock_version: lockVersion } }) };
    });
    vi.stubGlobal('fetch', fetchSpy);

    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const wrapper: React.FC<{ children: React.ReactNode }> = ({ children }) =>
      React.createElement(LayoutEditorProvider, { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko', children });
    const { result } = renderHook(
      () => {
        const editor = useLayoutEditor();
        const doc = useLayoutDocument();
        const components = ((doc.document?.raw as { components?: EditorNode[] } | undefined)?.components ?? []) as EditorNode[];
        const dnd = useCanvasDnd({
          frameEl: frame,
          nesting: NESTING,
          editMode: 'route',
          components,
          patchLayout: doc.patchLayout,
          pushHistory: () => {},
        });
        return { editor, doc, dnd, components };
      },
      { wrapper }
    );

    act(() => {
      result.current.editor.dispatch({ type: 'SELECT_ROUTE', route: { path: '/dnd-save', layoutName: 'dnd_save_matrix' } });
    });
    await waitFor(() => expect(result.current.components.map((c) => c.id)).toEqual(['a', 'b']));

    act(() => result.current.dnd.onDragStart(startEvent('0')));
    act(() => result.current.dnd.onDragEnd(endEvent('0', slot('', 2)))); // [a,b] 원본 트리: b(orig1) 뒤 = index 2
    expect(result.current.components.map((c) => c.id)).toEqual(['b', 'a']);

    // 5. 저장 — PUT 본문 content.components 가 이동 결과 순서다.
    await act(async () => {
      await result.current.doc.save();
    });
    const putCalls = fetchSpy.mock.calls.filter((c) => c[1]?.method === 'PUT');
    expect(putCalls).toHaveLength(1);
    const putContent = JSON.parse(JSON.parse(String(putCalls[0]![1]!.body)).content);
    expect((putContent.components as EditorNode[]).map((c) => c.id)).toEqual(['b', 'a']);

    // 6. 영구 반영 — reload 가 서버를 다시 조회해도 이동 결과가 유지된다.
    const getCountBefore = fetchSpy.mock.calls.filter((c) => c[1]?.method !== 'PUT').length;
    await act(async () => {
      await result.current.doc.reload();
    });
    expect(fetchSpy.mock.calls.filter((c) => c[1]?.method !== 'PUT').length).toBe(getCountBefore + 1);
    await waitFor(() => expect(result.current.components.map((c) => c.id)).toEqual(['b', 'a']));
    expect(result.current.doc.isDirty).toBe(false);
  });
});

describe('drag-drop matrix — 7. 버전 관리(undo/redo)', () => {
  /**
   * @scenario roundtrip=drag_drop
   * @effects dnd_move_undo_redo_via_drag_history
   */
  it('이동 후 undo 로 원상복구, redo 로 재적용', () => {
    const initial: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    let components = initial;
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 400, height: 100 } },
    ]);
    const { result: hist } = renderHook(() => useEditorHistory<EditorNode[]>(50));
    // baseline push
    act(() => hist.current.push({ actionKind: 'inline_text_edit', label: 'baseline', snapshot: initial }));
    // 이동 이력은 드래그(onDragEnd)가 pushHistory 로 직접 쌓는다.
    const { result: dnd } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: patch,
        pushHistory: (snapshot, label) => hist.current.push({ actionKind: 'move', label, snapshot }),
      })
    );
    act(() => dnd.current.onDragStart(startEvent('0')));
    act(() => dnd.current.onDragEnd(endEvent('0', slot('', 3))));
    expect(components.map((c) => c.id)).toEqual(['b', 'c', 'a']);
    expect(hist.current.canUndo).toBe(true);

    // undo → baseline
    let undone: EditorNode[] | null = null;
    act(() => {
      undone = hist.current.undo()?.snapshot ?? null;
    });
    expect(undone!.map((c) => c.id)).toEqual(['a', 'b', 'c']);

    // redo → 드래그가 쌓은 이동 결과
    let redone: EditorNode[] | null = null;
    act(() => {
      redone = hist.current.redo()?.snapshot ?? null;
    });
    expect(redone!.map((c) => c.id)).toEqual(['b', 'c', 'a']);
  });
});
