/**
 * 로그인 2단계 입력(이메일 → 비밀번호)에서 이메일이 사라지지 않는다 — 엔진 심각 회귀 대장 `login-two-step-email-loss` (7.1.1 회귀 점검).
 * 템플릿 sirsoft-basic (유저 화면 `/login`). 관리자 짝: `templates/_bundled/sirsoft-admin_basic/tests/Playwright/specs/admin/admin-login-two-step-email-retained.spec.ts`.
 *
 * 배경: 2026-04-22 엔진 상태 갱신 변경이 단위 129/129 green 인 채 로그인 폼의 이메일을 비웠다(엔진 심각 회귀 대장의 같은 행).
 *   7.1.1 은 자동바인딩 칸의 setLocal 덮어쓰기 기록을 render:false 뒤에 지우도록 바꿨으므로, 같은 대장 행을 실제 키 입력으로 다시 잰다.
 *
 * 판정: 실제 키 입력으로 이메일 → 비밀번호 → 제출. 로그인 요청은 가로채 본문(이메일)을 보고 401 로 답한다(서버에 닿지 않음).
 *   오류 문구 표시 · 두 칸 값 유지 · 오류 알림 중복 없음.
 *
 * @scenario-manifest tests/scenarios/e2e-login-two-step-email-retained.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- login-two-step-email-retained --workers=1
 */
import { test, expect } from '@playwright/test';
import { guardWrites, waitForSpa } from '../fixtures/env-account';

const EMAIL = 'e2e-two-step@example.test';
const PASSWORD = 'not-a-real-password-1';
const FAIL_MESSAGE = 'E2E 이메일 또는 비밀번호가 올바르지 않습니다.';

test.describe('사용자 로그인 — 이메일·비밀번호 2단계 입력 (대장 login-two-step-email-loss)', () => {
  /**
   * @scenario case=user_login_401
   * @effects login_body_carries_email, login_error_shown, login_inputs_kept, login_error_toast_not_duplicated
   */
  test('이메일 → 비밀번호 키 입력 후 제출 — 본문에 이메일, 401 문구, 두 칸 값 유지', async ({ page }) => {
    await guardWrites(page);
    const bodies: Array<Record<string, unknown>> = [];
    await page.route('**/api/auth/login', (route) => {
      bodies.push(route.request().postDataJSON() ?? {});

      return route.fulfill({ status: 401, contentType: 'application/json', body: JSON.stringify({ success: false, message: FAIL_MESSAGE }) });
    });

    await page.goto('/login');
    await waitForSpa(page);
    const email = page.locator('input[name="email"]').first();
    const password = page.locator('input[name="password"]').first();
    await expect(email).toBeVisible({ timeout: 20_000 });

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
    await expect(password, '제출 뒤 비밀번호 유지').toHaveValue(PASSWORD);
    expect(await page.getByText(FAIL_MESSAGE).count(), '같은 오류 문구가 겹쳐 뜨지 않는다(문구 칸 1 + 알림 최대 1)').toBeLessThanOrEqual(2);
    expect(bodies.length, '한 번 제출에 요청 한 번').toBe(1);
  });
});
