/**
 * 게시판 무변경 저장 + 저장 실패 노출 (#78).
 *
 * 관리자 폼은 조회 응답 전체를 _local.form 에 통째로 담아 그대로 PUT 한다. 따라서 요청에는
 * 항상 모든 키가 존재하고, 토글이 꺼진 상태의 종속 필드까지 검증을 타면 아무것도 바꾸지 않은
 * 저장조차 422 로 거부된다. 첨부를 쓰지 않는 게시판(notice/archive)이 여기에 해당했다.
 *
 * 백엔드 계약은 PHPUnit(BoardRequestTest 매트릭스, BoardManagementTest 왕복 저장)이 고정하고,
 * 브라우저 수준의 "무변경 저장 성공" 과 "실패 시 실제로 화면에 보이는가" 를 이 spec 이 담당한다.
 * 후자는 오류 처리가 붙어 있어도 바인딩 키가 틀리면 빈 안내가 뜨므로 별도 축이 필요하다.
 *
 * @scenario board-unchanged-save-and-error-visibility
 * @axes use_file_upload=false use_file_upload=true allowed_extensions=empty allowed_extensions=nonempty new_display_hours=0 outcome=success outcome=failure
 * @effects unchanged_save_succeeds_for_upload_disabled_board,
 *          unchanged_save_succeeds_for_zero_new_display_hours,
 *          unchanged_save_succeeds_for_upload_enabled_board,
 *          save_failure_surfaces_toast_with_server_message
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const EDIT_URL = (slug: string) => `/admin/boards/${slug}/edit`;

const EXTENSION_REQUIRED_TEXT = /허용 파일 확장자를 최소 1개|At least one allowed file extension/;

/**
 * 게시판 수정 화면을 열고 폼 로딩을 기다립니다.
 */
async function openBoardEdit(page: import('@playwright/test').Page, slug: string): Promise<void> {
  await page.goto(EDIT_URL(slug));
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('button[type="submit"]').first()).toBeVisible({ timeout: 30_000 });
}

/**
 * 아무것도 바꾸지 않고 저장하고, 저장 요청의 응답 상태를 반환합니다.
 */
async function saveUnchanged(page: import('@playwright/test').Page): Promise<number> {
  const [response] = await Promise.all([
    page.waitForResponse(
      (r) => r.request().method() === 'PUT' && r.url().includes('/admin/boards/'),
      { timeout: 30_000 }
    ),
    page.locator('button[type="submit"]').first().click(),
  ]);

  return response.status();
}

test.describe.skip('게시판 무변경 저장 및 저장 실패 노출 (#78)', () => {
  // @scenario use_file_upload=false, allowed_extensions=empty, outcome=success
  // @effects unchanged_save_succeeds_for_upload_disabled_board
  test('첨부 미사용 게시판(notice)을 아무것도 바꾸지 않고 저장하면 성공한다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await openBoardEdit(page, 'notice');

    expect(await saveUnchanged(page)).toBe(200);
    await expect(page.getByText(EXTENSION_REQUIRED_TEXT)).toHaveCount(0);
  });

  // @scenario use_file_upload=false, new_display_hours=0, outcome=success
  // @effects unchanged_save_succeeds_for_zero_new_display_hours
  test('NEW 배지 표시 기간이 0인 게시판(archive)을 무변경 저장하면 성공한다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await openBoardEdit(page, 'archive');

    expect(await saveUnchanged(page)).toBe(200);
  });

  // @scenario use_file_upload=true, allowed_extensions=nonempty, outcome=success
  // @effects unchanged_save_succeeds_for_upload_enabled_board
  test('첨부 사용 게시판(gallery)의 무변경 저장은 회귀 없이 계속 성공한다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await openBoardEdit(page, 'gallery');

    expect(await saveUnchanged(page)).toBe(200);
  });

  // @scenario use_file_upload=true, allowed_extensions=empty, outcome=failure
  // @effects save_failure_surfaces_toast_with_server_message
  test('저장이 422로 거부되면 서버 메시지가 담긴 안내가 실제로 화면에 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await openBoardEdit(page, 'gallery');

    // 첨부 사용은 켠 채로 확장자를 모두 비워 의도적으로 422 를 유발한다
    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    const removeChipButtons = page.locator(
      '[data-field="allowed_extensions"] button[aria-label*="제거"], [data-field="allowed_extensions"] button[aria-label*="remove"]'
    );
    const count = await removeChipButtons.count();
    for (let i = 0; i < count; i++) {
      await removeChipButtons.first().click();
    }

    const [response] = await Promise.all([
      page.waitForResponse(
        (r) => r.request().method() === 'PUT' && r.url().includes('/admin/boards/'),
        { timeout: 30_000 }
      ),
      page.locator('button[type="submit"]').first().click(),
    ]);
    expect(response.status()).toBe(422);

    // 안내가 비어 있지 않아야 한다 — 오류 처리가 붙어 있어도 바인딩 키가 틀리면 빈 값이 노출된다
    await expect(page.getByText(EXTENSION_REQUIRED_TEXT).first()).toBeVisible({ timeout: 10_000 });
  });
});
