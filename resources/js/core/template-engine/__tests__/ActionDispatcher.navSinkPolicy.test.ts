// e2e:allow 테스트 전용 수정 — engine-v1.75.3 이 외부 주소 새 창을 「빈 창 → opener 차단 → 주소 적재」로 바꾼 뒤 낡은 기대값을 정정. 런타임 동작 무변경이며 새 창 방식은 ActionDispatcher.hostTrust.test.ts 와 엔진 접합부 브라우저 매트릭스(2026-10-05)로 확인됨.
/**
 * ActionDispatcher 이동 sink 정책 테스트 (보안)
 *
 * 관리자 로그인 레이아웃은 `{{query.redirect}}` 를 그대로 `navigate` 로 넘긴다. 라우트가 맞지 않으면
 * `openWindow{target:'_self'}` 폴백 → `location.assign` 으로 흘러, `?redirect=javascript:…` 이 관리자
 * origin 에서 실행되고 `?redirect=//evil` 이 오프사이트로 나갔다. sink 자체가 판정해야 레이아웃이
 * 어떻게 적혀 있든 막힌다.
 *
 * @since engine-v1.73.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
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
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - 이동 sink 정책 (engine-v1.73.0)', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockMatch: ReturnType<typeof vi.fn>;
  let mockWindowOpen: ReturnType<typeof vi.fn>;
  let mockLocationAssign: ReturnType<typeof vi.fn>;
  let originalWindowOpen: typeof window.open;

  beforeEach(() => {
    mockNavigate = vi.fn();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);

    mockMatch = vi.fn();
    (window as any).__templateApp = {
      getRouter: () => ({
        match: mockMatch,
      }),
    };

    originalWindowOpen = window.open;
    mockWindowOpen = vi.fn();
    window.open = mockWindowOpen as any;

    // jsdom 의 location 은 spyOn/defineProperty 가 잠겨 있어 delete + 객체 교체로 우회한다.
    mockLocationAssign = vi.fn();
    delete (window as any).location;
    (window as any).location = {
      assign: mockLocationAssign,
      href: '',
      pathname: '',
      search: '',
      hash: '',
      origin: 'http://localhost',
    };
  });

  afterEach(() => {
    window.open = originalWindowOpen;
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  /**
   * @effects nav_sink_rejects_script_scheme
   */
  it('미매칭 폴백으로 흘러든 javascript: 경로는 location.assign 에 닿지 않고 / 로 간다', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: 'javascript:localStorage.setItem("g7_marker","TOKEN_READABLE")' },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockLocationAssign).toHaveBeenCalledTimes(1);
    expect(mockLocationAssign).toHaveBeenCalledWith('/');
  });

  /**
   * @effects nav_sink_rejects_offsite_target
   */
  it('미매칭 폴백으로 흘러든 외부·protocol-relative 경로는 오프사이트로 나가지 않는다 (open-redirect 차단)', async () => {
    mockMatch.mockReturnValue(null);

    for (const path of ['https://example.com/', '//example.com/', '/\\example.com/']) {
      mockLocationAssign.mockClear();
      await dispatcher.dispatchAction({ type: 'click', handler: 'navigate', params: { path } });
      expect(mockLocationAssign).toHaveBeenCalledWith('/');
    }
  });

  /**
   * @effects same_origin_redirect_preserved
   */
  it('미매칭 폴백의 same-origin 경로는 쿼리까지 그대로 이동한다 (정상 회귀)', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/admin/orders', query: { status: 'paid' } },
    });

    expect(mockLocationAssign).toHaveBeenCalledWith('/admin/orders?status=paid');
  });

  /**
   * @effects nav_sink_rejects_script_scheme, nav_sink_rejects_offsite_target
   */
  it('openWindow _self 를 직접 적어도 같은 판정을 거친다', async () => {
    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'openWindow',
      params: { path: 'javascript:alert(1)', target: '_self' },
    });
    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'openWindow',
      params: { path: '//evil.example/', target: '_self' },
    });

    expect(mockLocationAssign.mock.calls).toEqual([['/'], ['/']]);
  });

  /**
   * @effects dangerous_href_neutralized, external_link_preserved
   */
  it('openWindow _blank 는 실행 스킴만 막고 정상 외부 링크는 연다 (과차단 방지)', async () => {
    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'openWindow',
      params: { path: 'javascript:alert(1)', target: '_blank' },
    });
    expect(mockWindowOpen).not.toHaveBeenCalled();

    // 외부 주소는 빈 창을 먼저 열어 opener 를 끊은 뒤 싣는다(engine-v1.75.3) — 여는 방식이 바뀌어도
    // 「정상 외부 링크는 열린다」는 이 축의 단언은 그대로다. opener 차단 자체는 hostTrust 시험이 잠근다.
    const opened = { opener: window as unknown, location: { href: '' } };
    mockWindowOpen.mockReturnValue(opened);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'openWindow',
      params: { path: 'https://sir.kr/', target: '_blank' },
    });
    expect(mockWindowOpen).toHaveBeenCalledWith('', '_blank');
    expect(opened.location.href).toBe('https://sir.kr/');
  });
});
