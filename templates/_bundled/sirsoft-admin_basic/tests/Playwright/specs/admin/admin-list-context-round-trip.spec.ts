/**
 * sirsoft-admin_basic — 관리자 코어 목록 컨텍스트 왕복 (공개 이슈 #75).
 *
 * 결함: 회원·역할 목록에서 등록/수정 폼으로 들어갔다 돌아오면 표시 개수·검색어 같은 목록 상태가
 *   URL 에서 사라져, 돌아온 목록이 항상 1페이지 기본 상태로 초기화됐다.
 *
 * 반대 방향도 함께 고정한다 — 템플릿 목록의 사용자/관리자 탭은 각자 검색어·페이지를 갖는 별개의
 *   목록이므로 탭을 바꿀 때 이전 탭의 상태가 따라오면 안 된다. 수정 과정에서 실제로 한 번
 *   병합이 붙어 남의 검색 결과로 걸러진 빈 페이지가 열렸던 자리다.
 *
 * 표시 개수(`per_page`)를 표식으로 쓴다 — 시드 건수와 무관하게 항상 URL 에 실리는 목록 상태다.
 *
 * @scenario admin-list-context-round-trip
 * @axes leg=enter leg=cancel leg=tab_switch
 * @effects list_return_preserves_query tab_switch_resets_query
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

const LIST_STATE = 'per_page=10';

/**
 * 현재 URL 의 쿼리 파라미터를 돌려줍니다.
 *
 * @param url 페이지 URL
 * @returns 쿼리 파라미터
 */
function queryOf(url: string): URLSearchParams {
  return new URL(url).searchParams;
}

test.describe('@sirsoft-admin_basic 관리자 목록 컨텍스트 왕복 (#75)', () => {
  // @scenario leg=enter
  // @effects list_return_preserves_query
  test('회원 목록에서 등록 폼으로 들어가도 목록 상태가 URL 에 남는다', async ({ page, userManageToken }) => {
    await authenticatePage(page, userManageToken);
    await page.goto(`/admin/users?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#add_user_button').click();
    await page.waitForURL(/\/admin\/users\/create\?/, { timeout: 30_000 });

    expect(queryOf(page.url()).get('per_page')).toBe('10');
  });

  // @scenario leg=cancel
  // @effects list_return_preserves_query
  test('역할 등록 폼에서 취소하면 목록 상태가 유지된 채 목록으로 돌아온다', async ({ page, userManageToken }) => {
    await authenticatePage(page, userManageToken);
    await page.goto(`/admin/roles/create?${LIST_STATE}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await page.locator('#footer_cancel_button').click();
    await page.waitForURL(/\/admin\/roles\?/, { timeout: 30_000 });

    expect(queryOf(page.url()).get('per_page')).toBe('10');
  });

  // @scenario leg=tab_switch
  // @effects tab_switch_resets_query
  test('템플릿 목록 탭을 바꾸면 이전 탭의 검색어·페이지가 따라오지 않는다', async ({ page, templateReadToken }) => {
    await authenticatePage(page, templateReadToken);
    await page.goto('/admin/templates/user?search=sirsoft&page=2');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 탭은 WAI-ARIA 규약에 따라 role="tab" 으로 노출된다 (일반 button 이 아니다).
    await page.locator('#tab_navigation').getByRole('tab').nth(1).click();
    await page.waitForURL(/\/admin\/templates\/admin/, { timeout: 30_000 });

    const q = queryOf(page.url());
    expect(q.get('search')).toBeNull();
    expect(q.get('page')).toBeNull();
  });
});
