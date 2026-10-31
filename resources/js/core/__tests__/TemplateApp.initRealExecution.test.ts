/**
 * TemplateApp — init 항목·웹소켓 수신 동작을 실제 ActionDispatcher 로 실행한다 (engine-v1.80.0, #753)
 *
 * `TemplateApp.test.ts` 는 엔진 모듈을 모의 디스패처로 바꿔 「createHandler 에 무엇이 넘어가는가」 만 본다.
 * 이 파일은 실제 ActionDispatcher 를 꽂아 계획서 WP-T 가 요구한 실행 결과를 잠근다.
 *  - init 의 sequence·switch·actionRef 가 실제로 실행된다
 *  - conditions 안에 적은 확장 핸들러가 뒤늦게 등록돼도 기다렸다 실행된다
 *  - 웹소켓 onReceive 를 단일 객체로 적어도 수신 때 실행된다
 *
 * @since engine-v1.80.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { realDispatcher } = vi.hoisted(() => ({ realDispatcher: { current: null as any } }));

vi.mock('../template-engine', async () => {
  const { ActionDispatcher } = await vi.importActual<any>('../template-engine/ActionDispatcher');
  realDispatcher.current = new ActionDispatcher({ navigate: () => {} });
  return {
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    releaseTemplateRoot: vi.fn(),
    getActionDispatcher: () => realDispatcher.current,
    getState: () => ({ actionDispatcher: realDispatcher.current, reactRoot: null, currentLayoutJson: null }),
  };
});

import { TemplateApp } from '../TemplateApp';

describe('TemplateApp — init·웹소켓 수신 실제 실행 (#753)', () => {
  let calls: string[];
  const originalConfig = (window as any).G7Config;

  beforeEach(() => {
    calls = [];
    const dispatcher = realDispatcher.current;
    dispatcher.registerHandler('rec753', (action: any) => {
      calls.push(String(action.params?.v));
    });
    dispatcher.setNamedActions({ named753: { type: 'click', handler: 'rec753', params: { v: 'named' } } as any });
    (window as any).G7Core = { dispatch: vi.fn() };
  });

  afterEach(() => {
    (window as any).G7Config = originalConfig;
    delete (window as any).G7Core;
    realDispatcher.current.customHandlers?.delete?.('ext-mod.late753');
    vi.useRealTimers();
  });

  const makeApp = () => new TemplateApp({ templateId: 'test-template', templateType: 'admin', locale: 'ko', debug: false });

  /**
   * @scenario slot=init, shape=sequence
   * @effects init_runs_full_action
   */
  it('init 의 sequence·switch·actionRef 가 실제로 실행된다', async () => {
    await (makeApp() as any).executeInitActions([
      { handler: 'sequence', actions: [{ handler: 'rec753', params: { v: 'seq-1' } }, { handler: 'rec753', params: { v: 'seq-2' } }] },
      { handler: 'switch', params: { value: 'b' }, cases: { a: { handler: 'rec753', params: { v: 'case-a' } }, b: [{ handler: 'rec753', params: { v: 'case-b1' } }, { handler: 'rec753', params: { v: 'case-b2' } }] } },
      { actionRef: 'named753' },
    ], {});

    expect(calls).toEqual(['seq-1', 'seq-2', 'case-b1', 'case-b2', 'named']);
  });

  /**
   * 확장 번들이 핸들러를 늦게 등록해도(비동기 등록) conditions 안에 적은 확장 핸들러가 실행돼야 한다.
   * 최상위만 기다리던 종전에는 기다리지 않고 바로 실행해 「미등록 핸들러」 로 건너뛰었다.
   *
   * @scenario slot=init, shape=conditions
   * @effects init_runs_full_action
   */
  it('conditions 안의 확장 핸들러가 뒤늦게 등록돼도 기다렸다 실행한다', async () => {
    (window as any).G7Config = { activeModules: [{ identifier: 'ext-mod' }], activePlugins: [] };
    setTimeout(() => {
      realDispatcher.current.registerHandler('ext-mod.late753', () => {
        calls.push('late');
      });
    }, 200);

    await (makeApp() as any).executeInitActions([
      { handler: 'conditions', conditions: [{ if: '{{true}}', then: { handler: 'ext-mod.late753' } }] },
    ], {});

    expect(calls).toEqual(['late']);
  });

  /**
   * @scenario slot=websocket, shape=single
   * @effects listen_runs_full_action
   */
  it('웹소켓 onReceive 를 단일 객체로 적어도 수신 때 실행하고 $args[0] 로 페이로드를 읽는다', async () => {
    await (makeApp() as any).runWebSocketOnReceive('ws753', { handler: 'rec753', params: { v: '{{$args[0].n}}' } }, { n: 7 });
    await (makeApp() as any).runWebSocketOnReceive('ws753', [{ handler: 'rec753', params: { v: 'arr-1' } }, { handler: 'rec753', params: { v: 'arr-2' } }], {});

    expect(calls).toEqual(['7', 'arr-1', 'arr-2']);
  });
});
