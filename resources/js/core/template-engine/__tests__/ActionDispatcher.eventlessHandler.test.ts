/**
 * ActionDispatcher — 이벤트 없는 호출 (engine-v1.77.1, #736 S2)
 *
 * 1. 컴포넌트가 콜백을 이벤트 없이 부를 때(`onClose()`) — `createHandler` 가 돌려준 핸들러가 `event.type`·
 *    `preventDefault()` 를 읽다 TypeError 로 거부돼 액션이 실행되지 않았다. 관리자 모달 Escape 가 창을 닫지 못하고
 *    콘솔에 오류가 찍혔다(2025-12-19 부터). 이제 액션 type 의 대체 이벤트로 실행한다.
 *
 * @since engine-v1.77.1
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher — 이벤트 없이 불린 핸들러', () => {
  let dispatcher: ActionDispatcher;
  let calls: number;

  beforeEach(() => {
    vi.useFakeTimers();
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    calls = 0;
    dispatcher.registerHandler('countHandler', () => {
      calls++;
    });
  });

  afterEach(() => {
    dispatcher.clearDebounceTimers();
    vi.useRealTimers();
  });

  /**
   * @scenario caller=component_callback, event=none
   * @effects eventless_call_runs_action
   */
  it('이벤트 없이 불린 핸들러도 액션을 실행하고 거부하지 않는다 (모달 Escape → onClose())', async () => {
    const handler = dispatcher.createHandler({ type: 'click', handler: 'countHandler' } as any, {});

    await expect((handler as any)()).resolves.toBeUndefined();
    expect(calls).toBe(1);
  });

  /**
   * @scenario caller=component_callback, event=none
   * @effects eventless_call_runs_action
   */
  it('이벤트 없는 호출의 대체 이벤트는 액션 type 을 따른다', async () => {
    let seenType: string | undefined;
    dispatcher.registerHandler('typeProbe', (_action: any, context: any) => {
      seenType = context?.event?.type;
    });
    const handler = dispatcher.createHandler({ type: 'click', handler: 'typeProbe' } as any, {});

    await (handler as any)();
    expect(seenType).toBe('click');
  });
});
