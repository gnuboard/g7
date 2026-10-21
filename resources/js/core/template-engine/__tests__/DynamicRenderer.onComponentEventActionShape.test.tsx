/**
 * DynamicRenderer — `<on listen>`(onComponentEvent) 액션 통째 실행 (engine-v1.80.0, #753)
 *
 * 구독 콜백이 `{handler, params, onSuccess, onError}` 4개 키만 골라 createHandler 에 넘겨,
 * `<on listen>` 안의 암묵 sequence·`<sequence>`·`<conditions>`·`<switch>`·루트 `if`·`<action-ref>`·
 * `target`·`resultTo` 가 경고 없이 버려졌다(루트 `if` 는 무시하고 항상 실행). 리스너 결과도 늘 undefined 라
 * 보낸 쪽 `_local._eventResult` 는 받는 쪽이 실패해도 success:true 였다.
 *
 * 실물 DynamicRenderer · ActionDispatcher 로 렌더하고, 이벤트 버스(G7Core.componentEvent)만 모의한다.
 *
 * @since engine-v1.80.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, act } from '@testing-library/react';
import DynamicRenderer, { ComponentDefinition } from '../DynamicRenderer';
import { ComponentRegistry } from '../ComponentRegistry';
import { DataBindingEngine } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ActionDispatcher } from '../ActionDispatcher';
import { ErrorHandlingResolver } from '../../error/ErrorHandlingResolver';
import { Logger } from '../../utils/Logger';

const TestButton: React.FC<{ children?: React.ReactNode; text?: string }> = ({ children, text }) => <button>{children || text}</button>;

/** 레이아웃 리스너 결과 표식 — helpers/componentEventResult.ts 와 같은 전역 심볼 */
const LAYOUT_LISTENER_RESULT = Symbol.for('g7.layoutListenerResult');

describe('DynamicRenderer — onComponentEvent 액션 통째 실행 (#753)', () => {
  let registry: ComponentRegistry;
  let bindingEngine: DataBindingEngine;
  let translationEngine: TranslationEngine;
  let actionDispatcher: ActionDispatcher;
  const translationContext: TranslationContext = { templateId: 'test-template', locale: 'ko' };
  let subscribers: Map<string, Array<(data?: any) => any>>;
  let calls: Array<Record<string, any>>;
  let globalUpdates: any[];

  /** 실제 G7Core.componentEvent.emit 과 같은 계약 — 구독자 결과 배열을 돌려준다 */
  const emit = async (eventName: string, data?: any) => {
    const results: any[] = [];
    for (const cb of subscribers.get(eventName) || []) {
      results.push(await cb(data));
    }
    return results;
  };

  beforeEach(() => {
    ErrorHandlingResolver.resetInstance();
    Logger.getInstance().setDebug(false);
    registry = ComponentRegistry.getInstance();
    (registry as any).registry = { Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } } };
    bindingEngine = new DataBindingEngine();
    translationEngine = new TranslationEngine();
    actionDispatcher = new ActionDispatcher({ navigate: vi.fn() });

    subscribers = new Map();
    (window as any).G7Core = {
      componentEvent: {
        on: vi.fn((eventName: string, cb: (data?: any) => any) => {
          if (!subscribers.has(eventName)) subscribers.set(eventName, []);
          subscribers.get(eventName)!.push(cb);
          return () => {
            const list = subscribers.get(eventName) || [];
            const i = list.indexOf(cb);
            if (i > -1) list.splice(i, 1);
          };
        }),
        emit: vi.fn(emit),
        off: vi.fn(),
      },
    };

    calls = [];
    globalUpdates = [];
    actionDispatcher.registerHandler('rec', (action: any) => {
      calls.push({ ...(action.params ?? {}), ...(action.target !== undefined ? { target: action.target } : {}) });
      return action.params?.ret;
    });
    actionDispatcher.registerHandler('fail753', () => {
      throw Object.assign(new Error('listener api failed'), { status: 404 });
    });
    actionDispatcher.setNamedActions({ named753: { type: 'click', handler: 'rec', params: { v: 'named' } } as any });
    actionDispatcher.setGlobalStateUpdater((u: any) => globalUpdates.push(u));
  });

  afterEach(() => {
    delete (window as any).G7Core;
    subscribers.clear();
  });

  const mount = (onComponentEvent: any[]) =>
    render(
      <DynamicRenderer
        componentDef={{ id: 'listen-753', type: 'basic', name: 'Button', props: {}, text: 'L', onComponentEvent } as ComponentDefinition}
        dataContext={{}}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

  /**
   * @scenario slot=listen, shape=implicit_sequence
   * @effects listen_runs_full_action
   */
  it('암묵 sequence(자식 여럿)의 동작이 모두 순서대로 실행된다', async () => {
    mount([{ event: 'p:l1', handler: 'sequence', actions: [{ handler: 'rec', params: { v: 'a' } }, { handler: 'rec', params: { v: 'b' } }] }]);

    await emit('p:l1', {});

    expect(calls.map((c) => c.v)).toEqual(['a', 'b']);
  });

  /**
   * 계획서 WP-T: 「암묵 sequence(setState 2개 모두 반영)」 — 기록용 핸들러가 아니라 실제 setState 로 화면 값까지 본다.
   *
   * @scenario slot=listen, shape=implicit_sequence
   * @effects listen_runs_full_action
   */
  it('암묵 sequence 의 setState 두 개가 모두 화면 상태에 반영된다', async () => {
    const view = render(
      <DynamicRenderer
        componentDef={{
          id: 'listen-753',
          type: 'basic',
          name: 'Button',
          props: {},
          text: 'a={{_local.a ?? "-"}} b={{_local.b ?? "-"}}',
          onComponentEvent: [{
            event: 'p:set2',
            handler: 'sequence',
            actions: [
              { handler: 'setState', params: { target: 'local', a: '1' } },
              { handler: 'setState', params: { target: 'local', b: '2' } },
            ],
          }],
        } as ComponentDefinition}
        dataContext={{ _local: {} }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );
    expect(view.container.textContent).toContain('a=- b=-');

    await act(async () => {
      await emit('p:set2', {});
    });

    expect(view.container.textContent).toContain('a=1 b=2');
  });

  /**
   * @scenario slot=listen, shape=sequence
   * @effects listen_runs_full_action
   */
  it('params.actions 모양 sequence 도 실행된다 (회귀)', async () => {
    mount([{ event: 'p:l2', handler: 'sequence', params: { actions: [{ handler: 'rec', params: { v: 'done' } }] } }]);

    await emit('p:l2', {});

    expect(calls.map((c) => c.v)).toEqual(['done']);
  });

  /**
   * @scenario slot=listen, shape=conditions
   * @effects listen_runs_full_action
   */
  it('conditions 가 _eventData 로 갈린다', async () => {
    mount([
      {
        event: 'p:l3',
        handler: 'conditions',
        conditions: [
          { if: "{{_eventData.kind === 'a'}}", then: { handler: 'rec', params: { v: 'a-branch' } } },
          { then: { handler: 'rec', params: { v: 'b-branch' } } },
        ],
      },
    ]);

    await emit('p:l3', { kind: 'b' });

    expect(calls.map((c) => c.v)).toEqual(['b-branch']);
  });

  /**
   * @scenario slot=listen, shape=switch
   * @effects listen_runs_full_action
   */
  it('switch 가 _eventData 로 case 를 고른다', async () => {
    mount([{ event: 'p:l4', handler: 'switch', params: { value: '{{_eventData.kind}}' }, cases: { a: { handler: 'rec', params: { v: 'a-case' } } } }]);

    await emit('p:l4', { kind: 'a' });

    expect(calls.map((c) => c.v)).toEqual(['a-case']);
  });

  /**
   * @scenario slot=listen, shape=if
   * @effects listen_runs_full_action
   */
  it('루트 if 가 false 면 실행하지 않고 true 면 실행한다', async () => {
    mount([{ event: 'p:l5', handler: 'rec', if: '{{_eventData.allow === true}}', params: { v: 'allowed' } }]);

    await emit('p:l5', { allow: false });
    expect(calls).toEqual([]);

    await emit('p:l5', { allow: true });
    expect(calls.map((c) => c.v)).toEqual(['allowed']);
  });

  /**
   * @scenario slot=listen, shape=action_ref
   * @effects listen_runs_full_action
   */
  it('actionRef 가 named action 을 실행한다', async () => {
    mount([{ event: 'p:l6', actionRef: 'named753' }]);

    await emit('p:l6', {});

    expect(calls.map((c) => c.v)).toEqual(['named']);
  });

  /**
   * @scenario slot=listen, shape=target
   * @effects listen_runs_full_action
   */
  it('target(openModal) 이 전달돼 모달이 열린다', async () => {
    mount([{ event: 'p:l7', handler: 'openModal', target: 'p_modal' }]);

    await emit('p:l7', {});

    expect(globalUpdates.some((u) => u?.activeModal === 'p_modal' && (u?.modalStack ?? []).includes('p_modal'))).toBe(true);
  });

  /**
   * @scenario slot=listen, shape=single
   * @effects listen_runs_full_action
   */
  it('resultTo 가 결과를 상태에 싣는다', async () => {
    mount([{ event: 'p:l10', handler: 'rec', params: { ret: 'R753' }, resultTo: { target: '_global', key: 'got753' } }]);

    await emit('p:l10', {});

    expect(globalUpdates.some((u) => u?.got753 === 'R753')).toBe(true);
  });

  /**
   * 리스너는 마운트 때 한 번 구독된다 — 콜백이 마운트 시점 문맥을 붙잡으면 `{{_local.x}}` 가 옛 값으로 읽히고,
   * 로컬 setState 가 그 옛 스냅샷을 기준으로 저장소 B 를 맞춘다. 실행 시점의 최신 문맥을 읽어야 한다.
   *
   * @scenario slot=listen, shape=single
   * @effects listen_runs_full_action
   */
  it('리렌더 뒤 이벤트는 마운트 시점이 아니라 최신 _local 을 읽는다', async () => {
    const def = { id: 'listen-753', type: 'basic', name: 'Button', props: {}, text: 'L', onComponentEvent: [{ event: 'p:fresh', handler: 'rec', params: { n: '{{_local.n}}' } }] } as ComponentDefinition;
    const view = (n: number) => (
      <DynamicRenderer
        componentDef={def}
        dataContext={{ _local: { n } }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );
    const { rerender } = render(view(1));
    rerender(view(2));

    await emit('p:fresh', {});

    expect(subscribers.get('p:fresh')).toHaveLength(1);
    expect(calls).toEqual([{ n: 2 }]);
  });

  /**
   * @scenario slot=listen, shape=single
   * @effects event_result_reports_listener_failure
   */
  it('리스너 성공 → 결과가 표식 객체 success:true·data', async () => {
    mount([{ event: 'p:l9', handler: 'rec', params: { v: 'in', ret: 'ok' } }]);

    const results = await emit('p:l9', {});

    expect(results).toHaveLength(1);
    expect(results[0][LAYOUT_LISTENER_RESULT]).toBe(true);
    expect(results[0]).toMatchObject({ success: true, data: 'ok' });
  });

  /**
   * @scenario slot=listen, shape=single
   * @effects event_result_reports_listener_failure
   */
  it('리스너의 동작이 실패(+자기 onError) → 결과가 success:false·error, 리스너 onError 는 실행된다', async () => {
    mount([{ event: 'p:l8', handler: 'fail753', onError: { handler: 'rec', params: { v: 'own-fail' } } }]);

    const results = await emit('p:l8', {});

    expect(calls.map((c) => c.v)).toEqual(['own-fail']);
    expect(results[0][LAYOUT_LISTENER_RESULT]).toBe(true);
    expect(results[0].success).toBe(false);
    expect(typeof results[0].error).toBe('string');
  });

  /**
   * 계획서 WP-T: 「리스너의 apiCall 실패(+자기 onError) → success:false」 — 모의 throw 가 아니라 실제 apiCall 경로(fetch 404).
   *
   * @scenario slot=listen, shape=single
   * @effects event_result_reports_listener_failure
   */
  it('리스너의 실제 apiCall 이 404 로 실패하면 자기 onError 가 돌고 결과가 success:false 다', async () => {
    const fetchMock = vi.fn(async () => new Response(JSON.stringify({ success: false, message: 'not found' }), { status: 404, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetchMock);
    try {
      mount([{ event: 'p:api', handler: 'apiCall', target: '/api/__probe753_missing', params: { method: 'GET' }, onError: { handler: 'rec', params: { v: 'api-fail' } } }]);

      const [result] = await emit('p:api', {});

      expect(fetchMock).toHaveBeenCalledTimes(1);
      expect(String(fetchMock.mock.calls[0][0])).toContain('/api/__probe753_missing');
      expect(calls.map((c) => c.v)).toEqual(['api-fail']);
      expect(result[LAYOUT_LISTENER_RESULT]).toBe(true);
      expect(result.success).toBe(false);
    } finally {
      vi.unstubAllGlobals();
    }
  });

  /**
   * setState 의 결과는 병합된 상태 스냅숏(`__target` 표식)이다 — 동작의 결과 데이터가 아니라 엔진 내부 동기화 값.
   * 그대로 `_eventResult.data` 에 실으면 다음 emit 의 스냅숏이 이전 `_eventResult` 를 품어 emit 마다 한 겹씩
   * 쌓인다(이커머스 설정 탭 8회 클릭에 `_local` JSON 39 KB → 34 MB).
   *
   * @scenario slot=listen, shape=implicit_sequence
   * @effects event_result_reports_listener_failure
   */
  it('리스너 결과 data 는 마지막 동작의 결과이고 상태 쓰기 스냅숏은 싣지 않는다', async () => {
    const def = {
      id: 'listen-753',
      type: 'basic',
      name: 'Button',
      props: {},
      text: 'L',
      onComponentEvent: [
        { event: 'p:snap', handler: 'sequence', actions: [{ handler: 'setState', params: { target: 'local', tab: '{{_eventData.tab}}' } }, { handler: 'rec', params: { ret: 'last' } }] },
        { event: 'p:snap1', handler: 'setState', params: { target: 'local', tab: '{{_eventData.tab}}' } },
        { event: 'p:snap2', handler: 'sequence', actions: [{ handler: 'rec', params: { ret: 'first' } }, { handler: 'setState', params: { target: 'local', tab: '{{_eventData.tab}}' } }] },
      ],
    } as ComponentDefinition;
    render(
      <DynamicRenderer
        componentDef={def}
        dataContext={{ _local: { tab: 'a', _eventResult: { event: 'p:snap', success: true, data: { big: 'x'.repeat(1000) }, listeners: 1 } } }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

    const [seq] = await emit('p:snap', { tab: 'b' });
    const [single] = await emit('p:snap1', { tab: 'c' });
    const [seqEndsWithState] = await emit('p:snap2', { tab: 'd' });

    expect(seq.success).toBe(true);
    expect(seq.data).toBe('last');
    expect(single.success).toBe(true);
    expect(single.data).toBeUndefined();
    expect(seqEndsWithState.data).toBeUndefined();
  });

  /**
   * @scenario slot=listen, shape=single
   * @effects event_result_reports_listener_failure
   */
  it('상태 쓰기가 아닌 결과(배열·객체)는 그대로 data 에 싣는다 (회귀)', async () => {
    mount([{ event: 'p:arr', handler: 'rec', params: { ret: [{ id: 1 }, { id: 2 }] } }, { event: 'p:obj', handler: 'rec', params: { ret: { total: 3 } } }]);

    const [arr] = await emit('p:arr', {});
    const [obj] = await emit('p:obj', {});

    expect(arr.data).toEqual([{ id: 1 }, { id: 2 }]);
    expect(obj.data).toEqual({ total: 3 });
  });

  /**
   * 분기(conditions·switch)는 고른 동작의 실행 결과(`{success, data}`)를 돌려주고 executeAction 이 그것을 한 겹 더
   * 감싼다. 종전 판별은 최상위만 보아 분기 안 set-state 의 병합된 `_local` 스냅숏이 `_eventResult.data` 로 새어
   * emit 마다 이전 `_eventResult` 를 품었다(엄격 검사 K1, 2026-10-15).
   *
   * @scenario slot=listen, shape=conditions
   * @effects event_result_reports_listener_failure
   */
  it('conditions·switch 리스너의 결과 data 는 분기 동작의 결과이고 상태 쓰기 스냅숏은 싣지 않는다', async () => {
    const set = { handler: 'setState', params: { target: 'local', tab: '{{_eventData.tab}}' } };
    const def = {
      id: 'listen-753',
      type: 'basic',
      name: 'Button',
      props: {},
      text: 'L',
      onComponentEvent: [
        { event: 'p:cset', handler: 'conditions', conditions: [{ if: '{{true}}', then: set }] },
        { event: 'p:sset', handler: 'switch', params: { value: 'a' }, cases: { a: set } },
        { event: 'p:seqcset', handler: 'sequence', actions: [{ handler: 'rec', params: { ret: 'first' } }, { handler: 'conditions', conditions: [{ if: '{{true}}', then: set }] }] },
        { event: 'p:cnest', handler: 'conditions', conditions: [{ if: '{{true}}', then: { handler: 'switch', params: { value: 'a' }, cases: { a: set } } }] },
        { event: 'p:cret', handler: 'conditions', conditions: [{ if: '{{true}}', then: { handler: 'rec', params: { ret: 'branch' } } }] },
        { event: 'p:sret', handler: 'switch', params: { value: 'a' }, cases: { a: { handler: 'rec', params: { ret: { total: 3 } } } } },
      ],
    } as ComponentDefinition;
    render(
      <DynamicRenderer
        componentDef={def}
        dataContext={{ _local: { tab: 'a', _eventResult: { event: 'p:cset', success: true, data: { big: 'x'.repeat(1000) }, listeners: 1 } } }}
        translationContext={translationContext}
        registry={registry}
        bindingEngine={bindingEngine}
        translationEngine={translationEngine}
        actionDispatcher={actionDispatcher}
      />
    );

    for (const eventName of ['p:cset', 'p:sset', 'p:seqcset', 'p:cnest']) {
      const [result] = await emit(eventName, { tab: 'b' });
      expect(result.success, eventName).toBe(true);
      expect(result.data, eventName).toBeUndefined();
    }
    const [conditionsResult] = await emit('p:cret', {});
    const [switchResult] = await emit('p:sret', {});
    expect(conditionsResult.data).toBe('branch');
    expect(switchResult.data).toEqual({ total: 3 });
  });

  /**
   * 받는 쪽 동작이 하나라도 실패하면 `success:false` 다 — 분기 안·연속 실행 중간의 실패도, 그 동작의 `<fail>` 이
   * 처리했더라도 같다(2026-10-15 확정, 엄격 검사 K2). 보낸 쪽 흐름은 멈추지 않는다(W3).
   *
   * @scenario slot=listen, shape=switch
   * @effects event_result_reports_listener_failure
   */
  it('분기 안·연속 실행 중간의 동작이 실패하면 결과가 success:false·error 다', async () => {
    const failing = { handler: 'fail753', onError: { handler: 'rec', params: { v: 'own-fail' } } };
    mount([
      { event: 'p:cfail', handler: 'conditions', conditions: [{ if: '{{true}}', then: failing }] },
      { event: 'p:sfail', handler: 'switch', params: { value: 'a' }, cases: { a: failing } },
      { event: 'p:seqmid', handler: 'sequence', actions: [{ handler: 'rec', params: { v: 'before' } }, failing, { handler: 'rec', params: { v: 'after', ret: 'last' } }] },
      { event: 'p:parfail', handler: 'parallel', actions: [failing, { handler: 'rec', params: { v: 'par' } }] },
      { event: 'p:cok', handler: 'conditions', conditions: [{ if: '{{true}}', then: { handler: 'rec', params: { v: 'ok' } } }] },
    ]);

    for (const eventName of ['p:cfail', 'p:sfail', 'p:seqmid', 'p:parfail']) {
      const [result] = await emit(eventName, {});
      expect(result.success, eventName).toBe(false);
      expect(result.error, eventName).toContain('fail753');
    }
    // 연속 실행은 실패 뒤에도 이어지고(받는 쪽 자기 <fail> 실행), 결과 data 는 마지막 동작의 결과다
    const [sequenceResult] = await emit('p:seqmid', {});
    expect(sequenceResult.data).toBe('last');
    expect(calls.filter((c) => c.v === 'own-fail').length).toBeGreaterThanOrEqual(4);
    expect(calls.some((c) => c.v === 'after')).toBe(true);

    const [okResult] = await emit('p:cok', {});
    expect(okResult.success).toBe(true);
    expect(okResult.error).toBeUndefined();
  });
});
