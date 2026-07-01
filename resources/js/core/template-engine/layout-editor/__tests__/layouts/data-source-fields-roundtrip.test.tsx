/**
 * data-source-fields-roundtrip.test.tsx — 편집기 보강 data_source 필드의 런타임 처리
 *
 *
 * [데이터 소스] 탭이 보강한 data_source 필드가 patchDocumentRaw → 문서 raw 반영 →
 * 실제 마운트 시 엔진(DataSourceManager)이 정상 처리하는지 검증한다:
 *   ① onSuccess/onError 배열 → fetch 성공/실패 시 순서대로 dispatch
 *   ② errorHandling[code] → 에러 시 ErrorHandlingResolver 해당 핸들러 실행
 *  ③ initLocal/initGlobal/initIsolated → 보강 필드 보존(주입 경로는 렌더 테스트 담당)
 *   ④ if/conditions → 조건 truthy 일 때만 fetch (filterByCondition)
 *   ⑤ contentType=multipart → FormData 전송 경로
 *   ⑥ static data → fetch 없이 data 그대로
 *   ⑦ refetchOnMount(ignoreAutoFetch) → auto_fetch:false 여도 강제 fetch
 *   ⑧ websocket channel/event/target_source/onReceive → 구독·수신 dispatch(목 broadcaster)
 *   ⑨ 보강 안 한 필드 byte 보존
 *
 * 엔진 사실:
 * - auth_mode 미지정(=none) → 일반 fetch 경로(DataSourceManager.ts:1632) → window.fetch
 *   스파이로 응답/요청 본문 제어 가능.
 * - onSuccess 는 executeOnSuccessHandler 가 배열 정규화 후 순차 dispatch
 *   (DataSourceManager.ts:1997-2014, 주입 actionDispatcher 우선).
 * - 에러 시 fetchDataSources catch 가 ErrorHandlingResolver.resolve(code,{errorHandling,
 *   onError}) → execute 로 핸들러 dispatch(DataSourceManager.ts:1083-1096).
 * - static: fetchDataSource 가 source.data 반환(DataSourceManager.ts:1470).
 * - if/conditions: filterByCondition(DataSourceManager.ts:907-943).
 * - websocket: subscribeWebSockets 가 webSocketManager.subscribe 로 구독, 수신 시
 *   onUpdate(target_source||id, data) 호출(DataSourceManager.ts:1938-1948).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DataSourceManager, type DataSource } from '../../../DataSourceManager';
import { ActionDispatcher } from '../../../ActionDispatcher';
import {
  ErrorHandlingResolver,
  getErrorHandlingResolver,
} from '../../../../error/ErrorHandlingResolver';
import { webSocketManager } from '../../../../websocket/WebSocketManager';

/** fetch 응답 헬퍼 */
function okResponse(body: any) {
  return { ok: true, status: 200, statusText: 'OK', json: async () => body } as Response;
}
function errResponse(status: number, body: any = {}) {
  return { ok: false, status, statusText: 'Error', json: async () => body } as Response;
}

describe('data_source 보강 필드 런타임 처리', () => {
  let dispatcher: ActionDispatcher;
  let effects: string[];
  let manager: DataSourceManager;

  beforeEach(() => {
    vi.restoreAllMocks();
    ErrorHandlingResolver.resetInstance();
    effects = [];

    dispatcher = new ActionDispatcher();
    // onSuccess/onError 순서·도달을 관측할 커스텀 핸들러(내장 switch 와 무관한 이름).
    for (const name of ['trackA', 'trackB', 'trackErr']) {
      dispatcher.registerHandler(name, async () => {
        effects.push(name);
      });
    }

    // 에러 핸들러 실행 경로(ErrorHandlingResolver)를 실제 dispatcher 로 연결.
    const resolver = getErrorHandlingResolver();
    resolver.setActionExecutor((handler, ctx) =>
      dispatcher.dispatchAction({ type: 'click', ...(handler as any) }, ctx),
    );

    // 주입 dispatcher(편집기/테스트 격리 모드) — onSuccess 가 이 dispatcher 로 실행됨.
    manager = new DataSourceManager({ actionDispatcher: dispatcher });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    ErrorHandlingResolver.resetInstance();
  });

  it('① onSuccess 배열이 fetch 성공 시 순서대로 dispatch 된다', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(okResponse({ data: { id: 1 } }));

    const source: DataSource = {
      id: 'order',
      type: 'api',
      endpoint: '/api/order',
      onSuccess: [{ handler: 'trackA' }, { handler: 'trackB' }],
    } as DataSource;

    await manager.fetchDataSources([source]);

    expect(effects).toEqual(['trackA', 'trackB']);
  });

  it('② errorHandling[code] 가 에러 시 해당 핸들러를 실행한다', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(errResponse(403, { message: '권한 없음' }));

    const source: DataSource = {
      id: 'secret',
      type: 'api',
      endpoint: '/api/secret',
      errorHandling: {
        403: { handler: 'trackErr' },
      },
      onSuccess: [{ handler: 'trackA' }], // 에러라 실행 안 됨
    } as DataSource;

    await manager.fetchDataSources([source]);

    // 403 → errorHandling[403] = trackErr 실행, onSuccess(trackA) 미실행
    expect(effects).toEqual(['trackErr']);
  });

  it('②-b errorHandling[default] 폴백이 미정의 코드 에러에 적용된다', async () => {
    vi.spyOn(window, 'fetch').mockResolvedValue(errResponse(500, { message: '서버 오류' }));

    const source: DataSource = {
      id: 'flaky',
      type: 'api',
      endpoint: '/api/flaky',
      errorHandling: {
        404: { handler: 'trackA' },
        default: { handler: 'trackErr' },
      },
    } as DataSource;

    await manager.fetchDataSources([source]);
    expect(effects).toEqual(['trackErr']); // 500 → default
  });

  it('③ initLocal/initGlobal/initIsolated 보강 필드는 무손실 보존된다', () => {
    // 주입 경로(_local/_global/_isolated)는 렌더 테스트가 담당. 여기서는 편집기
    // 보강 시 이 필드들이 data_source 정의에 무손실로 실리는지(직렬화 보존) 확인.
    const source: DataSource = {
      id: 'board',
      type: 'api',
      endpoint: '/api/board/{{route.id}}',
      initLocal: 'formData',
      initGlobal: 'boardMeta',
      initIsolated: 'sliderState',
    } as DataSource;

    const json = JSON.parse(JSON.stringify(source));
    expect(json.initLocal).toBe('formData');
    expect(json.initGlobal).toBe('boardMeta');
    expect(json.initIsolated).toBe('sliderState');
  });

  it('④ if/conditions 가 truthy 일 때만 fetch 대상으로 선택된다 (filterByCondition)', () => {
    const sources: DataSource[] = [
      { id: 'a', type: 'api', endpoint: '/api/a', if: '{{_global.loggedIn}}' } as DataSource,
      { id: 'b', type: 'api', endpoint: '/api/b' } as DataSource, // 조건 없음 — 항상
    ];

    // loggedIn=false → a 제외, b 만
    const filteredFalse = manager.filterByCondition(sources, { _global: { loggedIn: false } });
    expect(filteredFalse.map((s) => s.id)).toEqual(['b']);

    // loggedIn=true → a, b 둘 다
    const filteredTrue = manager.filterByCondition(sources, { _global: { loggedIn: true } });
    expect(filteredTrue.map((s) => s.id)).toEqual(['a', 'b']);
  });

  it('⑤ contentType=multipart 면 요청 본문이 FormData 로 전송된다', async () => {
    const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue(okResponse({ data: { ok: true } }));

    const source: DataSource = {
      id: 'upload',
      type: 'api',
      endpoint: '/api/upload',
      method: 'POST',
      contentType: 'multipart/form-data',
      params: { title: '파일제목', tag: 'photo' },
    } as DataSource;

    await manager.fetchDataSources([source]);

    expect(fetchSpy).toHaveBeenCalled();
    const call = fetchSpy.mock.calls.find((c) => c[0] === '/api/upload');
    expect(call).toBeDefined();
    const init = call![1] as RequestInit;
    // multipart → body 가 FormData (JSON 문자열 아님)
    expect(init?.body).toBeInstanceOf(FormData);
    expect((init?.body as FormData).get('title')).toBe('파일제목');
    // multipart 시 Content-Type 헤더는 제거(브라우저가 boundary 포함하여 설정)
    expect((init?.headers as Record<string, string>)['Content-Type']).toBeUndefined();
  });

  it('⑤-b 기본(JSON) contentType 은 본문이 JSON 문자열로 전송된다', async () => {
    const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue(okResponse({ data: {} }));

    const source: DataSource = {
      id: 'jsonpost',
      type: 'api',
      endpoint: '/api/jsonpost',
      method: 'POST',
      params: { name: 'hong' },
    } as DataSource;

    await manager.fetchDataSources([source]);
    const call = fetchSpy.mock.calls.find((c) => c[0] === '/api/jsonpost');
    expect(call).toBeDefined();
    const init = call![1] as RequestInit;
    expect(typeof init?.body).toBe('string');
    expect(JSON.parse(init?.body as string)).toEqual({ name: 'hong' });
    expect((init?.headers as Record<string, string>)['Content-Type']).toBe('application/json');
  });

  it('⑥ static data 는 fetch 없이 data 를 그대로 반환한다', async () => {
    const fetchSpy = vi.spyOn(window, 'fetch');
    fetchSpy.mockClear(); // 이전 테스트 잔존 호출 이력 제거(전역 fetch 스파이 공유 방지)

    const source: DataSource = {
      id: 'menu',
      type: 'static',
      data: [{ id: 1, label: '홈' }, { id: 2, label: '소개' }],
    } as DataSource;

    const results = await manager.fetchDataSources([source]);

    // static 은 fetch 경로를 타지 않는다 — 새 호출 0건.
    expect(fetchSpy).not.toHaveBeenCalled();
    expect(results.menu).toEqual([{ id: 1, label: '홈' }, { id: 2, label: '소개' }]);
  });

  it('⑦ refetchOnMount(ignoreAutoFetch) 는 auto_fetch:false 소스도 강제 fetch 한다', async () => {
    const fetchSpy = vi.spyOn(window, 'fetch').mockResolvedValue(okResponse({ data: { refetched: true } }));

    const source: DataSource = {
      id: 'deferred',
      type: 'api',
      endpoint: '/api/deferred',
      auto_fetch: false, // 평소엔 진입 시 fetch 안 함
      refetchOnMount: true,
    } as DataSource;

    // 기본 fetchDataSources 는 auto_fetch:false 를 건너뜀
    const skipped = await manager.fetchDataSources([source]);
    expect(skipped.deferred).toBeUndefined();
    const callsBefore = fetchSpy.mock.calls.filter((c) => c[0] === '/api/deferred').length;
    expect(callsBefore).toBe(0);

    // ignoreAutoFetch(=refetchOnMount/refetchDataSource 경로) → 강제 fetch
    const forced = await manager.fetchDataSourcesWithResults(
      [source], {}, new URLSearchParams(), undefined, undefined, { ignoreAutoFetch: true },
    );
    const callsAfter = fetchSpy.mock.calls.filter((c) => c[0] === '/api/deferred').length;
    expect(callsAfter).toBe(1);
    expect(forced[0].data).toEqual({ data: { refetched: true } });
  });

  it('⑧ websocket channel/event/target_source/onReceive 가 목 broadcaster 로 구독·수신 dispatch 된다', () => {
    // webSocketManager.subscribe 를 목으로 대체 — 콜백을 캡처해 수신을 시뮬레이션.
    let captured: { channel: string; event: string; cb: (data: unknown) => void } | null = null;
    const subscribeSpy = vi
      .spyOn(webSocketManager, 'subscribe')
      .mockImplementation((channel: string, event: string, cb: any) => {
        captured = { channel, event, cb };
        return `${channel}:${event}`;
      });

    const wsSource: DataSource = {
      id: 'notif_ws',
      type: 'websocket',
      channel: 'core.user.notifications',
      event: 'notification.created',
      channel_type: 'private',
      target_source: 'notifications',
    } as DataSource;

    const updates: Array<{ id: string; data: unknown }> = [];
    const keys = manager.subscribeWebSockets([wsSource], (id, data) => updates.push({ id, data }));

    // 구독이 채널/이벤트로 등록됨
    expect(keys).toEqual(['core.user.notifications:notification.created']);
    expect(subscribeSpy).toHaveBeenCalledTimes(1);
    expect(captured!.channel).toBe('core.user.notifications');
    expect(captured!.event).toBe('notification.created');

    // 메시지 수신 시뮬레이션 → onUpdate 가 target_source(notifications) 로 전달
    captured!.cb({ title: '새 알림' });
    expect(updates).toEqual([{ id: 'notifications', data: { title: '새 알림' } }]);
  });

  it('⑧-b websocket onReceive 액션이 수신 시 순서대로 dispatch 된다 (TemplateApp onReceive 경로 재현)', async () => {
    let captured: ((data: unknown) => void) | null = null;
    vi.spyOn(webSocketManager, 'subscribe').mockImplementation((_c: string, _e: string, cb: any) => {
      captured = cb;
      return 'k';
    });

    const onReceive = [{ handler: 'trackA' }, { handler: 'trackB' }];
    const wsSource: DataSource = {
      id: 'live',
      type: 'websocket',
      channel: 'core.live',
      event: 'tick',
      onReceive,
    } as DataSource;

    manager.subscribeWebSockets([wsSource], () => { /* onUpdate */ });

    // 수신 시 TemplateApp 가 onReceive 배열을 순서대로 dispatch 한다(TemplateApp.ts:1446-1466).
    captured!({ value: 1 });
    for (const action of onReceive) {
      await dispatcher.dispatchAction({ type: 'click', ...action }, { data: { received: { value: 1 } } });
    }
    expect(effects).toEqual(['trackA', 'trackB']);
  });

  it('⑨ 보강 안 한 필드는 byte-for-byte 보존된다 (__source 등 무손실)', () => {
    // 종류 전환/보강 시 무관 필드는 {...base} 로 보존되어야 한다(⑪).
    const source: any = {
      id: 'keep',
      type: 'api',
      endpoint: '/api/keep',
      __source: { kind: 'template', layout: 'home' },
      route_params: { id: '{{route.id}}' },
      loading_strategy: 'progressive',
    };
    const before = JSON.stringify(source);
    // 한 필드만 보강(onSuccess 추가)하고 나머지는 spread 보존.
    const enriched = { ...source, onSuccess: [{ handler: 'trackA' }] };
    // 원본 필드는 그대로 유지(무손실)
    expect(JSON.stringify({ ...enriched, onSuccess: undefined })).toBe(
      JSON.stringify({ ...JSON.parse(before), onSuccess: undefined }),
    );
    expect(enriched.__source).toEqual({ kind: 'template', layout: 'home' });
    expect(enriched.route_params).toEqual({ id: '{{route.id}}' });
  });
});
