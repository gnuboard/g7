/**
 * sirsoft-admin_basic — 큐 작업 조회 권한이 없는 관리자의 「큐 모니터링」 탭 브라우저 회귀.
 *
 * 결함: 「스케줄 조회」만 있고 「큐 작업 조회」가 없는 관리자에게 스케줄 관리의 「큐 모니터링」 탭이 보였고, 열면
 *   `queue/status`·`queue/pending` 이 403 인데 화면은 권한 안내 없이 「측정 불가 / 대기 중인 작업이 없습니다.」로 그렸다.
 *   스케줄 탭에서도 `queue/status` 를 늘 불러 화면마다 403 이 났다.
 * 고침: 베이스가 로그인 관리자(current_user)를 받으면 큐 작업 조회 권한 보유를 전역에 올리고(`adminQueueJobsReadable`),
 *   거짓이면 큐 탭 항목을 숨기고 큐 주소로 직접 들어오면 권한 안내를 보이며 큐 요청을 보내지 않는다. 판정 전(새 탭 첫 화면)에
 *   나간 요청의 403 은 조용히 넘긴다. 받기 실패는 「없습니다」 대신 실패 안내로 보인다.
 *
 * 권한 경계 자체를 재므로 지정한 권한만 가진 계정(`issueScopedToken`, admin 역할 미부여)으로 연다. 권한 있는 계정 쪽은
 *   큐 API 응답을 가로채(라이브 큐를 건드리지 않음) 탭·대기 목록이 종전대로 그려지는지만 잰다. 쓰는 것이 없어 원복할 것이 없다.
 *
 * 대기: 관리자 화면은 실시간 연결 등으로 네트워크가 조용해지지 않아 `networkidle` 을 쓰지 않는다 — 주소·화면 요소·응답으로 기다린다.
 *
 * 시나리오 매니페스트: queue-management (tests/scenarios — test_files 귀속)
 * @axes e2e=queue_tab_without_queue_permission
 * @effects queue_tab_hidden_without_queue_permission, queue_tab_direct_entry_shows_permission_notice, queue_sources_not_requested_without_queue_permission
 */
import { test, expect, type Page } from '@playwright/test';
import { issueScopedToken, authenticatePage } from '../../../../../../../tests/Playwright/fixtures/auth';

const QUEUE_API = /\/api\/admin\/schedules\/queue\//;
// 첫 화면은 템플릿 자산·번들을 받느라 개발 서버에서 수십 초 걸린다(실측: 요청마다 4~8초) — 넉넉히 기다린다
const WAIT = 90_000;

/** 경로가 같은 GET 응답 하나를 기다린다. */
function responseOf(page: Page, pathname: string) {
  return page.waitForResponse((res) => new URL(res.url()).pathname === pathname && res.request().method() === 'GET', { timeout: WAIT });
}

/** 큐 API 요청 경로를 모은다. */
function trackQueueRequests(page: Page): string[] {
  const seen: string[] = [];
  page.on('request', (req) => {
    if (QUEUE_API.test(req.url())) seen.push(new URL(req.url()).pathname);
  });
  return seen;
}

test.describe('@sirsoft-admin_basic 큐 탭 — 큐 작업 조회 권한 없음', () => {
  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.schedules.read'));
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  });

  // @scenario e2e=queue_tab_without_queue_permission
  // @effects queue_tab_hidden_without_queue_permission, queue_sources_not_requested_without_queue_permission
  test('스케줄 탭: 큐 탭 항목이 없고, 판정 뒤 검색 이동은 큐 요청을 보내지 않는다', async ({ page }) => {
    const userLoaded = responseOf(page, '/api/admin/auth/user');
    const listLoaded = responseOf(page, '/api/admin/schedules');
    await page.goto('/admin/schedules');
    await Promise.all([userLoaded, listLoaded]);
    await expect(page.getByRole('tab', { name: '스케줄 작업' })).toBeVisible({ timeout: WAIT });
    await expect(page.getByRole('tab', { name: '큐 모니터링' })).toHaveCount(0);

    // 판정이 선 뒤의 이동(검색)은 큐 요청을 보내지 않는다 — 목록 응답을 받고 늦게 오는 요청이 없도록 잠시 더 본다
    const seen = trackQueueRequests(page);
    const searched = responseOf(page, '/api/admin/schedules');
    await page.locator('#search_button').click();
    await searched;
    await page.waitForTimeout(1_500);
    console.log(`queue_requests_after_search=${JSON.stringify(seen)}`);
    expect(seen).toEqual([]);
  });

  // @scenario e2e=queue_tab_without_queue_permission
  // @effects queue_tab_direct_entry_shows_permission_notice
  test('큐 주소로 직접 들어오면 권한 안내가 보이고 「대기 중인 작업이 없습니다.」·「측정 불가」는 그리지 않는다', async ({ page }) => {
    const appErrors: string[] = [];
    page.on('console', (msg) => {
      // 브라우저가 남기는 네트워크 실패 줄(Failed to load resource)은 판정 전 요청의 403 이라 뺀다 — 앱 오류만 센다
      if (msg.type() === 'error' && !/Failed to load resource/.test(msg.text())) appErrors.push(msg.text());
    });
    const userLoaded = responseOf(page, '/api/admin/auth/user');
    await page.goto('/admin/schedules/queue?view=pending');
    await userLoaded;

    await expect(page.locator('#queue_permission_denied')).toBeVisible({ timeout: WAIT });
    await expect(page.locator('#queue_permission_denied')).toContainText('큐 작업 조회');
    await expect(page.getByText('대기 중인 작업이 없습니다.')).toHaveCount(0);
    await expect(page.getByText('측정 불가')).toHaveCount(0);
    await expect(page.locator('#queue_pending_grid')).toHaveCount(0);
    console.log(`app_console_errors=${JSON.stringify(appErrors.filter((t) => /queue/.test(t)))}`);
    expect(appErrors.filter((t) => /queue_(status|pending)/.test(t))).toEqual([]);
  });
});

test.describe('@sirsoft-admin_basic 큐 탭 — 큐 작업 조회 권한 있음(종전 화면)', () => {
  test.beforeEach(async ({ page }) => {
    await authenticatePage(page, issueScopedToken('core.schedules.read', 'core.queue_jobs.read'));
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
    await page.route('**/api/admin/schedules/queue/**', async (route) => {
      const path = new URL(route.request().url()).pathname.replace('/api/admin/schedules/queue', '');
      const json = (body: unknown) => route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: body }) });
      if (path === '/status') {
        return json({ driver: 'database', connection: 'database', measurable: true, pending_count: 4, failed_count: 0,
          workers: { registered: 2, alive: 1, warning_count: 0 }, entries: [{ connection: 'database', queue: 'default' }], connections: ['database'] });
      }
      if (path === '/pending') {
        const rows = [1, 2, 3, 4].map((i) => ({ id: i, connection: 'database', queue: 'default', display_name: `E2E 대기 작업 ${i}`, state: 'ready', attempts: 0, max_tries: 1, created_at: '2026-10-29 09:00:00', available_at: '2026-10-29 09:00:00' }));
        return json({ data: rows, pagination: { total: 4, current_page: 1, last_page: 1, per_page: 20, has_more_pages: false }, meta: { measurable: true, connection: 'database', driver: 'database' } });
      }
      return route.continue();
    });
  });

  // @scenario e2e=queue_tab_without_queue_permission
  // @effects queue_tab_hidden_without_queue_permission
  test('큐 탭 항목이 보이고, 열면 대기 4건이 그려지며 권한 안내는 없다', async ({ page }) => {
    const userLoaded = responseOf(page, '/api/admin/auth/user');
    await page.goto('/admin/schedules');
    await userLoaded;
    const queueTab = page.getByRole('tab', { name: '큐 모니터링' });
    await expect(queueTab).toBeVisible({ timeout: WAIT });
    const pendingLoaded = responseOf(page, '/api/admin/schedules/queue/pending');
    await queueTab.click();
    await expect(page).toHaveURL(/\/admin\/schedules\/queue\?.*view=pending/, { timeout: WAIT });
    await pendingLoaded;
    await expect(page.locator('#queue_pending_grid')).toContainText('E2E 대기 작업 4', { timeout: WAIT });
    await expect(page.locator('#queue_permission_denied')).toHaveCount(0);
  });
});
