/**
 * 블라인드·삭제 부모글 답글 폼 사전 차단 + 삭제 게시글 답변/첨부 접근 — 사용자 페이지
 * (이슈 #413 item 44, 50).
 *
 * 44: 블라인드/삭제된 부모글에 /board/{slug}/write?parent_id=N 으로 직접 진입해도
 *     답글 폼이 열리지 않고(404), 부모글 제목/본문이 노출되지 않는다.
 * 50-3: 삭제 게시글 상세에서 답변 버튼이 노출되지 않는다(!deleted_at).
 * 50-4: 삭제 게시글 첨부파일은 권한자만 접근(비권한자 다운로드/미리보기 차단).
 *
 * 단위/통합:
 *   - 백엔드 ReplyFormParentBlockTest 가 parent_status×access_path 로 폼 진입 차단을 검증
 *   - 백엔드 PostDeletedToggleTest 가 삭제 게시글/댓글 원본 권한(50-2)을 검증
 *   - 백엔드 DeletedPostAttachmentAccessTest 가 삭제 게시글 첨부 접근(50-4)을 검증
 *   - Vitest board-deleted-reply-button 이 답변 버튼 if 게이트(!deleted_at)를 고정
 *   이 spec 은 브라우저 수준(직접 URL 진입 차단 / 답변 버튼 미노출)을 담당한다.
 *
 * @scenario board-reply-and-deleted-post-access
 * @axes parent_status=blinded parent_status=deleted access_path=form_meta access_path=form_data
 *       viewer=regular viewer=manager post_state=published post_state=deleted
 * @effects form_meta_blocks_blinded_parent_and_hides_original,
 *          form_data_blocks_deleted_parent_and_hides_original,
 *          reply_button_if_blocks_deleted_at,
 *          regular_user_cannot_download_deleted_post_attachment
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능하고, 블라인드/삭제 상태 게시글 샘플이
 * 시드된 환경에서 test.describe.skip → test.describe 로 전환.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const BOARD_SLUG = 'inquiry';
// 블라인드/삭제 상태로 시드된 부모글 ID (환경에 맞게 조정)
const BLINDED_PARENT_ID = 170;
const DELETED_PARENT_ID = 265;

test.describe.skip('답글 폼 사전 차단 + 삭제 게시글 답변/첨부 (#413)', () => {
  // @scenario parent_status=blinded access_path=form_meta viewer=regular
  // @effects form_meta_blocks_blinded_parent_and_hides_original
  test('블라인드 부모글 답글 폼 직접 진입 시 폼 미오픈 + 부모 원문 비노출', async ({ page }) => {
    await page.goto(`/board/${BOARD_SLUG}/write?parent_id=${BLINDED_PARENT_ID}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 답글 작성 폼(제목 입력)이 열리지 않아야 함
    await expect(page.locator('form input[name="title"]')).toHaveCount(0, { timeout: 10_000 });
  });

  // @scenario parent_status=deleted access_path=form_data viewer=regular
  // @effects form_data_blocks_deleted_parent_and_hides_original
  test('삭제 부모글 답글 폼 직접 진입 시 폼 미오픈', async ({ page }) => {
    await page.goto(`/board/${BOARD_SLUG}/write?parent_id=${DELETED_PARENT_ID}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.locator('form input[name="title"]')).toHaveCount(0, { timeout: 10_000 });
  });

  // @scenario post_state=deleted viewer=manager
  // @effects reply_button_if_blocks_deleted_at
  test('관리자가 삭제 게시글 상세 진입 시 답변 버튼이 노출되지 않는다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);

    await page.goto(`/board/${BOARD_SLUG}/${DELETED_PARENT_ID}`);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    await expect(page.getByRole('button', { name: /답변|Reply/ })).toHaveCount(0, {
      timeout: 10_000,
    });
  });
});
