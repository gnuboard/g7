// e2e:allow 레이아웃 로드 실패 화면 DOM 단위(운영 호출부 없음 — 동형 결함 정리). 브라우저 계층은 strict-csp.spec.ts.
/**
 * LayoutLoader.renderErrorState — 새로고침 버튼에 인라인 onclick 이 없다 (#158, engine-v1.84.1)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { LayoutLoader } from '../LayoutLoader';
import type { ComponentRegistry } from '../ComponentRegistry';

describe('LayoutLoader 오류 화면 인라인 핸들러 제거 (#158)', () => {
  const originalLocation = window.location;
  let reload: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    reload = vi.fn();
    Object.defineProperty(window, 'location', { configurable: true, value: { ...originalLocation, reload } });
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
  });

  /**
   * @scenario surface=error_screen, csp=none, failure=routes_500
   * @effects error_screen_reload_without_inline_handler
   */
  it('오류 상태 HTML 에 on* 속성이 없고 버튼 click 이 reload 를 부른다', () => {
    const loader = new LayoutLoader({ getComponent: vi.fn(), hasComponent: vi.fn() } as unknown as ComponentRegistry);
    const container = document.createElement('div');

    // 레이아웃 데이터 없이 렌더 → renderErrorState 경로
    loader.renderLayout(container);

    expect(container.querySelector('.layout-error')).not.toBeNull();
    expect(container.innerHTML).not.toMatch(/\son[a-z]+\s*=/i);

    (container.querySelector('button') as HTMLButtonElement).click();
    expect(reload).toHaveBeenCalledTimes(1);
  });
});
