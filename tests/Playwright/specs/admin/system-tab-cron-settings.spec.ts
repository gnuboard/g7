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
 * 4. **왕복이 도는 동안 그 버튼이 잠긴다.** 진행 표시가 없으면 운영자는 눌리지 않은 줄 알고
 *    다시 누른다 — 번호 재발급이 두 번 돌면 방금 등록한 주소가 그 자리에서 다시 무효가 된다.
 *    레이아웃 JSON 구조 검사는 `disabled` 표현식이 있다는 것까지만 알고, 그것이 실제로
 *    눌리지 않게 만드는지는 브라우저에서만 확정된다.
 *
 * @scenario web-cron-runner
 * @axes caller=panel, token=valid
 * @effects cron_url_is_masked_until_revealed, cron_url_is_fetched_on_demand, run_now_executes_the_runner, in_flight_controls_are_blocked_until_the_call_returns
 */
import { test, expect } from '@playwright/test';
// 루트 Playwright 트리의 spec 은 루트 픽스처를 쓴다. `admin-template-auth` 는 템플릿
// 패키지 안에만 있는 파일이라, 이 자리에서 부르면 모듈 해석이 실패해 **spec 이 수집조차
// 되지 않는다**(`--list` 가 0건). 형제 spec(`settings-core-update-tab.spec.ts`)과 같은
// 출처를 쓴다 — 템플릿 픽스처도 결국 이 둘을 재노출할 뿐이다.
import { issueToken, authenticatePage } from '../../fixtures/auth';

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

  // @scenario caller=panel, token=valid
  // @effects in_flight_controls_are_blocked_until_the_call_returns
  test('왕복이 도는 동안 그 버튼은 잠기고, 끝나면 다시 눌린다', async ({ page }) => {
    const token = issueToken('core.settings.read', 'core.settings.update');
    await authenticatePage(page, token);

    await page.goto('/admin/settings?tab=system');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const reveal = page.getByRole('button', { name: '보기' });
    await expect(reveal).toBeEnabled({ timeout: 10_000 });

    // 응답을 붙잡아 **왕복 중**이라는 상태를 관측 가능한 길이로 늘린다. 이 지연이 없으면
    // 요청이 즉시 끝나 「잠겼다가 풀린다」의 잠긴 구간을 잴 수 없다 — 스피너가 없어도
    // 초록이 되는 공허한 검사가 된다.
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });

    await page.route('**/api/admin/settings/system/cron-token*', async (route) => {
      await held;
      await route.continue();
    });

    await reveal.click();

    // 왕복이 끝나기 전에는 다시 눌리지 않는다 — 번호 조회·재발급처럼 자격증명을 다루는
    // 조작이 연타로 두 번 도는 것을 막는다.
    await expect(reveal).toBeDisabled({ timeout: 10_000 });

    release();

    await expect(page.locator('#cron_url_value')).toHaveValue(
      /\/api\/system\/cron\/run\?token=/,
      { timeout: 15_000 }
    );
  });
});
