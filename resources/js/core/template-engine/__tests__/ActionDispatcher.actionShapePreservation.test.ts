/**
 * ActionDispatcher — 액션 정의를 옮겨 담을 때 키를 버리지 않는다 (engine-v1.80.0, #753)
 *
 * 레이아웃이 적은 액션 정의를 엔진이 다른 자리(오류 처리기·데이터소스 오류 처리·navigate 대체 동작·
 * switch 갈래·이벤트 결과)로 옮겨 실행하면서 `handler·target·params·actions` 4개 키만 골라 담았다.
 * 그래서 단일 객체 `<fail>` 안의 `<conditions>`·`<switch>`·`<action-ref>`·`if`·`onSuccess` 가 경고 없이
 * 버려졌다(conditions 무동작, if 는 무시하고 항상 실행). 이제 원본을 통째로 넘긴다.
 *
 * @since engine-v1.80.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { ErrorHandlingResolver } from '../../error/ErrorHandlingResolver';
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

/** 레이아웃 리스너 결과 표식 — 엔진 helpers/componentEventResult.ts 와 같은 전역 심볼 */
const LAYOUT_LISTENER_RESULT = Symbol.for('g7.layoutListenerResult');

describe('[case:state-setstate-45] ActionDispatcher — 액션 모양 보존 (#753)', () => {
  let dispatcher: ActionDispatcher;
  let calls: Array<Record<string, any>>;

  /** 실패를 흉내 내는 핸들러가 던질 상태 코드 */
  let failStatus: number;

  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    calls = [];
    failStatus = 409;
    dispatcher.registerHandler('rec', (action: any) => {
      calls.push({ ...(action.params ?? {}), ...(action.target !== undefined ? { target: action.target } : {}) });
      return action.params?.ret;
    });
    dispatcher.registerHandler('fail753', () => {
      throw Object.assign(new Error('probe failure'), { status: failStatus });
    });
    dispatcher.setNamedActions({
      named753: { type: 'click', handler: 'rec', params: { v: 'named' } } as any,
    });
  });

  afterEach(() => {
    ErrorHandlingResolver.getInstance().clearAllConfig();
    delete (window as any).G7Core;
    delete (window as any).__templateApp;
  });

  /** 전역 상태 갱신 중 _local._eventResult 를 실은 마지막 값 — 실패 뒤 기본 오류 처리기가 다른 갱신(토스트)을 더 쌓는다 */
  const lastEventResult = (updates: any[]) => updates.map((u) => u?._local?._eventResult).filter(Boolean).at(-1);

  const run = (action: Record<string, any>, data: Record<string, any> = {}) =>
    dispatcher.dispatchAction({ type: 'click', ...action } as any, { data } as any);

  describe('실패 처리 — 단일 onError', () => {
    /**
     * @scenario slot=fail, shape=conditions
     * @effects fail_runs_full_action
     */
    it('(1) 단일 onError 의 conditions 가 상태 코드로 갈린다 (409 / 500)', async () => {
      const onError = {
        handler: 'conditions',
        conditions: [
          { if: '{{error.status === 409}}', then: { handler: 'rec', params: { v: '409-branch' } } },
          { then: { handler: 'rec', params: { v: 'other-branch' } } },
        ],
      };

      await run({ handler: 'fail753', onError });
      failStatus = 500;
      await run({ handler: 'fail753', onError });

      expect(calls.map((c) => c.v)).toEqual(['409-branch', 'other-branch']);
    });

    /**
     * @scenario slot=fail, shape=switch
     * @effects fail_runs_full_action
     */
    it('(2) 단일 onError 의 switch 가 상태 코드 case 를 고른다', async () => {
      await run({
        handler: 'fail753',
        onError: {
          handler: 'switch',
          params: { value: '{{String(error.status)}}' },
          cases: { '409': { handler: 'rec', params: { v: '409-case' } }, default: { handler: 'rec', params: { v: 'default' } } },
        },
      });

      expect(calls.map((c) => c.v)).toEqual(['409-case']);
    });

    /**
     * @scenario slot=fail, shape=if
     * @effects fail_runs_full_action
     */
    it('(3) 단일 onError 의 if 가 false 면 실행하지 않는다', async () => {
      await run({ handler: 'fail753', onError: { handler: 'rec', if: '{{error.status === 500}}', params: { v: 'should-not-run' } } });

      expect(calls).toEqual([]);
    });

    /**
     * @scenario slot=fail, shape=action_ref
     * @effects fail_runs_full_action
     */
    it('(4) 단일 onError 의 actionRef 가 named action 을 실행한다', async () => {
      await run({ handler: 'fail753', onError: { actionRef: 'named753' } });

      expect(calls.map((c) => c.v)).toEqual(['named']);
    });

    /**
     * @scenario slot=fail, shape=single
     * @effects fail_runs_full_action
     */
    it('(5) 단일 onError 안의 onSuccess 연쇄가 이어진다', async () => {
      await run({
        handler: 'fail753',
        onError: { handler: 'rec', params: { v: 'first' }, onSuccess: { handler: 'rec', params: { v: 'second' } } },
      });

      expect(calls.map((c) => c.v)).toEqual(['first', 'second']);
    });

    /**
     * @scenario slot=fail, shape=target
     * @effects fail_runs_full_action
     */
    it('단일 onError 의 target 은 지금처럼 전달된다 (회귀)', async () => {
      await run({ handler: 'fail753', onError: { handler: 'rec', target: 'modal_x', params: { v: 't' } } });

      expect(calls).toEqual([{ v: 't', target: 'modal_x' }]);
    });
  });

  describe('실패 처리 — errorHandling', () => {
    /**
     * @scenario slot=on_code, shape=conditions
     * @effects fail_runs_full_action
     */
    it('(6) errorHandling[409] 의 conditions 가 실행된다', async () => {
      await run({
        handler: 'fail753',
        errorHandling: {
          '409': { handler: 'conditions', conditions: [{ if: '{{error.status === 409}}', then: { handler: 'rec', params: { v: 'eh-409' } } }] },
        },
      });

      expect(calls.map((c) => c.v)).toEqual(['eh-409']);
    });

    /**
     * @scenario slot=on_code, shape=conditions
     * @effects fail_runs_full_action
     */
    it('(7) 레이아웃 단위 errorHandling 의 conditions 가 실행된다', async () => {
      ErrorHandlingResolver.getInstance().setLayoutConfig({
        '409': { handler: 'conditions', conditions: [{ then: { handler: 'rec', params: { v: 'layout-409' } } }] },
      } as any);

      await run({ handler: 'fail753' });

      expect(calls.map((c) => c.v)).toEqual(['layout-409']);
    });

    /**
     * @scenario slot=fail, shape=conditions
     * @effects fail_runs_full_action
     */
    it('(8) 1개짜리 onError 배열의 conditions 도 실행된다', async () => {
      await run({
        handler: 'fail753',
        onError: [{ handler: 'conditions', conditions: [{ if: '{{error.status === 409}}', then: { handler: 'rec', params: { v: 'arr1' } } }] }],
      });

      expect(calls.map((c) => c.v)).toEqual(['arr1']);
    });
  });

  describe('데이터소스 오류 처리 — resolver 실행기', () => {
    /**
     * @scenario slot=datasource_error, shape=conditions
     * @effects datasource_error_reads_route_query
     */
    it('(9) resolver.execute 의 route·query 가 {{route.x}}·{{query.y}} 로 읽히고 conditions 가 돈다', async () => {
      const resolver = ErrorHandlingResolver.getInstance();
      await resolver.execute(
        {
          handler: 'conditions',
          conditions: [
            { if: "{{query.mode === 'quiet'}}", then: { handler: 'rec', params: { r: '{{route.claim_number}}', q: '{{query.mode}}', s: '{{error.status}}' } } },
            { then: { handler: 'rec', params: { v: 'loud' } } },
          ],
        } as any,
        { status: 404, message: 'Not Found' } as any,
        { route: { claim_number: 'RT1' }, query: { mode: 'quiet' } } as any,
      );

      expect(calls).toEqual([{ r: 'RT1', q: 'quiet', s: 404 }]);
    });

    /**
     * @scenario slot=datasource_error, shape=single
     * @effects datasource_error_reads_route_query
     */
    it('resolver.execute 의 route·query 가 error·auth 를 덮지 못한다', async () => {
      const resolver = ErrorHandlingResolver.getInstance();
      await resolver.execute(
        { handler: 'rec', params: { s: '{{error.status}}', hasAuth: '{{!!auth}}' } } as any,
        { status: 404, message: 'Not Found' } as any,
        { route: {}, query: {}, error: { status: 999 }, auth: null } as any,
      );

      expect(calls).toEqual([{ s: 404, hasAuth: true }]);
    });
  });

  describe('작은 탈락', () => {
    /**
     * @scenario slot=navigate_fallback, shape=sequence
     * @effects fail_runs_full_action
     */
    it('(10) navigate 대체 동작 객체의 sequence 가 실행된다', async () => {
      (window as any).__templateApp = {
        getRouter: () => ({ match: () => null, getRoutes: () => [{ path: '/known' }] }),
      };

      await dispatcher.dispatchAction(
        {
          type: 'click',
          handler: 'navigate',
          params: {
            path: '/nowhere753',
            fallback: { handler: 'sequence', actions: [{ handler: 'rec', params: { v: 'fb1' } }, { handler: 'rec', params: { v: 'fb2' } }] },
          },
        } as any,
        { data: {}, navigate: vi.fn() } as any,
      );

      expect(calls.map((c) => c.v)).toEqual(['fb1', 'fb2']);
    });

    /**
     * @scenario slot=navigate_fallback, shape=single
     * @effects fail_runs_full_action
     */
    it('navigate 대체 동작 객체의 params 에 path 가 실린다 (회귀)', async () => {
      (window as any).__templateApp = {
        getRouter: () => ({ match: () => null, getRoutes: () => [{ path: '/known' }] }),
      };

      await dispatcher.dispatchAction(
        { type: 'click', handler: 'navigate', params: { path: '/nowhere753', fallback: { handler: 'rec', params: { v: 'single' } } } } as any,
        { data: {}, navigate: vi.fn() } as any,
      );

      expect(calls).toEqual([{ path: '/nowhere753', v: 'single' }]);
    });

    /**
     * @scenario slot=switch_case, shape=implicit_sequence
     * @effects fail_runs_full_action
     */
    it('(11) switch case 값이 배열이면 순서대로 실행한다', async () => {
      await run({
        handler: 'switch',
        params: { value: 'x' },
        cases: { x: [{ handler: 'rec', params: { v: 'a1' } }, { handler: 'rec', params: { v: 'a2' } }] },
      });

      expect(calls.map((c) => c.v)).toEqual(['a1', 'a2']);
    });
  });

  describe('이벤트 결과 (emitEvent)', () => {
    /**
     * @scenario slot=listen, shape=single
     * @effects event_result_reports_listener_failure
     */
    it('(12) 레이아웃 리스너 결과 표식을 풀어 _eventResult 에 success:false·error·data 를 쓰고 throw 하지 않는다', async () => {
      const updates: any[] = [];
      dispatcher.setGlobalStateUpdater((u) => updates.push(u));
      (window as any).G7Core = {
        state: { get: () => ({ _local: {} }) },
        componentEvent: {
          emit: vi.fn(async () => [
            { [LAYOUT_LISTENER_RESULT]: true, success: false, data: { got: 1 }, error: 'listener failed' },
          ]),
        },
      };

      const result = await run({ handler: 'emitEvent', params: { event: 'probe:x' } });

      expect(result.success).toBe(true);
      const eventResult = lastEventResult(updates);
      expect(eventResult).toEqual({ event: 'probe:x', success: false, data: { got: 1 }, error: 'listener failed', listeners: 1 });
    });

    /**
     * @scenario slot=listen, shape=single
     * @effects event_result_reports_listener_failure
     */
    it('성공한 레이아웃 리스너 결과는 data 만 풀어 success:true 로 싣는다', async () => {
      const updates: any[] = [];
      dispatcher.setGlobalStateUpdater((u) => updates.push(u));
      (window as any).G7Core = {
        state: { get: () => ({ _local: {} }) },
        componentEvent: {
          emit: vi.fn(async () => [{ [LAYOUT_LISTENER_RESULT]: true, success: true, data: 'ok' }]),
        },
      };

      await run({ handler: 'emitEvent', params: { event: 'probe:y' } });

      expect(lastEventResult(updates)).toEqual({ event: 'probe:y', success: true, data: 'ok', listeners: 1 });
    });

    /**
     * @scenario slot=listen, shape=single
     * @effects event_result_reports_listener_failure
     */
    it('컴포넌트 리스너가 throw 하면 지금처럼 보낸 쪽이 실패한다 (회귀)', async () => {
      const updates: any[] = [];
      dispatcher.setGlobalStateUpdater((u) => updates.push(u));
      (window as any).G7Core = {
        state: { get: () => ({ _local: {} }) },
        componentEvent: {
          emit: vi.fn(async () => {
            throw new Error('component listener threw');
          }),
        },
      };

      const result = await run({ handler: 'emitEvent', params: { event: 'probe:z' } });

      expect(result.success).toBe(false);
      expect(lastEventResult(updates)).toMatchObject({ event: 'probe:z', success: false, error: 'component listener threw' });
    });
  });
});
