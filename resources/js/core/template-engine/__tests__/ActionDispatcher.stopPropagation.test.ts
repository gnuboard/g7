/**
 * ActionDispatcher — 액션 키 `stopPropagation` 회귀 테스트
 *
 * 클릭 가능한 행 안에 토글·버튼을 두는 구조에서, 안쪽 컨트롤을 눌러도 바깥 행의 click 이 함께
 * 발화한다. 레이아웃은 예전부터 액션에 `stopPropagation: true` 를 적어 이를 막으려 했으나
 * 엔진에 소비처가 없어 **한 번도 동작한 적이 없었다** — 고시 목록의 활성 토글을 누르면 행 선택까지
 * 함께 일어나던 결함이 그 결과다.
 *
 * `handler: "stopPropagation"` 형태도 코퍼스에 있었으나 그런 핸들러는 등록된 적이 없어 무동작이었다.
 * 표면을 하나로 모으기 위해 키 형태만 지원한다.
 *
 * @since engine-v1.68.0
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({ login: vi.fn(), logout: vi.fn() })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher - stopPropagation', () => {
  let dispatcher: ActionDispatcher;

  beforeEach(() => {
    dispatcher = new ActionDispatcher();
  });

  /** 전파/기본동작 호출을 관측할 수 있는 최소 이벤트. */
  const makeEvent = (type: string) => {
    const event = new Event(type, { bubbles: true, cancelable: true });
    const stopPropagation = vi.fn();
    const preventDefault = vi.fn();

    Object.defineProperty(event, 'stopPropagation', { value: stopPropagation });
    Object.defineProperty(event, 'preventDefault', { value: preventDefault });

    return { event, stopPropagation, preventDefault };
  };

  it('stopPropagation: true 이면 상위로 전파를 막는다', async () => {
    const action: ActionDefinition = {
      type: 'change',
      handler: 'setState',
      stopPropagation: true,
      params: { target: 'local', touched: true },
    };

    const { event, stopPropagation } = makeEvent('change');
    await dispatcher.createHandler(action, {}, { state: {}, setState: vi.fn() })(event);

    expect(stopPropagation).toHaveBeenCalledTimes(1);
  });

  it('키가 없으면 전파를 막지 않는다 (기존 동작 보존)', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'setState',
      params: { target: 'local', touched: true },
    };

    const { event, stopPropagation } = makeEvent('click');
    await dispatcher.createHandler(action, {}, { state: {}, setState: vi.fn() })(event);

    expect(stopPropagation).not.toHaveBeenCalled();
  });

  it('stopPropagation: false 는 막지 않는다 (true 만 opt-in)', async () => {
    const action: ActionDefinition = {
      type: 'click',
      handler: 'setState',
      stopPropagation: false,
      params: { target: 'local', touched: true },
    };

    const { event, stopPropagation } = makeEvent('click');
    await dispatcher.createHandler(action, {}, { state: {}, setState: vi.fn() })(event);

    expect(stopPropagation).not.toHaveBeenCalled();
  });

  it('change 이벤트의 preventDefault 억제는 그대로다 — 전파 차단이 그 규칙을 건드리지 않는다', async () => {
    const action: ActionDefinition = {
      type: 'change',
      handler: 'setState',
      stopPropagation: true,
      params: { target: 'local', touched: true },
    };

    const { event, preventDefault, stopPropagation } = makeEvent('change');
    await dispatcher.createHandler(action, {}, { state: {}, setState: vi.fn() })(event);

    // 체크박스/토글의 change 에서 preventDefault 를 부르면 네이티브 상태 변경이 취소되어
    // React 상태와 어긋난다 — 전파만 막고 기본동작은 살려야 한다.
    expect(preventDefault).not.toHaveBeenCalled();
    expect(stopPropagation).toHaveBeenCalledTimes(1);
  });
});
