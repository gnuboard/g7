/**
 * deviceCorrection 테스트
 *
 * @description 서버 UA 판정(appConfig.isIos)이 놓친 iPadOS(데스크탑 UA)를 클라 신호로 보정하는 로직 검증.
 *
 * @effects client_corrects_ipados_desktop_ua, applepay_shown_on_ios
 */
// @scenario mark_form=badge, requires_ios=true, device=ipados_desktop_ua
// @scenario mark_form=svg, requires_ios=false, device=ipados_desktop_ua
// @scenario mark_form=none, requires_ios=false, device=ipados_desktop_ua

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { isIosMobileDeviceClient, correctIosDeviceState } from '../deviceCorrection';

const originalNavigator = globalThis.navigator;

function mockNavigator(overrides: Partial<Navigator> & Record<string, unknown>): void {
  Object.defineProperty(globalThis, 'navigator', {
    value: { userAgent: '', platform: '', maxTouchPoints: 0, ...overrides },
    configurable: true,
    writable: true,
  });
}

afterEach(() => {
  Object.defineProperty(globalThis, 'navigator', {
    value: originalNavigator,
    configurable: true,
    writable: true,
  });
  delete (window as any).G7Core;
});

describe('isIosMobileDeviceClient', () => {
  it('iPhone UA 를 iOS 로 판정한다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' });
    expect(isIosMobileDeviceClient()).toBe(true);
  });

  it('데스크탑 UA + maxTouchPoints>1 (iPadOS) 를 iOS 로 판정한다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17', maxTouchPoints: 5 });
    expect(isIosMobileDeviceClient()).toBe(true);
  });

  it('데스크탑 Mac (터치 없음) 은 iOS 가 아니다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17', maxTouchPoints: 0 });
    expect(isIosMobileDeviceClient()).toBe(false);
  });

  it('Android 는 iOS 가 아니다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8)', maxTouchPoints: 5 });
    expect(isIosMobileDeviceClient()).toBe(false);
  });
});

describe('correctIosDeviceState', () => {
  beforeEach(() => {
    const state: Record<string, any> = { appConfig: { isIos: false } };
    (window as any).G7Core = {
      state: {
        get: vi.fn(() => state),
        set: vi.fn((updates: Record<string, any>) => Object.assign(state, updates)),
      },
    };
  });

  it('클라가 iOS 로 판정하고 서버가 false 였으면 appConfig.isIos 를 true 로 보정한다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/17', maxTouchPoints: 5 });

    correctIosDeviceState();

    const setMock = (window as any).G7Core.state.set;
    expect(setMock).toHaveBeenCalledWith({ appConfig: { isIos: true } });
  });

  it('클라가 iOS 가 아니면 보정하지 않는다', () => {
    mockNavigator({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120', maxTouchPoints: 0 });

    correctIosDeviceState();

    expect((window as any).G7Core.state.set).not.toHaveBeenCalled();
  });

  it('서버가 이미 iOS 로 판정했으면 재보정하지 않는다 (다운그레이드 방지)', () => {
    const state: Record<string, any> = { appConfig: { isIos: true } };
    (window as any).G7Core.state.get = vi.fn(() => state);
    mockNavigator({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)' });

    correctIosDeviceState();

    expect((window as any).G7Core.state.set).not.toHaveBeenCalled();
  });
});
