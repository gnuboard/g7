/**
 * E2E: 코어 동의 런타임이 로그인·로그아웃 경계에서 주체를 바꿔 다시 읽는다 (#639)
 *
 * @scenario case=e2e_login_boundary
 * @effects consent_snapshot_follows_member_after_spa_login, consent_snapshot_returns_to_guest_after_logout
 *
 * 배경: 로그인은 SPA 이동이라 페이지가 다시 뜨지 않는다. 예전에는 이 플러그인이 부팅 때 한 번만 동의 상태를
 * 조회해, 게스트로 동의한 브라우저에서 동의 이력 없는 회원으로 로그인해도 차단 엔진이 게스트 동의(분석 허용)를
 * 그대로 썼다. 이제 코어 런타임이 인증 경계마다 `/api/consent` 를 다시 부르고 `_global.consent` 를 바꾼다.
 *
 * 검증:
 *  1. 「모두 동의」 게스트 쿠키로 진입 → 스냅샷 ready · 분석 허용
 *  2. 동의 이력 없는 회원으로 로그인(SPA) → 토큰을 단 `/api/consent` 재조회 → 분석 불허
 *  3. 로그아웃 → 토큰 없는 `/api/consent` 재조회 → 게스트 스냅샷(ready)으로 복귀
 */
import type { Page, Request } from '@playwright/test';
import { test, expect } from '../../fixtures/gdpr-guest-login-seed';

type Snapshot = { status: string; categories: Record<string, boolean>; needsRenewal: boolean } | null;

/** 코어 동의 파사드의 현재 스냅샷 */
async function consentSnapshot(page: Page): Promise<Snapshot> {
  try {
    return await page.evaluate(() => (window as any).G7Core?.consent?.getSnapshot?.() ?? null);
  } catch {
    // 로그아웃은 전체 이동을 일으킨다 — 이동 중에는 실행 컨텍스트가 사라지므로 null 로 돌려 poll 이 다시 읽게 한다.
    return null;
  }
}

/** `/api/consent` 요청인지 */
function isConsentRequest(request: Request): boolean {
  return new URL(request.url()).pathname === '/api/consent';
}

// @scenario case=e2e_login_boundary
// @effects consent_snapshot_follows_member_after_spa_login, consent_snapshot_returns_to_guest_after_logout
test('#639 게스트 동의 → 회원 로그인 → 로그아웃 경계마다 동의 스냅샷이 주체를 따라간다', async ({ page, gdprGuestLoginSeed }) => {
  const consentRequests: Array<{ authorized: boolean }> = [];
  page.on('request', (request) => {
    if (isConsentRequest(request)) {
      consentRequests.push({ authorized: Boolean(request.headers()['authorization']) });
    }
  });

  await page.goto('/');
  await page.context().addCookies([
    { name: 'gdpr_session', value: gdprGuestLoginSeed.guest_session_cookie_value, url: page.url() },
  ]);
  await page.reload();

  // 1. 동의한 게스트 — 분석 허용
  await expect.poll(async () => (await consentSnapshot(page))?.status, { timeout: 15_000 }).toBe('ready');
  expect((await consentSnapshot(page))?.categories.analytics).toBe(true);
  expect(consentRequests.some((r) => !r.authorized)).toBe(true);

  // 2. 동의 이력 없는 회원으로 SPA 로그인
  const beforeLogin = consentRequests.length;
  await page.goto('/login');
  await page.locator('input[name="email"]').first().fill(gdprGuestLoginSeed.member_email);
  await page.locator('input[name="password"]').first().fill(gdprGuestLoginSeed.member_password);
  await page.locator('button[type="submit"]').first().click();
  await expect(page).not.toHaveURL(/\/login/, { timeout: 20_000 });

  await expect.poll(async () => {
    const snapshot = await consentSnapshot(page);
    return snapshot?.status === 'ready' && snapshot.categories.analytics === false;
  }, { timeout: 15_000 }).toBe(true);
  expect(consentRequests.slice(beforeLogin).some((r) => r.authorized)).toBe(true);

  // 3. 로그아웃 — 게스트로 돌아와 토큰 없이 다시 읽는다
  const beforeLogout = consentRequests.length;
  await page.evaluate(() => (window as any).G7Core.dispatch({ handler: 'logout' }));

  await expect.poll(() => consentRequests.slice(beforeLogout).some((r) => !r.authorized), { timeout: 15_000 }).toBe(true);
  await expect.poll(async () => (await consentSnapshot(page))?.status, { timeout: 15_000 }).toBe('ready');
  expect(consentRequests.slice(beforeLogout).every((r) => !r.authorized)).toBe(true);
});
