/**
 * PreviewCanvas — 맞춤 줌 무대 구조 회귀 (창 1280 문서 가로 스크롤)
 *
 * 회귀 원인: frame 래퍼가 `width: deviceWidth` + `transform: scale(zoom)` 이라 화면에서는 줄어도
 * 레이아웃 폭은 deviceWidth(데스크톱 1280)로 남았다. 캔버스는 가용 폭을 재지 않았고 배율은 줌 100% 면
 * 1 이라, 창 1280 에서 문서 폭이 트리 280 + 여백 48 + 1280 = 1608px 이 되어 가로 스크롤이 생겼다.
 * 또 오버레이 레이어가 래퍼(transform) 안에 있어, 화면 좌표로 잰 박스가 축소 시 배율을 한 번 더
 * 맞아 노드와 어긋났다.
 *
 * 이 파일은 실물 PreviewCanvas 를 렌더해 다음을 잠근다(jsdom 은 레이아웃이 없어 캔버스 clientWidth 와
 * ResizeObserver 를 대역으로 준다).
 *  - 무대(`g7le-preview-stage`) 폭 = deviceWidth × 맞춤 배율, 래퍼 폭 = deviceWidth, 좌상단 기준 축소
 *  - frame 폭과 미리보기 vw 기준은 deviceWidth 그대로(미리보기 안 반응형 판정 불변)
 *  - 오버레이 레이어는 래퍼(transform) 밖, 무대 안 형제
 *  - 캔버스 폭이 바뀌면(ResizeObserver) 배율이 다시 맞춰진다
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_preview_stage_uses_scaled_layout_width, editor_overlay_outside_scale_transform, editor_preview_keeps_device_width_for_responsive
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup, act } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { PreviewCanvas, ACTUAL_SIZE_CANVAS_PADDING } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

function SelectRoute({ path, layoutName }: { path: string; layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  editorDispatch = dispatch;
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path, layoutName } });
  }, [dispatch, path, layoutName]);
  return null;
}

/** 편집기 상태 dispatch — 실제 크기 토글을 흉내 낼 때 쓴다. */
let editorDispatch: ReturnType<typeof useLayoutEditor>['dispatch'] | null = null;

/** 캔버스 clientWidth 대역 값(px). 테스트마다 바꾼다. */
let canvasClientWidth = 0;
/** 등록된 ResizeObserver 콜백 — 캔버스 크기 변경을 흉내 낼 때 부른다. */
const observers: Array<{ cb: ResizeObserverCallback; targets: Element[] }> = [];

class FakeResizeObserver {
  private entry: { cb: ResizeObserverCallback; targets: Element[] };
  constructor(cb: ResizeObserverCallback) {
    this.entry = { cb, targets: [] };
    observers.push(this.entry);
  }
  observe(el: Element): void {
    this.entry.targets.push(el);
  }
  unobserve(): void {}
  disconnect(): void {
    this.entry.targets = [];
  }
}

function fireCanvasResize(): void {
  for (const o of observers) {
    const canvas = o.targets.find((t) => (t as HTMLElement).dataset?.testid === 'g7le-preview-canvas');
    if (canvas) o.cb([], o as unknown as ResizeObserver);
  }
}

describe('[case:wysiwyg-10] PreviewCanvas — 맞춤 줌 무대(문서 가로 스크롤 제거)', () => {
  let originalG7Core: any;
  let clientWidthSpy: ReturnType<typeof vi.spyOn> | null = null;
  let originalRO: any;
  let originalRaf: any;

  function installFetchMock() {
    (globalThis as any).fetch = vi.fn(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({
            data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true },
          }),
        };
      }
      if (url.includes('/components.json')) {
        return {
          ok: true,
          json: async () => ({
            version: '1.0.0',
            templateId: 'sirsoft-basic',
            components: { basic: [], composite: [], layout: [] },
          }),
        };
      }
      if (url.includes('/lang/')) {
        return { ok: true, json: async () => ({}) };
      }
      if (url.includes('/api/layouts/')) {
        return {
          ok: true,
          json: async () => ({
            data: { components: [{ type: 'basic', name: 'Div', props: {} }] },
          }),
        };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });
  }

  beforeEach(() => {
    ComponentRegistry.resetInstance();
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = { t: (k: string) => k };
    (window as any).SirsoftBasic = {};
    observers.length = 0;
    originalRO = (window as any).ResizeObserver;
    (window as any).ResizeObserver = FakeResizeObserver;
    (globalThis as any).ResizeObserver = FakeResizeObserver;
    originalRaf = globalThis.requestAnimationFrame;
    // 다음 프레임 미룸을 매크로태스크로 흉내 낸다(window 와 전역 양쪽). 실제 rAF 처럼 핸들을
    // 먼저 돌려주고 콜백은 나중에 부른다.
    let rafSeq = 0;
    const immediateRaf = ((cb: FrameRequestCallback) => {
      rafSeq += 1;
      setTimeout(() => cb(0), 0);
      return rafSeq;
    }) as typeof window.requestAnimationFrame;
    window.requestAnimationFrame = immediateRaf;
    globalThis.requestAnimationFrame = immediateRaf;
    // 캔버스 요소만 clientWidth 를 돌려준다(그 밖은 jsdom 기본 0).
    clientWidthSpy = vi
      .spyOn(HTMLElement.prototype, 'clientWidth', 'get')
      .mockImplementation(function (this: HTMLElement) {
        return this.dataset?.testid === 'g7le-preview-canvas' ? canvasClientWidth : 0;
      });
  });

  afterEach(() => {
    cleanup();
    clientWidthSpy?.mockRestore();
    (window as any).ResizeObserver = originalRO;
    (globalThis as any).ResizeObserver = originalRO;
    window.requestAnimationFrame = originalRaf;
    globalThis.requestAnimationFrame = originalRaf;
    (window as any).G7Core = originalG7Core;
    delete (window as any).SirsoftBasic;
    delete (window as any).__templateApp;
    ComponentRegistry.resetInstance();
  });

  function renderCanvas() {
    const engine = new TranslationEngine();
    // children 은 필수 prop — props 로 넘긴다(타입 일치, 실행 동작 동일).
    const tree = React.createElement(
      EditorModalProvider,
      null,
      React.createElement(SelectRoute, { path: '/', layoutName: 'home' }),
      React.createElement(PreviewCanvas),
    );
    return render(
      React.createElement(TranslationProvider, {
        translationEngine: engine,
        translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
        children: React.createElement(LayoutEditorProvider, {
          templateIdentifier: 'sirsoft-basic',
          initialLocale: 'ko',
          children: tree,
        }),
      }),
    );
  }

  /** @effects editor_preview_stage_uses_scaled_layout_width, editor_preview_keeps_device_width_for_responsive */
  it('캔버스가 기기 폭보다 좁으면(창 1280 상당) 무대 폭을 축소 폭으로 잡고 래퍼는 deviceWidth 로 줄인다', async () => {
    // 창 1280 − 스크롤바 15 = 셸 최소 1280 → 캔버스 1280 − 트리 280 = 1000 → 가용 952.
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();

    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    const wrapper = (await findByTestId('g7le-preview-frame-wrapper')) as HTMLElement;
    const frame = (await findByTestId('g7le-preview-frame')) as HTMLElement;

    await waitFor(() => {
      expect(stage.style.width).toBe('952px');
    });
    // 래퍼는 기기 폭 그대로, 좌상단 기준 축소(화면상 frame 좌상단 = 무대 좌상단).
    expect(wrapper.style.width).toBe('1280px');
    expect(wrapper.style.transform).toBe(`scale(${952 / 1280})`);
    expect(wrapper.style.transformOrigin).toBe('top left');
    // 미리보기 안 반응형 판정 입력(frame 폭, vw 기준)은 기기 폭 그대로다.
    expect(frame.style.width).toBe('1280px');
    expect(frame.style.getPropertyValue('--g7le-vw')).toBe('12.8px');
    // 캔버스는 내용 폭에 밀려 늘지 않아야 측정이 갇히지 않는다.
    const canvas = (await findByTestId('g7le-preview-canvas')) as HTMLElement;
    expect(canvas.style.minWidth).toBe('0');
  });

  /** @effects editor_overlay_outside_scale_transform */
  it('오버레이 레이어는 래퍼(transform) 밖, 무대 안 형제이고 폭은 축소 폭이다', async () => {
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();

    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    const wrapper = (await findByTestId('g7le-preview-frame-wrapper')) as HTMLElement;
    const overlay = (await findByTestId('g7le-overlay-layer')) as HTMLElement;

    await waitFor(() => {
      expect(overlay.style.width).toBe('952px');
    });
    expect(wrapper.contains(overlay)).toBe(false);
    expect(overlay.parentElement).toBe(stage);
    // frame 은 계속 래퍼의 자식이다(frameEl.parentElement 를 쓰는 코드 호환).
    const frame = (await findByTestId('g7le-preview-frame')) as HTMLElement;
    expect(frame.parentElement).toBe(wrapper);
    // 무대는 쌓임 맥락이다 — 오버레이 z-index 가 sticky 헤더와 겨루지 않게.
    expect(stage.style.isolation).toBe('isolate');
    expect(stage.hasAttribute('data-g7le-preview-stage')).toBe(true);
  });

  /** @effects editor_preview_stage_uses_scaled_layout_width */
  it('캔버스가 기기 폭보다 넓으면 축소하지 않고 무대 높이는 자동(종전 레이아웃)', async () => {
    canvasClientWidth = 2000;
    installFetchMock();
    const { findByTestId } = renderCanvas();

    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    const wrapper = (await findByTestId('g7le-preview-frame-wrapper')) as HTMLElement;
    await waitFor(() => {
      expect(stage.style.width).toBe('1280px');
    });
    expect(wrapper.style.transform).toBe('scale(1)');
    expect(stage.style.height).toBe('');
  });

  /** @effects editor_preview_scale_fits_available_width */
  it('캔버스 폭이 바뀌면(ResizeObserver) 배율을 다시 맞춘다 — 줄면 즉시, 늘면 문턱 이상일 때', async () => {
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();
    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    await waitFor(() => {
      expect(stage.style.width).toBe('952px');
    });

    // 세로 스크롤바가 생겨 15px 좁아짐 → 즉시 반영.
    canvasClientWidth = 985;
    act(() => {
      fireCanvasResize();
    });
    await waitFor(() => {
      expect(stage.style.width).toBe('937px');
    });

    // 스크롤바가 사라져 15px 넓어짐 → 진동 방지 문턱 미만이라 반영하지 않는다.
    canvasClientWidth = 1000;
    act(() => {
      fireCanvasResize();
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });
    expect(stage.style.width).toBe('937px');

    // 트리 접기(+280) → 반영.
    canvasClientWidth = 1280;
    act(() => {
      fireCanvasResize();
    });
    await waitFor(() => {
      expect(stage.style.width).toBe('1232px');
    });
  });
  /** @effects editor_actual_size_toggle_shows_one_to_one */
  it('실제 크기(1:1)를 켜면 배율 1, 넘친 폭은 캔버스 안에서만 가로 스크롤(시작 정렬)하고 끄면 맞춤으로 돌아간다', async () => {
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();
    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    const canvas = (await findByTestId('g7le-preview-canvas')) as HTMLElement;
    await waitFor(() => {
      expect(stage.style.width).toBe('952px');
    });
    expect(canvas.style.overflow).toBe('visible');
    expect(canvas.style.justifyContent).toBe('center');

    act(() => {
      editorDispatch!({ type: 'SET_PREVIEW_ACTUAL_SIZE', actualSize: true });
    });
    await waitFor(() => {
      expect(stage.style.width).toBe('1280px');
    });
    // 캔버스 폭은 그대로(문서 가로 스크롤 없음), 넘친 폭은 캔버스 안 가로 스크롤.
    expect(canvas.style.minWidth).toBe('0');
    expect(canvas.style.overflowX).toBe('auto');
    expect(canvas.style.justifyContent).toBe('flex-start');

    act(() => {
      editorDispatch!({ type: 'SET_PREVIEW_ACTUAL_SIZE', actualSize: false });
    });
    expect(stage.style.width).toBe('952px');
    expect(canvas.style.overflow).toBe('visible');
  });

  /** @effects editor_actual_size_toggle_shows_one_to_one */
  it('실제 크기에서 캔버스 안쪽 여백이 프레임 밖 어포던스(위 50·아래 56·옆 30px)를 담는다 — 캔버스 스크롤 영역에 잘리지 않음', async () => {
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();
    const canvas = (await findByTestId('g7le-preview-canvas')) as HTMLElement;
    const stage = (await findByTestId('g7le-preview-stage')) as HTMLElement;
    await waitFor(() => {
      expect(stage.style.width).toBe('952px');
    });
    expect(canvas.style.padding).toBe('24px');

    act(() => {
      editorDispatch!({ type: 'SET_PREVIEW_ACTUAL_SIZE', actualSize: true });
    });
    // overflow-x:auto 는 세로도 잘라내는 스크롤 상자가 된다 — 어포던스 최대 바깥 거리(아래 56px)보다 넓게.
    expect(parseFloat(canvas.style.padding)).toBeGreaterThanOrEqual(ACTUAL_SIZE_CANVAS_PADDING);
    expect(ACTUAL_SIZE_CANVAS_PADDING).toBeGreaterThan(56);

    // 실제 크기 동안 넓어진 여백으로 잰 폭을 가용 폭으로 저장하지 않는다 — 끄면 바로 952 로 돌아간다.
    canvasClientWidth = 1000;
    act(() => {
      fireCanvasResize();
    });
    await act(async () => {
      await new Promise((r) => setTimeout(r, 20));
    });
    act(() => {
      editorDispatch!({ type: 'SET_PREVIEW_ACTUAL_SIZE', actualSize: false });
    });
    expect(stage.style.width).toBe('952px');
    expect(canvas.style.padding).toBe('24px');
  });

  /** @effects editor_canvas_height_follows_wrapped_header */
  it('캔버스 최소 높이는 창 높이에서 헤더 높이(--g7le-header-h)를 뺀 값 — 툴바가 감겨 헤더가 높아지면 줄어든다', async () => {
    canvasClientWidth = 1000;
    installFetchMock();
    const { findByTestId } = renderCanvas();
    const canvas = (await findByTestId('g7le-preview-canvas')) as HTMLElement;
    expect(canvas.style.minHeight).toBe('calc(100vh - var(--g7le-header-h, 0px))');
  });
});
