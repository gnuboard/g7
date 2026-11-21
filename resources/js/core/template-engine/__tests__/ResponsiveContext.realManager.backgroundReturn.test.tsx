/**
 * ResponsiveProvider + 실제 ResponsiveManager — 앱 전환 복귀 뒤 사용자에게 보이는 프리셋 (engine-v1.97.1)
 *
 * 회귀 배경: 휴대폰에서 다른 앱을 다녀와 화면이 가려진 채 불러와지면 엔진이 태블릿·PC 폭으로 시작하고,
 * 그 화면이 그려지면 브라우저가 화면을 축소해 `innerWidth` 가 부풀어 resize 뒤에도 PC·태블릿 화면에 머물렀다.
 * 여기서는 실제 싱글톤과 실제 Provider 를 써서 소비 컴포넌트가 받는 `matchedPreset` 을 단언한다.
 *
 * 배경: 내부 트러블슈팅 사례 「확장 코드의 화면 폭 판정 관련 이슈」 사례 2
 * @scenario-manifest tests/scenarios/responsive-viewport-background-return.yaml
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, screen, act, cleanup } from '@testing-library/react';

const originalInnerWidth = Object.getOwnPropertyDescriptor(window, 'innerWidth');
const originalMatchMedia = window.matchMedia;
const current = { iw: 980, cw: 980, css: 980, visibility: 'hidden' as 'hidden' | 'visible' };

/**
 * 창 환경을 바꾼다.
 *
 * @param env 바꿀 값
 */
function setEnv(env: Partial<typeof current>): void {
  Object.assign(current, env);
  Object.defineProperty(window, 'innerWidth', { configurable: true, get: () => current.iw });
  Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, get: () => current.cw });
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => current.visibility });
  (window as any).matchMedia = (query: string) => {
    const min = /min-width:\s*([\d.]+)px/.exec(query);
    const max = /max-width:\s*([\d.]+)px/.exec(query);

    return {
      matches: (min ? current.css >= Number(min[1]) : true) && (max ? current.css <= Number(max[1]) : true),
    };
  };
}

describe('ResponsiveProvider + 실제 ResponsiveManager — 앱 전환 복귀 (engine-v1.97.1)', () => {
  let destroy: (() => void) | null = null;

  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    cleanup();
    destroy?.();
    destroy = null;
    vi.useRealTimers();
    if (originalInnerWidth) {
      Object.defineProperty(window, 'innerWidth', originalInnerWidth);
    }
    delete (document.documentElement as any).clientWidth;
    delete (document as any).visibilityState;
    (window as any).matchMedia = originalMatchMedia;
  });

  // @scenario case=background_return
  // @effects engine_width_corrects_after_zoomed_out_resize
  it('C1 가려진 로드로 tablet(980)으로 시작해도 화면 축소 환경의 resize 뒤 matchedPreset 이 mobile 이 된다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980, visibility: 'hidden' });
    // 이 파일에서 처음 불러오는 순간 싱글톤이 만들어진다(시험 파일마다 모듈이 격리된다).
    // vi.resetModules() 를 쓰면 react 도 새로 불려 훅 호출이 깨지므로 쓰지 않는다.
    const { ResponsiveProvider, useResponsive } = await import('../ResponsiveContext');
    const { responsiveManager } = await import('../ResponsiveManager');
    destroy = () => responsiveManager.destroy();

    const Probe: React.FC = () => {
      const { matchedPreset, width } = useResponsive();

      return <div data-testid="probe">{`${matchedPreset}:${width}`}</div>;
    };

    render(
      <ResponsiveProvider>
        <Probe />
      </ResponsiveProvider>
    );
    expect(screen.getByTestId('probe').textContent).toBe('tablet:980');

    // 앞으로 돌아왔지만 태블릿 화면이 그려져 브라우저가 화면을 축소한 상태에서 resize
    setEnv({ iw: 980, cw: 390, css: 390, visibility: 'visible' });
    act(() => {
      window.dispatchEvent(new Event('resize'));
      vi.advanceTimersByTime(200);
    });

    expect(screen.getByTestId('probe').textContent).toBe('mobile:390');
  });
});
