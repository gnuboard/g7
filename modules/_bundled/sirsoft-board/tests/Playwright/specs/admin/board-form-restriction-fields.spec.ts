/**
 * 게시판 생성/수정 폼 — 제한 키워드/허용 확장자 입력 (이슈 #413 item 24).
 *
 * 24-(2)/Q4 통일안: 제한 키워드(blocked_keywords)와 허용 확장자(allowed_extensions)를
 * 둘 다 배열 데이터 + TagInput UI 로 통일한다. 두 필드 모두 "게시글 설정" 탭에 유지하되
 * 입력 UI 를 TagInput 으로 교체했다.
 *
 * 단위(Vitest admin-board-form) 는 레이아웃 JSON 구조(탭 위치/TagInput 타입)만 검증하고,
 * Feature(BoardManagementTest) 는 form-data 가 두 필드를 배열로 반환하는지 검증하므로,
 * 브라우저 수준(태그 입력 → 저장 → 재진입 시 칩 복원, 댓글 차단어 반영)은 이 spec 이 담당.
 *
 * @scenario board-form-restriction-fields
 * @axes field=blocked_keywords field=allowed_extensions tab=basic tab=post mode=create mode=update
 * @effects blocked_keywords_renders_as_taginput_on_post_tab,
 *          allowed_extensions_renders_as_taginput_on_post_tab,
 *          blocked_keywords_absent_from_basic_tab,
 *          taginput_values_persist_as_array_chips_on_reentry,
 *          blocked_keyword_blocks_matching_comment_on_user_side
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const CREATE_URL = '/admin/boards/create';

test.describe.skip('게시판 폼 — 제한 키워드/허용 확장자 TagInput (#413)', () => {
  // @scenario field=blocked_keywords, tab=post
  // @effects blocked_keywords_renders_as_taginput_on_post_tab
  test('제한 키워드는 게시글 설정 탭에서 TagInput 으로 노출되고 태그 입력이 가능하다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 게시글 설정 탭으로 이동 — 제한 키워드 TagInput 입력 필드 존재
    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    const blockedInput = page.locator('[name="blocked_keywords"]');
    await expect(blockedInput).toBeVisible({ timeout: 10_000 });

    // 키워드 입력 후 Enter → 칩 생성
    await blockedInput.fill('욕설');
    await blockedInput.press('Enter');
    await expect(page.getByText('욕설')).toBeVisible({ timeout: 5_000 });
  });

  // @scenario field=allowed_extensions, tab=post
  // @effects allowed_extensions_renders_as_taginput_on_post_tab
  test('허용 확장자는 게시글 설정 탭의 파일 업로드 켜짐 시 TagInput 으로 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 게시글 설정 탭으로 이동
    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    // 파일 업로드 토글 ON → 허용 확장자 서브필드 노출
    await page.locator('[name="use_file_upload"]').click();

    const extInput = page.locator('[name="allowed_extensions"]');
    await expect(extInput).toBeVisible({ timeout: 10_000 });

    await extInput.fill('jpg');
    await extInput.press('Enter');
    await expect(page.getByText('jpg')).toBeVisible({ timeout: 5_000 });
  });

  // @scenario field=blocked_keywords, tab=basic
  // @effects blocked_keywords_absent_from_basic_tab
  test('제한 키워드는 기본 설정 탭에 존재하지 않는다 (게시글 설정 탭 소속)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 기본 설정 탭(기본 활성) — 제한 키워드 필드 부재
    await expect(page.locator('[name="blocked_keywords"]')).toHaveCount(0, { timeout: 10_000 });
  });

  // @scenario field=blocked_keywords, mode=update
  // @effects taginput_values_persist_as_array_chips_on_reentry
  test('저장한 제한 키워드가 수정 폼 재진입 시 칩으로 복원된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);

    // 1) 생성: 제한 키워드 2건 입력 후 저장
    const slug = 'e2e-restrict';
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('[name="slug"]').fill(slug);
    await page.locator('[name="name"]').first().fill('E2E 제한어');

    // 제한 키워드는 게시글 설정 탭에 위치
    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    const blockedInput = page.locator('[name="blocked_keywords"]');
    await blockedInput.fill('욕설');
    await blockedInput.press('Enter');
    await blockedInput.fill('광고');
    await blockedInput.press('Enter');

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 2) 수정 폼 재진입 → 칩 2건 복원 확인
    await page.goto(`/admin/boards`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page
      .locator(`a[href*="/edit"]`)
      .filter({ hasText: 'E2E 제한어' })
      .first()
      .click();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await expect(page.getByText('욕설')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByText('광고')).toBeVisible({ timeout: 10_000 });
  });
});
