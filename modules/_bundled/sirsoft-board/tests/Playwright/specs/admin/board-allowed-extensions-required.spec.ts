/**
 * 게시판 첨부 "허용 확장자" 빈 값 저장 차단 (#413).
 *
 * 빈 값으로 저장하면 안내와 반대로 모든 파일 업로드가 거부되던 버그를 "빈 값 금지 — 최소 1개 필수"
 * 로 변경했다. 백엔드 검증(PHPUnit BoardRequestTest)과 레이아웃 구조/문구(Vitest)는 단위로 커버하고,
 * 브라우저 수준(빈 채 저장 시 오류 노출 / 첨부 미사용 게시판은 빈 값 허용 / 안내 문구 갱신 노출)은
 * 이 spec 이 담당한다.
 *
 * 정책 정정 (#78): "빈 값 금지" 는 첨부를 **사용하는** 게시판/기본값에만 적용된다.
 * 첨부 미사용이면 확장자는 검증 자체에서 배제된다 — 그러지 않으면 첨부를 쓰지 않는
 * 게시판을 무변경 저장하는 것조차 422 로 막힌다.
 *
 * @scenario board-allowed-extensions-required
 * @axes surface=settings_attachment surface=board_form use_file_upload=true use_file_upload=false value=empty value=nonempty
 * @effects empty_extensions_blocked_on_settings_save_when_upload_enabled,
 *          empty_extensions_allowed_on_settings_save_when_upload_disabled,
 *          empty_extensions_blocked_on_board_form_when_upload_enabled,
 *          empty_extensions_allowed_on_board_form_when_upload_disabled,
 *          allowed_extensions_guide_text_updated_to_min_one
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SETTINGS_URL = '/admin/boards/settings';
const CREATE_URL = '/admin/boards/create';

const EXTENSION_REQUIRED_TEXT = /허용 파일 확장자를 최소 1개|At least one allowed file extension/;

/**
 * 첨부 탭의 허용 확장자 칩을 모두 제거합니다.
 */
async function clearAllowedExtensionChips(page: import('@playwright/test').Page): Promise<void> {
  const removeChipButtons = page.locator(
    '[name="basic_defaults.allowed_extensions"] [data-chip-remove], [data-field="allowed_extensions"] button[aria-label*="제거"], [data-field="allowed_extensions"] button[aria-label*="remove"]'
  );
  const count = await removeChipButtons.count();
  for (let i = 0; i < count; i++) {
    await removeChipButtons.first().click();
  }
}

test.describe.skip('게시판 허용 확장자 빈 값 저장 차단 (#413, #78)', () => {
  // @scenario surface=settings_attachment, use_file_upload=true, value=empty
  // @effects empty_extensions_blocked_on_settings_save_when_upload_enabled
  test('환경설정에서 첨부 사용 ON + 허용 확장자를 비우고 저장하면 오류가 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 기본 설정 → 첨부파일 하위 탭으로 이동
    await page.getByRole('tab', { name: /첨부|Attachment/ }).click();

    // 첨부 사용을 켜 둔 상태에서 확장자를 모두 비운다
    const uploadToggle = page.locator('[name="basic_defaults.use_file_upload"]');
    if (!(await uploadToggle.isChecked())) {
      await uploadToggle.click();
    }
    await clearAllowedExtensionChips(page);

    // 저장 → min:1 검증 실패 안내 노출
    await page.locator('button[type="submit"]').first().click();
    await expect(page.getByText(EXTENSION_REQUIRED_TEXT)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario surface=settings_attachment, use_file_upload=false, value=empty
  // @effects empty_extensions_allowed_on_settings_save_when_upload_disabled
  test('환경설정에서 첨부 미사용이면 허용 확장자를 비워도 저장된다 (#78)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.getByRole('tab', { name: /첨부|Attachment/ }).click();

    // 첨부 사용을 끈 뒤 확장자를 모두 비운다
    const uploadToggle = page.locator('[name="basic_defaults.use_file_upload"]');
    if (await uploadToggle.isChecked()) {
      await uploadToggle.click();
    }
    await clearAllowedExtensionChips(page);

    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.getByText(EXTENSION_REQUIRED_TEXT)).toHaveCount(0, { timeout: 10_000 });
  });

  // @scenario surface=board_form, use_file_upload=true, value=empty
  // @effects empty_extensions_blocked_on_board_form_when_upload_enabled
  test('게시판 폼에서 파일 업로드 ON + 허용 확장자 빈 채 저장하면 오류가 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('[name="slug"]').fill('e2e-ext-empty');
    await page.locator('[name="name"]').first().fill('E2E 확장자 빈값');

    // 게시글 설정 탭 → 파일 업로드 ON (허용 확장자는 비운 채로 둠)
    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    await page.locator('[name="use_file_upload"]').click();

    await page.locator('button[type="submit"]').first().click();
    await expect(page.getByText(EXTENSION_REQUIRED_TEXT)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario surface=board_form, use_file_upload=false, value=empty
  // @effects empty_extensions_allowed_on_board_form_when_upload_disabled
  test('게시판 폼에서 파일 업로드 OFF 이면 허용 확장자 빈 값으로도 저장된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('[name="slug"]').fill('e2e-ext-noupload');
    await page.locator('[name="name"]').first().fill('E2E 첨부미사용');

    // 파일 업로드 OFF(기본) 상태 그대로 저장 → 확장자 미입력도 통과해야 함
    await page.locator('button[type="submit"]').first().click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 허용 확장자 검증 오류가 노출되지 않아야 한다
    await expect(page.getByText(EXTENSION_REQUIRED_TEXT)).toHaveCount(0, { timeout: 10_000 });
  });

  // @scenario surface=board_form, value=nonempty
  // @effects allowed_extensions_guide_text_updated_to_min_one
  test('허용 확장자 안내 문구가 "최소 1개 입력" 으로 노출된다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.getByRole('tab', { name: /게시글 설정|Post Settings/ }).click();
    await page.locator('[name="use_file_upload"]').click();

    // 갱신된 안내 문구 노출 (옛 "빈 값 ... 모든 확장자" 가 아님)
    await expect(
      page.getByText(/허용할 파일 확장자를 최소 1개 이상 입력/)
    ).toBeVisible({ timeout: 10_000 });
  });
});
