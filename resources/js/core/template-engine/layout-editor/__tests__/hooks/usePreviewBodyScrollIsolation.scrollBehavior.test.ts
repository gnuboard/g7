/**
 * usePreviewBodyScrollIsolation — 편집 대상 템플릿의 `html { scroll-behavior: smooth }` 격리
 *
 * 결함: 편집기 미리보기는 같은 문서라 편집 대상 템플릿 CSS(sirsoft-basic `main.css` 152행
 * `html { scroll-behavior: smooth }`)가 편집기 문서에도 걸린다. 그러면 편집기의 문서 스크롤이 모두
 * 비동기(부드러운 스크롤)가 된다. 미리보기 안 컴포넌트가 열리며 focus() 로 문서를 움직이거나 요소를
 * scrollIntoView 하면, 화면이 몇 백 ms 동안 계속 움직인다. 그 사이 잰 좌표로 누르면 다른 곳을 누르고(드래그 시작
 * 실패), 열린 ⓘ 메뉴가 멈춘 포인터 밑을 지나가며 mouseleave 로 닫힌다.
 *
 * 고침: 편집기 생존 동안 `<html>` 인라인 `scroll-behavior: auto` 를 둬 템플릿 규칙을 덮는다(인라인이
 * 비-important 규칙을 이긴다). 언마운트 때 원래 인라인 값으로 되돌린다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_document_scroll_not_smoothed_by_template_css
 */

import { describe, it, expect, afterEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { usePreviewBodyScrollIsolation } from '../../hooks/usePreviewBodyScrollIsolation';

describe('usePreviewBodyScrollIsolation — html scroll-behavior', () => {
  afterEach(() => {
    document.documentElement.style.scrollBehavior = '';
    document.getElementById('tpl-smooth')?.remove();
  });

  /** @effects editor_document_scroll_not_smoothed_by_template_css */
  it('편집기 마운트 동안 템플릿 CSS 의 html smooth 를 인라인 auto 로 덮고, 언마운트 때 되돌린다', () => {
    const style = document.createElement('style');
    style.id = 'tpl-smooth';
    style.textContent = 'html { scroll-behavior: smooth; }';
    document.head.appendChild(style);
    expect(getComputedStyle(document.documentElement).scrollBehavior).toBe('smooth');

    const { unmount } = renderHook(() => usePreviewBodyScrollIsolation());
    expect(document.documentElement.style.scrollBehavior).toBe('auto');
    expect(getComputedStyle(document.documentElement).scrollBehavior).toBe('auto');

    unmount();
    expect(document.documentElement.style.scrollBehavior).toBe('');
  });

  /** @effects editor_document_scroll_not_smoothed_by_template_css */
  it('편집기 진입 전 인라인 값이 있었으면 그 값으로 되돌린다', () => {
    document.documentElement.style.scrollBehavior = 'smooth';
    const { unmount } = renderHook(() => usePreviewBodyScrollIsolation());
    expect(document.documentElement.style.scrollBehavior).toBe('auto');
    unmount();
    expect(document.documentElement.style.scrollBehavior).toBe('smooth');
  });
});
