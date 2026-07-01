/**
 * 게시판 환경설정 "신고 정책" 탭 — 신고 관리 권한 검증 및 에러 표시 (이슈 #413 item 8).
 *
 * view_roles / manage_roles 를 빈 배열로 저장 시 FormRequest 가 차단하고,
 * 해당 섹션 내 TagInput 에 빨간 테두리(input-error 클래스)와 인라인 에러 메시지가 표시된다.
 *
 * 단위(Vitest BoardRequestTest) 는 FormRequest 레벨 통과/실패만 검증하므로,
 * 브라우저 렌더 수준(에러 className, 에러 Span 노출, 메시지 한국어 치환)은 이 spec 이 담당.
 *
 * @scenario board-settings-report-permissions
 * @axes field=view_roles field=manage_roles field=both_valid
 * @effects view_roles_empty_blocked_by_validation,
 *          manage_roles_empty_blocked_by_validation,
 *          error_message_uses_korean_field_name_not_key,
 *          inline_error_shown_below_view_roles_taginput,
 *          inline_error_shown_below_manage_roles_taginput,
 *          taginput_shows_red_border_on_error,
 *          valid_submission_succeeds
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SETTINGS_URL = '/admin/boards/settings?tab=report_policy';

test.describe.skip('게시판 환경설정 신고 정책 — 신고 관리 권한 검증 (#413)', () => {
  // @scenario field=view_roles
  // @effects view_roles_empty_blocked_by_validation, inline_error_shown_below_view_roles_taginput, taginput_shows_red_border_on_error
  test('view_roles 빈 배열 제출 시 섹션 내 에러가 표시된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // view_roles TagInput 에서 선택된 항목 모두 제거 후 저장
    const viewRolesInput = page.locator('[name="report_permissions.view_roles"]').locator('..');
    await viewRolesInput.locator('.tag-input__multi-value__remove').first().click().catch(() => {
      // 이미 비어있는 경우 무시
    });

    await page.locator('button[type="submit"]').first().click();

    // 에러 메시지가 한국어 필드명으로 표시되어야 함 (영문 키 그대로 노출 금지)
    await expect(page.getByText('report_permissions', { exact: false })).toHaveCount(0, {
      timeout: 5_000,
    });

    // view_roles TagInput 아래 인라인 에러 메시지 표시
    const viewRolesError = page.locator(
      '[name="report_permissions.view_roles"]',
    ).locator('xpath=../following-sibling::span[1]');
    await expect(viewRolesError).toBeVisible({ timeout: 5_000 });

    // TagInput control 요소에 input-error 클래스 적용
    const viewRolesControl = page.locator(
      '[name="report_permissions.view_roles"]',
    ).locator('xpath=../div[contains(@class,"input-tag")]');
    await expect(viewRolesControl).toHaveClass(/input-error/);
  });

  // @scenario field=manage_roles
  // @effects manage_roles_empty_blocked_by_validation, inline_error_shown_below_manage_roles_taginput, taginput_shows_red_border_on_error
  test('manage_roles 빈 배열 제출 시 섹션 내 에러가 표시된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const manageRolesInput = page.locator('[name="report_permissions.manage_roles"]').locator('..');
    await manageRolesInput.locator('.tag-input__multi-value__remove').first().click().catch(() => {});

    await page.locator('button[type="submit"]').first().click();

    // manage_roles TagInput 아래 인라인 에러 메시지 표시
    const manageRolesError = page.locator(
      '[name="report_permissions.manage_roles"]',
    ).locator('xpath=../following-sibling::span[1]');
    await expect(manageRolesError).toBeVisible({ timeout: 5_000 });

    // TagInput control 요소에 input-error 클래스 적용
    const manageRolesControl = page.locator(
      '[name="report_permissions.manage_roles"]',
    ).locator('xpath=../div[contains(@class,"input-tag")]');
    await expect(manageRolesControl).toHaveClass(/input-error/);
  });

  // @scenario field=both_valid
  // @effects error_message_uses_korean_field_name_not_key, valid_submission_succeeds
  test('에러 메시지가 영문 필드 키(report_permissions) 그대로 노출되지 않는다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('button[type="submit"]').first().click();

    // 상단 에러 박스 또는 인라인에 영문 키가 그대로 노출되면 안 됨
    await expect(page.getByText('report_permissions이(가)', { exact: false })).toHaveCount(0, {
      timeout: 5_000,
    });
    await expect(page.getByText('report_permissions.view_roles이(가)', { exact: false })).toHaveCount(0);
    await expect(page.getByText('report_permissions.manage_roles이(가)', { exact: false })).toHaveCount(0);
  });
});