/**
 * ResponsiveManager — 가려진 로드·화면 축소 뒤 폭을 바로잡는다 (engine-v1.97.1)
 *
 * 회귀 배경: 휴대폰에서 [카카오 로그인] → 카카오톡 앱 → 브라우저로 돌아오면 사이트가 PC 화면으로 축소되어
 * 굳고, 새로고침해야 모바일 화면으로 돌아왔다. 엔진은 모듈을 불러올 때 `innerWidth` 를 한 번 읽고
 * resize 때만 다시 읽었는데, (1) 화면이 가려진 채 불러오면 기기 폭이 아닌 값을 읽을 수 있고
 * (2) PC 화면이 한 번 그려지면 브라우저가 화면을 축소해 `innerWidth` 가 부풀어 resize 에서도 그 값을 다시 읽었다.
 *
 * 실제 모듈을 `vi.resetModules()` 뒤 다시 불러와 싱글톤 생성 시점의 환경을 바꾼다.
 * 매 시험 뒤 `destroy()` 로 수신기를 걷는다(다시 불러올 때마다 수신기가 쌓이지 않도록).
 *
 * 배경: 내부 트러블슈팅 사례 「확장 코드의 화면 폭 판정 관련 이슈」 사례 2
 * @scenario-manifest tests/scenarios/responsive-viewport-background-return.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

type Manager = {
  getWidth(): number;
  matches(key: string): boolean;
  subscribe(cb: (w: number) => void): () => void;
  destroy(): void;
  _setWidthForTesting(w: number): void;
};

interface Env {
  iw: number;
  cw: number;
  css: number;
  visibility?: 'visible' | 'hidden';
}

const originalInnerWidth = Object.getOwnPropertyDescriptor(window, 'innerWidth');
const originalMatchMedia = window.matchMedia;

/** 지금 환경 값 — 게터가 이 객체를 읽어 시험 중간에도 바꿀 수 있다 */
const current: Env = { iw: 1024, cw: 1024, css: 1024, visibility: 'visible' };

/**
 * 질의의 min/max 를 읽어 current.css 와 비교하는 가짜 matchMedia.
 */
const matchMediaSpy = vi.fn((query: string) => {
  const min = /min-width:\s*([\d.]+)px/.exec(query);
  const max = /max-width:\s*([\d.]+)px/.exec(query);
  const okMin = min ? current.css >= Number(min[1]) : true;
  const okMax = max ? current.css <= Number(max[1]) : true;

  return { matches: okMin && okMax } as MediaQueryList;
});

/**
 * 창 환경을 바꾼다 (innerWidth · clientWidth · CSS 폭 · 보임 상태).
 *
 * @param env 바꿀 값
 */
function setEnv(env: Partial<Env>): void {
  Object.assign(current, env);
  Object.defineProperty(window, 'innerWidth', { configurable: true, get: () => current.iw });
  Object.defineProperty(document.documentElement, 'clientWidth', { configurable: true, get: () => current.cw });
  Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => current.visibility });
  (window as any).matchMedia = matchMediaSpy;
}

/**
 * 환경을 원래대로 되돌린다.
 */
function restoreEnv(): void {
  if (originalInnerWidth) {
    Object.defineProperty(window, 'innerWidth', originalInnerWidth);
  }
  delete (document.documentElement as any).clientWidth;
  delete (document as any).visibilityState;
  (window as any).matchMedia = originalMatchMedia;
}

/**
 * 지금 환경으로 실제 모듈을 새로 불러온다(싱글톤 생성 = 첫 측정).
 *
 * @return 새 싱글톤
 */
async function loadManager(): Promise<Manager> {
  vi.resetModules();
  const mod = await import('../ResponsiveManager');

  return mod.responsiveManager as unknown as Manager;
}

describe('ResponsiveManager — 가려진 로드·화면 축소 뒤 폭 바로잡기 (engine-v1.97.1)', () => {
  let manager: Manager | null = null;

  beforeEach(() => {
    vi.useFakeTimers();
    matchMediaSpy.mockClear();
    setEnv({ iw: 1024, cw: 1024, css: 1024, visibility: 'visible' });
  });

  afterEach(() => {
    manager?.destroy();
    manager = null;
    vi.useRealTimers();
    restoreEnv();
  });

  // @scenario case=background_return
  // @effects engine_width_uses_layout_width_when_zoomed_out
  it('M1 불러올 때 innerWidth 가 부풀어 있어도(980, 레이아웃 390) 엔진 폭은 390', async () => {
    setEnv({ iw: 980, cw: 390, css: 390 });
    manager = await loadManager();

    expect(manager.getWidth()).toBe(390);
    expect(manager.matches('mobile')).toBe(true);
  });

  // @scenario case=background_return
  // @effects engine_width_corrects_after_zoomed_out_resize
  it('M2 가려진 로드(980/980) 뒤 레이아웃 390·innerWidth 980 에서 resize 가 오면 390 으로 바로잡는다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980, visibility: 'hidden' });
    manager = await loadManager();
    expect(manager.getWidth()).toBe(980);

    // 앞으로 돌아왔지만 PC·태블릿 화면이 그려진 탓에 브라우저가 화면을 축소한 상태
    setEnv({ iw: 980, cw: 390, css: 390, visibility: 'visible' });
    window.dispatchEvent(new Event('resize'));
    vi.advanceTimersByTime(200);

    expect(manager.getWidth()).toBe(390);
    expect(manager.matches('portable')).toBe(true);
  });

  // @scenario case=background_return
  // @effects engine_width_remeasures_on_visible
  it('M3 resize 없이 visibilitychange(visible) 만으로 다시 잰다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980, visibility: 'hidden' });
    manager = await loadManager();

    setEnv({ iw: 390, cw: 390, css: 390, visibility: 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));

    expect(manager.getWidth()).toBe(390);
  });

  // @scenario case=background_return
  // @effects engine_width_remeasures_on_visible
  it('M4 visible 직후 크기 반영이 늦어도 250ms 후속 재측정으로 바로잡는다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980, visibility: 'hidden' });
    manager = await loadManager();

    setEnv({ visibility: 'visible' });
    document.dispatchEvent(new Event('visibilitychange'));
    expect(manager.getWidth()).toBe(980);

    // 안드로이드: visible 이 복원된 화면 크기보다 먼저 온다
    setEnv({ iw: 390, cw: 390, css: 390 });
    vi.advanceTimersByTime(200);
    expect(manager.getWidth()).toBe(980);

    vi.advanceTimersByTime(60);
    expect(manager.getWidth()).toBe(390);
  });

  // @scenario case=background_return
  // @effects engine_width_remeasures_on_pageshow
  it('M5 pageshow 에서 다시 잰다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980 });
    manager = await loadManager();

    setEnv({ iw: 390, cw: 390, css: 390 });
    window.dispatchEvent(new Event('pageshow'));

    expect(manager.getWidth()).toBe(390);
  });

  // @scenario case=background_return
  // @effects engine_width_skips_hidden_transition
  it('M6 hidden 으로 바뀔 때는 재지 않는다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980, visibility: 'visible' });
    manager = await loadManager();
    const cb = vi.fn();
    manager.subscribe(cb);
    cb.mockClear();

    setEnv({ iw: 390, cw: 390, css: 390, visibility: 'hidden' });
    document.dispatchEvent(new Event('visibilitychange'));
    vi.advanceTimersByTime(300);

    expect(manager.getWidth()).toBe(980);
    expect(cb).not.toHaveBeenCalled();
  });

  // @scenario case=background_return
  // @effects engine_width_notifies_only_on_change
  it('M7 폭이 같으면 resize 에도 다시 알리지 않는다 (같은 폭 resize 3회)', async () => {
    setEnv({ iw: 390, cw: 390, css: 390 });
    manager = await loadManager();
    const cb = vi.fn();
    manager.subscribe(cb);
    cb.mockClear();

    for (let i = 0; i < 3; i++) {
      window.dispatchEvent(new Event('resize'));
      vi.advanceTimersByTime(200);
    }

    expect(cb).not.toHaveBeenCalled();
    expect(manager.getWidth()).toBe(390);
  });

  // @scenario case=background_return
  // @effects engine_width_notifies_only_on_change
  it('M8 _setWidthForTesting 은 같은 값이어도 알린다', async () => {
    setEnv({ iw: 390, cw: 390, css: 390 });
    manager = await loadManager();
    const cb = vi.fn();
    manager.subscribe(cb);
    cb.mockClear();

    manager._setWidthForTesting(390);

    expect(cb).toHaveBeenCalledTimes(1);
    expect(cb).toHaveBeenCalledWith(390);
  });

  // @scenario case=background_return
  // @effects engine_width_listeners_released_on_destroy
  it('M9 destroy 뒤에는 resize·visibilitychange·pageshow 에 반응하지 않고 타이머도 남지 않는다', async () => {
    setEnv({ iw: 980, cw: 980, css: 980 });
    manager = await loadManager();

    // 대기 중인 디바운스·후속 재측정 타이머를 만든 뒤 destroy
    window.dispatchEvent(new Event('resize'));
    window.dispatchEvent(new Event('pageshow'));
    manager.destroy();
    expect(vi.getTimerCount()).toBe(0);

    setEnv({ iw: 390, cw: 390, css: 390 });
    window.dispatchEvent(new Event('resize'));
    document.dispatchEvent(new Event('visibilitychange'));
    window.dispatchEvent(new Event('pageshow'));
    vi.advanceTimersByTime(500);

    expect(manager.getWidth()).toBe(980);
    expect(vi.getTimerCount()).toBe(0);
  });
});
