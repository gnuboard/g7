/**
 * 관리자 로그인 2단계 입력(이메일 → 비밀번호)에서 이메일이 사라지지 않는다 — 엔진 심각 회귀 대장 `login-two-step-email-loss` (7.1.1 회귀 점검).
 * 템플릿 sirsoft-admin_basic (`/admin/login`). 사용자 짝: `templates/_bundled/sirsoft-basic/tests/Playwright/specs/login-two-step-email-retained.spec.ts`.
 *
 * 판정: 실제 키 입력으로 이메일 → 비밀번호 → 제출. 로그인 요청은 가로채 본문(이메일)을 보고 401 로 답한다(서버에 닿지 않음 —
 *   계정이 필요 없다). 오류 문구 표시 · 두 칸 값 유지 · 오류 알림 중복 없음.
 *
 * @scenario-manifest tests/scenarios/e2e-admin-login-two-step-email-retained.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- admin-login-two-step-email-retained --workers=1
 */
import { test, expect } from '@playwright/test';

const EMAIL = 'e2e-admin-two-step@example.test';
const PASSWORD = 'not-a-real-password-1';
const FAIL_MESSAGE = 'E2E 관리자 이메일 또는 비밀번호가 올바르지 않습니다.';

test.describe('관리자 로그인 — 이메일·비밀번호 2단계 입력 (대장 login-two-step-email-loss)', () => {
  /**
   * @scenario case=admin_login_401
   * @effects login_body_carries_email, login_error_shown, login_inputs_kept, login_error_toast_not_duplicated
   */
  test('이메일 → 비밀번호 키 입력 후 제출 — 본문에 이메일, 401 문구, 두 칸 값 유지', async ({ page }) => {
    // 쓰기 요청은 서버에 보내지 않는다(로그인 요청은 아래에서 따로 401 로 답한다)
    await page.route(
      (url) => url.pathname.startsWith('/api/'),
      (route) => {
        const m = route.request().method();
        if (m === 'GET' || m === 'HEAD') return route.fallback();

        return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true,"data":{}}' });
      },
    );
    const bodies: Array<Record<string, unknown>> = [];
    await page.route('**/api/auth/admin/login', (route) => {
      bodies.push(route.request().postDataJSON() ?? {});

      return route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, message: FAIL_MESSAGE }) });
    });

    await page.goto('/admin/login');
    const email = page.locator('input[name="email"]').first();
    const password = page.locator('input[name="password"]').first();
    await expect(email).toBeVisible({ timeout: 30_000 });

    await email.click();
    await email.pressSequentially(EMAIL, { delay: 30 });
    await password.click();
    await password.pressSequentially(PASSWORD, { delay: 30 });
    await expect(email, '비밀번호 칸으로 옮긴 뒤 이메일 유지').toHaveValue(EMAIL);
    await password.press('Enter');

    await expect.poll(() => bodies.length, { message: '로그인 요청을 보냈다', timeout: 15_000 }).toBeGreaterThan(0);
    expect(bodies[0].email, '요청 본문 이메일').toBe(EMAIL);
    await expect(page.getByText(FAIL_MESSAGE).first(), '401 오류 문구').toBeVisible({ timeout: 10_000 });
    await page.waitForTimeout(1_000);
    await expect(email, '제출 뒤 이메일 유지').toHaveValue(EMAIL);
    expect(await page.getByText(FAIL_MESSAGE).count(), '같은 오류 문구가 겹쳐 뜨지 않는다(문구 칸 1 + 알림 최대 1)').toBeLessThanOrEqual(2);
    expect(bodies.length, '한 번 제출에 요청 한 번').toBe(1);
  });
});
