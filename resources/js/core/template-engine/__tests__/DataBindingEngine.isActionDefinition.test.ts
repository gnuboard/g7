/**
 * DataBindingEngine.isActionDefinition — 분기·참조 모양 액션 판별 (engine-v1.80.0, #753)
 *
 * 콜백 prop 에 담긴 액션 정의는 렌더 시점에 `{{}}` 를 미리 평가하면 안 된다(실행 시점의 최신 상태를 봐야 한다).
 * 판별식이 `handler` + (`params`·`actions`·`target`·`onSuccess`·`onError`) 만 액션으로 봐서,
 * `{handler:'conditions', conditions}`·`{handler:'switch', cases}`·`{actionRef}`·`{handler, if}` 는
 * 일반 객체로 취급돼 렌더 순간 값으로 굳었다.
 *
 * @since engine-v1.80.0
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '../DataBindingEngine';

describe('DataBindingEngine.isActionDefinition (#753)', () => {
  const engine = new DataBindingEngine();

  /**
   * @scenario slot=callback_prop, shape=conditions
   * @effects listen_runs_full_action
   */
  it('conditions 모양을 액션으로 본다', () => {
    expect(engine.isActionDefinition({ handler: 'conditions', conditions: [{ if: '{{x}}', then: { handler: 'toast' } }] })).toBe(true);
  });

  /**
   * @scenario slot=callback_prop, shape=switch
   * @effects listen_runs_full_action
   */
  it('switch(cases) 모양을 액션으로 본다', () => {
    expect(engine.isActionDefinition({ handler: 'switch', cases: { a: { handler: 'toast' } } })).toBe(true);
  });

  /**
   * @scenario slot=callback_prop, shape=action_ref
   * @effects listen_runs_full_action
   */
  it('actionRef 만 있는 모양을 액션으로 본다', () => {
    expect(engine.isActionDefinition({ actionRef: 'saveForm' })).toBe(true);
  });

  /**
   * @scenario slot=callback_prop, shape=if
   * @effects listen_runs_full_action
   */
  it('handler 와 if 만 있는 모양을 액션으로 본다', () => {
    expect(engine.isActionDefinition({ handler: 'closeModal', if: '{{_local.ready}}' })).toBe(true);
  });

  /**
   * @scenario slot=callback_prop, shape=conditions
   * @effects listen_runs_full_action
   */
  it('콜백 prop 의 conditions 액션은 렌더 시점에 {{}} 를 평가하지 않는다', () => {
    const def = { handler: 'conditions', conditions: [{ if: '{{_local.flag}}', then: { handler: 'setState', params: { v: '{{_local.v}}' } } }] };

    const resolved = engine.resolveObject({ onPick: def }, { _local: { flag: true, v: 'render-time' } });

    expect(resolved.onPick.conditions[0].if).toBe('{{_local.flag}}');
    expect(resolved.onPick.conditions[0].then.params.v).toBe('{{_local.v}}');
  });

  /**
   * @scenario slot=callback_prop, shape=single
   * @effects listen_runs_full_action
   */
  it('일반 객체는 액션이 아니다 (handler 가 문자열이 아니거나 액션 키가 없음)', () => {
    expect(engine.isActionDefinition({ handler: 1 } as any)).toBe(false);
    expect(engine.isActionDefinition({ handler: 'x', label: 'y' })).toBe(false);
    expect(engine.isActionDefinition({ actionRef: 3 } as any)).toBe(false);
    expect(engine.isActionDefinition({ conditions: [] })).toBe(false);
    expect(engine.isActionDefinition({ handler: 'x', cases: 'nope' } as any)).toBe(false);
  });
});
