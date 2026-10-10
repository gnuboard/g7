/**
 * E2E: 게스트로 쿠키 배너를 닫은 뒤 동의 이력 없는 계정으로 로그인 시 배너 재노출 회귀 가드
 *
 * @scenario case=e2e_guest_login_reappear
 * @effects banner_hidden_for_consented_guest, banner_reappears_after_login_with_no_consent_history, consent_snapshot_follows_member_after_spa_login
 *
 * 배경: `_global.gdprBannerDismissedFor` 가 "닫힘 여부"(boolean)가 아니라 "누가 닫았는지"
 * (사용자 식별자)를 저장하도록 수정되기 전에는, 게스트로 배너를 닫은 뒤 동의 이력이 없는
 * 다른 계정으로 로그인해도 배너가 계속 숨겨져 있는 결함이 있었다 (브라우저 스크린샷으로 재현·지적).
 * 현재는 cookie_banner.json 의 노출 조건이 `_global.gdprBannerDismissedFor !== (사용자 식별자)`
 * 로 비교하므로, 로그인으로 사용자가 바뀌면(게스트 uuid → 회원 uuid) 다시 노출되어야 한다.
 *
 * 검증:
 *  1. 서명된 "모두 동의" 게스트 세션 쿠키를 심은 상태로 홈에 진입하면 배너가 노출되지 않는다
 *     (게스트 자신은 이미 동의했으므로 정상)
 *  2. 로그인 폼으로 동의 이력이 전혀 없는 신규 계정에 실제로 로그인하면 배너가 다시 노출된다
 *     (회귀 재현 케이스 — 수정 전에는 여기서 실패)
 */
import { test, expect } from '../../fixtures/gdpr-guest-login-seed';

const BANNER = '#gdpr_cookie_banner';

// @scenario case=e2e_guest_login_reappear
// @effects banner_hidden_for_consented_guest, banner_reappears_after_login_with_no_consent_history, consent_snapshot_follows_member_after_spa_login
test('#509 보안검토 - 게스트로 동의 완료 후 미동의 계정으로 로그인하면 배너가 다시 노출된다', async ({ page, gdprGuestLoginSeed }) => {
  // 먼저 baseURL 로 진입해 현재 오리진을 확보한 뒤(하드코딩 회피), 서명된 게스트 세션
  // 쿠키를 심는다 (이미 "모두 동의" 이력이 기록된 상태).
  await page.goto('/');
  await page.context().addCookies([
    {
      name: 'gdpr_session',
      value: gdprGuestLoginSeed.guest_session_cookie_value,
      // `url` 과 `path` 는 함께 줄 수 없다 — 둘 중 하나만 받는다. 경로는 url 에서 도출된다.
      url: page.url(),
    },
  ]);

  await page.reload();
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 이미 동의한 게스트이므로 배너가 노출되지 않아야 한다.
  await expect(page.locator(BANNER)).not.toBeVisible({ timeout: 15_000 });

  // 2. 로그인 폼으로 동의 이력이 전혀 없는 신규 계정에 실제로 로그인한다.
  await page.goto('/login');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  const emailInput = page.locator('input[name="email"]').first();
  await expect(emailInput).toBeVisible({ timeout: 15_000 });
  await emailInput.fill(gdprGuestLoginSeed.member_email);

  const passwordInput = page.locator('input[name="password"]').first();
  await passwordInput.fill(gdprGuestLoginSeed.member_password);

  await page.locator('button[type="submit"]').first().click();

  // 로그인 성공 후 리다이렉트 대기 (로그인 페이지를 벗어남).
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });

  // 회귀 가드: 동의 이력 없는 계정으로 전환됐으므로 배너가 다시 노출되어야 한다.
  await expect(page.locator(BANNER)).toBeVisible({ timeout: 15_000 });

  // #639: 배너만이 아니라 코어 동의 런타임도 회원 기준으로 다시 읽어 게스트 동의(분석 허용)를 버린다 —
  // 자동 차단 엔진은 이 스냅샷을 따른다.
  await expect.poll(async () => page.evaluate(() => {
    const snapshot = (window as any).G7Core?.consent?.getSnapshot?.();
    return snapshot?.status === 'ready' && snapshot.categories?.analytics === false;
  }), { timeout: 15_000 }).toBe(true);
});