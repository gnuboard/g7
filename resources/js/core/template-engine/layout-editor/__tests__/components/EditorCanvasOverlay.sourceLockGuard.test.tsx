/**
 * EditorCanvasOverlay.sourceLockGuard.test.tsx — 상속·주입 노드 편집 진입 차단 (A3)
 *
 * `route` 편집 모드에서 상속(base)·주입(extension) 출처 노드는 저장 시
 * `stripInheritedNode` 가 통째로 폐기하므로 편집분이 오류도 경고도 없이 사라진다.
 * (저장은 200 으로 성공하고 `history.clear()` 로 undo 도 불가능하다.)
 *
 * 종전에는 잠금 판정이 ⓘ 컨텍스트 메뉴와 DnD 핸들에만 걸려 있었고, 아래 4개 진입점은
 * 무방비였다 — 더블클릭 인라인 편집 / 복제 / 키보드 `Delete` / 잘라내기.
 * 이 파일은 그 4개 표면 각각의 차단과, 라우트 소유 노드의 정상 동작(회귀 가드)을 잠근다.
 *
 * @since engine-v1.66.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, act, cleanup, fireEvent, waitFor } from '@testing-library/react';
import { EditorCanvasOverlay } from '../../components/EditorCanvasOverlay';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import type { UseLayoutDocumentResult, LoadedLayoutDocument } from '../../hooks/useLayoutDocument';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { readClipboard, clearClipboard } from '../../utils/editorClipboard';

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

/** 미리보기 프레임 + 편집 노드 DOM 모킹 (wrapper 로 감싼다 — 리스너 등록 대상) */
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

function mount(components: EditorNode[]) {
  const frame = buildFrame([
    { path: '0', rect: { left: 0, top: 0, width: 400, height: 100 } },
    { path: '1', rect: { left: 0, top: 100, width: 400, height: 100 } },
  ]);
  const { ctx, patchSpy } = buildDocCtx(components);
  const engine = new TranslationEngine();
  const manifest = { components: {} } as any;

  render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <EditorModalProvider>
          <RouteSeeder>
            <LayoutDocumentProvider value={ctx}>
              <EditorCanvasOverlay
                frameEl={frame}
                manifest={manifest}
                nesting={null}
                componentPalette={null}
              />
            </LayoutDocumentProvider>
          </RouteSeeder>
        </EditorModalProvider>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );

  const nodeEl = (path: string): HTMLElement =>
    frame.querySelector(`[data-editor-path="${path}"]`) as HTMLElement;

  /** 캔버스 노드 클릭 → 선택 (frame 부모 wrapper 의 capture 리스너가 잡는다) */
  const selectNode = (path: string): void => {
    act(() => {
      fireEvent.click(nodeEl(path), { clientX: 10, clientY: 10 });
    });
  };

  return { frame, nodeEl, selectNode, patchSpy };
}

/** 상속(base) 출처 + 평문 텍스트 — 더블클릭 인라인 편집의 대상이 되는 형태 */
const BASE_TEXT: EditorNode = {
  name: 'Span',
  type: 'basic',
  __source: { kind: 'base', layout: '_user_base' },
  text: '푸터 문구',
};
/** 라우트 소유 + 평문 텍스트 — 회귀 가드용 대조군 */
const ROUTE_TEXT: EditorNode = {
  name: 'Span',
  type: 'basic',
  __source: { kind: 'route' },
  text: '본문 문구',
};

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});
beforeEach(() => {
  delete (window as any).__g7LayoutEditorHistory;
  // 클립보드는 sessionStorage 라 케이스 간 누수된다 — N24d 의 「부재」 단언이 공허해진다.
  clearClipboard();
});

describe('EditorCanvasOverlay — 상속·주입 노드 인라인 편집 차단 (A3)', () => {
  it('N21 base 출처 평문 노드 더블클릭 → 인라인 편집기 미마운트', async () => {
    const { nodeEl } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    act(() => {
      fireEvent.doubleClick(nodeEl('0'), { clientX: 10, clientY: 10 });
    });
    expect(document.querySelector('[data-testid="g7le-inline-text-editor"]')).toBeNull();
  });

  it('N22 회귀 가드 — route 출처 평문 노드 더블클릭은 그대로 진입한다', async () => {
    const { nodeEl } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    act(() => {
      fireEvent.doubleClick(nodeEl('1'), { clientX: 10, clientY: 150 });
    });
    expect(document.querySelector('[data-testid="g7le-inline-text-editor"]')).not.toBeNull();
  });
});

describe('EditorCanvasOverlay — 상속·주입 노드 구조 편집 차단 (A3)', () => {
  it('N23 base 출처 노드 선택 + 키보드 Delete → patchLayout 미호출', async () => {
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('0');
    act(() => {
      fireEvent.keyDown(window, { key: 'Delete' });
    });
    expect(patchSpy).not.toHaveBeenCalled();
  });

  it('N24 base 출처 노드 잘라내기(Ctrl+X) → patchLayout 미호출', async () => {
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('0');
    act(() => {
      fireEvent.keyDown(window, { key: 'x', ctrlKey: true });
    });
    expect(patchSpy).not.toHaveBeenCalled();
  });

  /**
   * N24d — `handleCut` 의 **선두 가드 고유 목적**을 잰다.
   *
   * N24 는 `patchLayout` 미호출만 단언하는데, 그것은 `handleCut` 이 호출하는
   * `handleDelete` 의 가드(G7)만으로도 성립한다 — 그래서 `handleCut` 자신의 가드를 지워도
   * 초록이었다(라운드3 probe 실측). 계획서 §7.4 가 이 가드를 따로 둔 이유는
   * 「복사는 됐는데 원본이 남는」 어긋난 상태 방지, 즉 **클립보드에 기록되지 않는 것**이다.
   */
  it('N24d base 출처 노드 잘라내기 → 클립보드에도 기록되지 않는다', async () => {
    const { selectNode } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('0');
    act(() => {
      fireEvent.keyDown(window, { key: 'x', ctrlKey: true });
    });
    expect(readClipboard()).toBeNull();
  });

  /**
   * N24e — N24d 의 「부재」가 공허하지 않음을 증명하는 반증 가드.
   * 같은 하네스에서 route 출처 노드는 실제로 클립보드에 실린다.
   */
  it('N24e 회귀 가드 — route 출처 노드 잘라내기는 클립보드에 실린다', async () => {
    const { selectNode } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('1');
    act(() => {
      fireEvent.keyDown(window, { key: 'x', ctrlKey: true });
    });
    expect(readClipboard()).not.toBeNull();
  });

  it('N24b base 출처 노드는 복제 진입 경로 자체가 없다 (ⓘ 미렌더)', async () => {
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('0');

    // ⓘ 가 없으면 「복제」 메뉴 항목에 도달할 방법이 없다 — 복제의 유일한 호출부다.
    expect(document.querySelector('[data-testid="g7le-overlay-info-button"]')).toBeNull();
    expect(document.querySelector('[data-testid="g7le-context-menu-duplicate"]')).toBeNull();
    expect(patchSpy).not.toHaveBeenCalled();
  });

  it('N24c 회귀 가드 — route 출처 노드는 ⓘ→복제로 실제 복제된다', async () => {
    // 이 케이스가 없으면 N24b 의 「부재」 단언이 공허해진다 —
    // 복제 경로가 이 하네스에서 애초에 도달 가능함을 여기서 증명한다.
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('1');

    const info = document.querySelector('[data-testid="g7le-overlay-info-button"]') as HTMLElement;
    expect(info).not.toBeNull();
    act(() => {
      fireEvent.click(info);
    });

    const dup = document.querySelector('[data-testid="g7le-context-menu-duplicate"]') as HTMLElement;
    expect(dup).not.toBeNull();
    act(() => {
      fireEvent.click(dup);
    });
    expect(patchSpy).toHaveBeenCalled();
  });

  it('N25 회귀 가드 — route 출처 노드는 Delete 로 정상 삭제된다', async () => {
    const { selectNode, patchSpy } = mount([BASE_TEXT, ROUTE_TEXT]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    selectNode('1');
    act(() => {
      fireEvent.keyDown(window, { key: 'Delete' });
    });
    expect(patchSpy).toHaveBeenCalled();
  });
});
