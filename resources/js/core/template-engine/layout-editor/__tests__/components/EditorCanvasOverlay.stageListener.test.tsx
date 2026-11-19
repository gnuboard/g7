/**
 * EditorCanvasOverlay — 캔버스 capture 리스너 등록 위치(미리보기 무대) 회귀
 *
 * 배경: 맞춤 줌에서 오버레이 레이어를 축소 배율(transform) 밖으로 꺼내 frame 래퍼의 형제로 옮겼다
 * (PreviewCanvas `PreviewStage`). 오버레이(드래그 핸들, 어포던스) 위 클릭이 선택·인라인 편집에
 * 닿으려면 frame 과 오버레이의 공통 조상에 capture 리스너가 있어야 한다. 종전 등록 위치인
 * `frameEl.parentElement`(래퍼)는 더 이상 오버레이를 품지 않는다.
 *
 * 이 파일은 무대가 있으면 무대에, 없으면(손 조립 DOM) 종전대로 래퍼에 등록하는 결선 계약을 잠근다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_canvas_capture_listener_on_common_ancestor
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { EditorCanvasOverlay } from '../../components/EditorCanvasOverlay';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import type { UseLayoutDocumentResult } from '../../hooks/useLayoutDocument';

function buildDocCtx(): UseLayoutDocumentResult {
  return {
    document: { layoutName: 'home', raw: { components: [] }, lockVersion: 1 },
    isLoading: false,
    error: null,
    isDirty: false,
    saveSuccessCounter: 0,
    reload: async () => {},
    patchLayout: () => {},
    setLayoutComponents: () => {},
    save: async () => ({ kind: 'success', newLockVersion: 2 }),
  } as unknown as UseLayoutDocumentResult;
}

function mount(frame: HTMLElement): void {
  render(
    <TranslationProvider
      translationEngine={new TranslationEngine()}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <EditorModalProvider>
          <LayoutDocumentProvider value={buildDocCtx()}>
            <EditorCanvasOverlay
              frameEl={frame}
              manifest={{ components: {} } as any}
              nesting={null}
              componentPalette={null}
            />
          </LayoutDocumentProvider>
        </EditorModalProvider>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );
}

/** 등록된 capture 리스너 이벤트 이름 목록 */
function captureEvents(spy: { mock: { calls: unknown[][] } }): string[] {
  return spy.mock.calls.filter((c: unknown[]) => c[2] === true).map((c: unknown[]) => String(c[0]));
}

describe('EditorCanvasOverlay — capture 리스너는 frame 과 오버레이의 공통 조상에 단다', () => {
  afterEach(() => {
    cleanup();
    document.body.innerHTML = '';
  });

  /** @effects editor_canvas_capture_listener_on_common_ancestor */
  it('미리보기 무대가 있으면 래퍼가 아니라 무대에 click·dblclick·mousemove 를 단다', () => {
    // stage > wrapper > frame, stage > overlay-layer (PreviewStage 구조)
    const stage = document.createElement('div');
    stage.setAttribute('data-g7le-preview-stage', '');
    const wrapper = document.createElement('div');
    wrapper.className = 'g7le-preview-frame-wrapper';
    const frame = document.createElement('div');
    frame.className = 'g7le-preview-frame';
    const overlayLayer = document.createElement('div');
    overlayLayer.className = 'g7le-overlay-layer';
    wrapper.appendChild(frame);
    stage.appendChild(wrapper);
    stage.appendChild(overlayLayer);
    document.body.appendChild(stage);

    const stageSpy = vi.spyOn(stage, 'addEventListener');
    const wrapperSpy = vi.spyOn(wrapper, 'addEventListener');
    mount(frame);

    const onStage = captureEvents(stageSpy);
    expect(onStage).toEqual(expect.arrayContaining(['click', 'dblclick', 'mousemove', 'mouseleave']));
    expect(captureEvents(wrapperSpy)).not.toContain('click');
    // 무대는 오버레이 레이어도 품는다 — 오버레이 위 클릭이 이 리스너를 거친다.
    expect(stage.contains(overlayLayer)).toBe(true);
  });

  /** @effects editor_canvas_capture_listener_on_common_ancestor */
  it('무대가 없는 구성은 종전대로 frame 의 부모(래퍼)에 단다', () => {
    const wrapper = document.createElement('div');
    const frame = document.createElement('div');
    wrapper.appendChild(frame);
    document.body.appendChild(wrapper);

    const wrapperSpy = vi.spyOn(wrapper, 'addEventListener');
    mount(frame);

    expect(captureEvents(wrapperSpy)).toEqual(expect.arrayContaining(['click', 'dblclick', 'mousemove']));
  });
});
