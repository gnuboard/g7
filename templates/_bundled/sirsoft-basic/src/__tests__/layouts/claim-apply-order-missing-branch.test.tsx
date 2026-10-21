// e2e:allow 회원 클레임 신청 셸의 주문 404 분기 — 실제 엔진 데이터소스 오류 경로(DataSourceManager → 오류 처리기 → ActionDispatcher)로 고정. 브라우저 시나리오는 tests/Playwright/specs/claim-apply-order-missing.spec.ts
/**
 * @file claim-apply-order-missing-branch.test.tsx
 * @description 클레임 신청 셸의 주문 조회 404 를 주소(route.claim_number)로 가른다 (#753 X3)
 *
 * 작성 진입(`/mypage/orders/:order_number/claims/apply/:type`)의 404 는 열 수 없는 주문이라 오류 화면을 세운다.
 * 수정 진입(`/mypage/claims/:claim_number/edit`)은 주소에 주문번호가 없어 첫 호출이 반드시 404 이고 그것이
 * 정상 경로다 — 오류 화면을 세우면 수정 화면이 열리지 않는다.
 *
 * 종전에는 데이터소스 오류 처리가 conditions 를 버리고(키 4개만 옮겨 담음) 그 문맥에 route 도 없어서, 이 판정을
 * 이커머스 전용 핸들러(claimApplyOrderMissing)로 옮겨 우회했다. engine-v1.80.0 부터 오류 처리기를 통째로 실행하고
 * 문맥에 route·query 를 실어 레이아웃이 `<conditions>` 로 직접 가른다.
 *
 * 레이아웃 테스트 유틸은 데이터소스 오류 처리를 흉내 내지 않으므로, 이 화면의 실제 `order` 데이터소스 정의를
 * 실제 DataSourceManager 에 넣고 실제 ActionDispatcher(오류 처리기 연결 포함)로 실행한다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { DataSourceManager } from '@core/template-engine/DataSourceManager';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';
import { ErrorHandlingResolver } from '@core/error/ErrorHandlingResolver';
import { Logger } from '@core/utils/Logger';

import layout from '../../../layouts/mypage/claims/apply.json';

const apiGet = vi.fn();

vi.mock('@core/api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ get: apiGet, post: vi.fn(), getToken: vi.fn(() => 'token') })),
}));

vi.mock('@core/auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({ isAuthenticated: () => true, login: vi.fn(), logout: vi.fn() })),
  },
}));

type Node = Record<string, any>;

/** 이 셸의 실제 order 데이터소스 정의 */
const orderSource = ((layout as Node).data_sources as Node[]).find((s) => s.id === 'order') as Node;

let showErrorPage: ReturnType<typeof vi.fn>;
let suppress: ReturnType<typeof vi.fn>;

beforeEach(() => {
  ErrorHandlingResolver.resetInstance();
  Logger.getInstance().setDebug(false);
  apiGet.mockReset();
  apiGet.mockRejectedValue(Object.assign(new Error('Request failed with status code 404'), {
    response: { status: 404, data: { success: false, message: '주문을 찾을 수 없습니다.' } },
  }));

  // 실제 디스패처 — 생성자가 ErrorHandlingResolver 의 실행기를 이 디스패처로 연결한다
  const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
  showErrorPage = vi.fn();
  suppress = vi.fn();
  dispatcher.registerHandler('showErrorPage', showErrorPage);
  dispatcher.registerHandler('suppress', suppress);
});

/**
 * 주문 데이터소스를 주어진 라우트로 실제 실행하고, 비동기 오류 처리(blocking 은 기다리지 않음)가 끝날 때까지 흘려보낸다.
 *
 * @param routeParams 라우트 파라미터
 * @returns 데이터소스 실행 결과
 */
async function runOrderSource(routeParams: Record<string, string>) {
  const results = await new DataSourceManager().fetchDataSourcesWithResults([orderSource as any], routeParams, new URLSearchParams());
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }

  return results;
}

describe('클레임 신청 셸 — 주문 조회 404 분기 (#753)', () => {
  /**
   * @scenario entry=create
   * @effects create_404_shows_error_page
   */
  it('작성 진입(주소에 주문번호)의 404 는 오류 화면을 1번 세운다', async () => {
    const results = await runOrderSource({ order_number: 'NOPE753', type: 'return' });

    expect(results[0]).toMatchObject({ state: 'error', errorCode: 404 });
    expect(showErrorPage).toHaveBeenCalledTimes(1);
    expect(showErrorPage.mock.calls[0][0].params).toEqual({ errorCode: 404, target: 'content' });
    expect(suppress).not.toHaveBeenCalled();
  });

  /**
   * @scenario entry=edit
   * @effects edit_first_404_is_silent
   */
  it('수정 진입(주소에 신청서 번호)의 첫 404 는 조용히 넘긴다 — 오류 화면 0번', async () => {
    await runOrderSource({ claim_number: 'RT_PROBE753' });

    expect(showErrorPage).not.toHaveBeenCalled();
    expect(suppress).toHaveBeenCalledTimes(1);
  });
});
