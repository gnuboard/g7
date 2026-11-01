// e2e:allow 오류 화면 DOM 단위. 브라우저 계층(strict CSP 실제 차단)은 strict-csp.spec.ts 가 커버한다.
/**
 * ErrorDisplay — 새로고침 버튼에 인라인 on* 핸들러가 없다 (#158, engine-v1.84.1)
 *
 * strict CSP(`script-src 'self'`)에서는 `onclick`·`onmouseover`·`onmouseout` 속성이 실행되지 않아
 * 오류 화면의 유일한 탈출구(새로고침)가 무반응이었다. 리스너로 묶는다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ErrorDisplay } from '../ErrorDisplay';

describe('ErrorDisplay 인라인 핸들러 제거 (#158)', () => {
  const originalLocation = window.location;
  let reload: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
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
  it('렌더 HTML 에 on* 속성이 없고 버튼 click 이 reload 를 부른다', () => {
    ErrorDisplay.render('app', { title: '페이지 로딩 실패', message: '오류', showReloadButton: true, debug: false });

    const app = document.getElementById('app')!;
    expect(app.innerHTML).not.toMatch(/\son[a-z]+\s*=/i);

    const button = app.querySelector('button') as HTMLButtonElement;
    expect(button).not.toBeNull();
    button.click();
    expect(reload).toHaveBeenCalledTimes(1);
  });

  /**
   * @scenario surface=error_screen, csp=none, failure=routes_500
   * @effects error_screen_reload_without_inline_handler
   */
  it('hover 색 전환은 mouseenter/mouseleave 리스너로 동작한다', () => {
    ErrorDisplay.render('app', { title: 't', message: 'm', showReloadButton: true, debug: false });

    const button = document.querySelector('#app button') as HTMLButtonElement;
    button.dispatchEvent(new Event('mouseenter'));
    expect(button.style.backgroundColor).toBe('rgb(29, 78, 216)');
    button.dispatchEvent(new Event('mouseleave'));
    expect(button.style.backgroundColor).toBe('rgb(37, 99, 235)');
  });
});
