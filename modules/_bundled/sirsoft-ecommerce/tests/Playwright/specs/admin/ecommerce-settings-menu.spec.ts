/**
 * 이커머스 환경설정 메뉴 — 권한 매트릭스 통합 검증 (sample skeleton, placeholder).
 *
 * 본 spec 은 이커머스 모듈 작업 세션에서 다음 사전 작업 완료 후 활성화한다:
 *   1. templates/sirsoft-admin_basic 의 사이드바 메뉴 컴포넌트에
 *      data-testid="admin-menu-ecommerce-settings" 보강
 *   2. 이커머스 환경설정 폼 컴포넌트에 data-testid="ecommerce-settings-form" 보강
 *   3. test.skip → test 변경
 *
 * 매트릭스:
 *   - 환경설정 권한 보유자: 메뉴 클릭 시 환경설정 화면 진입
 *   - 권한 미보유자       : 메뉴 자체가 미노출
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

test.describe.skip('이커머스 환경설정 메뉴 — 권한 매트릭스 (placeholder — 모듈 작업 세션에서 활성화)', () => {
  test('환경설정 권한 보유자 — 메뉴 클릭 시 환경설정 화면 표시', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto('/admin');

    await page.click('[data-testid="admin-menu-ecommerce-settings"]');

    await expect(page.getByTestId('ecommerce-settings-form')).toBeVisible({ timeout: 10_000 });
    await expect(page).toHaveURL(/\/admin\/ecommerce\/settings/);
  });

  test('환경설정 권한 미보유자 — 메뉴 자체가 미노출', async ({ page, noPermissionToken }) => {
    await authenticatePage(page, noPermissionToken);
    await page.goto('/admin');
    await expect(page.getByTestId('admin-menu-ecommerce-settings')).not.toBeVisible();
  });
});
