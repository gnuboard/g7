/**
 * 실제 배포 번들의 인증 진단이 빈 사용자와 남은 토큰을 로그인으로 오인하지 않는다.
 * 브라우저 컨텍스트 안에서만 입력을 교체하며 실제 인증·저장소·덤프는 변경하지 않는다.
 * @scenario link=devtools_auth_diagnostic
 * @effects empty_user_is_not_authenticated
 */
import { test, expect } from '@playwright/test';

test('DevTools 인증 진단: 빈 사용자·명시적 로그아웃·상태 fallback', async ({ page }) => {
  await page.goto('/');
  await page.waitForFunction(() => Boolean((window as any).G7Core?.__runtime?.G7DevToolsCore));
  const results = await page.evaluate(() => {
    const original = (window as any).G7Core;
    const devtools = original.__runtime.G7DevToolsCore.getInstance();
    const cases = [
      { user: [], authenticated: false },
      { user: {}, authenticated: false },
      { user: null, authenticated: false },
      { user: { id: 7, email: 'fixture@example.test' }, authenticated: false },
      { user: null, authenticated: true },
      { user: { id: 7, email: 'fixture@example.test' }, authenticated: undefined },
    ];
    try {
      return cases.map(({ user, authenticated }) => {
        (window as any).G7Core = {
          api: { getToken: () => 'diagnostic-fixture-token' },
          auth: authenticated === undefined ? undefined : {
            getUser: () => user,
            isAuthenticated: () => authenticated,
          },
          state: { get: () => ({ _global: { user } }) },
        };
        const state = devtools.getAuthDebugInfo().state;
        return { authenticated: state.isAuthenticated, userId: state.user?.id ?? null };
      });
    } finally {
      (window as any).G7Core = original;
    }
  });
  expect(results).toEqual([
    { authenticated: false, userId: null },
    { authenticated: false, userId: null },
    { authenticated: false, userId: null },
    { authenticated: false, userId: 7 },
    { authenticated: true, userId: null },
    { authenticated: true, userId: 7 },
  ]);
  await expect(page.locator('#g7-devtools-root')).toBeAttached();
});
