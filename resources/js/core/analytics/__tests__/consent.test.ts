/**
 * 동의 브리지 — 조회·갱신·판정.
 *
 * 동의 여부를 모르는 상태에서 보내면 안 되고, 확인할 곳이 아예 없으면 막아서도
 * 안 된다(서버가 익명 모드로 내려가 개인정보 없이 수집한다). 두 상태를 가르는 것이
 * 이 브리지의 전부다.
 *
 * @scenario mode=consent
 * @effects consent_bridge_blocks_sending_until_consent_is_known,
 *   consent_bridge_does_not_block_when_there_is_no_consent_source,
 *   consent_bridge_updates_on_consent_change_events
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ANALYTICS_CONSENT_CHANGED_EVENT, ConsentBridge, consentGateRequired } from '../consent';

describe('consentGateRequired', () => {
  // @scenario mode=full|anonymous|consent
  // @effects consent_bridge_does_not_block_when_there_is_no_consent_source, consent_gate_applies_only_in_consent_mode
  it('동의 게이트는 플러그인이 있고 수집 모드가 동의 연동일 때만 선다', () => {
    // IP 저장·익명 모드는 동의를 게이트로 쓰지 않는다 — 플러그인이 있어도 막으면
    // 미동의 방문자(기본값)의 화면 이동이 통째로 빠진다.
    expect(consentGateRequired(true, 'full')).toBe(false);
    expect(consentGateRequired(true, 'anonymous')).toBe(false);
    expect(consentGateRequired(true, undefined)).toBe(false);
    expect(consentGateRequired(false, 'consent')).toBe(false);
    expect(consentGateRequired(true, 'consent')).toBe(true);
  });
});

describe('ConsentBridge', () => {
  beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { consent_source: 'provider', has_consent: true } }),
    }));
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  // @scenario mode=consent
  // @effects consent_bridge_does_not_block_when_there_is_no_consent_source
  it('동의를 확인할 곳이 없으면 막지 않는다', async () => {
    const bridge = new ConsentBridge(false);
    await bridge.start();

    // 서버가 익명 모드로 내려가므로 여기서 막으면 수집이 통째로 0 이 된다.
    expect(bridge.allowsSending()).toBe(true);
    expect(bridge.current().source).toBe('none');
    expect(fetch).not.toHaveBeenCalled();
  });

  // @scenario mode=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('확인할 곳이 있으면 코어 API 로 동의 상태를 읽는다', async () => {
    const bridge = new ConsentBridge(true);
    await bridge.start();

    expect(fetch).toHaveBeenCalledWith(
      '/api/analytics/consent',
      expect.objectContaining({ credentials: 'same-origin' }),
    );
    expect(bridge.allowsSending()).toBe(true);
    expect(bridge.current().granted).toBe(true);
  });

  // @scenario mode=consent, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('동의하지 않았으면 보내지 않는다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { consent_source: 'provider', has_consent: false } }),
    }));

    const bridge = new ConsentBridge(true);
    await bridge.start();

    expect(bridge.allowsSending()).toBe(false);
  });

  // @scenario mode=consent, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('조회에 실패하면 보내지 않는다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

    const bridge = new ConsentBridge(true);
    await bridge.start();

    // 「모름」을 「동의함」으로 해석하지 않는다.
    expect(bridge.allowsSending()).toBe(false);
  });

  // @scenario mode=consent
  // @effects tracker_swallows_transport_failures
  it('조회 실패가 예외로 번지지 않는다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('offline')));

    const bridge = new ConsentBridge(true);

    await expect(bridge.start()).resolves.toBeUndefined();
  });

  // @scenario mode=consent
  // @effects consent_bridge_updates_on_consent_change_events
  it('동의 변경 알림으로 갱신한다', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ data: { consent_source: 'provider', has_consent: false } }),
    }));

    const bridge = new ConsentBridge(true);
    await bridge.start();
    expect(bridge.allowsSending()).toBe(false);

    window.dispatchEvent(new CustomEvent(ANALYTICS_CONSENT_CHANGED_EVENT, {
      detail: { has_consent: true },
    }));

    expect(bridge.allowsSending()).toBe(true);

    bridge.stop();
  });

  // @scenario mode=consent
  // @effects consent_bridge_updates_on_consent_change_events
  it('구독을 해제하면 더 이상 갱신되지 않는다', async () => {
    const bridge = new ConsentBridge(true);
    await bridge.start();
    bridge.stop();

    window.dispatchEvent(new CustomEvent(ANALYTICS_CONSENT_CHANGED_EVENT, {
      detail: { has_consent: false },
    }));

    expect(bridge.current().granted).toBe(true);
  });
  // @scenario mode=consent, visitor=member
  // @effects consent_lookup_identifies_the_logged_in_member
  it('로그인 회원의 동의는 토큰을 실어야 그 회원 것으로 조회된다', async () => {
    const bridge = new ConsentBridge(true, () => 'member-token');
    await bridge.start();

    // 토큰이 없으면 서버가 게스트 세션으로 조회해, 동의한 회원도 미동의로 읽힌다.
    const headers = (fetch as any).mock.calls[0][1].headers;

    expect(headers.Authorization).toBe('Bearer member-token');
  });

  // @scenario mode=consent
  // @effects consent_bridge_updates_on_consent_change_events
  it('값을 싣지 않은 변경 알림이면 코어 API 로 다시 묻는다 (확장의 내부 모양을 추측하지 않는다)', async () => {
    const fetchMock = vi.fn()
      .mockResolvedValueOnce({ ok: true, json: async () => ({ data: { consent_source: 'provider', has_consent: false } }) })
      .mockResolvedValue({ ok: true, json: async () => ({ data: { consent_source: 'provider', has_consent: true } }) });
    vi.stubGlobal('fetch', fetchMock);

    const bridge = new ConsentBridge(true);
    await bridge.start();
    expect(bridge.allowsSending()).toBe(false);

    // 확장 고유 모양(categories)은 코어 계약이 아니다 — 읽지 않고 다시 묻는다.
    window.dispatchEvent(new CustomEvent(ANALYTICS_CONSENT_CHANGED_EVENT, {
      detail: { categories: { analytics: true } },
    }));
    await vi.waitFor(() => expect(bridge.allowsSending()).toBe(true));

    // 앞선 테스트가 남긴 구독자도 다시 물을 수 있으므로 하한만 본다.
    expect(fetchMock.mock.calls.length).toBeGreaterThanOrEqual(2);
    bridge.stop();
  });

  // @scenario mode=consent
  // @effects consent_bridge_updates_on_consent_change_events
  it('코어가 특정 확장의 이벤트·경로 이름을 쓰지 않는다', () => {
    expect(ANALYTICS_CONSENT_CHANGED_EVENT).toBe('g7:analytics-permission-changed');
  });
});
