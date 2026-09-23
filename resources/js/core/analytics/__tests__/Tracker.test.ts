/**
 * 트래커 — 전송 방식·첫 비콘·체류 시간·예외 격리.
 *
 * 이 트래커는 사이트 동작에 어떤 영향도 주지 않아야 한다. 전송이 실패해도, 라우터가
 * 핸들러를 기다려도, 화면은 그대로 굴러가야 한다. 그 성질은 「아무 일도 안 일어남」
 * 으로 보이므로 깨져도 눈에 띄지 않는다 — 여기서 직접 단언한다.
 *
 * @scenario source=beacon, dispatch=sync, visit_state=new|open
 * @effects tracker_sends_beacon_on_route_change, only_the_first_beacon_carries_init_and_referrer,
 *   tracker_reports_previous_page_dwell_time, tracker_caps_dwell_time_at_the_configured_maximum,
 *   tracker_attaches_bearer_token_when_present, tracker_defers_sending_so_navigation_is_not_delayed,
 *   tracker_swallows_transport_failures, tracker_skips_excluded_requests_before_sending,
 *   download_and_outlink_actions_store_target_in_dictionary
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AnalyticsTracker } from '../Tracker';

/**
 * jsdom 의 navigator 에는 doNotTrack 이 없어 spyOn 이 통하지 않는다.
 * 속성을 직접 정의해 브라우저의 추적 거부 상태를 재현한다.
 *
 * @param value 추적 거부 값 ('1' 이면 거부)
 */
function setDoNotTrack(value: string | null): void {
  Object.defineProperty(navigator, 'doNotTrack', {
    value,
    configurable: true,
    writable: true,
  });
}

/** 기본 수집 설정 */
const SETTINGS = { enabled: true, track_downloads: true, track_outlinks: true };

describe('AnalyticsTracker', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    vi.useFakeTimers();

    Object.defineProperty(document, 'cookie', { value: '', writable: true, configurable: true });
    setDoNotTrack(null);

    fetchMock = vi.fn().mockResolvedValue({ ok: true });
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('Request', class {
      keepalive = false;
    });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  /** 예약된 전송을 실행하고 마지막 페이로드를 돌려준다 */
  const flush = (): Record<string, unknown> | null => {
    vi.runAllTimers();

    if (fetchMock.mock.calls.length === 0) return null;

    const last = fetchMock.mock.calls[fetchMock.mock.calls.length - 1];

    return JSON.parse(last[1].body);
  };

  // @scenario mode=full, source=beacon, visit_state=new
  // @effects consent_gate_applies_only_in_consent_mode, tracker_sends_beacon_on_route_change
  it('동의 플러그인이 있어도 IP 저장 모드에서는 동의 확인 없이 보낸다', () => {
    const tracker = new AnalyticsTracker({
      settings: { ...SETTINGS, privacy_mode: 'full' },
      consentPluginActive: true,
    });

    // start() 를 부르지 않았으므로 동의 상태는 「모름」이다. full 모드는 그 상태에서도 보내야 한다.
    tracker.trackPageview({ path: '/' });

    expect(flush()?.t).toBe('pageview');
  });

  // @scenario mode=consent, source=beacon, exclusion=consent
  // @effects consent_bridge_blocks_sending_until_consent_is_known
  it('동의 연동 모드에서는 동의를 확인하기 전까지 보내지 않는다', () => {
    const tracker = new AnalyticsTracker({
      settings: { ...SETTINGS, privacy_mode: 'consent' },
      consentPluginActive: true,
    });

    tracker.trackPageview({ path: '/' });

    expect(flush()).toBeNull();
  });

  // @scenario mode=consent, source=beacon, visit_state=new
  // @effects consented_visitors_first_page_is_held_until_consent_is_known
  it('동의 조회보다 먼저 온 첫 화면은 버리지 않고 들고 있다가, 동의로 확인되면 보내고 아니면 버린다', async () => {
    // 첫 화면 비콘은 부팅 직후 동기로 나가 동의 조회보다 늘 먼저다. 버리면 동의한 방문자가
    // 새로 들어오거나 새로고침한 화면이 한 건도 안 남는다(#682 S3 라운드 13 브라우저 실측).
    const run = async (consents: Record<string, boolean> | 'fail') => {
      fetchMock.mockReset();
      fetchMock.mockImplementation(async (url: string) => {
        if (String(url).includes('/consent/cookie/status')) {
          if (consents === 'fail') throw new Error('network');

          return { ok: true, json: async () => ({ data: { consents } }) };
        }

        return { ok: true };
      });

      const tracker = new AnalyticsTracker({
        settings: { ...SETTINGS, privacy_mode: 'consent' },
        consentPluginActive: true,
      });

      tracker.trackPageview({ path: '/' });
      vi.runAllTimers();
      const beforeSettle = fetchMock.mock.calls.filter(([u]) => String(u).includes('/api/analytics/collect')).length;

      await tracker.start();
      vi.runAllTimers();

      const sent = fetchMock.mock.calls.filter(([u]) => String(u).includes('/api/analytics/collect'));
      tracker.stop();

      return { beforeSettle, sent: sent.map(([, init]) => JSON.parse(init.body)) };
    };

    const granted = await run({ analytics: true });
    expect(granted.beforeSettle, '조회가 끝나기 전에는 보내지 않는다').toBe(0);
    expect(granted.sent).toHaveLength(1);
    expect(granted.sent[0]).toMatchObject({ t: 'pageview', init: true });

    expect((await run({ analytics: false })).sent, '미동의면 들고 있던 비콘을 버린다').toHaveLength(0);
    expect((await run('fail')).sent, '조회에 실패해도 동의로 단정하지 않는다').toHaveLength(0);
  });

  // @scenario source=beacon, visit_state=new
  // @effects tracker_sends_beacon_on_route_change
  it('keepalive 를 지원하지 않으면 sendBeacon 으로 보낸다', () => {
    vi.stubGlobal('Request', class {});
    const sendBeacon = vi.fn().mockReturnValue(true);
    Object.defineProperty(navigator, 'sendBeacon', { value: sendBeacon, configurable: true, writable: true });

    const tracker = new AnalyticsTracker({ settings: SETTINGS });
    tracker.trackPageview({ path: '/' });
    vi.runAllTimers();

    expect(fetchMock).not.toHaveBeenCalled();
    expect(sendBeacon).toHaveBeenCalledTimes(1);
    expect(sendBeacon.mock.calls[0][0]).toBe('/api/analytics/collect');
  });

  // @scenario source=beacon, visit_state=new
  // @effects tracker_sends_beacon_on_route_change, only_the_first_beacon_carries_init_and_referrer
  it('화면 열람을 알리고 첫 비콘에만 init 를 싣는다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/', meta: { title: '홈' } });
    const first = flush();

    expect(first?.t).toBe('pageview');
    expect(first?.init).toBe(true);
    expect(first?.title).toBe('홈');

    tracker.trackPageview({ path: '/board', meta: { title: '게시판' } });
    const second = flush();

    expect(second?.init).toBe(false);
  });

  // @scenario source=beacon, visit_state=open
  // @effects repeated_navigation_to_the_same_page_counts_once
  it('같은 화면으로의 되풀이 이동(메뉴 더블클릭)이 1초 안에 오면 한 번만 센다', () => {
    // 브라우저 실측(§8 M1-T10): 메뉴 버튼 더블클릭이 같은 주소로 두 번 이동해 페이지뷰가 2건 나갔다.
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/board/free' });
    tracker.trackPageview({ path: '/board/free' });
    vi.runAllTimers();
    expect(fetchMock).toHaveBeenCalledTimes(1);

    // 창이 지나면 같은 화면을 다시 연 것은 새 열람이다.
    vi.advanceTimersByTime(1500);
    tracker.trackPageview({ path: '/board/free' });
    vi.runAllTimers();
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  // @scenario source=beacon, visit_state=open
  // @effects tracker_reports_previous_page_dwell_time
  it('직전 페이지 체류 시간을 다음 비콘에 싣는다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/' });
    flush();

    vi.advanceTimersByTime(42_000);

    tracker.trackPageview({ path: '/next' });
    const payload = flush();

    expect(payload?.prev).toBe(42);
  });

  // @scenario source=beacon, visit_state=open
  // @effects tracker_caps_dwell_time_at_the_configured_maximum
  it('체류 시간을 상한으로 자른다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/' });
    flush();

    // 탭을 하루 열어 둔 방문자. 그대로 실으면 평균 체류가 의미를 잃는다.
    vi.advanceTimersByTime(86_400_000);

    tracker.trackPageview({ path: '/next' });

    expect(flush()?.prev).toBe(1800);
  });

  // @scenario source=beacon, visit_state=new
  // @effects only_the_first_beacon_carries_init_and_referrer
  it('첫 비콘에만 유입 정보를 싣는다', () => {
    Object.defineProperty(document, 'referrer', {
      value: 'https://www.google.com/',
      configurable: true,
    });

    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/' });
    expect(flush()?.ref).toBe('https://www.google.com/');

    // SPA 이동의 referrer 는 자기 사이트다 — 실으면 유입 1위가 자기 자신이 된다.
    tracker.trackPageview({ path: '/next' });
    expect(flush()?.ref).toBeUndefined();
  });

  // @scenario source=beacon, visitor=member
  // @effects tracker_attaches_bearer_token_when_present
  it('인증 토큰이 있으면 헤더에 싣는다', () => {
    const tracker = new AnalyticsTracker({
      settings: SETTINGS,
      getAuthToken: () => 'test-token',
    });

    tracker.trackPageview({ path: '/' });
    vi.runAllTimers();

    const headers = fetchMock.mock.calls[0][1].headers;
    expect(headers.Authorization).toBe('Bearer test-token');
  });

  // @scenario source=beacon, visitor=guest
  // @effects tracker_attaches_bearer_token_when_present
  it('토큰이 없으면 인증 헤더를 붙이지 않는다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS, getAuthToken: () => null });

    tracker.trackPageview({ path: '/' });
    vi.runAllTimers();

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBeUndefined();
  });

  // @scenario source=beacon
  // @effects tracker_defers_sending_so_navigation_is_not_delayed
  it('전송은 같은 틱이 아니라 다음 틱에 일어난다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/' });

    // 라우터가 핸들러를 기다리므로 같은 틱에 전송하면 화면 전환이 비콘만큼 늦는다.
    expect(fetchMock).not.toHaveBeenCalled();

    vi.runAllTimers();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon
  // @effects tracker_swallows_transport_failures
  it('전송 실패를 호출자에게 던지지 않는다', () => {
    fetchMock.mockRejectedValue(new Error('network down'));

    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    expect(() => {
      tracker.trackPageview({ path: '/' });
      vi.runAllTimers();
    }).not.toThrow();
  });

  // @scenario exclusion=url_pattern
  // @effects tracker_skips_excluded_requests_before_sending
  it('제외 대상이면 아예 보내지 않는다', () => {
    const tracker = new AnalyticsTracker({
      settings: { ...SETTINGS, excluded_url_patterns: ['/*'] },
    });

    tracker.trackPageview({ path: '/' });
    vi.runAllTimers();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects download_and_outlink_actions_store_target_in_dictionary
  it('내려받기·외부 링크를 알린다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackDownload('https://example.test/a.pdf', 'a.pdf');
    expect(flush()?.t).toBe('download');

    tracker.trackOutlink('https://other.test/x');
    expect(flush()?.t).toBe('outlink');
  });

  // @scenario source=beacon
  // @effects blob_downloads_are_named_without_a_target
  it('받아 둔 내용을 저장시키는 blob·data 링크는 주소 없이 이름만 보낸다', () => {
    // 게시판 첨부는 파일을 받아 blob 주소의 임시 링크로 저장시킨다(브라우저 실측 §8 M1-T7).
    // blob 주소를 target 에 실으면 서버가 URL 이 아니라며 422 로 거절해 첨부 다운로드가 통째로 빠진다.
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackDownload('blob:https://example.test/41c5fb2c-a71e-4766-9e5f-1d303e0d304d', 'sample.jpg');
    const blob = flush();
    expect(blob?.t).toBe('download');
    expect(blob?.name).toBe('sample.jpg');
    expect(blob).not.toHaveProperty('target');

    tracker.trackDownload('data:text/csv;charset=utf-8,a%2Cb', 'rows.csv');
    expect(flush()).not.toHaveProperty('target');

    tracker.trackDownload('https://example.test/a.pdf', 'a.pdf');
    expect(flush()?.target).toBe('https://example.test/a.pdf');
  });

  // @scenario source=beacon
  // @effects tracker_skips_excluded_requests_before_sending
  it('내려받기 수집을 끄면 보내지 않는다', () => {
    const tracker = new AnalyticsTracker({ settings: { ...SETTINGS, track_downloads: false } });

    tracker.trackDownload('https://example.test/a.pdf');
    vi.runAllTimers();

    expect(fetchMock).not.toHaveBeenCalled();
  });

  // @scenario source=beacon
  // @effects tracker_sends_beacon_on_route_change
  it('설정한 차원 값을 다음 비콘부터 싣는다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.setDimension('board_id', '12');
    tracker.trackPageview({ path: '/board' });

    expect(flush()?.dims).toEqual({ board_id: '12' });
  });

  // @scenario source=beacon
  // @effects tracker_sends_beacon_on_route_change
  it('목표·이벤트를 알린다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackGoal('core.user_registered', { label: '#1' });
    const goal = flush();
    expect(goal?.t).toBe('goal');
    expect(goal?.goal).toBe('core.user_registered');

    tracker.trackEvent('newsletter_open');
    expect(flush()?.t).toBe('event');
  });

  // @scenario source=admin, exclusion=opt_out
  // @effects tracker_toggles_opt_out_through_the_server
  it('수집 제외·해제를 서버에 요청한다', async () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    await tracker.optOut();

    // 쿠키는 서버가 심는다 — GDPR 가로채기가 document.cookie 쓰기를 막기 때문이다.
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0][0]).toBe('/api/admin/analytics/opt-out');
    expect(fetchMock.mock.calls[0][1].method).toBe('POST');

    await tracker.optIn();

    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(fetchMock.mock.calls[1][1].method).toBe('DELETE');
  });

  // @scenario source=admin, exclusion=opt_out
  // @effects tracker_toggles_opt_out_through_the_server
  it('제외 토글에 인증 토큰을 싣고 실패해도 던지지 않는다', async () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS, getAuthToken: () => 'tok-1' });

    await tracker.optOut();

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer tok-1');

    fetchMock.mockRejectedValueOnce(new Error('network down'));

    // 실패가 호출자에게 새면 관리자 설정 화면이 깨진다(D15).
    await expect(tracker.optIn()).resolves.toBeUndefined();
  });
});
