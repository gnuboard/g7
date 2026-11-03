/** 예약 경계·탭 복귀·중복 요청·해제 회귀. @vitest-environment jsdom */
import React from 'react';
import { act, cleanup, render } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PopupLayer } from '../../components/composite/PopupLayer';
import { installCore } from './core';

const refetch = vi.fn();
const timing = { serverTime: '2026-12-01T00:00:00Z', refreshAt: '2026-12-01T00:00:10Z' };
const layer = (props: Record<string, unknown> = {}) => React.createElement(PopupLayer, { popups: [], ...timing, ...props } as any);

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-12-01T09:00:00Z')); // 방문자 시계 차이에도 서버 기준 상대 타이머
  refetch.mockReset().mockResolvedValue(undefined);
  installCore({ dataSource: { refetch } });
  Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
});
afterEach(() => { cleanup(); vi.useRealTimers(); });

describe('[case:backend-popup-open-page-refresh] 공개 팝업 예약 갱신', () => {
  // @scenario surface=refresh_boundary
  // @effects refresh_keeps_dismissal
  it('0건이어도 다음 예약 경계에서 한 번 조회하고 polling하지 않는다', async () => {
    render(layer());
    await act(async () => { await vi.advanceTimersByTimeAsync(11000); });
    expect(refetch).toHaveBeenCalledTimes(1);
    expect(refetch).toHaveBeenCalledWith('sirsoftPopup_public', { skipCache: true });
    await act(async () => { await vi.advanceTimersByTimeAsync(60000); });
    expect(refetch).toHaveBeenCalledTimes(1);
  });
  // @scenario surface=refresh_return
  // @effects refresh_keeps_dismissal
  it('탭 복귀 연속 이벤트와 진행 중 요청을 합친다', async () => {
    let done!: () => void;
    refetch.mockImplementation(() => new Promise<void>(r => { done = r; }));
    render(layer({ refreshAt: null }));
    await act(async () => {
      document.dispatchEvent(new Event('visibilitychange'));
      document.dispatchEvent(new Event('visibilitychange'));
      window.dispatchEvent(new Event('pageshow'));
    });
    expect(refetch).toHaveBeenCalledTimes(1);
    await act(async () => { done(); });
  });
  // @scenario surface=refresh_hidden
  // @effects refresh_keeps_dismissal
  it('숨긴 탭은 경계에서 조회하지 않고 돌아올 때 한 번 조회한다', async () => {
    render(layer());
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'hidden' });
    act(() => document.dispatchEvent(new Event('visibilitychange')));
    await act(async () => { await vi.advanceTimersByTimeAsync(15000); });
    expect(refetch).not.toHaveBeenCalled();
    Object.defineProperty(document, 'visibilityState', { configurable: true, value: 'visible' });
    await act(async () => document.dispatchEvent(new Event('visibilitychange')));
    expect(refetch).toHaveBeenCalledTimes(1);
  });
  // @scenario surface=refresh_cleanup
  // @effects refresh_keeps_dismissal
  it('언마운트하면 타이머·리스너가 사라지고 관리자 미리보기는 조회하지 않는다', async () => {
    const mounted = render(layer()); mounted.unmount();
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); await vi.advanceTimersByTimeAsync(20000); });
    expect(refetch).not.toHaveBeenCalled();
    render(layer({ preview: 'pc' }));
    await act(async () => { document.dispatchEvent(new Event('visibilitychange')); await vi.advanceTimersByTimeAsync(20000); });
    expect(refetch).not.toHaveBeenCalled();
  });
});
