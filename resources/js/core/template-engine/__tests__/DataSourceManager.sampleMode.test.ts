/**
 * DataSourceManager.sampleMode.test.ts
 *
 * DataSourceManager 의 샘플 모드 주입점 검증.
 *
 * - sampleProvider 옵션이 주입되지 않은 경우(일반 렌더 모드): 분기 진입 0,
 *   fetch 100% 보존 (회귀 가드).
 * - sampleProvider 옵션이 주입된 경우(편집 모드): fetch 호출 없이 프로바이더
 *   resolve() 결과를 반환.
 * - sampleProvider 가 has() 로 false 반환 시 일반 fetch 경로로 폴백.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { DataSourceManager, type DataSource, type SampleDataProvider } from '../DataSourceManager';

describe('DataSourceManager — 샘플 모드 주입점 ', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn(async () =>
      new Response(JSON.stringify({ success: true, data: { data: [] } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    );
    global.fetch = fetchMock as any;
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('일반 렌더 모드(옵션 미주입): API 데이터소스가 네트워크 fetch 를 호출한다 (회귀 가드)', async () => {
    const manager = new DataSourceManager();
    const source: DataSource = {
      id: 'recent-posts',
      type: 'api',
      endpoint: '/api/posts',
      method: 'GET',
    } as any;

    await (manager as any).fetchApiDataSource(
      source,
      {},
      new URLSearchParams(),
      undefined,
      undefined,
    );

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const calledUrl = fetchMock.mock.calls[0][0];
    expect(calledUrl).toContain('/api/posts');
  });

  it('편집 모드(옵션 주입 + has=true): fetch 호출 없이 sampleProvider.resolve() 결과 반환', async () => {
    const sampleResponse = { data: [{ id: 1, title: '샘플 게시물' }] };
    const sampleProvider: SampleDataProvider = {
      has: vi.fn(() => true),
      resolve: vi.fn(() => sampleResponse),
    };

    const manager = new DataSourceManager({ sampleProvider });
    const source: DataSource = {
      id: 'recent-posts',
      type: 'api',
      endpoint: '/api/posts',
      method: 'GET',
    } as any;

    const result = await (manager as any).fetchApiDataSource(
      source,
      {},
      new URLSearchParams(),
      undefined,
      undefined,
    );

    expect(fetchMock).not.toHaveBeenCalled();
    expect(sampleProvider.resolve).toHaveBeenCalledWith(source);
    expect(result).toBe(sampleResponse);
  });

  it('편집 모드 (옵션 주입 + has=false): 폴백으로 일반 fetch 경로 진입', async () => {
    const sampleProvider: SampleDataProvider = {
      has: vi.fn(() => false),
      resolve: vi.fn(() => ({ data: [] })),
    };

    const manager = new DataSourceManager({ sampleProvider });
    const source: DataSource = {
      id: 'unsupported-source',
      type: 'api',
      endpoint: '/api/foo',
      method: 'GET',
    } as any;

    await (manager as any).fetchApiDataSource(
      source,
      {},
      new URLSearchParams(),
      undefined,
      undefined,
    );

    expect(sampleProvider.has).toHaveBeenCalledWith('unsupported-source');
    expect(sampleProvider.resolve).not.toHaveBeenCalled();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('actionDispatcher 옵션 주입 시: onSuccess 가 옵션 dispatcher 사용 (호스트 globalState 누수 방지)', async () => {
    // 라우트 12 글 수정 등 onSuccess 에 setState 가 있는 layout 의 호스트 #app
    // unmount 결함 회귀 가드. 옵션 dispatcher 가 주입되면 전역 getActionDispatcher()
    // 대신 본 dispatcher 가 사용되어야 한다.
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true, data: { posts: [{ id: 1 }] } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const dispatchAction = vi.fn().mockResolvedValue(undefined);
    const isolatedDispatcher = { dispatchAction } as any;

    const manager = new DataSourceManager({ actionDispatcher: isolatedDispatcher });
    const source = {
      id: 'form_data',
      type: 'api',
      endpoint: '/api/form-data',
      method: 'GET',
      auto_fetch: true,
      onSuccess: {
        handler: 'setState',
        params: { target: 'local', form: '{{response.data.posts}}' },
      },
    } as any;

    await manager.fetchDataSources([source]);

    // 옵션 dispatcher 의 dispatchAction 이 호출되어야 함
    expect(dispatchAction).toHaveBeenCalledTimes(1);
    expect(dispatchAction.mock.calls[0][0]).toMatchObject({ handler: 'setState' });
  });

  it('actionDispatcher 옵션 미주입 시: onSuccess 가 전역 getActionDispatcher() 사용 (일반 렌더 보존)', async () => {
    // 옵션 미전달 시 기존 동작 100% 보존 — 본 케이스는 ActionDispatcher 전역
    // 인스턴스가 미초기화일 수 있는 단위 환경에서 silent skip 으로 떨어지는 것을 확인.
    fetchMock.mockResolvedValueOnce(
      new Response(JSON.stringify({ success: true, data: { posts: [] } }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      }),
    );

    const manager = new DataSourceManager(); // 옵션 없음
    const source = {
      id: 'form_data',
      type: 'api',
      endpoint: '/api/form-data',
      method: 'GET',
      auto_fetch: true,
      onSuccess: {
        handler: 'setState',
        params: { target: 'local', form: '{{response.data.posts}}' },
      },
    } as any;

    // 에러 없이 완료되어야 함 (전역 dispatcher 미초기화 시 warn 후 스킵)
    await expect(manager.fetchDataSources([source])).resolves.toBeDefined();
  });

  it('sampleProvider.resolve 가 Promise 를 반환해도 await 으로 정상 처리', async () => {
    const sampleResponse = { data: [{ id: 99 }] };
    const sampleProvider: SampleDataProvider = {
      has: () => true,
      resolve: () => Promise.resolve(sampleResponse),
    };

    const manager = new DataSourceManager({ sampleProvider });
    const source: DataSource = {
      id: 'async-source',
      type: 'api',
      endpoint: '/api/anything',
      method: 'GET',
    } as any;

    const result = await (manager as any).fetchApiDataSource(
      source,
      {},
      new URLSearchParams(),
    );

    expect(result).toBe(sampleResponse);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  // 지연 소스(auto_fetch:false) 샘플 주입.
  // 편집기 캔버스는 정적 시뮬레이션이라 탭/모달 활성 액션을 실행할 수 없어, 페이지 상태로
  // 게이트를 열어도 지연 소스 데이터가 비어 본체가 미렌더된다(myComments 내댓글 서브탭).
  // 샘플 모드에서는 지연 소스도 fetchDataSources 처리 대상에 포함해 샘플을 주입한다.
  describe('지연 소스(auto_fetch:false) — 단계 E 게이트 본체 노출', () => {
    it('샘플 모드: auto_fetch:false 소스도 샘플이 주입된다(결과 맵에 포함)', async () => {
      const sampleResponse = { data: { data: [{ id: 1, content: '내 댓글' }] } };
      const sampleProvider: SampleDataProvider = {
        has: (id: string) => id === 'myComments',
        resolve: () => sampleResponse,
      };
      const manager = new DataSourceManager({ sampleProvider });
      const lazySource: DataSource = {
        id: 'myComments',
        type: 'api',
        endpoint: '/api/me/my-comments',
        method: 'GET',
        auto_fetch: false,
      } as any;

      const results = await manager.fetchDataSources([lazySource]);

      expect(results).toHaveProperty('myComments');
      expect(results.myComments).toBe(sampleResponse);
      expect(fetchMock).not.toHaveBeenCalled();
    });

    it('일반 렌더 모드(옵션 미주입): auto_fetch:false 소스는 fetch 대상에서 제외된다(회귀 가드)', async () => {
      const manager = new DataSourceManager();
      const lazySource: DataSource = {
        id: 'myComments',
        type: 'api',
        endpoint: '/api/me/my-comments',
        method: 'GET',
        auto_fetch: false,
      } as any;

      const results = await manager.fetchDataSources([lazySource]);

      // 런타임은 지연 소스를 초기 fetch 하지 않는다(탭 활성 시 refetch) — 기존 동작 보존.
      expect(results).not.toHaveProperty('myComments');
      expect(fetchMock).not.toHaveBeenCalled();
    });
  });
});
