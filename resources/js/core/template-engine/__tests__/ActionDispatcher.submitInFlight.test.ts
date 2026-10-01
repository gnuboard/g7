/**
 * ActionDispatcher — 폼 제출 진행 중 재제출 차단 테스트
 *
 * 버그 시나리오(#708 실측): 역할 폼 저장 버튼을 더블클릭하면 PUT 이 2건 나갔다.
 * 버튼의 `disabled="{{_global.isSaving}}"` 는 첫 제출의 상태 변경이 렌더된 뒤에야 걸려,
 * 같은 틱의 두 번째 submit 을 막지 못한다.
 *
 * 시나리오 SSoT: tests/scenarios/form-submit-in-flight-guard.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1, name: 'Test User' }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

/** 실제 form 요소를 target 으로 하는 submit 이벤트 */
const submitEventOf = (form: HTMLFormElement) => ({
  type: 'submit',
  target: form,
  currentTarget: form,
  preventDefault: vi.fn(),
  stopPropagation: vi.fn(),
});

/** 바깥에서 끝낼 수 있는 대기 */
function deferred() {
  let resolve!: () => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<void>((res, rej) => {
    resolve = res;
    reject = rej;
  });

  return { promise, resolve, reject };
}

describe('ActionDispatcher — 폼 제출 진행 중 재제출 차단', () => {
  let dispatcher: ActionDispatcher;
  let form: HTMLFormElement;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    form = document.createElement('form');
    document.body.appendChild(form);
  });

  afterEach(() => {
    form.remove();
  });

  // @scenario submit_timing=during_previous, actions_per_event=single
  // @effects second_submit_during_flight_is_ignored, ignored_submit_still_prevents_default
  it('첫 제출이 끝나기 전의 두 번째 submit 은 실행하지 않고 기본 동작만 막는다', async () => {
    const gate = deferred();
    const save = vi.fn(() => gate.promise);
    dispatcher.registerHandler('saveForm', save);
    const props = dispatcher.bindActionsToProps({ actions: [{ type: 'submit', handler: 'saveForm' }] } as any, {});

    const first = submitEventOf(form);
    const second = submitEventOf(form);
    props.onSubmit(first);
    props.onSubmit(second);
    await Promise.resolve();

    expect(save).toHaveBeenCalledTimes(1);
    expect(second.preventDefault).toHaveBeenCalled();

    gate.resolve();
    await gate.promise;
  });

  // @scenario submit_timing=during_previous, actions_per_event=multiple
  // @effects actions_of_same_submit_event_all_run
  it('한 submit 이벤트에 묶인 액션들은 모두 실행된다', async () => {
    const gate = deferred();
    const first = vi.fn(() => gate.promise);
    const second = vi.fn();
    dispatcher.registerHandler('firstAction', first);
    dispatcher.registerHandler('secondAction', second);
    const props = dispatcher.bindActionsToProps({
      actions: [
        { type: 'submit', handler: 'firstAction' },
        { type: 'submit', handler: 'secondAction' },
      ],
    } as any, {});

    props.onSubmit(submitEventOf(form));
    await vi.waitFor(() => expect(second).toHaveBeenCalledTimes(1));
    expect(first).toHaveBeenCalledTimes(1);

    gate.resolve();
  });

  // @scenario submit_timing=after_previous, actions_per_event=single
  // @effects lock_releases_after_success
  it('첫 제출이 끝나면 다음 제출은 다시 실행된다', async () => {
    const save = vi.fn().mockResolvedValue(undefined);
    dispatcher.registerHandler('saveForm', save);
    const props = dispatcher.bindActionsToProps({ actions: [{ type: 'submit', handler: 'saveForm' }] } as any, {});

    await props.onSubmit(submitEventOf(form));
    await vi.waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    await new Promise((r) => setTimeout(r, 0));
    props.onSubmit(submitEventOf(form));

    await vi.waitFor(() => expect(save).toHaveBeenCalledTimes(2));
  });

  // @scenario submit_timing=after_previous, actions_per_event=single
  // @effects lock_releases_after_failure
  it('첫 제출이 실패해도 잠금은 풀린다', async () => {
    const save = vi.fn().mockRejectedValueOnce(new Error('network')).mockResolvedValue(undefined);
    dispatcher.registerHandler('saveForm', save);
    const props = dispatcher.bindActionsToProps({ actions: [{ type: 'submit', handler: 'saveForm' }] } as any, {});

    props.onSubmit(submitEventOf(form));
    await vi.waitFor(() => expect(save).toHaveBeenCalledTimes(1));
    await new Promise((r) => setTimeout(r, 0));
    props.onSubmit(submitEventOf(form));

    await vi.waitFor(() => expect(save).toHaveBeenCalledTimes(2));
  });

  // @scenario submit_timing=during_previous, actions_per_event=single
  // @effects other_forms_are_not_blocked
  it('다른 폼의 제출은 막지 않는다', async () => {
    const gate = deferred();
    const save = vi.fn(() => gate.promise);
    dispatcher.registerHandler('saveForm', save);
    const props = dispatcher.bindActionsToProps({ actions: [{ type: 'submit', handler: 'saveForm' }] } as any, {});
    const other = document.createElement('form');

    props.onSubmit(submitEventOf(form));
    props.onSubmit(submitEventOf(other));
    await vi.waitFor(() => expect(save).toHaveBeenCalledTimes(2));

    gate.resolve();
  });
});
