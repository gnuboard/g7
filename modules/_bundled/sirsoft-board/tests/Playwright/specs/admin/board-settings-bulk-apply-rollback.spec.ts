/**
 * 게시판 설정 일괄 적용 — 첫 실패 시 전체 롤백 및 모달 안내 (이슈 #413 item 26).
 *
 * 일괄 적용 도중 어느 게시판에서든 실패하면 응답이 HTTP 200 + data.rolled_back=true 로
 * 내려오고, 프론트는 모달을 닫지 않은 채 안내 박스(권한 실패=게시판명 문구 /
 * 컬럼 실패=generic 문구)와 warning 토스트를 노출한다. 성공 시에만 모달이 닫히고
 * success 토스트가 뜬다.
 *
 * 롤백의 원자성(앞 게시판·컬럼 변경 원복)·활동 이력 기록은 PHPUnit Feature 테스트
 * (BoardSettingsControllerTest)가 실패 주입(mock)으로 단언하고, 안내 박스 분기·모달
 * 미닫힘은 Vitest 레이아웃 테스트(admin-board-settings.test.tsx)가 구조로 단언한다.
 * 이 spec 은 브라우저 렌더 수준(실패 주입 후 안내 박스 가시성·모달 잔존·토스트)을 담당한다.
 *
 * @scenario board-settings-bulk-apply-rollback
 * @axes failure_point=permission failure_point=column failure_point=none
 * @effects permission_failure_rolls_back_all_boards,
 *          permission_failure_response_has_rolled_back_and_board_name,
 *          column_failure_rolls_back_all_changes,
 *          column_failure_response_has_rolled_back_and_null_board,
 *          aborted_notice_box_shown_and_modal_stays_open,
 *          aborted_notice_uses_board_name_for_permission_failure,
 *          aborted_notice_uses_generic_message_for_column_failure,
 *          aborted_activity_log_recorded,
 *          successful_bulk_apply_closes_modal_with_success_toast
 *
 * 활성화 절차: 실패 주입 가능한 스테이징(특정 게시판 권한 저장이 실패하도록 구성)에서
 * test.describe.skip → test.describe 로 전환.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SETTINGS_URL = '/admin/boards/settings?tab=bulk_apply';
const MODAL = '#bulk_apply_confirm_modal, [data-modal-id="bulk_apply_confirm_modal"]';

test.describe.skip('게시판 설정 일괄 적용 — 전체 롤백 안내 (#413)', () => {
  // @scenario failure_point=permission
  // @effects aborted_notice_box_shown_and_modal_stays_open,
  //          aborted_notice_uses_board_name_for_permission_failure,
  //          permission_failure_response_has_rolled_back_and_board_name
  test('권한 실패 시 모달이 닫히지 않고 게시판명 문구가 노출된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 권한 항목(manager 등)을 포함해 일괄 적용 대상 필드 선택 → 모달 열기
    await page.locator('[data-field-id="manager"], [name="bulk_field_manager"]').first().check().catch(() => {});
    await page.getByRole('button', { name: /일괄 적용|Bulk Apply/i }).first().click();

    const modal = page.locator(MODAL);
    await expect(modal).toBeVisible({ timeout: 5_000 });

    // 확인 버튼 클릭 → (실패 주입된 환경) 롤백 응답
    await modal.locator('#bulk_apply_confirm_button').click();

    // 모달은 닫히지 않고 유지된다 (롤백 분기에 closeModal 없음)
    await expect(modal).toBeVisible({ timeout: 10_000 });

    // 안내 박스: 게시판명을 포함한 권한 실패 문구
    await expect(modal.getByText(/권한 수정 중 오류|전체 변경이 취소/)).toBeVisible({
      timeout: 10_000,
    });

    // warning 토스트
    await expect(page.getByText(/일괄 적용이 취소되었습니다/)).toBeVisible({ timeout: 10_000 });
  });

  // @scenario failure_point=column
  // @effects aborted_notice_uses_generic_message_for_column_failure,
  //          column_failure_response_has_rolled_back_and_null_board,
  //          aborted_notice_box_shown_and_modal_stays_open
  test('컬럼 일괄 업데이트 실패 시 generic 문구가 노출된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 컬럼 필드(권한 외)만 선택 → 컬럼 업데이트 경로 실패 주입
    await page.locator('[data-field-id="per_page"], [name="bulk_field_per_page"]').first().check().catch(() => {});
    await page.getByRole('button', { name: /일괄 적용|Bulk Apply/i }).first().click();

    const modal = page.locator(MODAL);
    await expect(modal).toBeVisible({ timeout: 5_000 });
    await modal.locator('#bulk_apply_confirm_button').click();

    // 모달 유지 + generic 문구(게시판명 없음)
    await expect(modal).toBeVisible({ timeout: 10_000 });
    await expect(modal.getByText(/일괄 적용 중 오류가 발생하여 전체 변경이 취소/)).toBeVisible({
      timeout: 10_000,
    });
  });

  // @scenario failure_point=none
  // @effects successful_bulk_apply_closes_modal_with_success_toast
  test('성공 시 모달이 닫히고 success 토스트가 뜬다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(SETTINGS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('[data-field-id="per_page"], [name="bulk_field_per_page"]').first().check().catch(() => {});
    await page.getByRole('button', { name: /일괄 적용|Bulk Apply/i }).first().click();

    const modal = page.locator(MODAL);
    await expect(modal).toBeVisible({ timeout: 5_000 });
    await modal.locator('#bulk_apply_confirm_button').click();

    // 성공 분기: 모달이 닫힌다
    await expect(modal).toBeHidden({ timeout: 10_000 });
    // success 토스트(적용 개수 포함)
    await expect(page.getByText(/적용|개 게시판/)).toBeVisible({ timeout: 10_000 });
  });
});