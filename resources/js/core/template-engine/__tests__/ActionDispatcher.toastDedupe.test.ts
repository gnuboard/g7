/**
 * ActionDispatcher — 같은 토스트 겹쳐 쌓기 방지 (engine-v1.78.5, 7.1.0 영역 J D-J10 / 관측 O-D3)
 *
 * 권한이 좁은 운영자가 대시보드를 열면 화면 공통 데이터소스(알림·위젯 등) 13개가 403 으로 실패하고,
 * 시스템 기본 403 처리(토스트 `{{error.message}}`)가 같은 「해당 권한이 없습니다.」 를 13장 쌓았다.
 * 같은 종류·같은 문구의 토스트가 잠깐(3초) 안에 이미 떠 있으면 새로 쌓지 않는다. 문구가 다르거나
 * 시간이 지나면 종전대로 쌓인다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';

describe('ActionDispatcher — 같은 토스트 겹쳐 쌓기 방지', () => {
  let dispatcher: ActionDispatcher;
  let toasts: any[];

  beforeEach(() => {
    dispatcher = new ActionDispatcher();
    toasts = [];
    dispatcher.setGlobalStateUpdater((updates: any) => {
      if (updates.toasts) toasts = updates.toasts;
    });
    (window as any).G7Core = { state: { get: () => ({ toasts }) } };
  });

  afterEach(() => {
    delete (window as any).G7Core;
    vi.useRealTimers();
  });

  const toast = (message: string, type = 'error') =>
    dispatcher.dispatchAction({ handler: 'toast', params: { type, message } } as any, { data: {} } as any);

  /**
   * @scenario case=toast_same_burst
   * @effects identical_toast_not_stacked
   */
  it('같은 문구의 오류 토스트가 한꺼번에 13번 와도 1장만 쌓인다', async () => {
    for (let i = 0; i < 13; i++) {
      await toast('해당 권한이 없습니다.');
    }
    expect(toasts).toHaveLength(1);
  });

  /**
   * @scenario case=toast_different
   * @effects identical_toast_not_stacked
   */
  it('문구나 종류가 다르면 각각 쌓인다', async () => {
    await toast('해당 권한이 없습니다.');
    await toast('저장했습니다.', 'success');
    await toast('다른 오류');
    expect(toasts).toHaveLength(3);
  });

  /**
   * @scenario case=toast_after_window
   * @effects identical_toast_not_stacked
   */
  it('같은 문구라도 3초가 지나면 다시 쌓인다(사용자가 다시 누른 저장 실패 등)', async () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-06T00:00:00Z'));
    await toast('해당 권한이 없습니다.');
    vi.setSystemTime(new Date('2026-10-06T00:00:03.500Z'));
    await toast('해당 권한이 없습니다.');
    expect(toasts).toHaveLength(2);
  });
});
