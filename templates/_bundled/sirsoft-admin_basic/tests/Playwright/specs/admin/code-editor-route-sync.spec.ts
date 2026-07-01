/**
 * sirsoft-admin_basic — 코드 편집기 ?route= URL 동기화
 *
 * 코드 편집기(admin_template_layout_edit.json) 는 위지윅 편집기와 일관되게 `?route=`
 * 쿼리로 레이아웃을 식별한다. 본 spec 은 템플릿 소유 레이아웃의 동작을 검증한다
 * (위지윅 chrome 측 동작은 코어 chrome-controls.spec.ts 가 커버).
 *
 * @scenario code_editor_entry=direct_route_query
 * @effects code_editor_restores_layout_from_route_query
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('@sirsoft-admin_basic 코드 편집기 route 동기화', () => {
  test('?route= 로 직접 진입 시 해당 라우트 레이아웃이 선택된다', async ({ page, layoutEditToken }) => {
    // /board/:slug/write 라우트로 코드편집기 직접 진입 → board/form 레이아웃 복원
    await authenticatePage(page, layoutEditToken);
    await page.goto('/admin/templates/sirsoft-basic/edit?route=%2Fboard%2F%3Aslug%2Fwrite');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // init_actions 가 query.route → board/form 레이아웃으로 복원.
    // 사용자에게 보이는 결과(에디터 카드 파일명 헤더 "board/form.json")로 검증.
    await expect(
      page.locator('#editor_card').getByText('board/form.json'),
    ).toBeVisible({ timeout: 20_000 });
  });

  test('?route= 없이 진입 시 첫 레이아웃이 선택된다 (기존 동작 보존)', async ({ page, layoutEditToken }) => {
    await authenticatePage(page, layoutEditToken);
    await page.goto('/admin/templates/sirsoft-basic/edit');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 첫 레이아웃 파일명이 에디터 카드 헤더에 노출 (init_actions 의 query.route 분기 미발화)
    await expect(page.locator('#editor_card')).toBeVisible({ timeout: 20_000 });
    await expect(
      page.locator('#editor_card').getByText(/\.json$/),
    ).toBeVisible({ timeout: 20_000 });
  });
});
