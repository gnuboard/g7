/**
 * usePreviewMediaIsolation 회귀 테스트 (이슈 #141 D7)
 *
 * 편집기 생존 동안만 미리보기 프레임을 이름 있는 컨테이너로 지정하고(시트의 폭 `@media` 를
 * `@container` 로 나누는 재작성은 sheetRewriter 테스트가 잠근다), 언마운트 때 흔적을 남기지 않는다.
 * 컨테이너 쿼리를 지원하지 않는 환경에서는 아무것도 하지 않는다.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { renderHook } from '@testing-library/react';
import {
  usePreviewMediaIsolation,
  rescanPreviewMediaIsolation,
  MEDIA_ISOLATION_STYLE_KEY,
} from '../../hooks/usePreviewMediaIsolation';
import { getCssText } from '../../../../style/RuntimeStyleSheet';

// 표식 요소 — 생성형 시트 환경은 <meta>, 폴백(jsdom)은 <style>. 태그를 가리지 않고 속성으로 찾는다.
const containerStyle = () => document.head.querySelector('[data-g7le-media-isolation]');

describe('usePreviewMediaIsolation', () => {
  const originalCSS = (globalThis as any).CSS;

  afterEach(() => {
    (globalThis as any).CSS = originalCSS;
    delete (window as any).__g7lePreviewMediaIsolation;
  });

  /**
   * @scenario css_api=absent
   * @effects preview_media_isolation_noop_without_container_queries
   */
  it('컨테이너 쿼리를 지원하지 않으면 no-op', () => {
    (globalThis as any).CSS = { supports: () => false };
    const { unmount } = renderHook(() => usePreviewMediaIsolation());
    expect(containerStyle()).toBeNull();
    expect(() => rescanPreviewMediaIsolation()).not.toThrow();
    unmount();
  });

  /**
   * @scenario css_api=present, lifecycle=mount
   * @effects preview_media_isolation_container_declared_while_mounted
   */
  it('마운트 때 컨테이너 규칙을 붙이고 계측값을 노출, 언마운트 때 지운다', () => {
    (globalThis as any).CSS = { supports: vi.fn(() => true) };
    const { result, unmount } = renderHook(() => usePreviewMediaIsolation());

    const style = containerStyle();
    expect(style).not.toBeNull();
    expect(style!.id).toBe(MEDIA_ISOLATION_STYLE_KEY);
    expect(style!.hasAttribute('data-g7le-no-media-isolation')).toBe(true);
    const css = getCssText(MEDIA_ISOLATION_STYLE_KEY);
    expect(css).toContain('.g7le-preview-frame');
    expect(css).toContain('container:g7le-preview / inline-size');
    expect((window as any).__g7lePreviewMediaIsolation).toMatchObject({
      sheets: expect.any(Number),
      converted: expect.any(Number),
      unsupported: expect.any(Number),
      skipped: expect.any(Number),
      ms: expect.any(Number),
    });

    expect(typeof result.current.rescan).toBe('function');
    expect(() => result.current.rescan()).not.toThrow();
    expect(() => rescanPreviewMediaIsolation()).not.toThrow();

    unmount();
    expect(containerStyle()).toBeNull();
    expect(getCssText(MEDIA_ISOLATION_STYLE_KEY)).toBeNull();
    expect((window as any).__g7lePreviewMediaIsolation).toBeUndefined();
  });
});
