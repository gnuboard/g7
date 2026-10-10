/**
 * sirsoft-admin_basic — 코드 편집기 ?route= URL 동기화
 *
 * 코드 편집기(admin_template_layout_edit.g7.html) 는 위지윅 편집기와 일관되게 `?route=`
 * 쿼리로 레이아웃을 식별한다. 본 spec 은 템플릿 소유 레이아웃의 동작을 검증한다
 * (위지윅 chrome 측 동작은 코어 chrome-controls.spec.ts 가 커버).
 *
 * @scenario code_editor_entry=direct_route_query
 * @effects code_editor_restores_layout_from_route_query
 * @effects code_editor_clears_selection_left_over_from_another_template
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

test.describe('@sirsoft-admin_basic 코드 편집기 route 동기화', () => {
  /**
   * @scenario code_editor_entry=direct_route_query
   *
   * @effects code_editor_restores_layout_from_route_query
   */
  test('?route= 로 직접 진입 시 해당 라우트 레이아웃이 선택된다', async ({ page, layoutEditToken }) => {
    // /board/:slug/write 라우트로 코드편집기 직접 진입 → board/form 레이아웃 복원
    await authenticatePage(page, layoutEditToken);
    await page.goto('/admin/templates/sirsoft-basic/edit?route=%2Fboard%2F%3Aslug%2Fwrite');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // init_actions 가 query.route → board/form 레이아웃으로 복원.
    // 사용자에게 보이는 결과(에디터 카드 파일명 헤더 "board/form.g7.html")로 검증 — 편집기 정본이 HTML 이다 (#476).
    await expect(
      page.locator('#editor_card').getByText('board/form.g7.html'),
    ).toBeVisible({ timeout: 20_000 });
  });

  /**
   * @scenario code_editor_entry=no_route_query
   *
   * @effects code_editor_defaults_to_first_layout_without_route_query
   */
  test('?route= 없이 진입 시 첫 레이아웃이 선택된다 (기존 동작 보존)', async ({ page, layoutEditToken }) => {
    await authenticatePage(page, layoutEditToken);
    await page.goto('/admin/templates/sirsoft-basic/edit');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 첫 레이아웃 파일명이 에디터 카드 헤더에 노출 (init_actions 의 query.route 분기 미발화)
    await expect(page.locator('#editor_card')).toBeVisible({ timeout: 20_000 });
    await expect(
      page.locator('#editor_card').getByText(/\.g7\.html$/),
    ).toBeVisible({ timeout: 20_000 });
  });

  /**
   * @scenario code_editor_entry=stale_selection_from_other_template
   *
   * @effects code_editor_clears_selection_left_over_from_another_template
   */
  test('다른 템플릿에서 고른 파일이 남아 있어도 404 없이 이 템플릿의 첫 파일이 열린다', async ({ page, layoutEditToken }) => {
    // `selectedLayoutName` 은 템플릿으로 갈리지 않는 전역이라, 관리자 템플릿에서 고른 파일명이
    // SPA 이동 뒤에도 남는다. 그 상태로 본문 조회가 돌면 이 템플릿에 없는 파일을 겨냥해 404 가
    // 나고 편집기가 빈 채로 열린다(새로고침해야 풀림 — 브라우저 실측 2026-09-11).
    await authenticatePage(page, layoutEditToken);

    const notFound: string[] = [];
    page.on('response', (res) => {
      if (res.status() === 404 && /\/layouts\/[^?]+\?with_markup=1/.test(res.url())) {
        notFound.push(res.url());
      }
    });

    // ① 관리자 템플릿에서 그 템플릿에만 있는 파일을 고른다.
    await page.goto('/admin/templates/sirsoft-admin_basic/edit?route=%2Fadmin%2Fforgot-password');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await expect(
      page.locator('#editor_card').getByText('admin_forgot_password.g7.html'),
    ).toBeVisible({ timeout: 20_000 });

    // ② **새로고침 없이** 유저 템플릿 편집기로 건너간다 — `goto` 는 전역을 지워 이 결함을 못 만든다.
    //    화면의 「코드 편집」 버튼이 하는 것과 같은 SPA 이동(navigate 액션)을 그대로 부른다.
    await page.evaluate(() =>
      (window as any).G7Core.dispatch({
        handler: 'navigate',
        params: { path: '/admin/templates/sirsoft-basic/edit' },
      }),
    );
    await expect(page).toHaveURL(/\/admin\/templates\/sirsoft-basic\/edit/, { timeout: 20_000 });

    // ③ 이 템플릿의 첫 파일이 열리고, 낡은 선택을 겨냥한 404 는 한 건도 없다.
    await expect(
      page.locator('#editor_card').getByText('_user_base.g7.html'),
    ).toBeVisible({ timeout: 20_000 });
    expect(notFound, '낡은 선택으로 본문 조회가 404 를 맞으면 편집기가 빈 채로 열린다').toEqual([]);
  });
});
