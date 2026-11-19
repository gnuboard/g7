/**
 * @file admin-user-detail-route-id.test.ts
 * @description 관리자 회원 상세에 주입한 소셜 연결 데이터소스가 회원 주소가 잘못된 화면에서 오류 토스트를 띄우지 않는지 본다.
 *
 * 회원 상세는 주소의 회원 식별자(uuid)로 두 요청을 동시에 보낸다 — 화면의 회원 조회(`user`)와 이 플러그인이 주입한 소셜 연결
 * 목록(`socialUserAccounts`). 회원 조회의 404 는 화면을 오류 페이지로 바꾸지만, 소셜 연결 목록의 404 는 처리기가 없어 시스템
 * 기본 처리(오류 토스트에 응답 문구)로 떨어졌다. 그래서 `/admin/users/1` 처럼 uuid 꼴이 아닌 주소에서는 플러그인 라우트의 uuid
 * 제약에 걸린 Laravel 원문(「The route … could not be found.」)이, 없는 회원 uuid 에서는 「사용자를 찾을 수 없습니다.」가
 * 오류 페이지 위에 토스트로 떴다.
 *
 * 실제 앱이 화면에 들어올 때 쓰는 엔진 경로를 그대로 탄다 — 구운 화면(admin_basic 회원 상세)의 데이터소스와 구운 주입 조각의
 * 데이터소스를 합쳐 `DataSourceManager.filterByCondition`(주소로 데이터소스 고르기) → `fetchDataSources`(요청과 오류 처리기
 * 결정·실행)를 돌리고, 오류 처리기 실행기는 실행된 처리기를 기록한다. 서버 응답은 라우트 제약과 같은 규칙으로 흉내 낸다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

const apiGet = vi.fn();

vi.mock('@core/api/ApiClient', () => ({
  getApiClient: () => ({ get: apiGet, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() }),
}));

import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { AuthManager } from '@core/auth/AuthManager';
import { getErrorHandlingResolver } from '@core/error';

const ROOT = path.resolve(__dirname, '../../../..');
const PROJECT = path.resolve(ROOT, '../../..');
const read = (abs: string): any => JSON.parse(fs.readFileSync(abs, 'utf8'));

/** 구운 화면(관리자 회원 상세)과 구운 주입 조각 */
const hostLayout = read(path.join(PROJECT, 'templates/_bundled/sirsoft-admin_basic/layouts/admin_user_detail.json'));
const extension = read(path.join(ROOT, 'resources/extensions/admin_user_detail_social_tab.json'));

const KNOWN_UUID = '0f8fad5b-d9cb-469f-a165-70867728950e';
const MISSING_UUID = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
const UUID_SHAPE = /^[0-9a-fA-F-]{36}$/;

/**
 * 서버 응답 흉내 — 플러그인 라우트는 uuid 제약(`[0-9a-fA-F-]{36}`)에 안 맞으면 라우트 없음 404(Laravel 원문),
 * 맞지만 없는 회원이면 「사용자를 찾을 수 없습니다.」 404, 아는 회원이면 연결 목록.
 *
 * @param url ApiClient 기준 주소(`/api` 뺀 것)
 * @returns 응답 본문
 */
async function fakeServer(url: string): Promise<any> {
  const notFound = (message: string) => Promise.reject({ response: { status: 404, statusText: 'Not Found', data: { success: false, message } } });

  const social = url.match(/^\/plugins\/sirsoft-social_login\/admin\/users\/([^/]+)\/accounts$/);
  if (social) {
    if (!UUID_SHAPE.test(social[1])) {
      return notFound(`The route api${url} could not be found.`);
    }
    if (social[1] !== KNOWN_UUID) {
      return notFound('사용자를 찾을 수 없습니다.');
    }

    return { success: true, data: { has_password: true, accounts: [{ provider_key: 'kakao', label: '카카오' }], abilities: { can_unlink: true } } };
  }

  // 회원 상세의 회원 항목 계약(7.2.0) — 회원 조회와 같은 규칙. 403, 404 는 화면이 조용히 넘긴다(회원 조회가 오류 페이지를 보인다)
  const fields = url.match(/^\/admin\/users\/([^/]+)\/form-fields$/);
  if (fields) {
    if (fields[1] !== KNOWN_UUID) {
      return notFound('사용자를 찾을 수 없습니다.');
    }

    return { success: true, data: { fields: [], member: {} } };
  }

  const user = url.match(/^\/admin\/users\/([^/]+)$/);
  if (user) {
    if (user[1] !== KNOWN_UUID) {
      return notFound('사용자를 찾을 수 없습니다.');
    }

    return { success: true, data: { uuid: KNOWN_UUID, name: '회원' } };
  }

  return notFound(`The route api${url} could not be found.`);
}

/** 실행된 오류 처리기 기록 */
let executed: Array<{ handler: string; message?: string; status?: number }> = [];

/**
 * 회원 상세 화면에 들어가는 것처럼 데이터소스를 고르고 받는다.
 *
 * @param id 주소의 회원 식별자
 * @returns 받은 값과 요청 주소 목록
 */
async function enter(id: string): Promise<{ results: Record<string, any>; requested: string[] }> {
  const manager = new DataSourceManager();
  const raw = [...(hostLayout.data_sources ?? []), ...(extension.data_sources ?? [])];
  const route = { id };
  const selected = manager.filterByCondition(raw, { route, query: {}, _global: {} });
  const results = await manager.fetchDataSources(selected, route);

  return { results, requested: apiGet.mock.calls.map((c) => String(c[0])) };
}

beforeEach(() => {
  executed = [];
  apiGet.mockReset();
  apiGet.mockImplementation(fakeServer);
  vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
  getErrorHandlingResolver().setActionExecutor(async (handler: any, ctx: any) => {
    executed.push({ handler: handler.handler, message: ctx?.error?.message, status: ctx?.error?.status });
  });
});

afterEach(() => {
  vi.restoreAllMocks();
});

describe('관리자 회원 상세 — 주소의 회원 식별자가 잘못된 화면', () => {
  /**
   * @scenario surface=user_detail_tab, route_id=non_uuid
   * @effects detail_source_skipped_for_non_uuid_route, detail_not_found_without_toast
   */
  it('uuid 꼴이 아닌 주소(/admin/users/1)에서는 소셜 연결 목록을 요청하지 않고, 화면은 오류 페이지로 바뀔 뿐 토스트가 없다', async () => {
    const { requested } = await enter('1');

    expect(executed.filter((e) => e.handler === 'toast')).toEqual([]);
    expect(requested).toContain('/admin/users/1');
    expect(requested.filter((u) => u.includes('sirsoft-social_login'))).toEqual([]);
    expect(executed.some((e) => e.handler === 'showErrorPage' && e.status === 404)).toBe(true);
  });

  /**
   * @scenario surface=user_detail_tab, route_id=missing_uuid
   * @effects detail_not_found_without_toast
   */
  it('없는 회원 uuid 주소에서는 소셜 연결 목록의 404 를 조용히 넘긴다 — 오류 페이지 위에 토스트를 겹쳐 띄우지 않는다', async () => {
    const { requested } = await enter(MISSING_UUID);

    expect(requested).toContain(`/plugins/sirsoft-social_login/admin/users/${MISSING_UUID}/accounts`);
    expect(executed.filter((e) => e.handler === 'toast')).toEqual([]);
    expect(executed.some((e) => e.handler === 'showErrorPage' && e.status === 404)).toBe(true);
  });
});

describe('관리자 회원 상세 — 정상 회원', () => {
  /**
   * @scenario surface=user_detail_tab, route_id=uuid
   * @effects detail_tab_and_content_linked
   */
  it('정상 회원 uuid 주소에서는 소셜 연결 목록을 받아 탭에 싣고 오류 처리기는 돌지 않는다', async () => {
    const { results, requested } = await enter(KNOWN_UUID);

    expect(requested).toContain(`/plugins/sirsoft-social_login/admin/users/${KNOWN_UUID}/accounts`);
    expect(results.socialUserAccounts?.data?.accounts).toHaveLength(1);
    expect(executed).toEqual([]);
  });

  /**
   * @scenario surface=user_detail_tab, route_id=uuid
   * @effects detail_forbidden_notice_kept
   */
  it('연결 열람 권한이 없어 403 이면 지금처럼 안내 상태만 켠다(토스트 없음)', async () => {
    apiGet.mockImplementation(async (url: string) => {
      if (url.includes('sirsoft-social_login')) {
        return Promise.reject({ response: { status: 403, data: { success: false, message: '권한이 없습니다.' } } });
      }

      return fakeServer(url);
    });

    await enter(KNOWN_UUID);

    expect(executed.map((e) => e.handler)).toEqual(['setState']);
  });
});
