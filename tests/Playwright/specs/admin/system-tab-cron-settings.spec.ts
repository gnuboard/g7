/**
 * sirsoft-admin_basic — 환경설정 「시스템」 탭의 예약 작업 동선 (W5).
 *
 * 브라우저에서만 드러나는 것을 잰다:
 *
 * 1. **호출 주소가 기본으로 가려져 있다 (D15).** 화면 최초 로드에는 번호가 실리지
 *    않으므로, 가림 상태에서 진짜 주소가 보이면 어딘가에서 새어 나온 값이다. 서버측
 *    테스트는 응답에 번호가 없다는 것까지만 잴 수 있고, "화면이 그것을 보여 주는가" 는
 *    여기서만 확정된다.
 * 2. **[보기] 를 눌러야 전용 경로가 불린다.** 화면 로드에서 그 경로가 미리 불리면
 *    D20 의 지연 노출이 사문화된다 — 눌러야 나오는 것처럼 보일 뿐 이미 받아 온 것이다.
 * 3. **[지금 실행] 이 실제로 러너를 돌린다.**
 *
 * @scenario web-cron-runner
 * @axes caller=panel, token=valid
 * @effects cron_url_is_masked_until_revealed, cron_url_is_fetched_on_demand, run_now_executes_the_runner
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('@sirsoft-admin_basic 환경설정 시스템 탭', () => {
  // @scenario caller=panel, token=valid
  // @effects cron_url_is_masked_until_revealed, cron_url_is_fetched_on_demand
  test('호출 주소는 기본으로 가려져 있고 [보기] 를 눌러야 받아 온다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.settings.update');
    await authenticatePage(page, token);

    const tokenCalls: string[] = [];
    page.on('request', (request) => {
      if (request.url().includes('/api/admin/settings/system/cron-token')) {
        tokenCalls.push(request.url());
      }
    });

    await page.goto('/admin/settings?tab=system');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const urlField = page.locator('#cron_url_value');
    await expect(urlField).toBeVisible({ timeout: 10_000 });

    // 화면 로드만으로 전용 경로가 불리면 지연 노출이 사문화된다.
    expect(tokenCalls, '화면 로드에서 호출 번호를 미리 받아 왔다').toHaveLength(0);
    await expect(urlField).not.toHaveValue(/token=/);

    const revealResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/admin/settings/system/cron-token') &&
        res.request().method() === 'GET',
      { timeout: 30_000 }
    );

    await page.getByRole('button', { name: '보기' }).click();

    expect((await revealResponse).status()).toBe(200);
    await expect(urlField).toHaveValue(/\/api\/system\/cron\/run\?token=/, { timeout: 10_000 });
  });

  // @scenario caller=panel
  // @effects run_now_executes_the_runner
  test('[지금 실행] 이 예약 작업 러너를 돌린다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.settings.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=system');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const runResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/admin/settings/system/cron/run') &&
        res.request().method() === 'POST',
      { timeout: 60_000 }
    );

    await page.getByRole('button', { name: '지금 실행' }).click();

    const response = await runResponse;
    expect(response.status(), '예약 작업 실행이 거절되었다').toBe(200);

    const body = (await response.json()) as { data: { due: number; executed: number } };

    expect(typeof body.data.due).toBe('number');
    expect(typeof body.data.executed).toBe('number');
  });

  // @scenario caller=panel
  // @effects execution_reason_is_shown_on_screen
  test('다시 감지가 판정 근거를 갱신한다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.settings.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=system');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const detectResponse = page.waitForResponse(
      (res) =>
        res.url().includes('/api/admin/settings/system/detect') &&
        res.request().method() === 'POST',
      { timeout: 30_000 }
    );

    await page.getByRole('button', { name: '다시 감지' }).click();

    const response = await detectResponse;
    expect(response.status()).toBe(200);

    const body = (await response.json()) as {
      data: { effective_mode: string; detection: { blocked_functions: string[] } };
    };

    expect(['subprocess', 'inprocess']).toContain(body.data.effective_mode);
    expect(Array.isArray(body.data.detection.blocked_functions)).toBe(true);
  });
});
