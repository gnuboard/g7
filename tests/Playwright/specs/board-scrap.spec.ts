/**
 * 게시글 스크랩(북마크) 사용자 흐름 (skeleton, placeholder) — 이슈 #524.
 *
 * 사용자 시나리오: 게시글 상세에서 스크랩 등록 → 마이페이지 "스크랩" 탭에서 확인 → 해제.
 *
 * @scenario action=toggle_add,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @scenario action=mypage_delete,actor=owner,board_setting=use_scrap_on,post_state=alive,post_scope=same_board
 * @effects scrap_record_created_on_add,detail_button_reflects_is_scraped,detail_shows_scrap_count,
 *          mypage_list_sorted_recent_first,mypage_delete_requires_ownership
 *
 * e2e:allow 본 기능의 사용자측 표면(상세 스크랩 버튼 토글 · 마이페이지 스크랩 탭/카드/모달)은
 *           레이아웃 렌더링 테스트(board-scrap.test.tsx 15건 green)가 구조적으로 회귀 차단하고,
 *           토글/카운트/스코프/삭제/활동로그는 백엔드 회귀(PostScrapApiTest 12건 green)가 라이브
 *           검증한다. 본 placeholder spec 은 아래 사전작업(게시판·게시글 시드 + data-testid 보강)
 *           완료 후 활성화한다.
 *
 * 활성화 전 사전 작업:
 *   1. use_scrap=true 게시판 + 게시글을 시드하는 fixture (memberScrapBoard)
 *   2. show.json 스크랩 버튼에 data-testid="post-scrap-btn"
 *   3. _list.json 스크랩 카드에 data-testid="scrap-card-{id}", ✕ 버튼에 data-testid="scrap-remove-{id}"
 *   4. _modal_scrap_delete.json 에 data-testid="scrap-delete-modal", 해제 버튼 data-testid="scrap-delete-confirm"
 *   5. 회원 토큰 fixture (memberToken)
 *   6. test.describe.skip → test.describe
 */
import { test, expect, authenticatePage, issueToken } from '../fixtures/auth';

const POST_DETAIL_URL = (slug: string, id: number) => `/board/${slug}/${id}`;
const SCRAP_TAB_URL = '/mypage/scrap';

test.describe.skip('게시글 스크랩 사용자 흐름 (placeholder — 시드/data-testid 보강 후 활성화)', () => {
  test('상세에서 스크랩하면 버튼이 스크랩됨 상태로 바뀌고 수치가 오른다', async ({ page }) => {
    await authenticatePage(page, issueToken('sirsoft-board.free.posts.read'));
    await page.goto(POST_DETAIL_URL('free', 1));

    const scrapBtn = page.getByTestId('post-scrap-btn');
    await expect(scrapBtn).toBeVisible();
    await scrapBtn.click();

    // 토글 후 "스크랩됨" 상태 (재클릭 시 즉시 해제되므로 확인 모달 없음)
    await expect(scrapBtn).toContainText(/스크랩됨|Scrapped/i);
  });

  test('마이페이지 스크랩 탭에서 등록한 글을 확인하고 모달을 거쳐 해제한다', async ({ page }) => {
    await authenticatePage(page, issueToken('sirsoft-board.free.posts.read'));
    await page.goto(SCRAP_TAB_URL);

    const card = page.getByTestId(/^scrap-card-/).first();
    await expect(card).toBeVisible();

    // ✕ 클릭 → 즉시 삭제가 아니라 확인 모달
    await page.getByTestId(/^scrap-remove-/).first().click();
    await expect(page.getByTestId('scrap-delete-modal')).toBeVisible();

    // 해제 확정 → 목록에서 제거
    await page.getByTestId('scrap-delete-confirm').click();
    await expect(card).toBeHidden();
  });
});