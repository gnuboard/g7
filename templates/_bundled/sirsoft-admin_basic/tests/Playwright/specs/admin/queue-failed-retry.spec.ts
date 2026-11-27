/**
 * sirsoft-admin_basic — 큐 탭 실패 작업 재시도·삭제 브라우저 회귀.
 *
 * 7.2.0(D7·§5.5): 스케줄 관리 > 큐 탭의 「실패」 하위 탭에서 실패 작업을 건별·일괄로 다시 넣거나 지운다.
 *   재시도·삭제는 모두 확인 창을 거치고, 서버가 돌려준 **건별 결과**(`data.results[] = {uuid, ok, message}`)를 창에
 *   줄마다 보인다. 고르는 칸은 UUID 로 담기고 지금 쪽 안에서만 유지된다. 하위 탭(대기/실패/처리 기록)은
 *   `query.view` 이고 데이터소스는 보고 있는 하위 탭 것만 부른다.
 *
 * 실패 작업은 실제로 만들기 어렵고(워커·실 payload 필요) 라이브 큐를 건드리면 안 되므로 **큐 API 응답을 가로채**
 *   화면 계약(요청 본문·건별 결과 표시·목록/배지 재조회·하위 탭별 요청)만 잰다. 실제 재시도 연쇄(jobs·처리 기록으로
 *   옮겨 감)는 서버 테스트(JobQueueControllerTest)와 라이브 매트릭스 M15 가 덮는다. 쓰는 것이 없어 원복할 것이 없다.
 *
 * 시나리오 매니페스트: queue-management (tests/scenarios — test_files 귀속)
 * @axes e2e=queue_tab_failed_retry
 * @effects bulk_retry_per_item_results
 */
import { test, expect, type Page, type Route } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

const UUIDS = [
  '00000000-0000-4000-8000-0000000000a1',
  '00000000-0000-4000-8000-0000000000a2',
  '00000000-0000-4000-8000-0000000000a3',
];

function failedRow(uuid: string, i: number) {
  return {
    id: 900 + i,
    uuid,
    connection: 'database',
    queue: 'default',
    display_name: `schedule:${10 + i} E2E 실패 작업 ${i}`,
    job_class: 'App\\Jobs\\RunScheduledTaskJob',
    schedule_id: 10 + i,
    attempts: 1,
    max_tries: 1,
    exception_summary: `RuntimeException: E2E 실패 ${i}`,
    failed_at: '2026-10-16 09:30:00',
    abilities: { can_retry: true, can_delete: true },
  };
}

type Captured = { method: string; path: string; body: any };

/** 큐 API 를 가로챈다. 실패 목록은 `remaining` 상태를 따라 줄어든다(재시도·삭제가 성공한 건은 빠진다). */
async function mockQueueApi(page: Page, captured: Captured[]) {
  let remaining = [...UUIDS];
  const json = (route: Route, status: number, body: unknown) =>
    route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });

  await page.route('**/api/admin/schedules/queue/**', async (route) => {
    const req = route.request();
    const url = new URL(req.url());
    const path = url.pathname.replace('/api/admin/schedules/queue', '');
    const method = req.method();
    if (method !== 'GET') captured.push({ method, path, body: req.postDataJSON?.() ?? null });

    if (method === 'GET' && path === '/status') {
      return json(route, 200, { success: true, data: {
        driver: 'database', connection: 'database', measurable: true, pending_count: 0, failed_count: remaining.length,
        workers: { registered: 1, alive: 1, warning_count: 0, heartbeat_stale_after_minutes: 5, long_worker: null },
        entries: [{ connection: 'database', queue: 'default' }], connections: ['database'],
      } });
    }
    if (method === 'GET' && path === '/failed') {
      const rows = remaining.map((u) => failedRow(u, UUIDS.indexOf(u) + 1));
      return json(route, 200, { success: true, data: {
        data: rows,
        pagination: { total: rows.length, current_page: 1, last_page: 1, per_page: 20, has_more_pages: false },
        abilities: { can_retry: true, can_delete: true },
      } });
    }
    if (method === 'GET' && path.startsWith('/failed/')) {
      const uuid = path.split('/')[2];
      return json(route, 200, { success: true, data: { ...failedRow(uuid, UUIDS.indexOf(uuid) + 1), exception: 'RuntimeException: E2E 실패\n#0 {main}', payload_meta: { displayName: 'E2E', maxTries: 1 } } });
    }
    if (method === 'GET' && (path === '/pending' || path === '/logs')) {
      return json(route, 200, { success: true, data: { data: [], pagination: { total: 0, current_page: 1, last_page: 1, per_page: 20, has_more_pages: false }, meta: { measurable: true, connection: 'database', driver: 'database' } } });
    }
    // 일괄 재시도 — 첫 건은 성공, 둘째 건은 실패(건별 결과가 섞인 200)
    if (method === 'POST' && path === '/failed/retry') {
      const uuids: string[] = req.postDataJSON()?.uuids ?? [];
      const results = uuids.map((u, i) => ({ uuid: u, ok: i === 0, message: i === 0 ? '다시 큐에 넣었습니다.' : '원래 연결을 찾을 수 없습니다.' }));
      remaining = remaining.filter((u) => !results.some((r) => r.ok && r.uuid === u));
      return json(route, 200, { success: true, message: `재시도 완료 — 성공 1건, 실패 ${uuids.length - 1}건`, data: { results, succeeded: 1, failed: uuids.length - 1 } });
    }
    // 건별 재시도
    if (method === 'POST' && /^\/failed\/[^/]+\/retry$/.test(path)) {
      const uuid = path.split('/')[2];
      remaining = remaining.filter((u) => u !== uuid);
      return json(route, 200, { success: true, message: '다시 큐에 넣었습니다.', data: { results: [{ uuid, ok: true, message: '다시 큐에 넣었습니다.' }] } });
    }
    // 건별 삭제
    if (method === 'DELETE' && /^\/failed\/[^/]+$/.test(path)) {
      const uuid = path.split('/')[2];
      remaining = remaining.filter((u) => u !== uuid);
      return json(route, 200, { success: true, message: '삭제했습니다.', data: { results: [{ uuid, ok: true, message: '삭제했습니다.' }] } });
    }
    return route.continue();
  });
}

test.describe('@sirsoft-admin_basic 큐 탭 실패 작업 재시도', () => {
  test.beforeEach(async ({ page }) => {
    const token = issueToken('core.schedules.read', 'core.queue_jobs.read', 'core.queue_jobs.retry', 'core.queue_jobs.delete');
    await authenticatePage(page, token);
    await page.addInitScript(() => localStorage.setItem('g7_locale', 'ko'));
  });

  // @scenario e2e=queue_tab_failed_retry
  // @effects bulk_retry_per_item_results
  test('하위 탭은 자기 목록만 부르고, 실패 탭 배지는 실패 건수다 (M2·M16)', async ({ page }) => {
    const captured: Captured[] = [];
    await mockQueueApi(page, captured);
    const seen: string[] = [];
    page.on('request', (r) => {
      const m = r.url().match(/\/api\/admin\/schedules\/queue\/(pending|failed|logs)(\?|$)/);
      if (m) seen.push(m[1]);
    });
    await page.goto('/admin/schedules/queue');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    expect(seen).toEqual(['pending']);
    await expect(page.getByRole('tab', { name: /실패/ })).toContainText('3');

    seen.length = 0;
    await page.getByRole('tab', { name: /실패/ }).click();
    await expect(page).toHaveURL(/view=failed/);
    await page.waitForLoadState('networkidle');
    expect(seen).toEqual(['failed']);
    await expect(page.locator('#queue_failed_grid')).toContainText('E2E 실패 작업 1');

    seen.length = 0;
    await page.getByRole('tab', { name: /처리 기록/ }).click();
    await expect(page).toHaveURL(/view=logs/);
    await page.waitForLoadState('networkidle');
    expect(seen).toEqual(['logs']);
    console.log('subtab_requests=pending→failed→logs ok');
  });

  // @scenario e2e=queue_tab_failed_retry
  // @effects bulk_retry_per_item_results
  test('두 줄을 골라 일괄 재시도하면 UUID 배열이 한 번 나가고 창이 건별 결과를 보이며 목록·배지가 줄어든다 (M15·M16·M26)', async ({ page }) => {
    const captured: Captured[] = [];
    await mockQueueApi(page, captured);
    await page.goto('/admin/schedules/queue?view=failed');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const grid = page.locator('#queue_failed_grid');
    await expect(grid).toContainText('E2E 실패 작업 1');
    const checkboxes = grid.locator('tbody input[type=checkbox]');
    // 제어형 체크박스는 클릭 뒤 상태 반영이 비동기라 locator.check() 의 즉시 판정이 실패한다 — 클릭 후 단언한다.
    await checkboxes.nth(0).click();
    await expect(checkboxes.nth(0)).toBeChecked();
    await checkboxes.nth(1).click();
    await expect(checkboxes.nth(1)).toBeChecked();
    await expect(page.locator('#queue_bulk_retry_button')).toBeEnabled();
    await page.locator('#queue_bulk_retry_button').click();

    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText('2개');
    // 연타해도 요청은 한 번이다(진행 중 버튼 잠금).
    const submit = page.locator('#queue_confirm_submit');
    await submit.dblclick();
    await expect(page.locator('#queue_confirm_results')).toBeVisible({ timeout: 10_000 });

    const bulk = captured.filter((c) => c.method === 'POST' && c.path === '/failed/retry');
    console.log(`bulk_retry_requests=${bulk.length}, body=${JSON.stringify(bulk[0]?.body)}`);
    expect(bulk).toHaveLength(1);
    expect(bulk[0].body.uuids).toEqual([UUIDS[0], UUIDS[1]]);

    // 건별 결과 — 성공 1줄, 실패 1줄(서버 메시지 그대로)
    const results = page.locator('#queue_confirm_results li');
    await expect(results).toHaveCount(2);
    await expect(results.nth(0)).toContainText(UUIDS[0]);
    await expect(results.nth(1)).toContainText('원래 연결을 찾을 수 없습니다.');

    await page.locator('#queue_confirm_cancel').click();
    await expect(dialog).toBeHidden();
    // 성공한 1건이 빠져 2줄이 남고, 하위 탭 배지도 2 다(배지 = 목록 행 수).
    await expect(grid.locator('tbody tr')).toHaveCount(2);
    await expect(page.getByRole('tab', { name: /실패/ })).toContainText('2');
    // 고른 줄은 비워진다.
    await expect(page.locator('#queue_bulk_retry_button')).toBeDisabled();
  });

  // @scenario e2e=queue_tab_failed_retry
  // @effects delete_forgets_failed_job
  test('행 메뉴 상세 → 예외 전문을 받아 보이고, 행 메뉴 삭제는 그 UUID 로 DELETE 를 보낸다', async ({ page }) => {
    const captured: Captured[] = [];
    await mockQueueApi(page, captured);
    await page.goto('/admin/schedules/queue?view=failed');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const grid = page.locator('#queue_failed_grid');
    const firstRow = grid.locator('tbody tr').first();

    const detail = page.waitForRequest((r) => r.url().includes(`/queue/failed/${UUIDS[0]}`) && r.method() === 'GET');
    await firstRow.getByRole('button', { name: '작업' }).click();
    await page.getByRole('menuitem', { name: '상세' }).click();
    await detail;
    await expect(page.locator('#queue_job_detail_exception')).toContainText('#0 {main}');
    // 창 머리의 「모달 닫기」(×)와 바닥의 「닫기」가 함께 걸리지 않게 정확히 고른다
    await page.getByRole('dialog').getByRole('button', { name: '닫기', exact: true }).click();

    await firstRow.getByRole('button', { name: '작업' }).click();
    await page.getByRole('menuitem', { name: '삭제' }).click();
    await page.locator('#queue_confirm_submit').click();
    await expect(page.locator('#queue_confirm_results')).toBeVisible({ timeout: 10_000 });
    const deletes = captured.filter((c) => c.method === 'DELETE');
    expect(deletes.map((d) => d.path)).toEqual([`/failed/${UUIDS[0]}`]);
  });

  // D7(M1 실측): 행 메뉴 「재시도」 확인 창의 버튼을 더블클릭하면 POST 2건이 나가 두 번째 404 가 성공 문구를 덮었다.
  // @scenario e2e=queue_tab_failed_retry
  // @effects bulk_retry_per_item_results
  test('행 메뉴 재시도 확인 버튼을 더블클릭해도 요청은 1건이고 창은 성공을 보인다', async ({ page }) => {
    const captured: Captured[] = [];
    await mockQueueApi(page, captured);
    await page.goto('/admin/schedules/queue?view=failed');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });
    const firstRow = page.locator('#queue_failed_grid tbody tr').first();

    await firstRow.getByRole('button', { name: '작업' }).click();
    await page.getByRole('menuitem', { name: '재시도' }).click();
    await page.locator('#queue_confirm_submit').dblclick();
    await expect(page.locator('#queue_confirm_results')).toBeVisible({ timeout: 10_000 });

    const singles = captured.filter((c) => c.method === 'POST' && c.path === `/failed/${UUIDS[0]}/retry`);
    expect(singles).toHaveLength(1);
    await expect(page.locator('#queue_confirm_results li')).toHaveCount(1);
    await expect(page.locator('#queue_confirm_results li').first()).toContainText(UUIDS[0]);
  });
});
