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
 * SPA 이동이 비콘을 한 건 더 보내는 것은 「같은 화면 거듭 이동」 케이스가 `/boards` 비콘 1건으로 잰다.
 */
import { authenticatePage, expect, issueToken, test } from '../../fixtures/auth';
import { AnalyticsSettingsGuard, runArtisan, todayPageHits } from '../../fixtures/analytics';

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

  // @scenario source=beacon, visitor=guest, visit_state=open, exclusion=none
  // @effects repeated_navigation_to_the_same_page_counts_once, spa_navigation_sends_another_beacon
  test('같은 화면으로 거듭 이동하면(메뉴 더블클릭) 페이지뷰는 1건이다', async ({ page }) => {
    // 브라우저 실측(§8 M1-T10): 라우터는 같은 주소로의 이동도 매번 알려, 메뉴 더블클릭 한 번이
    // 같은 페이지뷰 2건이 됐다. 메뉴 데이터에 기대지 않도록 같은 이동을 한 틱에 두 번 부른다.
    const pageviews: string[] = [];
    page.on('request', (request) => {
      if (!request.url().includes(COLLECT_PATH)) return;
      try {
        const payload = JSON.parse(request.postData() ?? '{}');
        if (payload.t === 'pageview') pageviews.push(String(payload.url ?? ''));
      } catch {
        // 아래 단언이 판정한다.
      }
    });

    await page.goto('/');
    await expect.poll(() => pageviews.length, { timeout: 20_000 }).toBeGreaterThan(0);
    const before = pageviews.length;

    await page.evaluate(() => {
      const navigate = () => (window as unknown as { G7Core: { dispatch: (a: unknown) => void } }).G7Core
        .dispatch({ handler: 'navigate', params: { path: '/boards' } });
      navigate();
      navigate();
    });
    await page.waitForURL(/\/boards/, { timeout: 20_000 });
    await page.waitForTimeout(2_000);

    expect(pageviews.slice(before).filter((url) => url.includes('/boards'))).toHaveLength(1);
  });

  // @scenario source=beacon, visitor=guest, visit_state=open, exclusion=none
  // @effects tracker_clears_dimensions_on_navigation
  test('확장이 넣은 차원 값은 그 화면의 비콘에만 실리고 다른 화면으로 가면 빠진다', async ({ page }) => {
    // 비우지 않으면 게시판에서 넣은 board_id 가 홈·다른 메뉴 조회에도 붙어 그 게시판 수치가 부푼다.
    // 서버에 흔적을 남기지 않도록 수집 요청은 여기서 204 로 받아 두고, 브라우저가 보낸 본문만 잰다.
    const payloads: Record<string, unknown>[] = [];
    await page.route(`**${COLLECT_PATH}`, async (route) => {
      try {
        payloads.push(JSON.parse(route.request().postData() ?? '{}'));
      } catch {
        // 아래 단언이 판정한다.
      }
      await route.fulfill({ status: 204 });
    });

    type Analytics = { setDimension: (k: string, v: string) => void; trackEvent: (n: string) => void };
    type Core = { analytics?: Analytics; dispatch: (a: unknown) => void };

    await page.goto('/');
    await expect.poll(() => payloads.length, { timeout: 20_000 }).toBeGreaterThan(0);

    await page.evaluate(() => {
      const core = (window as unknown as { G7Core: Core }).G7Core;
      core.analytics?.setDimension('vendor-probe:thing', '12');
      core.analytics?.trackEvent('probe_same_screen');
    });
    await expect.poll(() => payloads.some((p) => p.name === 'probe_same_screen'), { timeout: 20_000 }).toBe(true);

    await page.evaluate(() => {
      (window as unknown as { G7Core: Core }).G7Core.dispatch({ handler: 'navigate', params: { path: '/boards' } });
    });
    await page.waitForURL(/\/boards/, { timeout: 20_000 });
    await expect.poll(() => payloads.some((p) => p.t === 'pageview' && String(p.url).includes('/boards')), { timeout: 20_000 }).toBe(true);

    await page.evaluate(() => {
      (window as unknown as { G7Core: Core }).G7Core.analytics?.trackEvent('probe_next_screen');
    });
    await expect.poll(() => payloads.some((p) => p.name === 'probe_next_screen'), { timeout: 20_000 }).toBe(true);

    const sameScreen = payloads.find((p) => p.name === 'probe_same_screen');
    const nextPageview = payloads.find((p) => p.t === 'pageview' && String(p.url).includes('/boards'));
    const nextScreen = payloads.find((p) => p.name === 'probe_next_screen');

    expect(sameScreen?.dims).toEqual({ 'vendor-probe:thing': '12' });
    expect(nextPageview?.dims).toBeUndefined();
    expect(nextScreen?.dims).toBeUndefined();
  });

  // @scenario source=beacon, visitor=guest, visit_state=open, exclusion=none
  // @effects blob_downloads_are_named_without_a_target
  test('받아 둔 내용을 저장시키는 blob 내려받기는 주소 없이 이름만 보낸다', async ({ page }) => {
    // 게시판 첨부처럼 파일을 받아 blob 임시 링크로 저장시키면, blob 주소를 target 에 실은 비콘은
    // 서버가 URL 이 아니라며 거절해 내려받기가 통째로 빠졌다(§8 M1-T7).
    const downloads: Record<string, unknown>[] = [];
    page.on('request', (request) => {
      if (!request.url().includes(COLLECT_PATH)) return;
      try {
        const payload = JSON.parse(request.postData() ?? '{}');
        if (payload.t === 'download') downloads.push(payload);
      } catch {
        // 아래 단언이 판정한다.
      }
    });

    await page.goto('/');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForTimeout(2_000);

    await page.evaluate(() => {
      const anchor = document.createElement('a');
      anchor.id = 'e2e-blob-download';
      anchor.href = URL.createObjectURL(new Blob(['a,b\n1,2'], { type: 'text/csv' }));
      anchor.download = 'rows.csv';
      anchor.textContent = 'rows.csv';
      document.body.appendChild(anchor);
    });
    const saved = page.waitForEvent('download');
    await page.locator('#e2e-blob-download').click();
    await saved;

    await expect.poll(() => downloads.length, { timeout: 10_000 }).toBe(1);
    expect(downloads[0]).toMatchObject({ t: 'download', name: 'rows.csv' });
    expect(downloads[0]).not.toHaveProperty('target');
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

  // @scenario visitor=admin, exclusion=opt_out
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

test.describe('방문자 통계 수집 → 집계 → 관리자 화면 (전체 흐름)', () => {
  // @scenario screen=overview, state=normal, viewport=1440, locale=ko, e2e=collect
  // @effects beacon_reaches_the_overview_after_archive
  test('게스트 방문이 원장에 쌓이고 시간 집계 뒤 관리자 페이지 리포트와 개요에 나온다', async ({ page }) => {
    const adminToken = issueToken('core.analytics.read', 'core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, adminToken);

    // Playwright 브라우저는 navigator.webdriver 가 참이라 기본 설정(자동화 브라우저 제외)에서는
    // 방문이 원장에 닿지 않는다(비콘은 204 를 받는다). 이 흐름만 잠시 풀고 finally 에서 되돌린다.
    //
    // 적재 방식 설정은 없앴다 — 수집 리스너가 `sync => true` 라 워커가 없어도 비콘이 요청 안에서
    // 원장에 닿는다(#682 S3 라운드 6). 예전에는 여기서 `dispatch_mode: 'sync'` 를 함께 고정해야
    // 아래 집계가 빈손으로 끝나지 않았다.
    await guard.apply({ block_headless: false });

    try {
      // 기준선 — 먼저 집계해 두어야 「집계 전 쌓여 있던 방문」이 이번 증가분에 섞이지 않는다.
      runArtisan('analytics:archive --period=hour');
      const before = await todayPageHits(page.request, adminToken, '/boards');

      const beacons: string[] = [];
      page.on('request', (request) => {
        if (request.url().includes(COLLECT_PATH)) beacons.push(request.postData() ?? '');
      });

      await page.goto('/');
      await expect.poll(() => beacons.length, { timeout: 20_000 }).toBeGreaterThan(0);
      await expect
        .poll(() => page.evaluate(() => typeof (window as any).G7Core?.dispatch), { timeout: 20_000 })
        .toBe('function');

      // 메뉴 클릭과 같은 SPA 이동 — 새로 불러오지 않고 라우터만 바뀐다.
      await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'navigate', params: { path: '/boards' } }));
      await expect(page).toHaveURL(/\/boards/);
      await expect.poll(() => beacons.some((body) => body.includes('/boards')), { timeout: 20_000 }).toBe(true);

      runArtisan('analytics:archive --period=hour');
      const after = await todayPageHits(page.request, adminToken, '/boards');
      expect(after, '이번 방문의 /boards 페이지뷰가 집계 표에 더해져야 한다').toBeGreaterThanOrEqual(before + 1);

      // 화면 — 관리자로 전환해 페이지 리포트(검색)와 개요 KPI 를 본다.
      await authenticatePage(page, adminToken);
      await page.goto('/admin/analytics/pages?preset=today&q=boards');
      await expect(page.locator('#analytics_report_grid').getByText('/boards', { exact: true }).first()).toBeVisible({ timeout: 30_000 });

      await page.goto('/admin/analytics?preset=today');
      const today = page.locator('#kpi_today');
      await expect(today).toBeVisible({ timeout: 30_000 });
      await expect.poll(async () => Number(((await today.textContent()) ?? '').replace(/[^0-9]/g, '').slice(0, 9) || '0'), { timeout: 20_000 })
        .toBeGreaterThanOrEqual(1);
    } finally {
      await guard.restore();
    }
  });
});

test.describe('방문자 통계 비콘 — 동의 연동 모드', () => {
  // @scenario source=beacon, visitor=guest, visit_state=new, exclusion=none
  // @effects consented_visitors_first_page_is_held_until_consent_is_known
  test('동의한 방문자가 새로고침한 첫 화면도 비콘을 보낸다', async ({ page }) => {
    // 첫 화면 비콘은 부팅 직후 나가 동의 조회보다 늘 먼저다. 트래커가 그 순간의 「모름」을 미동의로
    // 버리면, 동의한 방문자가 새로 들어오거나 새로고침한 화면이 한 건도 남지 않는다 — 메뉴 이동은
    // 세어지니 단위 테스트와 SPA 실측만으로는 드러나지 않았다(#682 S3 라운드 13 브라우저 실측).
    const adminToken = issueToken('core.analytics.read', 'core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, adminToken);

    const beacons: Array<Record<string, unknown>> = [];
    page.on('request', (request) => {
      if (request.url().includes(COLLECT_PATH)) beacons.push(JSON.parse(request.postData() ?? '{}'));
    });

    await guard.apply({ privacy_mode: 'consent' });

    try {
      await page.goto('/');
      await page.waitForLoadState('networkidle', { timeout: 30_000 });
      // 동의 연동 모드는 동의 관리 플러그인이 켜진 설치에서만 성립한다 — 이 spec 의 전제다.
      const pluginActive = await page.evaluate(() => ((window as any).G7Config?.activePlugins ?? [])
        .some((plugin: any) => plugin?.identifier === 'sirsoft-gdpr'));
      expect(pluginActive, '동의 관리 플러그인이 활성이어야 한다').toBe(true);
      expect(beacons, '동의 전에는 보내지 않는다').toHaveLength(0);

      // 같은 배너가 두 벌 그려지는 설치가 있어(겹친 위쪽이 아래쪽의 클릭을 가린다) 위쪽을 누른다.
      // 동의 저장 요청이 끝나기 전에 새로고침하면 그 요청이 취소돼 동의가 남지 않는다 — 저장 응답을 받고
      // 새로고침한다(#682 S3 라운드 14: 저장이 빨랐던 첫 회만 통과하고 반복 실행에서 3/4 실패했다).
      await Promise.all([
        page.waitForResponse((res) => res.url().includes('/consent/cookie') && res.request().method() === 'POST'),
        page.locator('#gdpr_cookie_banner').last().getByRole('button', { name: /Accept All|모두 동의/ }).click(),
      ]);

      await page.reload();
      await expect
        .poll(() => beacons.filter((body) => body.init === true).length, { timeout: 20_000 })
        .toBeGreaterThan(0);
    } finally {
      await guard.restore();
    }
  });
});
