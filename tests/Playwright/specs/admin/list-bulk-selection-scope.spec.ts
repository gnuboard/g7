/**
 * E2E: 일괄 처리 목록의 선택 범위 (selectionScope)
 *
 * 체크박스 선택은 화면 밖(전역 상태)에 저장되므로, 검색으로 그 행이 목록에서 빠져도
 * 선택은 남는다. 그 상태에서 일괄 처리를 누르면 **운영자가 보고 있지도, 체크하지도 않은
 * 행이 대상**이 된다. 확인 창은 건수만 말하고("N명의 사용자를 탈퇴 처리합니다") 대상을
 * 부르지 않으므로 실행 전에 알아챌 방법이 없고, 서버는 정상 처리하므로 오류도 남지 않는다.
 *
 * 관측 가능한 결과는 **나가는 요청의 ids** 다 — 화면만 봐서는 구분되지 않으므로 여기서
 * 잠근다. 단위 테스트(DataGrid.test.tsx)는 컴포넌트가 정리를 수행하는지를 보고, 이 spec 은
 * 그 정리가 실제 화면·실제 전역 상태·실제 요청까지 도달하는지를 본다.
 *
 * // @scenario surface=admin_user_list, transition=search_filter_change, scope=page
 * // @effects hidden_row_selection_dropped, bulk_button_disabled_when_selection_empties, bulk_request_carries_only_visible_checked_rows
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const ADMIN_PERMISSIONS = ['core.users.read', 'core.users.update', 'core.users.create'];

/** 이 실행에서만 쓰는 접두사 — 검색으로 한 명씩 고립시키기 위해 필요하다. */
const RUN_TAG = `e2esel${Date.now().toString(36)}`;

/**
 * 테스트용 회원을 만들고 uuid 를 돌려줍니다.
 *
 * @param page Playwright 페이지
 * @param token 관리자 토큰
 * @param slug 이메일 로컬파트 구분자
 * @return 생성된 회원 uuid
 */
async function createTestUser(page: any, token: string, slug: string): Promise<string> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  const rolesResponse = await page.request.get('/api/admin/roles/active', { headers });
  const roles = (await rolesResponse.json())?.data?.data ?? [];
  const roleId = (roles.find((role: any) => role.identifier === 'user') ?? roles[roles.length - 1])?.id;

  expect(roleId, '일반 사용자 역할을 찾지 못했습니다').toBeTruthy();

  const response = await page.request.post('/api/admin/users', {
    headers,
    data: {
      name: `E2E선택범위 ${slug}`,
      email: `${RUN_TAG}${slug}@example.com`,
      password: 'Password!234',
      password_confirmation: 'Password!234',
      status: 'active',
      role_ids: [roleId],
    },
  });

  expect(response.status(), `테스트 회원 생성 실패: ${await response.text()}`).toBeLessThan(300);

  return (await response.json())?.data?.uuid;
}

/**
 * 화면의 검색창으로 목록을 걸러냅니다.
 *
 * `page.goto` 로 URL 을 갈아끼우면 전체 페이지가 다시 로드되면서 전역 상태가 통째로
 * 비워진다 — 그러면 선택이 사라진 이유가 "컴포넌트가 정리해서" 인지 "페이지가 새로
 * 뜨면서" 인지 구분되지 않고, 수정이 없어도 통과하는 테스트가 된다. 결함이 실제로
 * 관측된 경로는 앱이 떠 있는 채로 목록만 다시 부르는 화면 내 검색이므로 그 경로를 쓴다.
 *
 * @param page Playwright 페이지
 * @param keyword 검색어
 * @return void
 */
async function searchUserList(page: any, keyword: string): Promise<void> {
  const searchInput = page.locator('#search_input');
  await searchInput.waitFor({ state: 'visible', timeout: 20_000 });
  await searchInput.fill(keyword);
  await searchInput.press('Enter');

  await expect(page.locator('#users_data_grid__body tbody tr')).toHaveCount(1, { timeout: 20_000 });
  await expect(page.locator('#users_data_grid__body tbody tr').first()).toContainText(keyword, {
    timeout: 20_000,
  });
}

/**
 * 행 체크박스를 켜고 실제로 켜졌는지 확인합니다.
 *
 * `check()` 는 클릭 직후 상태를 확인하는데, 이 체크박스는 전역 상태를 거쳐 다시 내려오는
 * 제어 컴포넌트라 그 시점에 아직 false 다 (실측). 클릭과 확인을 분리한다.
 *
 * @param page Playwright 페이지
 * @return void
 */
async function checkFirstRow(page: any): Promise<void> {
  const checkbox = page.locator('#users_data_grid__body tbody tr input[type="checkbox"]').first();
  await checkbox.click();
  await expect(checkbox).toBeChecked({ timeout: 10_000 });
}

test('@smoke 회원 목록: 검색으로 사라진 행은 선택이 풀리고 일괄 처리 대상에서도 빠진다', async ({ page }) => {
  const token = issueToken(...ADMIN_PERMISSIONS);
  await authenticatePage(page, token);

  const hiddenUserUuid = await createTestUser(page, token, 'hidden');
  const visibleUserUuid = await createTestUser(page, token, 'visible');

  // 1) hidden 만 보이는 목록에서 그 행을 체크한다.
  await page.goto('/admin/users');
  await searchUserList(page, `${RUN_TAG}hidden`);
  await checkFirstRow(page);

  const bulkWithdraw = page.locator('#bulk_withdraw');
  await expect(bulkWithdraw).toBeEnabled({ timeout: 10_000 });

  // 2) 앱이 떠 있는 채로 검색만 바꾼다 — hidden 은 화면에서 사라지지만 전역 상태는 살아 있다.
  await searchUserList(page, `${RUN_TAG}visible`);

  // 사라진 행의 선택이 남아 있으면 버튼이 살아 있다. 그 상태가 결함이다.
  await expect(bulkWithdraw).toBeDisabled({ timeout: 20_000 });

  // 3) 보이는 행만 체크하고 일괄 탈퇴를 실행한다.
  await checkFirstRow(page);
  await expect(bulkWithdraw).toBeEnabled({ timeout: 10_000 });

  const bulkRequest = page.waitForRequest(
    (request: any) => request.url().includes('/api/admin/users/bulk-status') && request.method() === 'PATCH'
  );

  await bulkWithdraw.click();

  // 확인 모달의 실행 버튼은 "확인"(공통 문구)이다 — 모달이 뜬 뒤에 누른다.
  const confirmButton = page.getByRole('button', { name: /^(확인|Confirm)$/ }).last();
  await expect(confirmButton).toBeVisible({ timeout: 10_000 });
  await confirmButton.click();

  const sentIds = (await (await bulkRequest).postDataJSON())?.ids ?? [];

  expect(sentIds, '화면에 보이던 행이 대상에서 빠졌습니다').toContain(visibleUserUuid);
  expect(sentIds, '화면에서 사라진 행이 일괄 처리 대상에 실렸습니다').not.toContain(hiddenUserUuid);
});
