/**
 * DndCanvasLayer.modalConfine.test.tsx — 모달/반복항목 편집 모드 드래그 핸들 편집루트 confine
 *
 *
 * 모달 편집은 호스트 전체를 인플레이스로 렌더하되 모달 노드(editableRootPath)와 그 자손만
 * 편집 가능하다. modal 모드의 호스트 노드는 classifyLockKind 가 none(확장만 잠금)이라 종전엔
 * 딤(잠긴 호스트) 영역까지 드래그 핸들이 만들어졌다.
 * 편집 루트 confine 으로 딤 노드 핸들 생성을 차단한다.
 *
 * 별도 파일로 둔 이유: 공유 describe 블록의 getBoundingClientRect 스파이 상호 간섭으로 nested
 * path 핸들 측정이 불안정했다(같은 시나리오가 격리 파일에선 통과). getComputedStyle 을 명시
 * 모킹해 측정 안정화.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render } from '@testing-library/react';
import { DndCanvasLayer } from '../../components/DndCanvasLayer';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { NestingSpec } from '../../spec/specTypes';

const NESTING: NestingSpec = {
  draggable: ['Div', 'Span', 'Button'],
  containers: { Div: { accepts: ['Div', 'Span', 'Button'] } },
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
  // jsdom 은 레이아웃 엔진이 없어 getComputedStyle 기본값이 노드별 차이를 못 줘 nested 핸들
  // 측정이 불안정 — block 으로 명시 모킹(measureOverlay/boxIntersectsFrame 안정화).
  vi.spyOn(window, 'getComputedStyle').mockReturnValue({
    display: 'block',
    flexDirection: '',
    flexWrap: '',
  } as unknown as CSSStyleDeclaration);
  document.body.appendChild(frame);
  return frame;
}

afterEach(() => {
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

function handleIds(): string[] {
  return Array.from(document.querySelectorAll('[data-testid^="g7le-dnd-handle"]')).map((h) =>
    h.getAttribute('data-testid')!
  );
}

describe('DndCanvasLayer — 모달 편집 편집루트 confine', () => {
  it('딤(편집루트 밖 호스트) 노드는 핸들 미렌더, 모달 노드 자손만 핸들', () => {
    const components: EditorNode[] = [
      { name: 'Div', id: 'host_dim', children: [{ name: 'Span', id: 'dim_child' }] }, // path 0 = 딤
      { name: 'Div', id: 'modal', children: [{ name: 'Button', id: 'modal_btn' }] }, // path 1 = 모달(편집 대상)
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '0.children.0', rect: { left: 0, top: 0, width: 400, height: 50 } },
      { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
      { path: '1.children.0', rect: { left: 0, top: 100, width: 400, height: 50 } },
    ]);
    render(
      <DndCanvasLayer
        frameEl={frame}
        nesting={NESTING}
        editMode="modal"
        editableRootPath={[1]}
        components={components}
        patchLayout={vi.fn()}
        pushHistory={vi.fn()}
        onSelectPath={vi.fn()}
      />
    );
    const ids = handleIds();
    // 딤(호스트, path 0) 및 그 자식 → 핸들 없음(편집 루트 밖).
    expect(ids).not.toContain('g7le-dnd-handle-0');
    expect(ids).not.toContain('g7le-dnd-handle-0.children.0');
    // 모달 노드 자손(path 1.children.0) → 핸들 있음(편집 루트 내부 — 개별 편집 대상).
    expect(ids).toContain('g7le-dnd-handle-1.children.0');
  });

  it('route 모드는 confine 미적용 — 전 노드 핸들 (회귀 가드)', () => {
    const components: EditorNode[] = [
      { name: 'Div', id: 'a', children: [{ name: 'Span', id: 'a_child' }] },
      { name: 'Div', id: 'b' },
    ];
    const frame = buildFrame([
      { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
      { path: '0.children.0', rect: { left: 0, top: 0, width: 400, height: 50 } },
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
    const ids = handleIds();
    expect(ids).toContain('g7le-dnd-handle-0');
    expect(ids).toContain('g7le-dnd-handle-1');
  });
});
