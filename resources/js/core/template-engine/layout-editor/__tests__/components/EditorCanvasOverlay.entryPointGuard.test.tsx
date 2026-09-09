/**
 * EditorCanvasOverlay.entryPointGuard.test.tsx — 구조 편집 콜백의 선두 가드 (A3)
 *
 * `handleDuplicate` 는 `ElementOverlay` 의 ⓘ 컨텍스트 메뉴에서만 호출되고, 그 ⓘ 는
 * 같은 잠금 판정으로 이미 가려진다(`ElementOverlay` 가 `isContextMenuAllowed` 로 게이트).
 * 그래서 ⓘ 를 통과하는 테스트만으로는 **콜백 자신의 선두 가드가 지워져도 초록**이다
 * (라운드3 probe 로 전 스위트 3,587 green 실측).
 *
 * 그러나 이 저장소는 같은 자리에서 이미 반대 입장을 취하고 있다 —
 * `handleEditProps` 는 "ElementOverlay 가 ⓘ 를 미표시하지만, 외부 호출자(Chrome stub) 등
 * 다른 경로로도 들어올 수 있으므로 여기서도 방어한다" 는 주석과 함께 자체 가드를 둔다.
 * 이 파일은 그 계약을 `handleDuplicate` 에도 적용해 **호출자를 신뢰하지 않는다**를 잠근다:
 * 오버레이를 스텁으로 바꿔 잠금 여부와 무관하게 콜백을 넘겨받아 직접 호출한다.
 *
 * @since engine-v1.66.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, cleanup, fireEvent, waitFor } from '@testing-library/react';
import type { UseLayoutDocumentResult, LoadedLayoutDocument } from '../../hooks/useLayoutDocument';
import type { EditorNode } from '../../utils/layoutTreeUtils';

// ⓘ 게이트를 우회하는 스텁 — lockKind 와 무관하게 onDuplicate 트리거를 항상 렌더한다.
// 「다른 경로로 들어온 호출자」를 그대로 흉내낸다.
vi.mock('../../components/ElementOverlay', () => ({
  ElementOverlay: ({
    onDuplicate,
    lockKind,
  }: {
    onDuplicate: () => void;
    lockKind: string;
  }): React.ReactElement => (
    <button data-testid="stub-duplicate" data-lock-kind={lockKind} onClick={onDuplicate}>
      dup
    </button>
  ),
}));

import { EditorCanvasOverlay } from '../../components/EditorCanvasOverlay';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

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
  const wrapper = document.createElement('div');
  const frame = document.createElement('div');
  vi.spyOn(frame, 'getBoundingClientRect').mockReturnValue(
    domRect({ left: 0, top: 0, width: 400, height: 400 }),
  );
  for (const c of children) {
    const el = document.createElement('div');
    el.dataset.editorPath = c.path;
    el.dataset.editorId = `id-${c.path}`;
    el.setAttribute('data-editor-path', c.path);
    vi.spyOn(el, 'getBoundingClientRect').mockReturnValue(domRect(c.rect));
    frame.appendChild(el);
  }
  wrapper.appendChild(frame);
  document.body.appendChild(wrapper);
  return frame;
}

function buildDocCtx(initialComponents: EditorNode[]): {
  ctx: UseLayoutDocumentResult;
  patchSpy: ReturnType<typeof vi.fn>;
} {
  let document_: LoadedLayoutDocument = {
    layoutName: 'home',
    raw: { components: initialComponents },
    lockVersion: 1,
  };
  const patchSpy = vi.fn();
  const ctx: UseLayoutDocumentResult = {
    document: document_,
    isLoading: false,
    error: null,
    isDirty: false,
    saveSuccessCounter: 0,
    reload: async () => {},
    patchLayout: (patcher) => {
      patchSpy(patcher);
      const current = (document_.raw.components as EditorNode[]) ?? [];
      const next = patcher(current);
      document_ = { ...document_, raw: { ...document_.raw, components: next } };
      (ctx as any).document = document_;
    },
    setLayoutComponents: (next) => {
      document_ = { ...document_, raw: { ...document_.raw, components: next } };
      (ctx as any).document = document_;
    },
    save: async () => ({ kind: 'success', newLockVersion: 2 }),
  };
  return { ctx, patchSpy };
}

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
  }, [dispatch]);
  return <>{children}</>;
}

const BASE_TEXT: EditorNode = {
  name: 'Span',
  id: 'base-span',
  __source: { kind: 'base', layout: '_user_base' },
  text: '공통 문구',
};
const ROUTE_TEXT: EditorNode = { name: 'Span', id: 'route-span', __source: { kind: 'route' }, text: '라우트 문구' };

function mount(components: EditorNode[]) {
  const frame = buildFrame([
    { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
    { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
  ]);
  const { ctx, patchSpy } = buildDocCtx(components);
  const engine = new TranslationEngine();

  render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <EditorModalProvider>
          <LayoutDocumentProvider value={ctx}>
            <RouteSeeder>
              <EditorCanvasOverlay frameEl={frame} spec={{ components: {} } as any} />
            </RouteSeeder>
          </LayoutDocumentProvider>
        </EditorModalProvider>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );

  const selectNode = (path: string): void => {
    const el = frame.querySelector(`[data-editor-path="${path}"]`) as HTMLElement;
    act(() => {
      fireEvent.click(el);
    });
  };
  return { selectNode, patchSpy };
}

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});
beforeEach(() => {
  delete (window as any).__g7LayoutEditorHistory;
});

describe('EditorCanvasOverlay — 구조 편집 콜백의 선두 가드 (호출자 비신뢰)', () => {
  it('N24f base 출처 노드 — ⓘ 를 우회해 onDuplicate 를 직접 호출해도 patchLayout 미호출', async () => {
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('0');

    const btn = document.querySelector('[data-testid="stub-duplicate"]') as HTMLElement;
    expect(btn, '스텁 오버레이가 잠금 여부와 무관하게 트리거를 내야 한다').not.toBeNull();
    expect(btn.getAttribute('data-lock-kind')).toBe('base');
    act(() => {
      fireEvent.click(btn);
    });
    expect(patchSpy).not.toHaveBeenCalled();
  });

  it('N24g 회귀 가드 — route 출처 노드는 같은 경로로 실제 복제된다', async () => {
    // N24f 의 「부재」가 공허하지 않음을 증명한다 — 이 하네스에서 복제는 도달 가능하다.
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('1');

    const btn = document.querySelector('[data-testid="stub-duplicate"]') as HTMLElement;
    expect(btn.getAttribute('data-lock-kind')).toBe('none');
    act(() => {
      fireEvent.click(btn);
    });
    expect(patchSpy).toHaveBeenCalled();
  });
});
