/**
 * sirsoft-admin_basic — 활동 로그 「삭제 직전 기록만 보기」 필터
 *
 * 영구 삭제·선언 정리·커스텀 번역 삭제 직전에 남긴 기록만 골라 보는 체크박스다. 고르는 동안에는 화면
 * 상태(`_local.filter`)에만 담기고 [검색]을 눌러야 주소(`deletion_snapshot=1`)로 옮겨 가며, 목록 데이터소스는
 * 주소만 읽는다. 그래서 세 지점이 따로 어긋날 수 있다 — 주소에 실리지 않음 · 주소에는 있는데 목록 요청에
 * 빠짐 · 새로고침하면 체크가 풀림. [초기화]는 체크와 주소를 함께 비워야 한다.
 *
 * 목록 응답은 고정한다(개발 DB 에 삭제 직전 기록이 있는지에 좌우되지 않도록). 요청 URL 은 브라우저가
 * 실제로 만든 것을 검사한다. 이 화면은 읽기만 한다.
 *
 * @scenario-manifest tests/scenarios/e2e-activity-log-deletion-snapshot-filter.yaml
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page, Route } from '@playwright/test';

const LIST_PATH = '/api/admin/activity-logs';

/**
 * 목록 응답 — 필터가 켜졌으면 삭제 직전 기록 1건, 아니면 일반 기록 2건.
 *
 * @param snapshotOnly deletion_snapshot 요청 여부
 * @returns 목록 응답 본문
 */
function listBody(snapshotOnly: boolean): Record<string, unknown> {
  const row = (id: number, action: string, description: string) => ({
    id,
    log_type: 'system',
    action,
    action_label: action,
    description,
    localized_description: description,
    loggable_type: null,
    loggable_id: null,
    user: null,
    ip_address: '127.0.0.1',
    has_details: snapshotOnly,
    created_at: '2026-11-16T10:00:00+09:00',
  });
  const rows = snapshotOnly
    ? [row(9001, 'layout.force_deleted', 'E2E 삭제 직전 기록')]
    : [row(9002, 'user.update', 'E2E 일반 기록 1'), row(9003, 'user.update', 'E2E 일반 기록 2')];
  return {
    success: true,
    data: {
      data: rows,
      pagination: { current_page: 1, last_page: 1, per_page: 20, total: rows.length, has_more_pages: false },
      abilities: { can_delete: false },
    },
  };
}

/**
 * 목록 GET 을 고정 응답으로 대체하고 요청 URL 을 모읍니다. 쓰기 요청은 안전망으로 막습니다.
 *
 * @param page Playwright page
 * @param seen 목록 요청 URL 기록
 */
async function stubList(page: Page, seen: URL[]): Promise<void> {
  await page.route('**/api/**', async (route: Route) => {
    if (route.request().method() === 'GET') {
      await route.fallback();
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
  });
  await page.route(
    (url) => url.pathname === LIST_PATH,
    async (route: Route) => {
      const url = new URL(route.request().url());
      seen.push(url);
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(listBody(url.searchParams.get('deletion_snapshot') === '1')),
      });
    },
  );
}

/** 필터 체크박스 */
function snapshotCheckbox(page: Page) {
  return page.locator('#deletion_snapshot_filter input[type="checkbox"]');
}

/** 마지막 목록 요청의 deletion_snapshot 값 */
function lastSnapshotParam(seen: URL[]): string | null {
  return seen.length > 0 ? seen[seen.length - 1].searchParams.get('deletion_snapshot') : null;
}

test.describe('@sirsoft-admin_basic 활동 로그 — 삭제 직전 기록만 보기', () => {
  test.describe.configure({ timeout: 120_000 });

  /**
   * @scenario case=search_reload_reset
   * @effects deletion_snapshot_search_puts_flag_in_url, deletion_snapshot_list_request_carries_flag, deletion_snapshot_checked_after_reload, deletion_snapshot_reset_clears_flag
   */
  test('켜고 [검색] → 주소·목록 요청에 deletion_snapshot=1, 새로고침 뒤에도 체크, [초기화]로 해제', async ({
    page,
    adminDashboardToken,
  }) => {
    const seen: URL[] = [];
    await stubList(page, seen);
    await authenticatePage(page, adminDashboardToken);

    await page.goto('/admin/activity-logs');
    const checkbox = snapshotCheckbox(page);
    await expect(checkbox).toBeVisible({ timeout: 60_000 });
    await expect(page.getByText('E2E 일반 기록 1')).toBeVisible({ timeout: 30_000 });

    // 첫 화면 — 꺼져 있고 목록 요청에도 없다
    await expect(checkbox).not.toBeChecked();
    expect(lastSnapshotParam(seen)).toBeNull();
    // 안내 문구가 함께 보인다
    await expect(page.locator('#deletion_snapshot_filter')).toContainText('삭제 직전에 남긴 기록만 보입니다');

    // 체크만으로는 조회하지 않는다 — [검색] 이 주소로 옮긴다
    const before = seen.length;
    await checkbox.check();
    await expect(checkbox).toBeChecked();
    expect(page.url()).not.toContain('deletion_snapshot');

    await page.locator('#filter_actions button.btn-primary').click();
    await expect.poll(() => new URL(page.url()).searchParams.get('deletion_snapshot'), { timeout: 20_000 }).toBe('1');
    await expect.poll(() => seen.length, { timeout: 20_000 }).toBeGreaterThan(before);
    expect(lastSnapshotParam(seen)).toBe('1');
    expect(seen[seen.length - 1].pathname).toBe(LIST_PATH);
    await expect(page.getByText('E2E 삭제 직전 기록')).toBeVisible({ timeout: 20_000 });

    // 새로고침 — 주소에서 체크를 되살리고 같은 조건으로 다시 받는다
    const beforeReload = seen.length;
    await page.reload();
    await expect(snapshotCheckbox(page)).toBeVisible({ timeout: 60_000 });
    await expect(snapshotCheckbox(page)).toBeChecked();
    await expect.poll(() => seen.length, { timeout: 30_000 }).toBeGreaterThan(beforeReload);
    expect(lastSnapshotParam(seen)).toBe('1');
    await expect(page.getByText('E2E 삭제 직전 기록')).toBeVisible({ timeout: 20_000 });

    // [초기화] — 체크 해제, 주소와 목록 요청에서 빠진다
    const beforeReset = seen.length;
    await page.locator('#filter_reset_button').click();
    await expect(snapshotCheckbox(page)).not.toBeChecked();
    await expect.poll(() => new URL(page.url()).searchParams.get('deletion_snapshot'), { timeout: 20_000 }).toBeNull();
    await expect.poll(() => seen.length, { timeout: 20_000 }).toBeGreaterThan(beforeReset);
    expect(lastSnapshotParam(seen)).toBeNull();
    await expect(page.getByText('E2E 일반 기록 1')).toBeVisible({ timeout: 20_000 });
  });
});
