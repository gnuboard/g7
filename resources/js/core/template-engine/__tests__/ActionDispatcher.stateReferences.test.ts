/**
 * @file ActionDispatcher.stateReferences.test.ts
 * @description ActionDispatcher 가 상태를 읽는 자리의 잘못된 참조 (7.1.3)
 *
 * - E10 모달을 연 뒤 부모 `_global` 이 바뀌어도 모달의 `$parent._global`·`$parent._computed` 가 연 시점 사본이었다
 *   (게시판 유형 관리 모달 `$parent._computed.canManageBoardTypes` 등 번들 7곳).
 * - E11 remount 가 `G7Core.state.get()._global._remountKeys` 를 읽었다 — `state.get()` 은 `_global` 그 자체라 늘 undefined 여서
 *   카운터가 늘 1 이었고, 두 번째 remount 부터 다시 마운트되지 않았다.
 * - E13 객체로 쓴 `params.callbackAction` 의 `{{$event…}}` 가 디스패치 때 미리 풀려(undefined) 콜백 시점 값을 못 받았다.
 *
 * @scenario-manifest tests/scenarios/engine-binding-cache-error-state.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { DataBindingEngine } from '../DataBindingEngine';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn().mockResolvedValue({ id: 1 }),
      logout: vi.fn().mockResolvedValue(undefined),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher 상태 참조 (7.1.3)', () => {
  let dispatcher: ActionDispatcher;
  let globalState: Record<string, any>;

  beforeEach(() => {
    globalState = {};
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    // 엔진(TemplateApp.setGlobalState)처럼 바꿀 때마다 새 객체로 재대입하고, G7Core.state.get() 은 그 얕은 사본(_global 내용물)을 돌려준다
    dispatcher.setGlobalStateUpdater(vi.fn((updates) => {
      globalState = { ...globalState, ...updates };
    }));
    (window as any).G7Core = { state: { get: () => ({ ...globalState }), getLocal: () => ({}) } };
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__g7LayoutContextStack;
    vi.clearAllMocks();
  });

  /**
   * @scenario path=modal_parent_context
   * @effects modal_parent_global_and_computed_follow_parent
   */
  it('E10. 모달을 연 뒤 부모 _global 이 바뀌면 $parent._global·$parent._computed 가 새 값이다', async () => {
    globalState = { perm: false, modalStack: [] };
    const definitions = { canManage: '{{_global.perm === true}}', label: "{{_local.name ?? 'none'}}" };

    await dispatcher.executeAction({ handler: 'openModal', target: 'type_modal' } as any, {
      state: { name: 'board' },
      setState: vi.fn(),
      data: {
        _global: { ...globalState },
        _local: { name: 'board' },
        _computedDefinitions: definitions,
        _computed: { canManage: false, label: 'board' },
      },
    } as any);

    const entry = (window as any).__g7LayoutContextStack.at(-1);
    expect(entry.dataContext._computed.canManage).toBe(false);

    // 모달 안 조작이 부모 전역 상태를 바꾼다(권한 로드 완료 등)
    globalState = { ...globalState, perm: true };

    const engine = new DataBindingEngine();
    engine.startRenderCycle();
    const modalContext = { $parent: entry.dataContext };
    expect(engine.resolve('$parent._global.perm', modalContext)).toBe(true); // 결함: false(연 시점 사본)
    expect(engine.resolve('$parent._computed.canManage', modalContext)).toBe(true); // 결함: false
    expect(engine.resolve('$parent._computed.label', modalContext)).toBe('board');
  });

  /**
   * @scenario path=modal_parent_context
   * @effects modal_parent_global_and_computed_follow_parent
   */
  it('E10. computed 정의가 없으면 $parent._computed 는 연 시점 값 그대로다(종전 동작)', async () => {
    globalState = { modalStack: [] };
    await dispatcher.executeAction({ handler: 'openModal', target: 'm' } as any, {
      state: {}, setState: vi.fn(), data: { _global: {}, _computed: { a: 1 } },
    } as any);
    const entry = (window as any).__g7LayoutContextStack.at(-1);
    expect(entry.dataContext._computed).toEqual({ a: 1 });
  });

  /**
   * @scenario path=remount
   * @effects remount_key_increments_each_time
   */
  it('E11. 같은 컴포넌트를 두 번 remount 하면 key 가 1 → 2 로 는다', async () => {
    await dispatcher.executeAction({ handler: 'remount', params: { componentId: 'editor' } } as any, { data: {} } as any);
    expect(globalState._remountKeys).toEqual({ editor: 1 });

    await dispatcher.executeAction({ handler: 'remount', params: { componentId: 'editor' } } as any, { data: {} } as any);
    expect(globalState._remountKeys).toEqual({ editor: 2 }); // 결함: { editor: 1 } — 두 번째부터 다시 마운트되지 않음
  });

  /**
   * @scenario path=callback_action
   * @effects callback_action_object_resolved_at_callback_time
   */
  it('E13. 객체로 쓴 callbackAction 은 디스패치 때 풀지 않고 원문으로 전달한다', () => {
    const resolved = (dispatcher as any).resolveParams({
      constructor: 'daum.Postcode',
      label: '{{title}}',
      callbackAction: { handler: 'setState', params: { target: 'local', zip: '{{$event.zonecode}}' } },
    }, { title: 'T' });

    expect(resolved.label).toBe('T');
    expect(resolved.callbackAction.params.zip).toBe('{{$event.zonecode}}'); // 결함: undefined(미리 풂)
  });

  /**
   * @scenario path=callback_action
   * @effects callback_action_object_resolved_at_callback_time
   */
  it('E13. 문자열 식 callbackAction(확장 지점 콜백)은 종전처럼 풀어 액션 정의를 넘긴다', () => {
    const action = { handler: 'setState', params: { target: 'local', zip: '{{$event.zonecode}}' } };
    const resolved = (dispatcher as any).resolveParams({
      callbackAction: '{{extensionPointCallbacks.onAddressSelect}}',
    }, { extensionPointCallbacks: { onAddressSelect: action } });

    expect(resolved.callbackAction).toEqual(action);
  });
});
