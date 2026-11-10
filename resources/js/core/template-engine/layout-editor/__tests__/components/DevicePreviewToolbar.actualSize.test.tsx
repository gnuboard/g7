/**
 * DevicePreviewToolbar — 「실제 크기(1:1, 가로 스크롤 허용)」 토글 회귀 (2026-11-08 설계 확정)
 *
 * 맞춤 줌(줌 = 배율 상한)을 기본으로 둔 뒤, 좁은 창에서도 미리보기를 1:1 로 볼 길이 없어졌다. 토글을
 * 켜면 줌과 가용 폭을 무시하고 배율 1 로 그리고(캔버스가 좁으면 문서 가로 스크롤), 끄면 맞춤 줌으로
 * 돌아간다. 켜진 동안 줌 슬라이더는 쓸 수 없고, 배율 표시는 실제 배율(100%)이다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, act } from '@testing-library/react';
import { DevicePreviewToolbar } from '../../components/DevicePreviewToolbar';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { computePreviewScale } from '../../hooks/useDevicePreview';

let dispatchRef: ReturnType<typeof useLayoutEditor>['dispatch'] | null = null;
function Grab(): null {
  dispatchRef = useLayoutEditor().dispatch;
  return null;
}

function renderToolbar(): void {
  render(
    <TranslationProvider
      translationEngine={new TranslationEngine()}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        <Grab />
        <DevicePreviewToolbar />
      </LayoutEditorProvider>
    </TranslationProvider>,
  );
}

afterEach(() => {
  cleanup();
  dispatchRef = null;
});

describe('실제 크기 토글', () => {
  /** @effects editor_actual_size_toggle_shows_one_to_one */
  it('맞춤 줌 74% 상태에서 켜면 100%(1:1)·슬라이더 잠금, 끄면 맞춤으로 돌아간다', () => {
    renderToolbar();
    act(() => {
      dispatchRef!({ type: 'SET_PREVIEW_AVAILABLE_WIDTH', width: 952 });
    });
    const toggle = screen.getByTestId('g7le-zoom-actual-size');
    const slider = screen.getByTestId('g7le-zoom-slider') as HTMLInputElement;
    const value = screen.getByTestId('g7le-zoom-value');
    expect(value.textContent).toBe('74%');
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(slider.disabled).toBe(false);
    // 라벨은 다국어 키를 거친다(사전이 비어 키 그대로).
    expect(toggle.textContent).toBe('layout_editor.zoom.actual_size');
    expect(toggle.getAttribute('title')).toBe('layout_editor.zoom.actual_size_hint');

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    expect(value.textContent).toBe('100%');
    expect(slider.disabled).toBe(true);

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    expect(value.textContent).toBe('74%');
  });

  /** @effects editor_actual_size_toggle_shows_one_to_one */
  it('실제 크기는 줌·가용 폭과 무관하게 배율 1', () => {
    expect(computePreviewScale(1280, 0.5, 600, true)).toBe(1);
    expect(computePreviewScale(1920, 1, 952, true)).toBe(1);
    expect(computePreviewScale(1280, 1, 952, false)).toBeCloseTo(952 / 1280, 10);
  });
});
