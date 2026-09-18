/**
 * E2E: 방문자 통계 비콘 왕복 (이슈 #682 S1)
 *
 * 단위·Feature 테스트는 트래커와 적재 파이프라인을 **따로** 잠근다. 그 사이의 배선
 * — 사용자 화면이 실제로 부팅될 때 트래커가 켜지고, 라우트 이동이 비콘을 만들고,
 * 서버가 그것을 204 로 받는 것 — 은 브라우저에서만 드러난다.
 *
 * 특히 이 축은 조용히 깨진다. 트래커가 아예 켜지지 않아도 화면은 멀쩡하고 콘솔에도
 * 아무것도 남지 않는다. 운영자는 「방문자가 0명」이라는 화면을 보고서야 알아차린다.
 *
 * 축 요약(마커 아님 — 평문): 첫 진입·SPA 이동은 게스트 비콘, 관리자 화면은 수집 제외.
 * 효과 요약(마커 아님 — 평문): beacon_reaches_server_with_204,
 *   spa_navigation_sends_another_beacon, admin_template_does_not_track.
 */
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';

/** 수집 비콘 경로 */
const COLLECT_PATH = '/api/analytics/collect';

test.describe('방문자 통계 비콘', () => {
  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects beacon_reaches_server_with_204
  test('사용자 화면 첫 진입이 비콘을 보내고 서버가 204 로 받는다', async ({ page }) => {
    const responses: number[] = [];

    page.on('response', (response) => {
      if (response.url().includes(COLLECT_PATH)) responses.push(response.status());
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 트래커는 전송을 다음 틱으로 미루므로 응답이 올 때까지 기다린다.
    await expect
      .poll(() => responses.length, { timeout: 20_000 })
      .toBeGreaterThan(0);

    // 비콘은 어떤 경우에도 204 다 — 4xx 를 주면 방문자 콘솔에 오류가 뜬다.
    expect(responses.every((status) => status === 204)).toBe(true);
  });

  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects beacon_reaches_server_with_204
  test('첫 비콘이 페이지뷰로 init 표식과 함께 나간다', async ({ page }) => {
    const payloads: Record<string, unknown>[] = [];

    page.on('request', (request) => {
      if (!request.url().includes(COLLECT_PATH)) return;

      try {
        payloads.push(JSON.parse(request.postData() ?? '{}'));
      } catch {
        // 본문을 못 읽어도 테스트를 깨뜨리지 않는다 — 아래 단언이 판정한다.
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect.poll(() => payloads.length, { timeout: 20_000 }).toBeGreaterThan(0);

    const first = payloads[0];
    expect(first.t).toBe('pageview');
    expect(first.init).toBe(true);
    expect(typeof first.url).toBe('string');
  });

  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects first_screen_sends_exactly_one_pageview
  test('첫 화면은 비콘을 한 번만 보낸다', async ({ page }) => {
    const payloads: Record<string, unknown>[] = [];

    page.on('request', (request) => {
      if (!request.url().includes(COLLECT_PATH)) return;

      try {
        payloads.push(JSON.parse(request.postData() ?? '{}'));
      } catch {
        // 본문을 못 읽어도 테스트를 깨뜨리지 않는다 — 아래 단언이 판정한다.
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect.poll(() => payloads.length, { timeout: 20_000 }).toBeGreaterThan(0);

    // 라우터는 첫 화면도 routeChange 로 알린다. 직접 보낸 첫 비콘과 겹쳐 두 건이 나가면
    // 모든 방문의 페이지뷰가 하나씩 부풀고, 한 쪽만 본 방문이 이탈로 잡히지 않는다.
    await page.waitForTimeout(3_000);

    expect(payloads.map((payload) => `${payload.t}:${payload.init}`)).toEqual(['pageview:true']);
  });

  // @scenario source=beacon, visitor=admin, exclusion=disabled
  // @effects admin_template_does_not_track
  test('관리자 화면은 수집하지 않는다', async ({ page }) => {
    const requests: string[] = [];

    page.on('request', (request) => {
      if (request.url().includes(COLLECT_PATH)) requests.push(request.url());
    });

    await authenticatePage(page, issueToken('core.dashboard.read'));
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 관리자 화면까지 세면 인기 페이지 1위가 관리자 대시보드가 되어 통계가 쓸모없어진다.
    // 트래커가 늦게 보낼 수 있으므로 잠시 더 지켜본 뒤 판정한다.
    await page.waitForTimeout(3_000);

    expect(requests).toHaveLength(0);
  });

  // @scenario source=admin, exclusion=opt_out
  // @effects tracker_toggles_opt_out_through_the_server
  test('수집 제외를 켜면 이후 이동이 비콘을 만들지 않는다', async ({ page }) => {
    // 제외 토글은 운영자 기능이다 — 엔드포인트가 core.analytics.update 를 요구하므로
    // 그 권한으로 인증한 뒤 사용자 사이트에서 부른다(§4.5 「관리자 opt-out」).
    await authenticatePage(page, issueToken('core.analytics.update'));

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 전역 API 가 실제로 노출됐는지부터 본다 — 단위 테스트는 모듈을 직접 부르므로
    // 「TemplateApp 이 window 에 붙이지 않았다」를 잡지 못한다.
    // 트래커 부팅은 비동기라 붙을 때까지 기다린다(즉시 읽으면 undefined 다).
    await expect
      .poll(
        () => page.evaluate(
          () => typeof (window as unknown as { G7Core?: { analytics?: { optOut?: unknown } } })
            .G7Core?.analytics?.optOut,
        ),
        { timeout: 20_000 },
      )
      .toBe('function');

    const beforeCookies = await page.context().cookies();
    expect(beforeCookies.some((cookie) => cookie.name === 'g7_analytics_optout')).toBe(false);

    await page.evaluate(
      () => (window as unknown as { G7Core: { analytics: { optOut(): Promise<void> } } })
        .G7Core.analytics.optOut(),
    );

    // 쿠키는 서버가 심는다. 트래커가 되읽어야 하므로 HttpOnly 가 아니어야 한다.
    const afterCookies = await page.context().cookies();
    const optOut = afterCookies.find((cookie) => cookie.name === 'g7_analytics_optout');
    expect(optOut?.value).toBe('1');
    expect(optOut?.httpOnly).toBe(false);

    const requests: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes(COLLECT_PATH)) requests.push(request.url());
    });

    await page.reload();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForTimeout(3_000);

    expect(requests).toHaveLength(0);

    // 해제하면 다시 세어야 한다 — 제외가 되돌릴 수 없으면 운영자가 자기 사이트를
    // 영영 못 센다.
    await page.evaluate(
      () => (window as unknown as { G7Core: { analytics: { optIn(): Promise<void> } } })
        .G7Core.analytics.optIn(),
    );

    const restored = await page.context().cookies();
    expect(restored.some((cookie) => cookie.name === 'g7_analytics_optout' && cookie.value === '1')).toBe(false);
  });
});
