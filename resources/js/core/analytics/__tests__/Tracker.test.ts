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
 *   download_and_outlink_actions_store_target_in_dictionary,
 *   tracker_uses_keepalive_so_unloading_pages_still_deliver, tracker_fits_payload_to_server_field_limits,
 *   prerendered_page_counts_its_landing_pageview_when_activated
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AnalyticsTracker } from '../Tracker';
import type { ConsentSnapshotLike, ConsentSource } from '../consent';

/** 코어 동의 런타임 흉내 — 판정 대기·완료를 테스트가 직접 조작한다 */
interface FakeConsentSource extends ConsentSource {
  /** 판정을 끝낸다 (완료 리스너를 부른다) */
  settle: (snapshot: Partial<ConsentSnapshotLike> & { status: ConsentSnapshotLike['status'] }) => void;
  /** 인증 경계처럼 다시 판정 대기로 돌린다 */
  resetPending: () => void;
}

/**
 * 코어 동의 런타임을 흉내 낸다. 처음에는 판정 대기(unknown)다.
 *
 * @returns 가짜 동의 출처
 */
function fakeConsentSource(): FakeConsentSource {
  let snapshot: ConsentSnapshotLike = { status: 'unknown', categories: { necessary: true }, needsRenewal: false };
  let pending = true;
  const listeners = new Set<(s: ConsentSnapshotLike) => void>();

  return {
    getSnapshot: () => snapshot,
    isPending: () => pending,
    onSettled: (listener) => {
      listeners.add(listener);
      return () => listeners.delete(listener);
    },
    settle: (next) => {
      snapshot = { categories: { necessary: true }, needsRenewal: false, ...next };
      pending = false;
      listeners.forEach((listener) => listener(snapshot));
    },
    resetPending: () => {
      snapshot = { status: 'unknown', categories: { necessary: true }, needsRenewal: false };
      pending = true;
    },
  };
}

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

  /** 수집 경로로 나간 비콘의 페이로드 */
  const collected = (): Array<Record<string, unknown>> => fetchMock.mock.calls
    .filter(([url]) => String(url).includes('/api/analytics/collect'))
    .map(([, init]) => JSON.parse(init.body));

  /**
   * 동의 연동 모드 트래커 (동의 관리 제공자 있음) — 판정 출처는 코어 동의 런타임 흉내다.
   *
   * @param source 동의 출처
   * @returns 트래커
   */
  const consentTracker = (source: ConsentSource): AnalyticsTracker => new AnalyticsTracker({
    settings: { ...SETTINGS, privacy_mode: 'consent' },
    consentPluginActive: true,
    consentSource: source,
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
    const tracker = consentTracker(fakeConsentSource());

    tracker.trackPageview({ path: '/' });

    expect(flush()).toBeNull();
  });

  // @scenario mode=consent, source=beacon, visit_state=new
  // @effects consented_visitors_first_page_is_held_until_consent_is_known
  it('동의 조회보다 먼저 온 첫 화면은 버리지 않고 들고 있다가, 동의로 확인되면 보내고 아니면 버린다', async () => {
    // 첫 화면 비콘은 부팅 직후 동기로 나가 동의 조회보다 늘 먼저다. 버리면 동의한 방문자가
    // 새로 들어오거나 새로고침한 화면이 한 건도 안 남는다(#682 S3 라운드 13 브라우저 실측).
    const run = async (settled: Partial<ConsentSnapshotLike> & { status: ConsentSnapshotLike['status'] }) => {
      fetchMock.mockClear();
      const source = fakeConsentSource();
      const tracker = consentTracker(source);

      tracker.trackPageview({ path: '/' });
      vi.runAllTimers();
      const beforeSettle = collected().length;

      await tracker.start();
      source.settle(settled);
      vi.runAllTimers();

      const sent = collected();
      tracker.stop();

      return { beforeSettle, sent };
    };

    const granted = await run({ status: 'ready', categories: { necessary: true, analytics: true } });
    expect(granted.beforeSettle, '조회가 끝나기 전에는 보내지 않는다').toBe(0);
    expect(granted.sent).toHaveLength(1);
    expect(granted.sent[0]).toMatchObject({ t: 'pageview', init: true });

    expect((await run({ status: 'ready', categories: { necessary: true, analytics: false } })).sent, '미동의면 들고 있던 비콘을 버린다').toHaveLength(0);
    expect((await run({ status: 'unknown' })).sent, '조회에 실패해도(unknown) 동의로 단정하지 않는다').toHaveLength(0);
    expect((await run({ status: 'ready', categories: { analytics: true }, needsRenewal: true })).sent, '재동의 대상은 동의로 보지 않는다').toHaveLength(0);
    expect((await run({ status: 'none' })).sent, '동의 관리 제공자가 없다고 확인되면 보낸다(서버가 익명 처리)').toHaveLength(1);
  });

  // @scenario mode=consent, source=beacon, visit_state=open
  // @effects consented_visitors_first_page_is_held_until_consent_is_known
  it('로그인·로그아웃으로 판정이 다시 대기로 돌아가면 그 사이의 화면 이동도 들고 있다가 새 판정으로 보낸다 (#639)', async () => {
    // 로그인은 SPA 이동이다 — 로그인 직후 화면의 비콘은 동의 재조회보다 먼저 나간다. 버리면 회원으로
    // 들어온 첫 화면이 통째로 빠지고, 옛(게스트) 판정으로 보내면 동의하지 않은 회원이 세어진다.
    const source = fakeConsentSource();
    const tracker = consentTracker(source);
    await tracker.start();
    source.settle({ status: 'ready', categories: { necessary: true, analytics: false } });

    tracker.trackPageview({ path: '/login' });
    vi.runAllTimers();
    expect(collected(), '게스트는 미동의').toHaveLength(0);

    source.resetPending();
    tracker.trackPageview({ path: '/' });
    vi.runAllTimers();
    expect(collected(), '새 판정 전에는 보내지 않는다').toHaveLength(0);

    source.settle({ status: 'ready', categories: { necessary: true, analytics: true } });
    vi.runAllTimers();
    expect(collected().map((body) => body.title)).toEqual(['/']);
    tracker.stop();
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
  // @effects tracker_clears_dimensions_on_navigation
  it('차원 값은 넣은 화면의 비콘에만 싣고 다른 화면으로 넘어가면 비운다', () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackPageview({ path: '/board/12' });
    tracker.setDimension('sirsoft-board:board_id', '12');
    tracker.trackDownload('https://example.test/a.pdf');
    tracker.trackPageview({ path: '/' });
    tracker.trackDownload('https://example.test/b.pdf');
    vi.runAllTimers();

    const [landing, sameScreen, nextScreen, nextAction] = collected();
    expect(landing.dims).toBeUndefined();
    expect(sameScreen.dims).toEqual({ 'sirsoft-board:board_id': '12' });
    expect(nextScreen.dims).toBeUndefined();
    expect(nextAction.dims).toBeUndefined();
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

  // @scenario visitor=admin, exclusion=opt_out
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

  // @scenario visitor=admin, exclusion=opt_out
  // @effects tracker_toggles_opt_out_through_the_server
  it('제외 토글에 인증 토큰을 싣고 실패해도 던지지 않는다', async () => {
    const tracker = new AnalyticsTracker({ settings: SETTINGS, getAuthToken: () => 'tok-1' });

    await tracker.optOut();

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer tok-1');

    fetchMock.mockRejectedValueOnce(new Error('network down'));

    // 실패가 호출자에게 새면 관리자 설정 화면이 깨진다(D15).
    await expect(tracker.optIn()).resolves.toBeUndefined();
  });

  // @scenario source=beacon, visit_state=open
  // @effects tracker_uses_keepalive_so_unloading_pages_still_deliver
  it('fetch 전송은 keepalive 로 보내 화면을 떠나는 중에도 끊기지 않는다', () => {
    // keepalive 가 빠지면 링크를 눌러 떠나는 순간의 비콘(외부 링크·마지막 화면)이 조용히 끊긴다.
    // sendBeacon 분기는 따로 남아 있어 이 단언이 없으면 떼어도 다른 테스트가 모두 초록이다.
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    tracker.trackOutlink('https://other.test/x');
    vi.runAllTimers();

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('/api/analytics/collect');
    expect(init).toMatchObject({ method: 'POST', keepalive: true, credentials: 'same-origin' });
  });

  // @scenario mode=consent, source=beacon, visit_state=new
  // @effects consented_visitors_first_page_is_held_until_consent_is_known
  it('동의 조회 전에 들고 있는 비콘은 5건까지만이고, 동의로 확인되면 그 5건만 보낸다', async () => {
    const source = fakeConsentSource();
    const tracker = consentTracker(source);

    // 조회가 오래 걸리는 동안 화면을 7번 옮겼다 — 무한히 쌓지 않는다.
    for (let i = 1; i <= 7; i++) tracker.trackPageview({ path: `/p${i}` });
    vi.runAllTimers();
    expect(collected(), '조회 전에는 보내지 않는다').toHaveLength(0);

    await tracker.start();
    source.settle({ status: 'ready', categories: { necessary: true, analytics: true } });
    vi.runAllTimers();

    expect(collected().map((body) => body.title)).toEqual(['/p1', '/p2', '/p3', '/p4', '/p5']);
    tracker.stop();
  });

  // @scenario mode=consent, source=beacon, exclusion=opt_out
  // @effects tracker_honours_do_not_track_and_opt_out, consented_visitors_first_page_is_held_until_consent_is_known
  it('들고 있는 사이에 수집 제외를 고르면 동의로 확인돼도 그 비콘을 보내지 않는다', async () => {
    const source = fakeConsentSource();
    const tracker = consentTracker(source);

    tracker.trackPageview({ path: '/' });

    // 동의 조회가 끝나기 전에 방문자가 「내 방문은 세지 않기」를 골랐다(서버가 쿠키를 심음).
    document.cookie = 'g7_analytics_optout=1';

    await tracker.start();
    source.settle({ status: 'ready', categories: { necessary: true, analytics: true } });
    vi.runAllTimers();

    expect(collected()).toHaveLength(0);
    tracker.stop();
  });

  // @scenario mode=consent, source=beacon, exclusion=consent
  // @effects consented_visitors_first_page_is_held_until_consent_is_known, consent_bridge_blocks_sending_until_consent_is_known
  it('동의 판정이 unknown 으로 끝나면 들고 있던 비콘을 버리고, 그 뒤 화면도 미동의로 다룬다', async () => {
    // 런타임은 조회 실패(401·500·형식 오류)를 unknown 으로 둔다 — 본문을 추측해 동의로 읽지 않는다.
    const source = fakeConsentSource();
    const tracker = consentTracker(source);

    tracker.trackPageview({ path: '/' });
    await tracker.start();
    source.settle({ status: 'unknown' });
    vi.runAllTimers();

    expect(collected()).toHaveLength(0);

    // 판정은 끝났다 — 그 뒤의 화면도 「모름」이 아니라 미동의로 다뤄 들고 있지 않는다.
    tracker.trackPageview({ path: '/next' });
    vi.runAllTimers();
    expect(collected()).toHaveLength(0);
    tracker.stop();
  });

  // @scenario source=beacon
  // @effects tracker_swallows_transport_failures
  it('수집 응답이 !ok(429·5xx)여도 다시 보내지 않고 호출자에게 던지지 않는다', async () => {
    fetchMock.mockResolvedValue({ ok: false, status: 429 });
    const tracker = new AnalyticsTracker({ settings: SETTINGS });

    expect(() => {
      tracker.trackPageview({ path: '/' });
      vi.runAllTimers();
    }).not.toThrow();

    // 응답을 기다리지 않는 전송이다 — 재시도가 붙으면 과부하(429) 중인 서버를 더 두드린다.
    await Promise.resolve();
    await Promise.resolve();
    vi.runAllTimers();
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  // @scenario source=beacon, visit_state=new
  // @effects tracker_fits_payload_to_server_field_limits
  it('서버 검증 상한을 넘는 필드는 보내기 전에 맞춰 비콘 전체가 버려지지 않게 한다', () => {
    // 서버(CollectRequest)는 한 필드라도 넘으면 비콘 전체를 검증 실패로 버린다 —
    // 긴 게시글 제목 하나 때문에 그 화면 열람이 통째로 빠졌다(#682 R12-D2).
    Object.defineProperty(document, 'referrer', {
      value: `https://ref.test/?q=${'r'.repeat(1100)}`,
      configurable: true,
    });
    window.history.pushState({}, '', `/long/path?q=${'u'.repeat(3000)}#frag`);

    try {
      const tracker = new AnalyticsTracker({ settings: SETTINGS });
      for (let i = 0; i < 25; i++) tracker.setDimension(`d${i}`, i === 0 ? '값'.repeat(300) : String(i));

      // 경계에 서로게이트 쌍(이모지)을 둔다 — UTF-16 단위로 자르면 반쪽이 남아 서버 JSON 해석이 깨진다.
      const emoji = String.fromCodePoint(0x1f600);
      tracker.trackPageview({ path: '/long/path', meta: { title: `${'a'.repeat(511)}${emoji}${emoji}` } });
      const page = flush();

      expect(page?.title).toBe(`${'a'.repeat(511)}${emoji}`);
      expect(Array.from(String(page?.title))).toHaveLength(512);
      // 주소는 자르면 다른 주소가 된다 — 넘는 유입은 뺀다.
      expect(page).not.toHaveProperty('ref');
      // 방문 URL 은 필수라 빼지 못한다 — 쿼리·조각을 떼어 상한 안에 둔다.
      expect(page?.url).toBe(`${window.location.origin}/long/path`);
      const dims = page?.dims as Record<string, string>;
      expect(Object.keys(dims)).toHaveLength(20);
      expect(Object.keys(dims)[19]).toBe('d19');
      expect(Array.from(dims.d0)).toHaveLength(255);

      tracker.trackEvent('e'.repeat(300));
      expect(Array.from(String(flush()?.name))).toHaveLength(255);

      tracker.trackOutlink(`https://other.test/${'t'.repeat(1100)}`);
      const outlink = flush();
      expect(outlink?.t).toBe('outlink');
      expect(outlink).not.toHaveProperty('target');

      // 상한 안의 값은 건드리지 않는다.
      tracker.trackDownload('https://example.test/a.pdf', 'a.pdf');
      expect(flush()).toMatchObject({ target: 'https://example.test/a.pdf', name: 'a.pdf' });
    } finally {
      window.history.pushState({}, '', '/');
      Object.defineProperty(document, 'referrer', { value: '', configurable: true });
    }
  });

  // @scenario source=beacon, visit_state=new, exclusion=prefetch
  // @effects prerendered_page_counts_its_landing_pageview_when_activated, tracker_skips_excluded_requests_before_sending
  it('미리 그리던 화면은 보내지 않다가 실제로 열리면 그 첫 화면을 init 비콘으로 보낸다', () => {
    // 브라우저가 미리 그린 화면을 방문자가 실제로 열면 그때가 랜딩이다. 미리 그릴 때 버리고
    // 끝나면 그 방문의 첫 화면이 브라우저·서버 어디에도 남지 않는다(#682 R12-D5).
    const doc = document as Document & { prerendering?: boolean };
    doc.prerendering = true;

    try {
      const tracker = new AnalyticsTracker({ settings: SETTINGS });

      tracker.trackPageview({ path: '/', meta: { title: '홈' } });
      vi.advanceTimersByTime(5000);
      expect(fetchMock, '미리 그리는 동안에는 보내지 않는다').not.toHaveBeenCalled();

      doc.prerendering = false;
      document.dispatchEvent(new Event('prerenderingchange'));
      vi.runAllTimers();

      expect(collected()).toHaveLength(1);
      expect(collected()[0]).toMatchObject({ t: 'pageview', init: true, title: '홈' });
      expect(collected()[0]).not.toHaveProperty('prev');

      // 한 번 열린 뒤에는 알림을 다시 받아도 되풀이해 보내지 않는다.
      document.dispatchEvent(new Event('prerenderingchange'));
      vi.runAllTimers();
      expect(collected()).toHaveLength(1);

      tracker.stop();
    } finally {
      delete doc.prerendering;
    }
  });

  // @scenario source=beacon, exclusion=prefetch
  // @effects prerendered_page_counts_its_landing_pageview_when_activated
  it('미리 그리다 열리기 전에 멈추면 보내지 않고, 미리 가져오기 제외를 끄면 미루지 않는다', () => {
    const doc = document as Document & { prerendering?: boolean };
    doc.prerendering = true;

    try {
      const stopped = new AnalyticsTracker({ settings: SETTINGS });
      stopped.trackPageview({ path: '/' });
      stopped.stop();

      doc.prerendering = false;
      document.dispatchEvent(new Event('prerenderingchange'));
      vi.runAllTimers();
      expect(fetchMock, '멈춘 트래커는 열려도 보내지 않는다').not.toHaveBeenCalled();

      // 운영자가 미리 그린 화면도 세기로 했으면 그 자리에서 보낸다.
      doc.prerendering = true;
      const counting = new AnalyticsTracker({ settings: { ...SETTINGS, exclude_prefetch: false } });
      counting.trackPageview({ path: '/' });
      vi.runAllTimers();
      expect(collected()).toHaveLength(1);
    } finally {
      delete doc.prerendering;
    }
  });
});
