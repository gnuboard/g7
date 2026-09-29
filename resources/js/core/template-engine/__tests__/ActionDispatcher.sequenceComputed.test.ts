/**
 * sequence 안 setState 뒤 computed 재계산 규칙 — 공개 이슈 140 (WP2)
 *
 * 수정 전 sequence 재계산은 결과를 `{}` 에서 새로 만들고 `{{}}` 문자열 정의만 평가했다. 그래서
 * `$switch` computed 와 `{{}}` 없는 리터럴 computed(마이페이지 `currentTab` 등)의 키가 setState
 * 직후 사라져 다음 액션에서 undefined 가 됐다. 공용 평가기(`evaluateComputedDefinitions`)로
 * 렌더 경로와 같은 규칙을 쓴다.
 *
 * @since engine-v1.74.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn(() => ({ login: vi.fn(), logout: vi.fn() })) },
}));
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('ActionDispatcher — sequence setState 뒤 computed 재계산', () => {
  let dispatcher: ActionDispatcher;
  let captured: any;

  beforeEach(() => {
    (window as any).G7Core = { state: { getLocal: vi.fn(() => ({})), get: vi.fn(() => ({})) } };
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    captured = null;
    dispatcher.registerHandler('captureComputed', async (_action: any, ctx: any) => {
      captured = { ...ctx.data?._computed };
      return { data: captured };
    });
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__g7SequenceLocalSync;
    vi.clearAllMocks();
  });

  const run = (definitions: Record<string, any>, computed: Record<string, any>, actions: any[]) =>
    dispatcher.executeAction(
      { handler: 'sequence' as const, actions } as any,
      {
        state: { tab: 'a', qty: 1 },
        data: { _local: { tab: 'a', qty: 1 }, _computed: computed, _computedDefinitions: definitions },
        setState: vi.fn(),
      } as any
    );

  /**

   * @scenario layer=action_sequence

   * @effects sequence_computed_keeps_switch_and_literal_keys

   */
  it('$switch computed 가 setState 직후에도 평가되어 남는다', async () => {
    await run(
      { label: { $switch: '{{_local.tab}}', $cases: { a: 'A', b: 'B' }, $default: 'Z' } },
      { label: 'A' },
      [{ handler: 'setState', params: { target: 'local', tab: 'b' } }, { handler: 'captureComputed' }]
    );
    expect(captured.label).toBe('B'); // 수정 전: undefined
  });

  /**

   * @scenario layer=action_sequence

   * @effects sequence_computed_keeps_switch_and_literal_keys

   */
  it('{{}} 없는 리터럴 computed 키가 setState 직후에도 남는다', async () => {
    await run(
      { currentTab: 'profile', total: '{{_local.qty * 10}}' },
      { currentTab: 'profile', total: 10 },
      [{ handler: 'setState', params: { target: 'local', qty: 3 } }, { handler: 'captureComputed' }]
    );
    expect(captured).toEqual({ currentTab: 'profile', total: 30 }); // 수정 전: currentTab 소실
  });

  /**

   * @scenario layer=action_sequence

   * @effects computed_later_key_reads_previous

   */
  it('정의 순서 참조는 최신 값, 뒤쪽 키 참조는 직전 값(undefined 아님)', async () => {
    await run(
      { total: '{{($computed.sub ?? -1) + 3000}}', sub: '{{_local.qty * 1000}}', twice: '{{$computed.sub * 2}}' },
      { total: 4000, sub: 1000, twice: 2000 },
      [{ handler: 'setState', params: { target: 'local', qty: 2 } }, { handler: 'captureComputed' }]
    );
    expect(captured).toEqual({ total: 4000, sub: 2000, twice: 4000 }); // 수정 전 total 은 2999(undefined → -1)
  });

  /**

   * @scenario layer=action_sequence

   * @effects sequence_computed_keeps_switch_and_literal_keys

   */
  it('커스텀 핸들러가 setLocal 로 바꾼 뒤 재계산도 같은 규칙이다', async () => {
    dispatcher.registerHandler('customSet', async () => {
      (window as any).__g7SequenceLocalSync = { tab: 'b', qty: 1 };
      return { data: null };
    });
    await run(
      { label: { $switch: '{{_local.tab}}', $cases: { a: 'A', b: 'B' } }, currentTab: 'profile' },
      { label: 'A', currentTab: 'profile' },
      [{ handler: 'customSet' }, { handler: 'captureComputed' }]
    );
    expect(captured).toEqual({ label: 'B', currentTab: 'profile' });
  });

  /**

   * @scenario layer=action_sequence

   * @effects computed_failure_policy_per_site

   */
  it('평가 실패 키는 직전 값을 유지하고 다른 키는 갱신된다(경고를 새로 만들지 않는다)', async () => {
    const warn = vi.spyOn(console, 'warn');
    await run(
      { bad: '{{[].reduce((a, b) => a)}}', ok: '{{_local.qty + 1}}' },
      { bad: 'old', ok: 2 },
      [{ handler: 'setState', params: { target: 'local', qty: 5 } }, { handler: 'captureComputed' }]
    );
    expect(captured).toEqual({ bad: 'old', ok: 6 });
    expect(warn.mock.calls.filter((c) => String(c.join(' ')).includes('computed'))).toEqual([]);
  });
});
