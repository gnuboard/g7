/**
 * 회원 새로고침에서 장바구니 키 유지 E2E (#639 ⑦, 결정 D15).
 *
 * 기본 템플릿은 로그인 경계에서 장바구니 키를 새로 발급한다. 그런데 템플릿 초기화는 window.load 에 돌아 부팅 인증
 * 확인보다 먼저 설치될 수 있고, 그 뒤 도착한 인증 확인 결과(비인증 → 인증)를 로그인으로 보면 회원이 새로고침할
 * 때마다 키를 다시 받았다(실측 2026-10-06: 새로고침 3회 → 키 3개, 발급 요청 4회). 부팅 순서는 브라우저에서만
 * 드러나므로 단위 테스트가 아니라 여기서 잠근다 — 실제 로그인의 재발급은 템플릿 Vitest 가 잠근다.
 *
 * @scenario surface=auth_boundary, storage=guest_keys, e2e=member_reload
 * @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import type { Page } from '@playwright/test';

/**
 * 부팅 인증 확인이 끝날 때까지 기다린다.
 *
 * @param page 대상 페이지
 */
async function waitForMemberBoot(page: Page): Promise<void> {
  await page.waitForFunction(
    () => (window as any).G7Core?.AuthManager?.getInstance?.().getState?.().isAuthenticated === true,
    null,
    { timeout: 30_000 }
  );
  // 재발급은 인증 확인 이벤트 직후 비동기로 나간다 — 나갈 기회를 준 뒤 센다.
  await page.waitForTimeout(2_000);
}

// @scenario surface=auth_boundary, storage=guest_keys, e2e=member_reload
// @effects guest_storage_is_cleared_or_reissued_at_auth_boundary
test('회원이 새로고침해도 장바구니 키를 다시 발급하지 않는다', async ({ page, memberToken }) => {
  await authenticatePage(page, memberToken);

  let keyIssues = 0;
  page.on('request', (request) => {
    if (request.method() === 'POST' && /\/api\/modules\/sirsoft-ecommerce\/cart\/key(\?|$)/.test(request.url())) {
      keyIssues += 1;
    }
  });
  const cartKey = () => page.evaluate(() => localStorage.getItem('g7_cart_key'));

  await page.goto('/');
  await waitForMemberBoot(page);
  // 첫 방문은 저장된 키가 없어 initCartKey 가 한 번 발급한다 — 그 발급이 끝난 뒤를 기준점으로 삼는다.
  // 부하가 크면(병렬 워커) 부팅 뒤 2초 안에 끝나지 않아, 늦게 끝난 첫 발급을 새로고침 발급으로 세게 된다.
  await page.waitForFunction(() => !!localStorage.getItem('g7_cart_key'), null, { timeout: 30_000 });
  const firstKey = await cartKey();
  const issuesAfterBoot = keyIssues;

  for (let i = 0; i < 2; i++) {
    await page.reload();
    await waitForMemberBoot(page);
  }

  expect(await cartKey(), '새로고침 뒤에도 같은 장바구니 키').toBe(firstKey);
  expect(keyIssues, '새로고침으로 키 발급 요청이 늘지 않는다').toBe(issuesAfterBoot);
});
