// e2e:allow 엔진 데이터소스·오류 처리기 경로를 실제 클래스로 태운 회귀 — 비회원 주문조회 → 상세 화면의 브라우저 재측정은 g7_2 W3 최종 리허설 몫(7.1.0 출시 최종 수정 긴급 배정)
/**
 * @file guestOrderShowNoLoginRedirect.test.ts
 * @description 비회원 주문 상세에서 NHN KCP 조각의 모의입금 정보 소스가 로그인 화면으로 튕기지 않는지
 *
 * 회귀 배경(7.1.0 W3 중간 리허설 실측): 비회원이 「주문조회」로 조회에 성공해도 주문 상세 대신 로그인 화면으로
 * 이동했다(NHN KCP 플러그인이 켜져 있으면 다른 결제대행사 주문에서도). 결제 정보 카드 슬롯 조각(user_order_show)의
 * 데이터 소스 `nhnkcp_mock_deposit` 은 로그인 전용 주소라 `auth_mode="required"` 인데, 엔진은 토큰이 없으면 요청을
 * 건너뛰고 그 사실을 401 로 알린다. 소스에 401 처리기가 없어 템플릿 기본 401 처리(로그인 이동)로 떨어졌다.
 * 이 조각이 비회원 상세의 같은 슬롯에도 붙게 되면서(결제 정보 카드 슬롯 공용화) 드러났다.
 *
 * 고정하는 것: 비회원(토큰 없음)일 때 이 소스의 실패가 화면 이동을 부르지 않는다 — 엔진의 실제 데이터 소스 관리자와
 * 오류 처리기 결정 경로를 태우고, 템플릿 기본 401 처리를 「로그인 이동」으로 둔 채 검사한다.
 *
 * @scenario-manifest tests/scenarios/vbank-mock-deposit.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import extension from '../../../extensions/user_order_show.json';

const mockIsAuthenticated = vi.fn(() => false);
vi.mock('../../../../../../../resources/js/core/auth/AuthManager', () => ({
  AuthManager: { getInstance: vi.fn(() => ({ isAuthenticated: mockIsAuthenticated })) },
}));

const { DataSourceManager } = await import('../../../../../../../resources/js/core/template-engine/DataSourceManager');
const { getErrorHandlingResolver } = await import('../../../../../../../resources/js/core/error');

/** 이 조각의 모의입금 정보 데이터 소스(굽기 산출 JSON 그대로) */
const source = ((extension as any).data_sources ?? []).find((d: any) => d.id === 'nhnkcp_mock_deposit');

/** 템플릿 기본 오류 처리 — 사용자 템플릿처럼 401 이면 로그인 화면으로 보낸다 */
const TEMPLATE_DEFAULTS = { 401: { handler: 'navigate', params: { path: '/login' } } };

describe('비회원 주문 상세 — NHN KCP 모의입금 정보 소스', () => {
  let executor: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    executor = vi.fn(async () => undefined);
    const resolver = getErrorHandlingResolver();
    resolver.setTemplateConfig(TEMPLATE_DEFAULTS as any);
    resolver.setLayoutConfig(null);
    resolver.setActionExecutor(executor as any);
    global.fetch = vi.fn() as any;
  });

  afterEach(() => {
    getErrorHandlingResolver().setTemplateConfig(null as any);
    mockIsAuthenticated.mockReset();
    mockIsAuthenticated.mockReturnValue(false);
  });

  // @scenario case=guest_order_show
  // @effects guest_order_show_does_not_redirect_to_login
  it('비회원(토큰 없음)이면 요청을 건너뛰되 로그인 화면으로 보내지 않는다', async () => {
    expect(source, '모의입금 정보 데이터 소스를 찾지 못함').toBeTruthy();

    await new DataSourceManager().fetchDataSources([source], { order_number: 'ORD-GUEST-1' });

    expect(global.fetch).not.toHaveBeenCalled();
    const navigations = executor.mock.calls.filter(([handler]: any[]) => handler?.handler === 'navigate');
    expect(navigations, '비회원 주문 상세에서 로그인 이동이 실행됐다').toEqual([]);
  });

  // @scenario case=guest_order_show
  // @effects guest_order_show_does_not_redirect_to_login
  it('이 소스의 401·403 은 조각 안에서 조용히 넘긴다(템플릿 기본 처리로 떨어지지 않는다)', () => {
    const resolver = getErrorHandlingResolver();

    for (const code of [401, 403]) {
      const result = resolver.resolve(code, { errorHandling: source.errorHandling, onError: source.onError });
      expect({ code, handler: result.handler?.handler, level: result.level }).toEqual({ code, handler: 'suppress', level: 'action' });
    }
  });
});
