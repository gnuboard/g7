/**
 * E2E: 방문자 통계 — 화면 열 때 집계와 집계 신선도 안내
 *
 * 스케줄러(cron)가 없는 설치에서도 관리자가 집계 화면을 열면 1분 이내 최신 숫자가 보여야 하고, 기간 줄에
 * 「마지막 집계」가, 멈춤·상한 초과는 상단 경고가 떠야 한다. 레이아웃 계약 테스트는 조건식까지만 잠근다 —
 * 미들웨어가 요청 앞에서 집계를 돌리고 응답에 신선도를 싣고 화면이 그 값을 문구로 그리는 것은 브라우저에서만 드러난다.
 *
 * 축 요약(마커 아님 — 평문): 비콘 → 페이지 화면 진입 → 행·문구 / 설정 끔 + 멈춘 응답 → 경고·「지금 집계」 → 재요청.
 */
import { authenticatePage, expect, issueScopedToken, issueToken, test } from '../../fixtures/auth';
import { AnalyticsSettingsGuard, runArtisan, runSeed } from '../../fixtures/analytics';

/** 화면 열 때 집계의 1분 문 — 앞선 요청이 닫아 둔 문을 테스트마다 연다 */
const GATE_KEY = 'analytics:archive:on_view:gate';

test.describe('방문자 통계 — 화면 열 때 집계', () => {
  // 토큰 발급·설정 저장·artisan(1분 문 열기·정리 후 재집계)이 각각 프로세스를 띄워 기본 30초를 넘긴다.
  test.setTimeout(120_000);

  // @scenario screen=pages, state=normal, viewport=1440, locale=ko, e2e=archive_on_view
  // @effects on_view_archives_before_the_report_answers
  test('비콘 하나 뒤 페이지 화면을 열면 그 자리에서 집계돼 행이 보이고, 기간 줄에 「마지막 집계」가 뜬다', async ({ page }) => {
    const token = issueToken('core.analytics.read', 'core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    await guard.apply({ archive_on_view_enabled: true });

    try {
      const marker = `/qa-e2e-onview-${Date.now()}`;
      const beacon = await page.request.post('/api/analytics/collect', {
        headers: { 'Content-Type': 'application/json' },
        data: { t: 'pageview', url: `${new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'https://g7.dev').origin}${marker}`, title: 'QA', res: '1113x1113', lang: 'ko-KR', init: true },
      });
      expect(beacon.status(), '비콘은 늘 204').toBe(204);

      runArtisan(`cache:forget ${GATE_KEY}`);
      await authenticatePage(page, token);
      const report = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/reports/page_url'), { timeout: 30_000 });
      await page.goto('/admin/analytics/pages?preset=today');
      const body = await (await report).json();

      expect(body?.data?.freshness?.on_view?.enabled, '신선도 블록').toBe(true);
      expect(['ran', 'fresh', 'locked'], `on_view 결과 ${body?.data?.freshness?.on_view?.status}`).toContain(body?.data?.freshness?.on_view?.status);
      await expect(page.locator('#period_freshness')).toContainText('마지막 집계', { timeout: 30_000 });
      await expect(page.locator('#analytics_report_grid')).toContainText(marker.slice(1), { timeout: 30_000 });
    } finally {
      await guard.restore();
      // 표식 방문(해상도 1113x1113)은 원장에서 지우고 오늘치를 다시 집계한다.
      runSeed('cleanup-onview');
      runArtisan('analytics:archive --period=hour');
    }
  });

  // @scenario screen=pages, state=stale, viewport=1440, locale=ko, e2e=archive_on_view
  // @effects archive_stale_warning_offers_archive_now
  test('화면 열 때 집계를 끄고 집계가 멈췄으면 노란 경고와 「지금 집계」가 뜨고, 누르면 집계 요청 뒤 목록을 다시 받는다', async ({ page }) => {
    const token = issueToken('core.analytics.read', 'core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    await guard.apply({ archive_on_view_enabled: false });

    // 화면 열 때 집계를 껐으니 표식 방문은 「지금 집계」 전까지 목록에 없다 — 누른 뒤 행이 보이는지로 집계를 잰다.
    const marker = `/qa-e2e-archive-now-${Date.now()}`;

    try {
      const beacon = await page.request.post('/api/analytics/collect', {
        headers: { 'Content-Type': 'application/json' },
        data: { t: 'pageview', url: `${new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'https://g7.dev').origin}${marker}`, title: 'QA', res: '1113x1113', lang: 'ko-KR', init: true },
      });
      expect(beacon.status(), '비콘은 늘 204').toBe(204);

      // 실제 실행 이력을 5시간 전으로 돌리는 대신, 응답의 신선도만 「5시간 멈춤」으로 바꿔 화면 분기를 연다
      // (개발 DB 의 이력을 건드리지 않는다). 「지금 집계」 요청과 그 뒤 재요청은 실제 서버로 간다.
      let stubbed = 0;
      await page.route('**/api/admin/analytics/reports/page_url**', async (route) => {
        const response = await route.fetch();
        const json = await response.json();
        if (stubbed === 0 && json?.data) {
          json.data.freshness = {
            ...(json.data.freshness ?? {}),
            last_run_at: '2026-09-28 17:05:00',
            is_today: true,
            lag_minutes: 300,
            stale: true,
            on_view: { enabled: false, status: 'disabled', today_visits: null, max_visits: 50000 },
          };
        }
        stubbed += 1;
        await route.fulfill({ response, json });
      });

      await authenticatePage(page, token);
      await page.goto('/admin/analytics/pages?preset=today');

      const alert = page.locator('#alert_archive_stale');
      await expect(alert).toBeVisible({ timeout: 30_000 });
      await expect(alert).toContainText('5시간째');

      const post = page.waitForResponse((r) => r.url().endsWith('/api/admin/analytics/archive-runs') && r.request().method() === 'POST');
      const refetch = page.waitForResponse((r) => r.url().includes('/api/admin/analytics/reports/page_url'));
      await page.locator('#alert_archive_now_button').click();
      expect((await post).status(), '「지금 집계」').toBe(202);
      await refetch;
      await expect(alert, '재요청은 실제 신선도(멈춤 아님)로 그린다').toBeHidden({ timeout: 30_000 });
      await expect(page.locator('#analytics_report_grid'), '집계된 표식 방문이 목록에 보인다').toContainText(marker.slice(1), { timeout: 30_000 });
    } finally {
      await guard.restore();
      runSeed('cleanup-onview');
      runArtisan('analytics:archive --period=hour');
    }
  });

  // @scenario screen=pages, state=error, viewport=1440, locale=ko, e2e=archive_on_view
  // @effects row_trend_failure_is_not_shown_as_data
  test('추이 창 요청이 실패하면 앞서 연 다른 행의 차트 대신 오류 카드를 띄운다', async ({ page }) => {
    // 엔진은 실패한 요청에 마지막 성공 값(원시 정의로 키를 잡아 다른 행의 것)이나 fallback 을 성공처럼 준다 —
    // 창은 응답의 report·label 이 고른 행과 같을 때만 그린다. 그 판정이 실제 엔진 거동에서 먹는지는 브라우저에서만 드러난다.
    const token = issueToken('core.analytics.read', 'core.analytics.update');
    const guard = new AnalyticsSettingsGuard(page.request, token);
    await guard.apply({ archive_on_view_enabled: true });

    try {
      const stamp = Date.now();
      const first = `/qa-e2e-trend-a-${stamp}`;
      const second = `/qa-e2e-trend-b-${stamp}`;
      const origin = new URL(process.env.PLAYWRIGHT_BASE_URL ?? 'https://g7.dev').origin;
      for (const marker of [first, second]) {
        const beacon = await page.request.post('/api/analytics/collect', {
          headers: { 'Content-Type': 'application/json' },
          data: { t: 'pageview', url: `${origin}${marker}`, title: 'QA', res: '1113x1113', lang: 'ko-KR', init: marker === first },
        });
        expect(beacon.status(), '비콘은 늘 204').toBe(204);
      }

      // 이 케이스가 재는 것은 추이 창이다 — 앞 케이스 정리의 집계가 1분 안이면 화면 열 때 집계가 「fresh」로
      // 건너뛰므로 표식 방문을 CLI 로 바로 집계해 둔다.
      runArtisan('analytics:archive --period=hour');
      await authenticatePage(page, token);
      await page.goto('/admin/analytics/pages?preset=today');
      // 추이 아이콘은 표 칸 안이라 data-ref 가 id 로 나가지 않는다 — 행 이름·버튼 이름(aria-label)으로 찾는다.
      const grid = page.locator('#analytics_report_grid');
      await expect(grid).toContainText(second.slice(1), { timeout: 30_000 });

      // 첫 행 — 실제 서버 응답으로 차트가 그려진다
      const ok = page.waitForResponse((r) => r.url().includes('/reports/page_url/series'));
      await grid.getByRole('row').filter({ hasText: first }).getByRole('button', { name: '이 항목의 추이 보기' }).click();
      expect((await ok).status()).toBe(200);
      await expect(page.locator('#row_trend_chart')).toHaveCount(1, { timeout: 30_000 });
      await expect(page.locator('#row_trend_error')).toHaveCount(0);
      await page.getByRole('button', { name: '닫기' }).last().click();

      // 둘째 행 — 요청이 실패한다
      await page.route('**/api/admin/analytics/reports/page_url/series**', (route) => route.fulfill({ status: 500, json: { success: false, message: 'qa failure' } }));
      const failed = page.waitForResponse((r) => r.url().includes('/reports/page_url/series'));
      await grid.getByRole('row').filter({ hasText: second }).getByRole('button', { name: '이 항목의 추이 보기' }).click();
      expect((await failed).status()).toBe(500);
      await expect(page.locator('#row_trend_error'), '실패는 오류 카드로').toBeVisible({ timeout: 30_000 });
      await expect(page.locator('#row_trend_chart'), '첫 행의 차트가 둘째 행 제목 아래 남지 않는다').toHaveCount(0);
      await expect(page.locator('#row_trend_empty'), '실패가 「기록 없음」으로 보이지 않는다').toHaveCount(0);
    } finally {
      await guard.restore();
      runSeed('cleanup-onview');
      runArtisan('analytics:archive --period=hour');
    }
  });

  // @scenario screen=pages, state=stale, viewport=1440, locale=ko, e2e=archive_on_view
  // @effects archive_now_button_needs_update_permission
  test('보기 전용 관리자에게는 멈춤 경고 문구만 보이고 「지금 집계」 버튼은 없다', async ({ page }) => {
    // admin 역할을 주지 않는 좁힌 토큰 — 권한 경계를 재려면 이 계정이 수정 권한을 갖지 않아야 한다.
    const readOnly = issueScopedToken('core.analytics.read');
    await page.route('**/api/admin/analytics/reports/page_url**', async (route) => {
      const response = await route.fetch();
      const json = await response.json();
      if (json?.data) {
        json.data.freshness = { ...(json.data.freshness ?? {}), stale: true, lag_minutes: 300, last_run_at: '2026-09-28 17:05:00', on_view: { enabled: false, status: 'disabled', max_visits: 50000 } };
      }
      await route.fulfill({ response, json });
    });

    await authenticatePage(page, readOnly);
    await page.goto('/admin/analytics/pages?preset=today');
    await expect(page.locator('#alert_archive_stale')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('#alert_archive_now_button')).toHaveCount(0);
  });
});

