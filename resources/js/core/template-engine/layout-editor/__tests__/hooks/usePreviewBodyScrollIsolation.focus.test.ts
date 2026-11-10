/**
 * usePreviewBodyScrollIsolation — 미리보기 안 자동 포커스가 편집기 문서를 스크롤하지 않는다 (#869 λ 이월 4)
 *
 * 결함: 편집기를 열자마자 문서가 1155px 내려갔다. sirsoft-popup 의 팝업 대화창(`g7pp-dialog`)이 마운트 때 focus() 를
 * 불렀고, 미리보기는 같은 문서라 브라우저가 편집기 화면 전체를 그 창 쪽으로 스크롤했다. 편집기 생존 동안 미리보기
 * frame 안 요소의 focus 에만 `preventScroll: true` 를 더한다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_preview_autofocus_does_not_scroll_editor
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import { usePreviewBodyScrollIsolation } from '../../hooks/usePreviewBodyScrollIsolation';

afterEach(() => {
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

function mount(): { inFrame: HTMLButtonElement; outside: HTMLButtonElement } {
  const frame = document.createElement('div');
  frame.className = 'g7le-preview-frame';
  const inFrame = document.createElement('button');
  frame.appendChild(inFrame);
  const outside = document.createElement('button');
  document.body.append(frame, outside);
  return { inFrame, outside };
}

describe('미리보기 자동 포커스 스크롤 차단', () => {
  /** @effects editor_preview_autofocus_does_not_scroll_editor */
  it('편집기 마운트 동안 frame 안 요소 focus 는 preventScroll:true, 밖은 그대로, 언마운트하면 원래 focus', () => {
    const original = HTMLElement.prototype.focus;
    const spy = vi.fn(function (this: HTMLElement) { /* 기록만 */ });
    HTMLElement.prototype.focus = spy as unknown as typeof HTMLElement.prototype.focus;
    try {
      const { inFrame, outside } = mount();
      const { unmount } = renderHook(() => usePreviewBodyScrollIsolation());

      inFrame.focus({ focusVisible: true } as FocusOptions);
      expect(spy).toHaveBeenLastCalledWith({ focusVisible: true, preventScroll: true });
      inFrame.focus();
      expect(spy).toHaveBeenLastCalledWith({ preventScroll: true });
      outside.focus();
      expect(spy).toHaveBeenLastCalledWith(undefined);

      unmount();
      expect(HTMLElement.prototype.focus).toBe(spy);
    } finally {
      HTMLElement.prototype.focus = original;
    }
  });
});
