/**
 * startInterval / stopInterval 핸들러 테스트.
 *
 * engine-v1.45.0 추가 — 카운트다운 타이머 등 주기적 UI 업데이트 지원.
 * S4 본인인증 화면(identity_challenge.json) 이 남은 시간·재전송 쿨다운
 * tick 에 사용하는 기반 API.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';

describe('ActionDispatcher — startInterval / stopInterval', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();
    mockNavigate = vi.fn();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
  });

  afterEach(() => {
    dispatcher.stopAllIntervals();
    vi.useRealTimers();
  });

  it('invokes actions periodically at the specified interval', async () => {
    const boundProps = dispatcher.bindActionsToProps({
      actions: [
        {
          type: 'click',
          handler: 'startInterval',
          params: {
            id: 'test_tick',
            intervalMs: 1000,
            actions: [{ handler: 'navigate', params: { path: '/tick' } }],
          },
        },
      ],
    });

    await boundProps.onClick?.({ preventDefault: vi.fn() } as any);

    // Initially 0 invocations (interval not fired yet)
    expect(mockNavigate).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1000);
    expect(mockNavigate).toHaveBeenCalledTimes(1);

    vi.advanceTimersByTime(2000);
    expect(mockNavigate).toHaveBeenCalledTimes(3);
  });

  it('stopInterval halts the timer by id', async () => {
    const start = dispatcher.bindActionsToProps({
      actions: [
        {
          type: 'click',
          handler: 'startInterval',
          params: {
            id: 'halt_me',
            intervalMs: 500,
            actions: [{ handler: 'navigate', params: { path: '/x' } }],
          },
        },
      ],
    });
    await start.onClick?.({ preventDefault: vi.fn() } as any);

    vi.advanceTimersByTime(1500);
    expect(mockNavigate).toHaveBeenCalledTimes(3);

    const stop = dispatcher.bindActionsToProps({
      actions: [
        {
          type: 'click',
          handler: 'stopInterval',
          params: { id: 'halt_me' },
        },
      ],
    });
    await stop.onClick?.({ preventDefault: vi.fn() } as any);

    vi.advanceTimersByTime(5000);
    expect(mockNavigate).toHaveBeenCalledTimes(3); // no further ticks after stop
  });

  it('replaces existing timer when same id is registered twice (idempotent)', async () => {
    const run = async (intervalMs: number) => {
      const props = dispatcher.bindActionsToProps({
        actions: [
          {
            type: 'click',
            handler: 'startInterval',
            params: {
              id: 'duplicate',
              intervalMs,
              actions: [{ handler: 'navigate', params: { path: '/x' } }],
            },
          },
        ],
      });
      await props.onClick?.({ preventDefault: vi.fn() } as any);
    };

    await run(1000);
    vi.advanceTimersByTime(500);
    expect(mockNavigate).toHaveBeenCalledTimes(0);

    // Re-register with shorter interval — old timer should be cleared
    await run(200);

    vi.advanceTimersByTime(200);
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    vi.advanceTimersByTime(400);
    expect(mockNavigate).toHaveBeenCalledTimes(3);
  });

  it('stopAllIntervals clears every registered timer', async () => {
    for (const id of ['a', 'b', 'c']) {
      const props = dispatcher.bindActionsToProps({
        actions: [
          {
            type: 'click',
            handler: 'startInterval',
            params: {
              id,
              intervalMs: 100,
              actions: [{ handler: 'navigate', params: { path: '/x' } }],
            },
          },
        ],
      });
      await props.onClick?.({ preventDefault: vi.fn() } as any);
    }

    vi.advanceTimersByTime(100);
    expect(mockNavigate).toHaveBeenCalledTimes(3); // 1 per id

    dispatcher.stopAllIntervals();

    vi.advanceTimersByTime(1000);
    expect(mockNavigate).toHaveBeenCalledTimes(3); // no further ticks
  });

  it('rejects startInterval with missing id or non-positive intervalMs', async () => {
    const cases = [
      { id: '', intervalMs: 1000 },
      { id: 'bad_ms', intervalMs: 0 },
      { id: 'bad_ms_2', intervalMs: -100 },
    ];

    for (const params of cases) {
      const props = dispatcher.bindActionsToProps({
        actions: [
          {
            type: 'click',
            handler: 'startInterval',
            params: {
              ...params,
              actions: [{ handler: 'navigate', params: { path: '/x' } }],
            },
          },
        ],
      });
      await props.onClick?.({ preventDefault: vi.fn() } as any);
    }

    vi.advanceTimersByTime(5000);
    expect(mockNavigate).not.toHaveBeenCalled();
  });
});
