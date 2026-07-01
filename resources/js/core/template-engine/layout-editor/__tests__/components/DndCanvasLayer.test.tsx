/**
 * DndCanvasLayer.test.tsx — 드래그 핸들 렌더 가드
 *
 * - draggable + 비잠금 노드만 드래그 핸들 렌더
 * - data_bound / base 출처 노드는 핸들 미렌더
 * - 핸들 클릭 시 onSelectPath 위임
 *
 * dnd-kit DndContext 마운트 + frame DOM 기하 모킹.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { DndCanvasLayer } from '../../components/DndCanvasLayer';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { NestingSpec } from '../../spec/specTypes';

const NESTING: NestingSpec = {
  draggable: ['Div', 'Span'],
  containers: { Div: { accepts: ['Div', 'Span'] } },
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

function buildFrame(children: Array<{ path: string; rect: Rect }>): HTMLElement {
  const frame = document.createElement('div');
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(
    domRect({ left: 0, top: 0, width: 400, height: 400 })
  );
  for (const c of children) {
    const el = document.createElement('div');
    el.dataset.editorPath = c.path;
    el.setAttribute('data-editor-path', c.path);
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(domRect(c.rect));
    frame.appendChild(el);
  }
  document.body.appendChild(frame);
  return frame;
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('DndCanvasLayer — 드래그 핸들 렌더 가드', () => {
  it('draggable 노드에만 핸들 렌더', () => {
    const components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Form', id: 'f' }, // not draggable
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    expect(screen.queryByTestId('g7le-dnd-handle-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-1')).not.toBeInTheDocument();
  });

  it('핸들 더블클릭 → onRequestInlineEdit(path) forward', () => {
    // 드래그 핸들이 노드 박스를 pointerEvents:auto 로 덮으므로 실제 마우스 더블클릭은
    // 핸들에 먼저 맞는다. 핸들이 더블클릭을 인라인 편집 진입점으로 forward 해야 한다.
    const components: EditorNode[] = [{ name: 'Div', id: 'h', text: '제목' }];
    const frame = buildFrame([{ path: '0', rect: { left: 0, top: 0, width: 400, height: 40 } }]);
    const onRequestInlineEdit = vi.fn();
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
        onRequestInlineEdit={onRequestInlineEdit}
      />
    );
    const handle = screen.getByTestId('g7le-dnd-handle-0');
    fireEvent.doubleClick(handle);
    expect(onRequestInlineEdit).toHaveBeenCalledWith('0');
  });

  it('data_bound 노드도 드래그 핸들 렌더 — 선택/이동 허용', () => {
    //  명세: data_bound 노드는 인라인 텍스트 편집만 불가하고 선택·드래그·구조
    // 편집은 허용. 자신이 데이터 바인딩(`{{}}`)인 노드(상품 이미지 갤러리 등 composite
    // 포함)도 위치 이동은 정당한 구조 편집이므로 핸들이 렌더되어야 한다.
    // (종전: data_bound 를 잠금 취급해 핸들 미렌더 → 선택/이동 불가 결함.)
    const components: EditorNode[] = [
      { name: 'Div', id: 'a', text: '{{user.name}}' }, // 자신 바인딩 = data_bound
      { name: 'Div', id: 'b' },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    // data_bound 노드(0)도 핸들 렌더 — 드래그/선택 가능.
    expect(screen.queryByTestId('g7le-dnd-handle-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-1')).toBeInTheDocument();
  });

  it('반복(iteration) 인스턴스 내부 노드는 핸들 미렌더 — 개별 인스턴스 직접 편집 금지', () => {
    // 자신 바인딩 data_bound 는 허용하되, **조상이 iteration** 인 펼침 인스턴스 내부
    // 노드는 핸들 미렌더(묶음 단위 편집은 가상 묶음/iteration_item 모드).
    // 트리: List(iteration 정의) → 펼침 인스턴스(.iteration.0)는 DOM 에서 List 자식으로
    // 렌더되며 조상에 iteration 노드가 있다.
    const components: EditorNode[] = [
      {
        name: 'Div',
        id: 'list',
        iteration: { source: '{{items}}' } as EditorNode['iteration'],
        children: [{ name: 'Span', id: 'cell' }],
      },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 200 } },
    ]);
    // 펼침 인스턴스 + 그 내부 자식을 DOM 에 추가 (DynamicRenderer 가 .iteration.N 으로 펼침)
    const listEl = frame.querySelector('[data-editor-path="0"]')!;
    for (const [p, top] of [['0.iteration.0', 0], ['0.iteration.1', 50]] as const) {
      const inst = document.createElement('div');
      inst.dataset.editorPath = p;
      inst.setAttribute('data-editor-path', p);
      vi.spyOn(inst, 'getBoundingClientRect').mockReturnValue(domRect({ left: 0, top, width: 400, height: 50 }));
      listEl.appendChild(inst);
      const cell = document.createElement('span');
      cell.dataset.editorPath = `${p}.children.0`;
      cell.setAttribute('data-editor-path', `${p}.children.0`);
      vi.spyOn(cell, 'getBoundingClientRect').mockReturnValue(domRect({ left: 5, top: top + 5, width: 200, height: 20 }));
      inst.appendChild(cell);
    }
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    // 펼침 인스턴스(0.iteration.0) 및 그 내부 자식 — 개별 핸들 미렌더(조상 iteration).
    expect(screen.queryByTestId('g7le-dnd-handle-0.iteration.0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-0.iteration.0.children.0')).not.toBeInTheDocument();
    // 대신 **이터레이션 원본 노드(0)** 의 가상 묶음 핸들이 1개 생성.
    const groupHandle = screen.queryByTestId('g7le-dnd-handle-0');
    expect(groupHandle, '이터레이션 가상 묶음 핸들이 원본 path 로 생성되어야 함').toBeInTheDocument();
    expect(groupHandle?.getAttribute('data-dnd-iteration-group')).toBe('true');
    // 묶음 box = 인스턴스 union (top 0~100) — height 100(인스턴스 2개 × 50).
    expect(groupHandle?.style.height).toBe('100px');
  });

  it('frame 밖으로 클리핑된 노드(닫힌 모바일 드로어 등)는 핸들 미렌더', () => {
    // 닫힌 모바일 드로어(`fixed translate-x-full`)는 frame 밖으로 밀려 overflow:hidden
    // 으로 시각적으로 가려지지만 getBoundingClientRect 좌표는 그대로다. 가려진 노드
    // 자리에 드래그 핸들/점선이 편집기 회색 배경에 노출되던 회귀를 잠근다.
    const components: EditorNode[] = [
      { name: 'Div', id: 'visible' },
      { name: 'Div', id: 'clipped-right' }, // frame(width 400) 우측 밖
      { name: 'Div', id: 'clipped-below' }, // frame(height 400) 하단 밖
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 460, top: 0, width: 320, height: 400 } }, // left ≥ 400 → 우측 밖
      { path: '2', rect: { left: 0, top: 460, width: 400, height: 100 } }, // top ≥ 400 → 하단 밖
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    expect(screen.queryByTestId('g7le-dnd-handle-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-1')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-2')).not.toBeInTheDocument();
  });

  it('실제 잠금(base 출처) 노드는 여전히 핸들 미렌더', () => {
    // data_bound 와 달리 base/partial/extension 출처 잠금은 핸들 미렌더 유지(회귀 가드).
    const components: EditorNode[] = [
      { name: 'Div', id: 'base', __source: { kind: 'base' } },
      { name: 'Div', id: 'own', __source: { kind: 'route' } },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    expect(screen.queryByTestId('g7le-dnd-handle-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-1')).toBeInTheDocument();
  });

  it('base 출처 노드는 route 모드에서 핸들 미렌더', () => {
    const components: EditorNode[] = [
      { name: 'Div', id: 'base', __source: { kind: 'base' } },
      { name: 'Div', id: 'own', __source: { kind: 'route' } },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    expect(screen.queryByTestId('g7le-dnd-handle-0')).not.toBeInTheDocument();
    expect(screen.queryByTestId('g7le-dnd-handle-1')).toBeInTheDocument();
  });

  it('핸들 클릭 시 onSelectPath 위임', () => {
    const onSelect = vi.fn();
    const components: EditorNode[] = [{ name: 'Div', id: 'a' }];
    const frame = buildFrame([{ path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } }]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={onSelect}
      />
    );
    fireEvent.click(screen.getByTestId('g7le-dnd-handle-0'));
    expect(onSelect).toHaveBeenCalledWith('0');
  });

  it('모든 핸들은 드래그 가능(grab) + z 깊이순 — 클릭/드래그 시작 모두 깊은 핸들 우선 (결함 2)', () => {
    // 부모 Div(0) 안에 자식 Div(0.children.0). 둘 다 draggable.
    // 핸들 비활성화로 선택 기준 드래그를 구현하면 자손이 덮은 영역에서 부모 드래그가
    // 막히므로, 모든 핸들은 드래그 가능(grab)하게 둔다. "선택 기준 드래그"는
    // useCanvasDnd.onDragStart 의 드래그 path 치환으로 처리(별도 hook 테스트).
    // z 는 깊이순 — 깊은(자식) 핸들이 위여야 클릭/드래그 시작이 가장 구체적 요소에 도달.
    const components: EditorNode[] = [
      { name: 'Div', id: 'parent', children: [{ name: 'Div', id: 'child' }] },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 200 } },
    ]);
    const parentEl = frame.querySelector('[data-editor-path="0"]')!;
    const childEl = document.createElement('div');
    childEl.dataset.editorPath = '0.children.0';
    childEl.setAttribute('data-editor-path', '0.children.0');
    vi.spyOn(childEl, 'getBoundingClientRect').mockReturnValue(
      domRect({ left: 20, top: 20, width: 200, height: 60 })
    );
    parentEl.appendChild(childEl);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
        selectedPath="0"
      />
    );
    const parentHandle = screen.getByTestId('g7le-dnd-handle-0');
    const childHandle = screen.getByTestId('g7le-dnd-handle-0.children.0');
    // 선택 여부와 무관하게 둘 다 드래그 가능(grab) — 핸들 비활성화 안 함.
    expect(parentHandle.style.cursor).toBe('grab');
    expect(childHandle.style.cursor).toBe('grab');
    // z 는 깊이순 — 자식(depth1=21)이 부모(depth0=20)보다 위.
    expect(Number(parentHandle.style.zIndex)).toBe(20);
    expect(Number(childHandle.style.zIndex)).toBe(21);
    expect(Number(childHandle.style.zIndex)).toBeGreaterThan(Number(parentHandle.style.zIndex));
    expect(parentHandle.style.pointerEvents).toBe('auto');
    expect(childHandle.style.pointerEvents).toBe('auto');
  });

  it('자손 핸들 클릭 시 자손 path 로 재선택 위임 (자식 재선택 가능)', () => {
    const onSelect = vi.fn();
    const components: EditorNode[] = [
      { name: 'Div', id: 'parent', children: [{ name: 'Div', id: 'child' }] },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 200 } },
    ]);
    const parentEl = frame.querySelector('[data-editor-path="0"]')!;
    const childEl = document.createElement('div');
    childEl.dataset.editorPath = '0.children.0';
    childEl.setAttribute('data-editor-path', '0.children.0');
    vi.spyOn(childEl, 'getBoundingClientRect').mockReturnValue(
      domRect({ left: 20, top: 20, width: 200, height: 60 })
    );
    parentEl.appendChild(childEl);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={onSelect}
        selectedPath="0"
      />
    );
    // 부모가 선택된 상태에서 자손 핸들 클릭 → 자손 재선택 (드래그 비활성이어도 클릭은 발화)
    fireEvent.click(screen.getByTestId('g7le-dnd-handle-0.children.0'));
    expect(onSelect).toHaveBeenCalledWith('0.children.0');
  });

  it('선택이 없으면 모든 draggable 핸들이 활성(첫 클릭/드래그 가능)', () => {
    const components: EditorNode[] = [
      { name: 'Div', id: 'parent', children: [{ name: 'Div', id: 'child' }] },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 200 } },
    ]);
    const parentEl = frame.querySelector('[data-editor-path="0"]')!;
    const childEl = document.createElement('div');
    childEl.dataset.editorPath = '0.children.0';
    childEl.setAttribute('data-editor-path', '0.children.0');
    vi.spyOn(childEl, 'getBoundingClientRect').mockReturnValue(
      domRect({ left: 20, top: 20, width: 200, height: 60 })
    );
    parentEl.appendChild(childEl);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    // 선택 없음 → 둘 다 drag 활성(grab). 자손도 dragDisabled 아님.
    expect(screen.getByTestId('g7le-dnd-handle-0').style.cursor).toBe('grab');
    expect(screen.getByTestId('g7le-dnd-handle-0.children.0').style.cursor).toBe('grab');
  });

  it('자손이 아닌 형제는 선택 무관하게 드래그 활성', () => {
    // 선택='0' 일 때 형제 '1' 은 자손이 아니므로 드래그 가능해야.
    const components: EditorNode[] = [
      { name: 'Div', id: 'a' },
      { name: 'Div', id: 'b' },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="route"
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
        selectedPath="0"
      />
    );
    expect(screen.getByTestId('g7le-dnd-handle-1').style.cursor).toBe('grab');
  });

  it('frameEl 이 null 이면 렌더 안 함', () => {
    const { container } = render(
      <DndCanvasLayer
        frameEl={null}
        nesting={NESTING}
        editMode="route"
        components={[{ name: 'Div', id: 'a' }]}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    expect(container.firstChild).toBeNull();
  });
});
