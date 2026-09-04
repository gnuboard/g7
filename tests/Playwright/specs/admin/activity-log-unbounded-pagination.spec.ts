/**
 * E2E: 총 건수를 정확히 세지 못한 목록의 페이지 번호 목록
 *
 * @scenario activity_log_unbounded_pager_numbers, activity_log_unbounded_pager_no_last
 * @effects page_numbers_visible, last_page_jump_hidden
 *
 * 총 건수 집계 상한(관리자 > 환경설정 > 고급)을 활동 로그 건수보다 작게 낮춰
 * `last_page: null` 응답을 실제 서버에서 만들고, 활동 로그 화면의 페이저가
 *  1. 1 페이지 번호 버튼과 다음 페이지(2) 번호 버튼을 그리고
 *  2. 마지막 페이지 점프 버튼은 그리지 않으며
 *  3. 번호 버튼을 눌러 그 페이지로 이동하는지
 * 를 확인한다. 종전에는 현재 페이지 숫자 하나만 남아 앞 페이지로 뛸 방법이 없었다.
 * 테스트가 끝나면 상한을 원래 값으로 되돌린다.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const SETTINGS_URL = '/api/admin/settings';

async function readResultCap(page: import('@playwright/test').Page, token: string): Promise<number> {
  const res = await page.request.get(SETTINGS_URL, { headers: { Authorization: `Bearer ${token}` } });
  expect(res.ok()).toBeTruthy();
  const body = await res.json();
  return Number(body?.data?.advanced?.pagination_result_cap ?? 10000);
}

async function writeResultCap(page: import('@playwright/test').Page, token: string, cap: number): Promise<void> {
  const res = await page.request.post(SETTINGS_URL, {
    headers: { Authorization: `Bearer ${token}` },
    data: { advanced: { pagination_result_cap: cap } },
  });
  expect(res.ok(), `설정 저장 실패: ${res.status()}`).toBeTruthy();
}

// @scenario link=activity_log_unbounded_pager_numbers, permitted=na
// @effects page_numbers_visible, last_page_jump_hidden
test('#519 후속 - 총 건수를 모르는 활동 로그에서도 페이지 번호가 그려지고 마지막 페이지 버튼만 감춰진다', async ({ page }) => {
  const token = issueToken('core.settings.update');
  await authenticatePage(page, token);

  const originalCap = await readResultCap(page, token);
  await writeResultCap(page, token, 5);

  try {
    await page.goto('/admin/activity-logs?per_page=5');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const list = await page.request.get('/api/admin/activity-logs?per_page=5', {
      headers: { Authorization: `Bearer ${token}` },
    });
    const listBody = await list.json();
    const meta = listBody?.data?.pagination ?? listBody?.meta;
    test.skip(!meta || meta.last_page !== null, '활동 로그가 상한(5건) 이하라 last_page 가 계산된다 — 이 시나리오의 전제가 성립하지 않는다');

    // 페이저 루트는 Div(role=navigation) 이라 태그가 아니라 역할·이름으로 찾는다
    const nav = page.getByRole('navigation', { name: '페이지네이션' }).first();
    await expect(nav).toBeVisible({ timeout: 15_000 });

    const pageButton = (n: number) => nav.locator('button', { hasText: new RegExp(`^${n}$`) });
    await expect(pageButton(1)).toBeVisible();
    await expect(pageButton(1)).toHaveAttribute('aria-current', 'page');
    await expect(pageButton(2)).toBeVisible();
    await expect(nav.locator('button[aria-label="마지막 페이지"]')).toHaveCount(0);
    await expect(nav.locator('button[aria-label="다음 페이지"]')).toBeEnabled();

    await pageButton(2).click();
    await expect(pageButton(2)).toHaveAttribute('aria-current', 'page', { timeout: 15_000 });
    await expect(pageButton(1)).toBeVisible();
    await expect(pageButton(3)).toBeVisible();
  } finally {
    await writeResultCap(page, token, originalCap);
  }
});
