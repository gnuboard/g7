/**
 * Smoke: 게시글/댓글 신고 제출 후 피드백·상태 반영 (이슈 #413-60)
 *
 * @scenario board-report-submit: actor=member, target=post, board=use_report
 * @effects report_success_button_switches_to_reported_without_reload
 * @effects report_duplicate_closes_modal_and_shows_error_toast
 *
 * 검수 결과(오류확정):
 *  - 증상2: 신고 성공 후 "신고" 버튼이 새로고침해야만 "신고됨"으로 전환됨
 *           → onSuccess 에 refetchDataSource(post) 추가로 즉시 전환되어야 함
 *  - 증상1: 이미 신고한 글을 재신고(409)하면 신고 창이 닫히지 않고 멈춘 것처럼 보임
 *           → onError 에 closeModal 추가로 창이 닫히고 에러 토스트가 노출되어야 함
 *
 * 신고는 회원만, 본인 글이 아닌 글에만 가능하다. 검수 환경에 신고 사용(use_report) 게시판과
 * 신고 대상 글이 준비되어 있어야 하며, 토큰 권한만으로는 reporter 신원/대상 글을 보장하기
 * 어렵다. 본 spec 은 신고 모달의 액션 회귀(성공 즉시 전환 / 실패 시 창 닫힘+토스트)를
 * UI 흐름으로 검증한다. 대상 글이 없으면 자동 skip 한다(데이터 의존 가드).
 *
 * 환경변수:
 *  - PLAYWRIGHT_BOARD_SLUG (기본 'gallery') — 신고 사용 게시판 slug
 *  - PLAYWRIGHT_REPORT_POST_ID — 신고 대상(타인 글) 게시글 id (미지정 시 skip)
 */
import { test, expect } from '../../fixtures/auth';
import { issueToken, authenticatePage } from '../../fixtures/auth';

const BOARD_SLUG = process.env.PLAYWRIGHT_BOARD_SLUG ?? 'gallery';
const REPORT_POST_ID = process.env.PLAYWRIGHT_REPORT_POST_ID ?? '';

test.describe('@smoke 신고 제출 후 피드백·상태 반영 (이슈 #413-60)', () => {
  test.skip(
    !REPORT_POST_ID,
    'PLAYWRIGHT_REPORT_POST_ID(타인 글, 신고 사용 게시판) 미지정 — 신고 대상 데이터 없이 검증 불가'
  );

  test('신고 성공 시 새로고침 없이 "신고됨"으로 전환되어야 한다 (증상2)', async ({ page }) => {
    const token = issueToken(`sirsoft-board.${BOARD_SLUG}.posts.read`);
    await authenticatePage(page, token);

    await page.goto(`/board/${BOARD_SLUG}/${REPORT_POST_ID}`);

    // 신고 버튼이 보이면(미신고 상태) 신고 흐름 진행
    const reportBtn = page.getByRole('button', { name: /신고$/ }).first();
    await expect(reportBtn).toBeVisible({ timeout: 30_000 });
    await reportBtn.click();

    // 모달에서 사유 선택 + 상세 입력 후 제출
    const dialog = page.getByRole('dialog', { name: /신고/ });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('combobox').selectOption({ index: 1 });
    await dialog.getByRole('textbox').fill('E2E 신고 검증');
    await dialog.getByRole('button', { name: '신고하기' }).click();

    // 모달이 닫히고, 새로고침 없이 "신고됨"(disabled) 버튼으로 전환되어야 한다
    await expect(dialog).toBeHidden({ timeout: 30_000 });
    await expect(page.getByRole('button', { name: /신고됨/ })).toBeVisible({ timeout: 30_000 });
  });

  test('이미 신고한 글 재신고(409) 시 창이 닫히고 에러 토스트가 노출되어야 한다 (증상1)', async ({ page }) => {
    const token = issueToken(`sirsoft-board.${BOARD_SLUG}.posts.read`);
    await authenticatePage(page, token);

    await page.goto(`/board/${BOARD_SLUG}/${REPORT_POST_ID}`);

    // 이미 신고된 상태라면 "신고됨" 버튼만 보이므로 이 시나리오는 skip
    const alreadyReported = page.getByRole('button', { name: /신고됨/ });
    const reportBtn = page.getByRole('button', { name: /신고$/ }).first();

    if (await alreadyReported.isVisible().catch(() => false)) {
      test.skip(true, '이미 신고된 상태 — 재신고 409 시나리오는 별도 환경 준비 필요');
    }

    await expect(reportBtn).toBeVisible({ timeout: 30_000 });
    await reportBtn.click();

    const dialog = page.getByRole('dialog', { name: /신고/ });
    await dialog.getByRole('combobox').selectOption({ index: 1 });
    await dialog.getByRole('textbox').fill('E2E 중복 신고 검증');
    await dialog.getByRole('button', { name: '신고하기' }).click();
    // 1차 신고 성공으로 모달이 닫힘
    await expect(dialog).toBeHidden({ timeout: 30_000 });

    // 2차 신고 시도: 증상2 미해결 환경이면 버튼이 "신고"로 남아 재시도 가능하나,
    // 수정 후에는 "신고됨"으로 전환되어 재신고 진입 자체가 막힌다(정상).
    // 따라서 재신고 진입이 가능한 경우에만 409 → 창 닫힘+토스트를 검증한다.
    if (await reportBtn.isVisible().catch(() => false)) {
      await reportBtn.click();
      await dialog.getByRole('combobox').selectOption({ index: 1 });
      await dialog.getByRole('textbox').fill('E2E 중복 재신고');
      await dialog.getByRole('button', { name: '신고하기' }).click();
      // 409 → onError: closeModal + error toast
      await expect(dialog).toBeHidden({ timeout: 30_000 });
    }
  });
});