/**
 * @file ActionDispatcher.navigateRefetchDataSources.test.ts
 * @description navigate(replace:true) 의 refetch_data_sources 가 updateQueryParams 의 재조회 대상으로 전달되는지 (engine-v1.79.0)
 *
 * 배경: 관리자 환경설정 본인인증 탭의 정책 목록 검색·필터·쪽 넘김이 navigate replace 로 쿼리를 바꾸면
 * 엔진이 auto_fetch 소스를 전부 다시 받아, 같은 탭 위쪽 설정 칸에서 고친 값이 서버 값으로 돌아가고
 * hasChanges 가 풀렸다. 목록 조작은 refetch_data_sources 로 목록 소스만 다시 받는다.
 *
 * [case:state-global-tab-refetch-overlay-overlap]
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn(() => ({ login: vi.fn(), logout: vi.fn() })) },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn() })),
}));

describe('navigate replace — refetch_data_sources 전달', () => {
  let dispatcher: ActionDispatcher;
  let updateQueryParams: ReturnType<typeof vi.fn>;

  const trigger = async (params: Record<string, any>) => {
    const action: ActionDefinition = { type: 'click', handler: 'navigate', params };
    const handler = dispatcher.createHandler(action);
    await handler({ preventDefault: vi.fn(), type: 'click', target: null } as unknown as Event);
  };

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    updateQueryParams = vi.fn().mockResolvedValue(undefined);
    (window as any).G7Core = { updateQueryParams };
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  });

  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  // @scenario case=navigate_param_string
  // @effects navigate_refetch_param_forwarded
  it('문자열(쉼표 구분)을 id 목록으로 바꿔 transition_overlay_target 과 함께 넘긴다', async () => {
    await trigger({
      path: '/admin/settings',
      replace: true,
      query: { tab: 'identity', page: 2 },
      transition_overlay_target: 'policy_table_card',
      refetch_data_sources: 'policies, purposes',
    });
    expect(updateQueryParams).toHaveBeenCalledWith('/admin/settings?tab=identity&page=2', {
      transitionOverlayTarget: 'policy_table_card',
      refetchDataSourceIds: ['policies', 'purposes'],
    });
  });

  // @scenario case=navigate_param_array
  // @effects navigate_refetch_param_forwarded
  it('배열도 받는다', async () => {
    await trigger({ path: '/admin/settings', replace: true, query: { tab: 'identity' }, refetch_data_sources: ['policies'] });
    expect(updateQueryParams).toHaveBeenCalledWith('/admin/settings?tab=identity', { refetchDataSourceIds: ['policies'] });
  });

  // @scenario case=navigate_param_absent
  // @effects single_call_unchanged
  it('지정하지 않으면 종전과 같이 옵션 없이 부른다(전체 재조회)', async () => {
    await trigger({ path: '/admin/settings', replace: true, query: { tab: 'general' } });
    expect(updateQueryParams).toHaveBeenCalledWith('/admin/settings?tab=general', undefined);
  });

  // @scenario case=navigate_param_blank
  // @effects single_call_unchanged
  it('빈 문자열이면 지정하지 않은 것과 같다', async () => {
    await trigger({ path: '/admin/settings', replace: true, query: { tab: 'general' }, refetch_data_sources: ' ' });
    expect(updateQueryParams).toHaveBeenCalledWith('/admin/settings?tab=general', undefined);
  });
});
