/**
 * Smoke: 관리자 역할 목록의 refresh IconButton(outline variant) 동작 검증.
 *
 * Round A-1 시맨틱화 회귀 커버:
 * - 7개 list 화면(menu/module/plugin/role/schedule/template/user_list) 의 정사각 outline
 *   아이콘 버튼이 인라인 Tailwind → IconButton(variant="outline", size="md") 으로 치환됨.
 * - 본 spec 은 admin_role_list 의 refresh 버튼을 대표로 검증 (나머지 6개는 동일 구조).
 * - refs #399
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test('@smoke /admin/roles 진입 시 refresh IconButton 이 마운트되고 클릭하면 roles 데이터소스가 재요청된다', async ({ page }) => {
  const token = issueToken('core.permissions.read');
  await authenticatePage(page, token);

  await page.goto('/admin/roles');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

  // 인증 가드 — 토큰 권한이 부족하면 로그인으로 리다이렉트됨
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );
  expect(page.url(), '권한 보유 토큰임에도 /admin/login 으로 리다이렉트되었습니다').not.toMatch(/\/admin\/login/);
  expect(page.url(), 'URL 이 /admin/roles 가 아닙니다').toMatch(/\/admin\/roles/);

  // refresh IconButton 마운트 확인 (id="refresh_button")
  const refreshButton = page.locator('#refresh_button');
  await refreshButton.waitFor({ state: 'visible', timeout: 15_000 });

  // 클릭 → roles 데이터소스 GET 재요청 발생 검증
  const reloadRequest = page.waitForRequest(
    (req) => req.url().includes('/api/admin/roles') && req.method() === 'GET',
    { timeout: 10_000 },
  );
  await refreshButton.click();
  await reloadRequest;
});
