/**
 * useCanvasDnd.test.ts — 드래그 앤 드롭 재배치 오케스트레이션
 *
 * dnd-kit 이벤트(onDragStart/onDragMove/onDragEnd)를 직접 호출해:
 *  - moveNode 적용 → patchLayout 호출
 *  - pushHistory(actionKind: move) 호출
 *  - nesting/잠금 거부 시 이동 생략
 *  - no-op(같은 위치) 가드
 *  - devtools editor-dnd 1회 적재(source='drag')
 * 를 검증한다.
 *
 * jsdom 기하 모킹 — frame 요소 + getBoundingClientRect + elementsFromPoint.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useCanvasDnd } from '../../hooks/useCanvasDnd';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { NestingSpec } from '../../spec/specTypes';
import * as trackers from '../../devtools/editorTrackers';

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
function rect(r: Rect): DOMRect {
  return {
    left: r.left,
    top: r.top,
    width: r.width,
    height: r.height,
    right: r.left + r.width,
    bottom: r.top + r.height,
    x: r.left,
    y: r.top,
    toJSON: () => ({}),
  } as DOMRect;
}

/** 루트 직속 children 을 가로/세로 박스로 모킹한 frame 생성 */
function buildFrame(
  children: Array<{ path: string; rect: Rect }>,
  frameRect: Rect = { left: 0, top: 0, width: 400, height: 400 }
): HTMLElement {
  const frame = document.createElement('div');
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(rect(frameRect));
  const styleMap = new Map<Element, Record<string, string>>();
  styleMap.set(frame, { display: 'block', flexDirection: '', flexWrap: '' });
  for (const c of children) {
    const el = document.createElement('div');
    el.dataset.editorPath = c.path;
    el.setAttribute('data-editor-path', c.path);
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(rect(c.rect));
    styleMap.set(el, { display: 'block', flexDirection: '', flexWrap: '' });
    frame.appendChild(el);
  }
  vi.spyOn(window, 'getComputedStyle').mockImplementation(
    (el: Element) =>
      (styleMap.get(el) ?? { display: 'block', flexDirection: '', flexWrap: '' }) as unknown as CSSStyleDeclaration
  );
  document.body.appendChild(frame);
  return frame;
}

/**
 * dnd-kit DragEnd/Move 이벤트 스텁 — 명시적 드롭존 기준.
 * 드롭 타깃은 dnd-kit `over`(hover 중 droppable slot id)로 결정되므로,
 * 슬롯 id `slot:<containerPath>:<index>` 를 직접 전달한다. (기하 좌표 불요)
 */
function endEvent(id: string, overSlotId: string | null): any {
  return {
    active: { id },
    over: overSlotId ? { id: overSlotId } : null,
    activatorEvent: { clientX: 0, clientY: 0 } as PointerEvent,
    delta: { x: 0, y: 0 },
  };
}
/** 슬롯 id 헬퍼 — `slot:<containerPath>:<index>` */
function slot(containerPath: string, index: number): string {
  return `slot:${containerPath}:${index}`;
}
function startEvent(id: string): any {
  return { active: { id }, activatorEvent: { clientX: 0, clientY: 0 } as PointerEvent };
}

/** 트리 전체 노드 수(재귀) */
function countNodes(nodes: EditorNode[]): number {
  let n = 0;
  for (const node of nodes) {
    n += 1;
    if (Array.isArray(node.children)) n += countNodes(node.children as EditorNode[]);
  }
  return n;
}

/** 트리 전체 id 수집(재귀) */
function collectIds(nodes: EditorNode[]): string[] {
  const out: string[] = [];
  for (const node of nodes) {
    if (typeof node.id === 'string') out.push(node.id);
    if (Array.isArray(node.children)) out.push(...collectIds(node.children as EditorNode[]));
  }
  return out;
}

describe('useCanvasDnd — block flow 재배치', () => {
  let patchSpy: ReturnType<typeof vi.fn>;
  let pushSpy: ReturnType<typeof vi.fn>;
  let components: EditorNode[];

  beforeEach(() => {
    components = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    patchSpy = vi.fn((patcher: (c: EditorNode[]) => EditorNode[]) => {
      components = patcher(components);
    });
    pushSpy = vi.fn();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (document as any).elementsFromPoint;
    document.body.innerHTML = '';
  });

  it('첫 노드를 끝으로 드래그하면 순서가 [b, c, a] 로 변경', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: patchSpy,
        pushHistory: pushSpy,
      })
    );

    act(() => result.current.onDragStart(startEvent('0')));
    // 루트 끝 슬롯 — 원본 트리 인덱스: 마지막 비드래그 자식 c(orig2) 뒤 = index 3.
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));

    expect(patchSpy).toHaveBeenCalledTimes(1);
    expect(components.map((c) => c.id)).toEqual(['b', 'c', 'a']);
    expect(pushSpy).toHaveBeenCalledTimes(1);
    expect(pushSpy.mock.calls[0]![1]).toContain('move');
  });

  it('스냅샷 경로 — 앞쪽 형제 노드를 뒤쪽 컨테이너 안으로 이동해도 유실되지 않음', () => {
    // 위젯 사라짐 결함의 정확한 형태: 드래그 노드(A, path 0)가 대상 컨테이너(G, path 1)의
    // **앞쪽 형제**. A 제거 시 G 의 path 가 1→0 으로 밀려, 원본 좌표 toParentPath=[1] 이
    // base 트리에서 어긋나 insert 실패 → A 유실. rebase/원본-moveNode 로 보존되어야 한다.
    let nested: EditorNode[] = [
      { name: 'Div', id: 'A' },
      { name: 'Div', id: 'G', children: [{ name: 'Div', id: 'w0' }, { name: 'Div', id: 'w1' }] },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      nested = p(nested);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 80 } }, // A
      { path: '1', rect: { left: 0, top: 80, width: 400, height: 240 } }, // G (컨테이너)
    ]);
    // 위젯 w0,w1 을 G(1) 안에 중첩
    const g = frame.querySelector('[data-editor-path="1"]')!;
    for (const [p, top] of [['1.children.0', 100], ['1.children.1', 200]] as const) {
      const el = document.createElement('div');
      el.dataset.editorPath = p;
      el.setAttribute('data-editor-path', p);
      vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(
        rect({ left: 10, top, width: 380, height: 80 })
      );
      g.appendChild(el);
    }
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: nested,
        patchLayout: patch,
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // A, fromPath [0]
    // G(1) 안 w0·w1 사이 슬롯(index 1) — A 를 G 안으로. 노드 유실 없이 보존.
    act(() => result.current.onDragEnd(endEvent('0', slot('1', 1))));

    // A 가 유실되지 않고 G 안으로 이동 — 루트엔 G 하나, G.children 은 A 포함 3개.
    const totalNodes = countNodes(nested);
    expect(totalNodes, 'A 가 유실되지 않고 트리에 보존(총 4 노드)').toBe(4);
    const ids = collectIds(nested);
    expect(ids).toContain('A');
  });

  it('채워진 Div 부모(contents 래퍼 모사) 안에서 형제 재배치 허용', () => {
    // 위젯 카드들이 채워진 일반 Div(W) 안에 있음. 같은 부모 내 카드 재배치는 nest 가
    // 아니라 형제 순서 변경이므로, allowsNestingInContainer 의 빈/레이아웃 제한과 무관하게
    // 허용되어 W 안 인덱스로 commit 되어야 한다(grandparent 로 새어나가 전체 폭 배치 X).
    // 카드는 실제 stat 카드처럼 콘텐츠가 채워져 있음(자식 보유) — 빈 컨테이너 nest 예외를
    // 타지 않도록. 채워진 카드 위 드롭 → nest 거부 → 부모 W 로 폴백 → 같은 부모 재배치.
    let wrapped: EditorNode[] = [
      {
        name: 'Div',
        id: 'W', // 채워진 일반 Div (contents 래퍼)
        children: [
          { name: 'Div', id: 'c0', children: [{ name: 'Span', id: 'c0t' }] },
          { name: 'Div', id: 'c1', children: [{ name: 'Span', id: 'c1t' }] },
          { name: 'Div', id: 'c2', children: [{ name: 'Span', id: 'c2t' }] },
        ],
      },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      wrapped = p(wrapped);
    });
    const frame = buildFrame([{ path: '0', rect: { left: 0, top: 0, width: 400, height: 360 } }]);
    const w = frame.querySelector('[data-editor-path="0"]')!;
    for (const [p, top] of [
      ['0.children.0', 0],
      ['0.children.1', 120],
      ['0.children.2', 240],
    ] as const) {
      const el = document.createElement('div');
      el.dataset.editorPath = p;
      el.setAttribute('data-editor-path', p);
      vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(
        rect({ left: 0, top, width: 400, height: 120 })
      );
      w.appendChild(el);
    }
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: wrapped,
        patchLayout: patch,
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0.children.0'))); // c0, fromPath [0,0]
    // W(0) 안 끝 슬롯 — 원본 트리 인덱스: 마지막 비드래그 자식 c2(orig2) 뒤 = index 3.
    act(() => result.current.onDragEnd(endEvent('0.children.0', slot('0', 3))));

    const wNode = wrapped[0]!;
    const childIds = (wNode.children as EditorNode[]).map((c) => c.id);
    expect(childIds, 'W 안에서 형제 재배치 — c0 가 끝으로').toEqual(['c1', 'c2', 'c0']);
    // 루트엔 여전히 W 하나(카드가 grandparent 로 새어나가지 않음)
    expect(wrapped.length).toBe(1);
  });

  it('같은 위치로 드롭하면(no-op) 변형/이력 생략', () => {
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: patchSpy,
        pushHistory: pushSpy,
      })
    );
    act(() => result.current.onDragStart(startEvent('0')));
    // 루트 index 0 슬롯 = 원래 위치(fromIndex 0) → no-op.
    act(() => result.current.onDragEnd(endEvent('0', slot('', 0))));

    expect(patchSpy).not.toHaveBeenCalled();
    expect(pushSpy).not.toHaveBeenCalled();
  });
});

describe('useCanvasDnd — 거부 케이스', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    delete (document as any).elementsFromPoint;
    document.body.innerHTML = '';
  });

  it('draggable 목록에 없는 컴포넌트는 onDragStart 에서 denied 적재 + 이동 안 함', () => {
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    let components: EditorNode[] = [{ name: 'Form', id: 'f' }, { name: 'Div', id: 'd' }];
    const patchSpy = vi.fn();
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: patchSpy,
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // Form = not draggable
    const startCall = trackSpy.mock.calls.find((c) => c[0].result === 'denied');
    expect(startCall?.[0].decision).toBe('denied_no_draggable');
    expect(startCall?.[0].source).toBe('drag');
  });

  it('자신 바인딩(data_bound) 노드는 드래그 허용 — denied_data_bound 아님', () => {
    // data_bound 는 인라인 텍스트 편집만 불가, 드래그/구조 편집 허용.
    // 자신이 `{{}}` 바인딩인 노드(상품 이미지 갤러리 등)도 onDragStart 가 거부하지 않아야.
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    const components: EditorNode[] = [
      { name: 'Div', id: 'bound', props: { src: '{{product.image}}' } }, // 자신 바인딩
      { name: 'Div', id: 'plain' },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: vi.fn(),
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // data_bound 노드
    // 거부 적재가 없어야 함(allowed) — denied_data_bound 발화 금지.
    const deniedCall = trackSpy.mock.calls.find((c) => c[0].result === 'denied');
    expect(deniedCall, 'data_bound 노드 드래그 시작은 거부되지 않아야 함').toBeUndefined();
    expect(result.current.activeDragPath, '드래그가 정상 시작되어야 함').toBe('0');
  });

  it('드롭 존이 없으면(드래그 대상이 비draggable) cancelled 적재 + 이동 안 함', () => {
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    // Form 은 draggable 아님 → onDragEnd 에서 makeCanDropInContainer 가 어디서도
    // 통과 못 하고 루트('')도 isDraggableNode(Form) 거부 → zone null → cancelled.
    let components: EditorNode[] = [{ name: 'Form', id: 'f' }, { name: 'Div', id: 'd' }];
    const patchSpy = vi.fn();
    const pushSpy = vi.fn();
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING, // draggable: Div/Span/Button (Form 미포함)
        editMode: 'route',
        components,
        patchLayout: patchSpy,
        pushHistory: pushSpy,
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // Form
    // 유효 슬롯 위가 아님(over=null) → 드롭 불가 cancelled.
    act(() => result.current.onDragEnd(endEvent('0', null)));

    expect(patchSpy).not.toHaveBeenCalled();
    expect(pushSpy).not.toHaveBeenCalled();
    const cancelled = trackSpy.mock.calls.find(
      (c) => c[0].source === 'drag' && c[0].result === 'cancelled'
    );
    expect(cancelled).toBeTruthy();
    expect(cancelled?.[0].decision).toBe('denied_no_accepts');
  });
});

describe('useCanvasDnd — 이동 후 선택 재복원', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('루트 형제 이동 시 onMovePath 가 이동 노드의 새 path 로 호출됨', () => {
    let comps: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
      { name: 'Div', id: 'c' },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      comps = p(comps);
    });
    const onMovePath = vi.fn();
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '2', rect: { left: 0, top: 200, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: comps,
        patchLayout: patch,
        pushHistory: vi.fn(),
        onMovePath,
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // a, fromPath [0]
    // 원본 트리 인덱스: 마지막 비드래그 자식 c(orig2) 뒤 = index 3 → 끝으로 [b,c,a]
    act(() => result.current.onDragEnd(endEvent('0', slot('', 3))));
    // a 가 이동 후 인덱스 2 → 새 path '2'
    expect(comps.map((c) => c.id)).toEqual(['b', 'c', 'a']);
    expect(onMovePath).toHaveBeenCalledWith('2');
  });

  it('다른 컨테이너로 이동 시 onMovePath 가 목적지 path 로 호출됨', () => {
    let nested: EditorNode[] = [
      { name: 'Div', id: 'A' },
      { name: 'Div', id: 'G', children: [{ name: 'Div', id: 'w0' }] },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      nested = p(nested);
    });
    const onMovePath = vi.fn();
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 80 } },
      { path: '1', rect: { left: 0, top: 80, width: 400, height: 160 } },
    ]);
    const g = frame.querySelector('[data-editor-path="1"]')!;
    const el = document.createElement('div');
    el.dataset.editorPath = '1.children.0';
    el.setAttribute('data-editor-path', '1.children.0');
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(rect({ left: 10, top: 100, width: 380, height: 60 }));
    g.appendChild(el);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: nested,
        patchLayout: patch,
        pushHistory: vi.fn(),
        onMovePath,
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // A, fromPath [0]
    // G(1) 안 w0 앞(index 0) — A 를 G 안으로. A 제거로 G 가 [1]→[0] 로 rebase.
    act(() => result.current.onDragEnd(endEvent('0', slot('1', 0))));
    // A 가 G(이제 path '0') 의 children[0] → 새 path '0.children.0'
    expect(onMovePath).toHaveBeenCalledWith('0.children.0');
  });
});

describe('useCanvasDnd — includeContainer 관련 레벨 (결함 1: 바깥→다른 컨테이너 이동)', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('루트로 빠져나온 노드 재드래그 시 다른 accepting 컨테이너가 includeContainer=true', () => {
    // 트리: [A(루트 직속, 드래그), G(컨테이너)=[w0]]. A 는 이미 루트로 빠져나온 상태.
    // A 를 다시 G 안으로 넣으려면 includeContainer('1') 이 true 여야 슬롯이 깔린다.
    // 결함 1: fromParent=[] (루트) 면 (B) 형제 순회 루프가 안 돌아 G 가 relevant 에 빠짐.
    const nested: EditorNode[] = [
      { name: 'Div', id: 'A' },
      { name: 'Div', id: 'G', children: [{ name: 'Div', id: 'w0' }] },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 80 } }, // A (드래그, 루트 직속)
      { path: '1', rect: { left: 0, top: 80, width: 400, height: 160 } }, // G
    ]);
    const g = frame.querySelector('[data-editor-path="1"]')!;
    const el = document.createElement('div');
    el.dataset.editorPath = '1.children.0';
    el.setAttribute('data-editor-path', '1.children.0');
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(rect({ left: 10, top: 100, width: 380, height: 60 }));
    g.appendChild(el);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: nested,
        patchLayout: vi.fn(),
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // A, fromPath [0] (루트 직속)
    const { includeContainer } = result.current.buildSlotPredicates('Div');
    expect(includeContainer(''), '루트는 항상 포함').toBe(true);
    expect(includeContainer('1'), 'A 를 G 안으로 넣을 수 있어야 함').toBe(true);
  });

  it('깊은 다른 컨테이너(조부모의 형제의 자식)도 includeContainer=true', () => {
    // 트리: [A(드래그), Outer=[Inner=[w0]]]. A 를 Outer.Inner 깊숙이 nest 가능해야.
    // 결함 1: relevant 가 조상 체인의 직접 형제만 봐서 Inner(2단계 깊이)가 빠짐.
    const nested: EditorNode[] = [
      { name: 'Div', id: 'A' },
      {
        name: 'Div',
        id: 'Outer',
        children: [{ name: 'Div', id: 'Inner', children: [{ name: 'Div', id: 'w0' }] }],
      },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 80 } }, // A
      { path: '1', rect: { left: 0, top: 80, width: 400, height: 240 } }, // Outer
    ]);
    const outer = frame.querySelector('[data-editor-path="1"]')!;
    const innerEl = document.createElement('div');
    innerEl.dataset.editorPath = '1.children.0';
    innerEl.setAttribute('data-editor-path', '1.children.0');
    vi.spyOn(innerEl, 'getBoundingClientRect').mockReturnValue(rect({ left: 10, top: 100, width: 380, height: 200 }));
    outer.appendChild(innerEl);
    const w0El = document.createElement('div');
    w0El.dataset.editorPath = '1.children.0.children.0';
    w0El.setAttribute('data-editor-path', '1.children.0.children.0');
    vi.spyOn(w0El, 'getBoundingClientRect').mockReturnValue(rect({ left: 20, top: 110, width: 360, height: 60 }));
    innerEl.appendChild(w0El);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: nested,
        patchLayout: vi.fn(),
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // A, fromPath [0]
    const { includeContainer } = result.current.buildSlotPredicates('Div');
    expect(includeContainer('1'), 'Outer 포함').toBe(true);
    expect(includeContainer('1.children.0'), '깊은 Inner 컨테이너도 nest 타깃으로 포함').toBe(true);
  });

  it('드래그 노드 자신/자손은 includeContainer=false (자기 안에 드롭 불가)', () => {
    // 트리: [A(드래그, 컨테이너)=[a0], B]. A 자신/A.a0 은 relevant 제외.
    const nested: EditorNode[] = [
      { name: 'Div', id: 'A', children: [{ name: 'Div', id: 'a0' }] },
      { name: 'Div', id: 'B' },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 160 } }, // A (드래그)
      { path: '1', rect: { left: 0, top: 160, width: 400, height: 80 } }, // B
    ]);
    const a = frame.querySelector('[data-editor-path="0"]')!;
    const a0 = document.createElement('div');
    a0.dataset.editorPath = '0.children.0';
    a0.setAttribute('data-editor-path', '0.children.0');
    vi.spyOn(a0, 'getBoundingClientRect').mockReturnValue(rect({ left: 10, top: 10, width: 380, height: 60 }));
    a.appendChild(a0);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: nested,
        patchLayout: vi.fn(),
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // A, fromPath [0]
    const { includeContainer } = result.current.buildSlotPredicates('Div');
    expect(includeContainer('0'), '드래그 노드 자신 제외').toBe(false);
    expect(includeContainer('0.children.0'), '드래그 노드 자손 제외').toBe(false);
    expect(includeContainer('1'), '다른 컨테이너 B 포함').toBe(true);
  });
});

describe('useCanvasDnd — 선택 기준 드래그 치환 (결함 2: 자손 잡아도 선택 부모 이동)', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('selectedPath 의 자손을 드래그 시작하면 선택 부모가 이동 commit 된다', () => {
    // 트리: [P(=선택, children=[c0]), B]. 사용자가 P 를 선택한 뒤 자손 c0(P.children.0)이
    // 덮은 영역을 잡고 끌면, 드래그 path 가 P 로 치환되어 P 가 이동해야 한다.
    let comps: EditorNode[] = [
      { name: 'Div', id: 'P', children: [{ name: 'Div', id: 'c0' }] },
      { name: 'Div', id: 'B' },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => { comps = p(comps); });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 160 } }, // P
      { path: '1', rect: { left: 0, top: 160, width: 400, height: 80 } }, // B
    ]);
    const p = frame.querySelector('[data-editor-path="0"]')!;
    const c0 = document.createElement('div');
    c0.dataset.editorPath = '0.children.0';
    c0.setAttribute('data-editor-path', '0.children.0');
    vi.spyOn(c0, 'getBoundingClientRect').mockReturnValue(rect({ left: 10, top: 10, width: 380, height: 60 }));
    p.appendChild(c0);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: comps,
        patchLayout: patch,
        pushHistory: vi.fn(),
        selectedPath: '0', // P 선택됨
      })
    );
    // 자손 c0 핸들로 드래그 시작 (active.id = '0.children.0')
    act(() => result.current.onDragStart(startEvent('0.children.0')));
    // 드래그 path 가 P('0') 로 치환 → activeDragName 은 P 의 이름
    expect(result.current.activeDragPath).toBe('0');
    // 루트 끝(원본 트리 인덱스 2)으로 드롭 → P 가 B 뒤로 [B, P]
    act(() => result.current.onDragEnd(endEvent('0.children.0', slot('', 2))));
    // P 가 이동(자손 c0 가 아니라) — 루트 순서 [B, P], P.children 보존
    expect(comps.map((c) => c.id)).toEqual(['B', 'P']);
    expect((comps[1]!.children as EditorNode[])[0]!.id).toBe('c0');
  });

  it('selectedPath 가 없으면 치환 없음 — 잡은 자손 그대로 드래그', () => {
    let comps: EditorNode[] = [
      { name: 'Div', id: 'P', children: [{ name: 'Div', id: 'c0' }, { name: 'Div', id: 'c1' }] },
    ];
    const patch = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => { comps = p(comps); });
    const frame = buildFrame([{ path: '0', rect: { left: 0, top: 0, width: 400, height: 200 } }]);
    const p = frame.querySelector('[data-editor-path="0"]')!;
    for (const [pp, top] of [['0.children.0', 0], ['0.children.1', 100]] as const) {
      const el = document.createElement('div');
      el.dataset.editorPath = pp; el.setAttribute('data-editor-path', pp);
      vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(rect({ left: 0, top, width: 400, height: 100 }));
      p.appendChild(el);
    }
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: comps,
        patchLayout: patch,
        pushHistory: vi.fn(),
        // selectedPath 미전달
      })
    );
    act(() => result.current.onDragStart(startEvent('0.children.0')));
    // 치환 없음 → 자손 c0 그대로 드래그
    expect(result.current.activeDragPath).toBe('0.children.0');
  });

  it('selectedPath 자신을 드래그하면 치환 없음(자손이 아니라 자기 자신)', () => {
    let comps: EditorNode[] = [{ name: 'Div', id: 'P', children: [{ name: 'Div', id: 'c0' }] }, { name: 'Div', id: 'B' }];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 160 } },
      { path: '1', rect: { left: 0, top: 160, width: 400, height: 80 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components: comps,
        patchLayout: vi.fn(),
        pushHistory: vi.fn(),
        selectedPath: '0',
      })
    );
    act(() => result.current.onDragStart(startEvent('0'))); // P 자신 드래그
    expect(result.current.activeDragPath).toBe('0');
  });
});

describe('useCanvasDnd — 단순 형제 재배치 commit', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('루트 형제 재배치 commit — [a,b] 에서 a 를 끝 슬롯으로 → [b,a]', () => {
    let components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
    ];
    const patchSpy = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: frame,
        nesting: NESTING,
        editMode: 'route',
        components,
        patchLayout: patchSpy,
        pushHistory: vi.fn(),
      })
    );
    act(() => result.current.onDragStart(startEvent('0')));
    // 원본 트리 인덱스: 마지막 비드래그 자식 b(orig1) 뒤 = index 2 → [b,a]
    act(() => result.current.onDragEnd(endEvent('0', slot('', 2))));
    expect(components.map((c) => c.id)).toEqual(['b', 'a']);
  });
});

// 모달(및 반복항목) 편집 모드는 편집 대상 노드 서브트리(editableRootPath)
// 밖으로의 드래그/드롭을 차단해야 한다. modal 모드의 호스트 노드는 isNodeLocked 가 잠그지
// 않아(확장만 잠금) 종전엔 딤 영역으로의 드롭/딤 영역으로의 이탈이 가능했다.
describe('useCanvasDnd — 모달 편집 편집루트 confine', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // host[0] = 딤(잠긴 호스트 컨테이너), host[1] = 모달 노드(편집 대상), 모달 자식 Button.
  function buildModalScene() {
    let components: EditorNode[] = [
      { name: 'Div', id: 'host_dim', children: [{ name: 'Span', id: 'dim_child' }] },
      { name: 'Div', id: 'modal', children: [{ name: 'Button', id: 'modal_btn' }] },
    ];
    const patchSpy = vi.fn((p: (c: EditorNode[]) => EditorNode[]) => {
      components = p(components);
    });
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '0.children.0', rect: { left: 0, top: 0, width: 400, height: 50 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '1.children.0', rect: { left: 0, top: 100, width: 400, height: 50 } },
    ]);
    return { get components() { return components; }, patchSpy, frame };
  }

  it('모달 내부 노드를 딤(호스트) 컨테이너로 드롭 → 거부(commit 안 함)', () => {
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    const scene = buildModalScene();
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: scene.frame,
        nesting: NESTING,
        editMode: 'modal',
        editableRootPath: [1], // 모달 노드 = host components[1]
        components: scene.components,
        patchLayout: scene.patchSpy,
        pushHistory: vi.fn(),
      })
    );
    // 모달 자식(Button, path 1.children.0) 을 드래그해 딤 호스트 컨테이너(path '0') 로 드롭 시도.
    act(() => result.current.onDragStart(startEvent('1.children.0')));
    act(() => result.current.onDragEnd(endEvent('1.children.0', slot('0', 0))));
    expect(scene.patchSpy, '딤 영역으로의 commit 은 차단되어야 한다').not.toHaveBeenCalled();
    const denied = trackSpy.mock.calls.find(
      (c) => c[0].source === 'drag' && c[0].result === 'denied'
    );
    expect(denied).toBeTruthy();
  });

  it('모달 내부 노드의 드래그 시작은 거부되지 않음 (편집 루트 내부)', () => {
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    const scene = buildModalScene();
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: scene.frame,
        nesting: NESTING,
        editMode: 'modal',
        editableRootPath: [1],
        components: scene.components,
        patchLayout: scene.patchSpy,
        pushHistory: vi.fn(),
      })
    );
    // 모달 자식(Button, path 1.children.0) 드래그 시작 — 편집 루트(모달[1]) 내부라 허용.
    act(() => result.current.onDragStart(startEvent('1.children.0')));
    const denied = trackSpy.mock.calls.find(
      (c) => c[0].source === 'drag' && c[0].result === 'denied'
    );
    expect(denied, '모달 내부 노드 드래그 시작은 거부되지 않아야 한다').toBeUndefined();
    expect(result.current.activeDragPath, '모달 내부 노드 드래그가 정상 시작되어야 한다').toBe(
      '1.children.0'
    );
  });

  it('딤(호스트) 노드의 드래그 시작 → 거부(편집 루트 밖)', () => {
    const trackSpy = vi.spyOn(trackers, 'trackEditorDnd');
    const scene = buildModalScene();
    const { result } = renderHook(() =>
      useCanvasDnd({
        frameEl: scene.frame,
        nesting: NESTING,
        editMode: 'modal',
        editableRootPath: [1],
        components: scene.components,
        patchLayout: scene.patchSpy,
        pushHistory: vi.fn(),
      })
    );
    // 딤 호스트 자식(Span, path 0.children.0) 드래그 시작 시도 — 편집 루트(모달[1]) 밖.
    // onDragStart 는 activeDragPath 를 항상 설정하나(기존 설계 — 시각 표시), 편집 루트 밖이면
    // decision='denied_data_bound' 로 적재되고, onDragMove 가 드롭존을 계산하지 않으며,
    // onDragEnd 의 commit confine 가 차단한다(실제 이동 불가).
    act(() => result.current.onDragStart(startEvent('0.children.0')));
    const denied = trackSpy.mock.calls.find(
      (c) => c[0].source === 'drag' && c[0].result === 'denied'
    );
    expect(denied?.[0].decision, '딤 노드 드래그 시작은 denied 로 적재되어야 한다').toBe(
      'denied_data_bound'
    );
    // onDragMove 로 호스트 컨테이너 위를 hover 해도 드롭존이 계산되지 않아야 한다(편집 루트 밖).
    act(() =>
      result.current.onDragMove({
        active: { id: '0.children.0' },
        over: { id: slot('0', 0) },
        activatorEvent: { clientX: 0, clientY: 0 } as PointerEvent,
        delta: { x: 0, y: 0 },
      } as any)
    );
    expect(result.current.activeDropZone, '편집 루트 밖 드래그는 드롭존이 없어야 한다').toBeNull();
  });
});
