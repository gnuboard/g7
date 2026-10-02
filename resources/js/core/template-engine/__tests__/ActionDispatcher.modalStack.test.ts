/**
 * ActionDispatcher — 같은 동작 순서 안의 창 닫기/열기가 최신 창 스택을 읽는다 (engine-v1.75.2)
 *
 * `handleSequence()` 는 단계마다 `data` 를 `...context.data` 로 다시 만들어 `_global` 이 sequence 시작 시점의
 * 사본이다. `handleCloseModal()`·`handleOpenModal()` 이 그 사본의 `modalStack` 을 읽으면, 같은 sequence 안의
 * closeModal → openModal 이 방금 닫은 창을 되살린다 — 닫기는 `[]` 를 쓰고 열기는 낡은 `[A]` 에 쌓아 `[A, B]` 를 쓴다
 * (#712 실측: 관리자 확장 목록의 강제 활성 창 → 「언어팩 다시 켜기」 창).
 *
 * 트러블슈팅 `[case:state-setstate-44]` 의 회귀 그물이다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
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

describe('ActionDispatcher 창 스택 — sequence 안의 closeModal/openModal', () => {
  let dispatcher: ActionDispatcher;
  let globalState: Record<string, any>;

  beforeEach(() => {
    globalState = {};
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    // 엔진처럼 globalStateUpdater 직후 G7Core.state.get() 이 최신 `_global` 내용물을 돌려주게 둔다
    dispatcher.setGlobalStateUpdater(vi.fn((updates) => {
      globalState = { ...globalState, ...updates };
    }));
    (window as any).G7Core = { state: { get: () => globalState, getLocal: () => ({}) } };
    Logger.getInstance().setDebug(false);
  });

  afterEach(() => {
    delete (window as any).G7Core;
    delete (window as any).__g7LayoutContextStack;
    vi.clearAllMocks();
  });

  /**
   * 강제 활성 창의 성공 분기처럼 닫고 바로 다른 창을 열면, 새 창만 남는다.
   *
   * @scenario case=close_then_open_stale
   * @effects close_then_open_keeps_only_new_modal
   */
  it('closeModal 직후 openModal 은 최신 창 목록에 쌓아 닫힌 창을 되살리지 않는다', async () => {
    globalState = { modalStack: ['force_modal'], activeModal: 'force_modal' };

    await dispatcher.executeAction({
      handler: 'sequence',
      actions: [
        { handler: 'closeModal' },
        { handler: 'openModal', target: 'lp_modal' },
      ],
    } as any, {
      state: {},
      setState: vi.fn(),
      // sequence 시작 시점의 사본 — 이후 단계에서 갱신되지 않는다
      data: { _global: { modalStack: ['force_modal'], activeModal: 'force_modal' } },
    } as any);

    expect(globalState.modalStack).toEqual(['lp_modal']);
    expect(globalState.activeModal).toBe('lp_modal');
  });

  /**
   * 같은 sequence 안에서 연 창을 바로 닫으면 그 창이 닫힌다 — 낡은 사본의 맨 위(처음 창)를 빼지 않는다.
   *
   * @scenario case=open_then_close_stale
   * @effects open_then_close_removes_the_opened_modal
   */
  it('openModal 직후 closeModal 은 방금 연 창을 닫는다', async () => {
    globalState = { modalStack: ['base_modal'], activeModal: 'base_modal' };

    await dispatcher.executeAction({
      handler: 'sequence',
      actions: [
        { handler: 'openModal', target: 'child_modal' },
        { handler: 'closeModal' },
      ],
    } as any, {
      state: {},
      setState: vi.fn(),
      data: { _global: { modalStack: ['base_modal'], activeModal: 'base_modal' } },
    } as any);

    expect(globalState.modalStack).toEqual(['base_modal']);
    expect(globalState.activeModal).toBe('base_modal');
  });

  /**
   * 문맥에 전역 사본이 없는 호출(액션 문맥 없이 `G7Core.dispatch()` 를 부른 확장 JS — 본인인증 launcher·플러그인 닫기)은
   * 이번 수정 범위 밖이라 종전대로 빈 스택에서 시작한다. 이 경로의 의미를 바꾸려면 의식적으로 이 테스트를 고쳐야 한다.
   *
   * @scenario case=single_open_no_snapshot
   * @effects no_snapshot_context_keeps_legacy_empty_stack_start
   */
  it('전역 사본이 없는 문맥의 openModal 은 종전대로 빈 스택에서 시작한다', async () => {
    globalState = { modalStack: ['parent_modal'], activeModal: 'parent_modal' };

    await dispatcher.executeAction(
      { handler: 'openModal', target: 'identity-challenge-modal' } as any,
      { state: {}, setState: vi.fn(), data: { sidebarOpen: true } } as any,
    );

    expect(globalState.modalStack).toEqual(['identity-challenge-modal']);
  });

  /**
   * 단일 액션(사본이 최신값과 같은 경우)은 종전과 같은 결과다 — 일반 화면의 창 열기/닫기 회귀 그물.
   *
   * @scenario case=single_open_close_fresh
   * @effects single_open_close_unchanged
   */
  it('단일 openModal·closeModal 은 종전과 같이 쌓고 뺀다', async () => {
    globalState = { modalStack: ['a'], activeModal: 'a' };
    const ctx = () => ({ state: {}, setState: vi.fn(), data: { _global: { ...globalState } } });

    await dispatcher.executeAction({ handler: 'openModal', target: 'b' } as any, ctx() as any);
    expect(globalState.modalStack).toEqual(['a', 'b']);

    await dispatcher.executeAction({ handler: 'openModal', target: 'b' } as any, ctx() as any);
    expect(globalState.modalStack).toEqual(['a', 'b']);

    await dispatcher.executeAction({ handler: 'closeModal' } as any, ctx() as any);
    expect(globalState.modalStack).toEqual(['a']);
    expect(globalState.activeModal).toBe('a');
  });
});
