/**
 * DataSourceManager — 요청 위생 회귀 테스트 (engine-v1.79.0)
 *
 * 1) 값이 없는(undefined·null) GET 파라미터는 쿼리스트링에 싣지 않는다 — 종전에는 일반 fetch 경로가
 *    `new URLSearchParams(params)` 로 만들어 `page="{{query.page}}"` 를 쿼리 없이 열면
 *    `?page=undefined` 가 나가 422 가 났다(7.1.0 실측 E-M3-1).
 * 2) 레이아웃이 suppress 로 처리한 실패와, 토큰이 없어 보내지 않고 건너뛴 인증 필요 요청은
 *    예정된 흐름이라 디버그 콘솔에 error 로 남기지 않는다 — 종전에는 비회원 화면마다
 *    「Auth required but not authenticated」 3건(O-K1-2), 연결 실패를 suppress 한 알림 템플릿
 *    편집 창에서 2건(E-M2-1)이 console.error 로 찍혔다. 처리기가 없거나 화면에 알리는 실패는 종전대로 error.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { DataSourceManager, DataSource } from '../DataSourceManager';
import { getErrorHandlingResolver } from '../../error';
import Logger from '../../utils/Logger';

const mockIsAuthenticated = vi.fn(() => false);
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      isAuthenticated: mockIsAuthenticated,
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ get: vi.fn(), post: vi.fn() })),
}));

global.fetch = vi.fn();

/**
 * 실패 응답을 흉내 낸 fetch 결과를 만든다.
 *
 * @param status HTTP 상태 코드
 * @return fetch 가 돌려줄 응답 모양
 */
function failedResponse(status: number) {
  return {
    ok: false,
    status,
    statusText: 'Error',
    json: () => Promise.resolve({ message: '비즈뿌리오 서버에 연결할 수 없습니다.' }),
    headers: new Headers({ 'content-type': 'application/json' }),
  };
}

/**
 * console.error 호출 중 DataSourceManager 접두가 붙은 것만 센다.
 *
 * @param spy console.error 스파이
 * @return DataSourceManager 가 남긴 error 줄 수
 */
function dataSourceErrorLines(spy: ReturnType<typeof vi.spyOn>): number {
  return spy.mock.calls.filter((args) => String(args[0]).includes('[DataSourceManager]')).length;
}

describe('DataSourceManager 요청 위생', () => {
  let manager: DataSourceManager;
  let errorSpy: ReturnType<typeof vi.spyOn>;
  const executor = vi.fn(async () => undefined);

  beforeEach(() => {
    vi.clearAllMocks();
    mockIsAuthenticated.mockReturnValue(false);
    manager = new DataSourceManager();
    getErrorHandlingResolver().setActionExecutor(executor);
    Logger.setDebug(true);
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  afterEach(() => {
    errorSpy.mockRestore();
    Logger.setDebug(false);
    manager.clearCache();
  });

  describe('값이 없는 GET 파라미터', () => {
    /**
     * @scenario resolution_path=public_fetch
     * @effects undefined_or_null_get_params_left_out_of_query_string
     */
    it('쿼리에 없는 {{query.page}} 는 "undefined" 문자열로 나가지 않는다', async () => {
      (global.fetch as any).mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });

      const sources: DataSource[] = [
        {
          id: 'memos',
          type: 'api',
          endpoint: '/api/modules/sample/memos',
          method: 'GET',
          auto_fetch: true,
          params: { page: '{{query.page}}', per_page: 10, sort: '{{query.sort ?? null}}' } as any,
        },
      ];

      await manager.fetchDataSources(sources, {}, new URLSearchParams());

      const url = (global.fetch as any).mock.calls[0][0] as string;
      expect(url).toBe('/api/modules/sample/memos?per_page=10');
      expect(url).not.toContain('undefined');
      expect(url).not.toContain('null');
    });

    /**
     * @scenario resolution_path=public_fetch
     * @effects undefined_or_null_get_params_left_out_of_query_string
     */
    it('값이 있는 파라미터와 0·false 는 그대로 싣는다', async () => {
      (global.fetch as any).mockResolvedValue({ ok: true, json: async () => ({ data: [] }) });

      const sources: DataSource[] = [
        {
          id: 'memos',
          type: 'api',
          endpoint: '/api/modules/sample/memos',
          method: 'GET',
          auto_fetch: true,
          params: { page: '{{query.page}}', offset: 0, unread: false } as any,
        },
      ];

      await manager.fetchDataSources(sources, {}, new URLSearchParams('page=3'));

      expect((global.fetch as any).mock.calls[0][0]).toBe('/api/modules/sample/memos?page=3&offset=0&unread=false');
    });
  });

  describe('예정된 실패의 콘솔 기록', () => {
    const suppressed422: DataSource = {
      id: 'bizppurio_categories',
      type: 'api',
      endpoint: '/api/plugins/sample/categories',
      method: 'GET',
      auto_fetch: true,
      errorHandling: { 422: { handler: 'suppress' } } as any,
      fallback: { data: [] },
    };

    /**
     * @scenario resolution_path=public_fetch
     * @effects suppressed_failure_not_logged_as_console_error
     */
    it('suppress 로 처리한 실패는 console.error 를 남기지 않는다 (fetchDataSourcesWithResults)', async () => {
      (global.fetch as any).mockResolvedValueOnce(failedResponse(422));

      const results = await manager.fetchDataSourcesWithResults([suppressed422]);

      expect(executor).toHaveBeenCalledWith(expect.objectContaining({ handler: 'suppress' }), expect.anything());
      expect(results[0].data).toEqual({ data: [] });
      expect(dataSourceErrorLines(errorSpy)).toBe(0);
    });

    /**
     * @scenario resolution_path=public_fetch
     * @effects suppressed_failure_not_logged_as_console_error
     */
    it('suppress 로 처리한 실패는 console.error 를 남기지 않는다 (fetchDataSources)', async () => {
      (global.fetch as any).mockResolvedValueOnce(failedResponse(422));

      await manager.fetchDataSources([suppressed422]);

      expect(dataSourceErrorLines(errorSpy)).toBe(0);
    });

    /**
     * @scenario resolution_path=public_fetch
     * @effects suppressed_failure_not_logged_as_console_error
     */
    it('suppress 로 처리한 실패는 console.error 를 남기지 않는다 (background)', async () => {
      (global.fetch as any).mockResolvedValueOnce(failedResponse(422));
      const onUpdate = vi.fn();

      manager.fetchDataSourcesInBackground(
        [{ ...suppressed422, loading_strategy: 'background' } as DataSource],
        {},
        new URLSearchParams(),
        onUpdate,
      );
      await vi.waitFor(() => expect(onUpdate).toHaveBeenCalled());

      expect(dataSourceErrorLines(errorSpy)).toBe(0);
    });

    /**
     * @scenario resolution_path=auth_skipped_no_token
     * @effects auth_skipped_request_not_logged_as_console_error
     */
    it('비회원이라 건너뛴 인증 필요 요청은 console.error 를 남기지 않는다', async () => {
      const sources: DataSource[] = ['current_user', 'notification_unread_count', 'notifications'].map((id) => ({
        id,
        type: 'api',
        endpoint: `/api/${id}`,
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
        loading_strategy: 'progressive',
        errorHandling: { 401: { handler: 'suppress' } } as any,
        fallback: { data: [] },
      }));

      const results = await manager.fetchDataSourcesWithResults(sources);

      expect(global.fetch).not.toHaveBeenCalled();
      expect(results.map((r) => r.state)).toEqual(['success', 'success', 'success']);
      expect(dataSourceErrorLines(errorSpy)).toBe(0);
    });

    /**
     * @scenario resolution_path=public_fetch
     * @effects unhandled_failure_still_logged_as_console_error
     */
    it('suppress 하지 않은 실패는 종전대로 console.error 를 남긴다', async () => {
      (global.fetch as any).mockResolvedValueOnce(failedResponse(500));

      await manager.fetchDataSourcesWithResults([
        { id: 'broken', type: 'api', endpoint: '/api/broken', method: 'GET', auto_fetch: true },
      ]);

      expect(dataSourceErrorLines(errorSpy)).toBe(1);
    });
  });
});
