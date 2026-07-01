/**
 * EditorCanvasOverlay.pick.test.tsx — 컴포넌트 영역 pick 모드 캔버스 수신부 회귀
 *
 *
 * ComponentTargetPicker.test.tsx 는 **위젯 측**(pick-request 발사 + picked 회신 수신)을
 * 잠근다. 본 테스트는 그 짝인 **캔버스 측**(EditorCanvasOverlay)을 잠근다 — D-J 결함의
 * 실제 무대는 캔버스 수신부다:
 *
 *  ① pick-request 수신 → pick 모드 진입(`g7le-canvas-pick-banner` 배너 렌더).
 *  ② pick 모드 중 캔버스의 id 보유 노드 클릭 → 그 노드 id 를 picked 이벤트로 회신 + 모드 종료.
 *  ③ id 미부여 노드 클릭 → 회신 없음(모드 유지) — 사용자가 다른 노드를 다시 고를 수 있어야 함.
 *  ④ Esc → cancelled 회신 + 모드 종료.
 *  ⑤ 취소 배너 버튼 → cancelled 회신 + 모드 종료.
 *  ⑥ node.props.id 와 node.id 양쪽에서 id 추출(우선순위 props.id).
 *
 * 합성 이벤트 false-negative 주의([[feedback_chrome_mcp_synthetic_events_false_negative...]]) —
 * 본 단위는 캔버스 수신 **결선 계약**(리스너 등록 위치/회신 형태/모드 전이)을 잠그고,
 * 실제 사용자 클릭 좌표 도달·핸들 가림은 라이브(Chrome MCP)가 본령으로 검증한다.
 *
 * @since engine-v1.50.0 — 캔버스 수신부 가드 (세션 D 보강)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, act, waitFor, fireEvent } from '@testing-library/react';
import { EditorCanvasOverlay } from '../../components/EditorCanvasOverlay';
import {
  COMPONENT_TARGET_PICK_REQUEST_EVENT,
  COMPONENT_TARGET_PICKED_EVENT,
} from '../../components/property-controls/ComponentTargetPicker';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import type { UseLayoutDocumentResult, LoadedLayoutDocument } from '../../hooks/useLayoutDocument';
import type { EditorNode } from '../../utils/layoutTreeUtils';

function buildDocCtx(initialComponents: EditorNode[]): UseLayoutDocumentResult {
  let document: LoadedLayoutDocument = {
    layoutName: 'home',
    raw: { components: initialComponents },
    lockVersion: 1,
  };
  const ctx: UseLayoutDocumentResult = {
    document,
    isLoading: false,
    error: null,
    isDirty: false,
    saveSuccessCounter: 0,
    reload: async () => {},
    patchLayout: (patcher) => {
      const current = (document.raw.components as EditorNode[]) ?? [];
      const next = patcher(current);
      document = { ...document, raw: { ...document.raw, components: next } };
      (ctx as any).document = document;
    },
    setLayoutComponents: (next) => {
      document = { ...document, raw: { ...document.raw, components: next } };
      (ctx as any).document = document;
    },
    save: async () => ({ kind: 'success', newLockVersion: 2 }),
  };
  return ctx;
}

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
  }, [dispatch]);
  return <>{children}</>;
}

/**
 * 실제 frame DOM 을 만들어 EditorCanvasOverlay 에 주입한다. frame 안에 `data-editor-path`
 * 노드를 심어, 그 위에서 click 을 fire 하면 overlay 의 capture 리스너(wrapper 등록)가 잡는다.
 * components prop 과 path 가 일치해야 id 추출이 동작한다(findNodeByPath(root, parseEditorPath)).
 */
function mountWithFrame(components: EditorNode[]): {
  frameEl: HTMLElement;
  nodeEl: (path: string) => HTMLElement;
  unmount: () => void;
  rerender: () => void;
} {
  const ctx = buildDocCtx(components);
  const engine = new TranslationEngine();
  const manifest = { components: {} } as any;

  // wrapper(g7le-preview-frame-wrapper) > frame(g7le-preview-frame) > 노드들.
  // overlay 는 frameEl.parentElement(=wrapper) 에 capture 리스너를 등록한다.
  const wrapper = window.document.createElement('div');
  wrapper.className = 'g7le-preview-frame-wrapper';
  const frame = window.document.createElement('div');
  frame.className = 'g7le-preview-frame';
  wrapper.appendChild(frame);
  window.document.body.appendChild(wrapper);

  // components 의 각 인덱스를 data-editor-path 노드로 렌더(루트 직계 = "0","1",...).
  components.forEach((_, idx) => {
    const el = window.document.createElement('div');
    el.setAttribute('data-editor-path', String(idx));
    el.setAttribute('data-editor-id', String(idx));
    el.textContent = `node-${idx}`;
    frame.appendChild(el);
  });

  let rerenderFn: () => void = () => {};
  function Harness(): React.ReactElement {
    const [, force] = React.useState(0);
    rerenderFn = () => force((v) => v + 1);
    return (
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
      </TranslationProvider>
    );
  }

  const utils = render(<Harness />);

  return {
    frameEl: frame,
    nodeEl: (path: string) =>
      frame.querySelector(`[data-editor-path="${path}"]`) as HTMLElement,
    unmount: () => {
      utils.unmount();
      wrapper.remove();
    },
    rerender: () => act(() => rerenderFn()),
  };
}

function fireRequest(): { requestId: string; received: Array<{ id?: string; cancelled?: boolean }> } {
  const received: Array<{ id?: string; cancelled?: boolean }> = [];
  const requestId = `pick-test-${received.length}-${'x'}`;
  const onPicked = (e: Event): void => {
    const d = (e as CustomEvent).detail;
    if (d?.requestId === requestId) received.push(d);
  };
  window.addEventListener(COMPONENT_TARGET_PICKED_EVENT, onPicked);
  act(() => {
    window.dispatchEvent(
      new CustomEvent(COMPONENT_TARGET_PICK_REQUEST_EVENT, { detail: { requestId } }),
    );
  });
  // 핸들러 정리는 각 it 에서 검증 후 자연 GC — received 캡처가 목적.
  return { requestId, received };
}

describe('EditorCanvasOverlay — 컴포넌트 영역 pick 캔버스 수신부 (D-J)', () => {
  beforeEach(() => {
    delete (window as any).__g7LayoutEditorHistory;
    window.document.body.innerHTML = '';
  });

  it('① pick-request 수신 → pick 배너 렌더', async () => {
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    h.unmount();
  });

  it('② id 보유 노드 클릭 → 그 id 를 picked 회신 + 배너 사라짐(모드 종료)', async () => {
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    // 캔버스 노드(path "0") 클릭 — wrapper capture 리스너가 잡아 picked 회신.
    act(() => fireEvent.click(h.nodeEl('0')));
    expect(received.at(-1)).toMatchObject({ id: 'main-form' });
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeNull(),
    );
    // pick 회신 후 캔버스에 부수 선택이 남지 않는다("선택한 컴포넌트가
    // 선택되어 버림"). finishPick 의 clearSelection 으로 선택 오버레이(ⓘ)가 뜨지 않아야 한다.
    expect(window.document.querySelector('[data-testid="g7le-overlay-info-button"]')).toBeNull();
    h.unmount();
  });

  it('③ id 미부여 노드 클릭 → 회신 없음(모드 유지, 배너 잔존)', async () => {
    const h = mountWithFrame([{ name: 'Div' }]); // id 없음(props.id/id 둘 다 부재)
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    act(() => fireEvent.click(h.nodeEl('0')));
    // id 없는 노드 → 회신 안 함.
    expect(received.length).toBe(0);
    // 모드 유지 — 배너 잔존.
    expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy();
    h.unmount();
  });

  it('③-b 자동 부여 id(auto_*) 노드 클릭 → 회신 없음(직접 부여 id 만 선택 가능)', async () => {
    // 편집기/렌더러가 자동 부여한 auto_ id 는 "유저 직접 부여"가 아니므로 선택 대상에서 제외.
    const h = mountWithFrame([{ name: 'Span', props: { id: 'auto_Span_ab12cd' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    act(() => fireEvent.click(h.nodeEl('0')));
    // auto_ id → 회신 안 함(모드 유지).
    expect(received.length).toBe(0);
    expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy();
    h.unmount();
  });

  it('④ Esc → cancelled 회신 + 모드 종료', async () => {
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    act(() => {
      window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    });
    expect(received.at(-1)).toMatchObject({ cancelled: true });
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeNull(),
    );
    h.unmount();
  });

  it('⑤ 취소 배너 버튼 → cancelled 회신 + 모드 종료', async () => {
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    const cancelBtn = await waitFor(() => {
      const b = window.document.querySelector('[data-testid="g7le-canvas-pick-cancel"]');
      expect(b).toBeTruthy();
      return b as HTMLElement;
    });
    act(() => fireEvent.click(cancelBtn));
    expect(received.at(-1)).toMatchObject({ cancelled: true });
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeNull(),
    );
    h.unmount();
  });

  it('⑥ node.id(레거시 위치)에서도 id 추출 — props.id 부재 시 폴백', async () => {
    const h = mountWithFrame([{ name: 'Form', id: 'legacy-id' } as never]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    const { received } = fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    act(() => fireEvent.click(h.nodeEl('0')));
    expect(received.at(-1)).toMatchObject({ id: 'legacy-id' });
    h.unmount();
  });

  // ── pick 모드 시각 분리 ──
  // pick 모드에서는 일반 선택 어포던스(ⓘ/리사이즈/＋요소추가/↑타입칩/선택 실선)를 숨기고,
  // pick 전용 hover(id 보유=강조, id 없음=불가 안내)만 둔다.

  it('⑦ pick 모드 중에는 일반 선택 어포던스(ⓘ·＋요소추가)가 DOM 에 없다(영역 선택 전용)', async () => {
    // ElementOverlay/InsertionAffordances 를 pick 모드면 `!pickRequestId` 가드로 통째 미렌더.
    // jsdom 에선 일반 모드에서도 selectedBox 측정(rect=0)이 안 돼 어포던스가 안 뜨므로, 본 단위는
    // "pick 모드에서 어포던스 컨테이너가 확실히 없음"을 잠그고, "선택돼 있던 어포던스가 pick 진입 시
    // 사라짐"의 시각 확인은 라이브가 담당한다.
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    expect(window.document.querySelector('[data-testid="g7le-overlay-info-button"]')).toBeNull();
    expect(window.document.querySelector('[data-testid="g7le-insertion-affordances"]')).toBeNull();
    h.unmount();
  });

  it('⑧ pick 종료(취소) 후 일반 선택 어포던스 컨테이너(InsertionAffordances)가 복귀한다', async () => {
    // pick 모드 가드(`!pickRequestId`)가 모드 종료 시 풀려 일반 편집 UI 가 돌아오는지(영구 숨김
    // 아님) 확인. InsertionAffordances 는 points 가 있을 때만 렌더되나, pick 모드 동안 강제
    // 미렌더 → 종료 후엔 일반 조건으로 복귀(컨테이너 가드가 pickRequestId 에만 의존).
    const h = mountWithFrame([{ name: 'Form', props: { id: 'main-form' } }]);
    await waitFor(() => expect((window as any).__g7LayoutEditorHistory).toBeTruthy());
    fireRequest();
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeTruthy(),
    );
    // 취소 → pick 모드 종료.
    const cancelBtn = window.document.querySelector('[data-testid="g7le-canvas-pick-cancel"]') as HTMLElement;
    act(() => fireEvent.click(cancelBtn));
    await waitFor(() =>
      expect(window.document.querySelector('[data-testid="g7le-canvas-pick-banner"]')).toBeNull(),
    );
    // 모드 종료 후 pick hover 박스도 사라짐(잔존 금지).
    expect(window.document.querySelector('[data-testid="g7le-canvas-pick-hover"]')).toBeNull();
    h.unmount();
  });
});
