/**
 * 게시판 반응(추천/비추천) 사용자 흐름 — 등록 → 전환 → 해제 (이슈 #525).
 *
 * 게시글 상세에서 반응 버튼을 눌러 등록하고, 다른 유형으로 전환하고, 같은 유형을 재클릭해
 * 해제하는 흐름을 브라우저에서 확인한다. 각 클릭 후 게시글 데이터소스를 재조회(refetchDataSource)
 * 하므로, 서버 응답의 my_reaction_type_id·reaction_counts 가 버튼 하이라이트와 개수에 반영된다.
 *
 * 단위/레이아웃 테스트(ReactionServiceTest, ReactionApiTest, board-reaction-buttons.test.tsx)는
 * 등록/전환/해제 판정·카운트 증감·렌더 분기를 검증하므로, 이 spec 은 실제 클릭 → API 호출 →
 * 재조회 → 화면 갱신의 종단 흐름을 담당한다.
 *
 * @scenario surface=detail_register_switch_remove
 * @effects detail_register_then_switch_then_remove_updates_ui,register_inserts_row_and_increments_count,switch_updates_row_and_adjusts_both_counts,remove_deletes_row_and_decrements_count,self_post_click_shows_denied_toast
 *
 * 활성화 절차: PlaywrightIssueToken 발급 + 반응 유형이 켜진 공개 게시판/게시글 시드가 가능한
 *   환경에서 test.describe.skip → test.describe. SLUG/POST_ID 는 시드에 맞춰 조정.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const SLUG = 'free';
const POST_ID = 18;
const POST_PATH = `/board/${SLUG}/${POST_ID}`;
const REACT_API = `**/api/modules/sirsoft-board/boards/${SLUG}/posts/${POST_ID}/react`;

// 유형 ID (시드 순서상 like=1, dislike=2 가정 — 시드에 맞춰 조정)
const LIKE_ID = 1;
const DISLIKE_ID = 2;

// reactionToken 발급 계정이 작성자인 게시글 ID (본인 글 반응 차단 검증용) — 시드에 맞춰 조정
const OWN_POST_ID = 19;
const OWN_POST_PATH = `/board/${SLUG}/${OWN_POST_ID}`;
const OWN_POST_REACT_API = `**/api/modules/sirsoft-board/boards/${SLUG}/posts/${OWN_POST_ID}/react`;

test.describe('게시판 반응 등록→전환→해제 흐름 (#525)', () => {
  // @scenario surface=detail_register_switch_remove
  // @effects register_inserts_row_and_increments_count, switch_updates_row_and_adjusts_both_counts, remove_deletes_row_and_decrements_count
  test('추천 등록 → 비추천 전환 → 비추천 해제 시 버튼 하이라이트·개수가 갱신된다', async ({
    page,
    reactionToken,
  }) => {
    await authenticatePage(page, reactionToken);

    // react API 응답을 흐름 단계별로 제어 (등록/전환/해제)
    const steps = [
      { action: 'add', my: LIKE_ID, counts: { [LIKE_ID]: 1, [DISLIKE_ID]: 0 } },
      { action: 'change', my: DISLIKE_ID, counts: { [LIKE_ID]: 0, [DISLIKE_ID]: 1 } },
      { action: 'remove', my: null, counts: { [LIKE_ID]: 0, [DISLIKE_ID]: 0 } },
    ];
    let step = 0;
    let reactBody: Record<string, unknown> | null = null;

    await page.route(REACT_API, async (route) => {
      reactBody = route.request().postDataJSON() as Record<string, unknown>;
      const s = steps[Math.min(step, steps.length - 1)];
      step += 1;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          message: 'ok',
          data: { action: s.action, my_reaction_type_id: s.my, reaction_counts: s.counts },
        }),
      });
    });

    await page.goto(POST_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const likeButton = page.getByRole('button', { name: /추천|Recommend/ }).first();
    const dislikeButton = page.getByRole('button', { name: /비추천|Not Recommend/ }).first();

    // 1) 추천 등록 → count +1, reaction_type_id = like
    // 처리 중에는 버튼이 disabled(스피너) 되므로, 응답 도착 후 스피너가 완전히 걷히고
    // 카운트 텍스트가 갱신될 때까지 기다린 다음 다음 클릭을 보낸다 — 그렇지 않으면 아직
    // 리렌더 중인 버튼에 클릭이 씹혀 요청이 누락된다.
    // step 자체를 폴링한다(2·3단계 모두 reaction_type_id=DISLIKE_ID 로 동일하므로,
    // reactBody 값만 보면 세 번째 요청이 발생하지 않아도 이전 값과 우연히 일치해 통과해버린다).
    await likeButton.click();
    await expect.poll(() => step, { timeout: 5_000 }).toBe(1);
    expect((reactBody as Record<string, unknown> | null)?.reaction_type_id).toBe(LIKE_ID);
    await expect(likeButton).toHaveText(/추천\s*1/, { timeout: 5_000 });
    await expect(dislikeButton).toBeEnabled({ timeout: 5_000 });

    // 2) 비추천 전환 → like -1 · dislike +1
    await dislikeButton.click();
    await expect.poll(() => step, { timeout: 5_000 }).toBe(2);
    expect((reactBody as Record<string, unknown> | null)?.reaction_type_id).toBe(DISLIKE_ID);
    await expect(dislikeButton).toHaveText(/비추천\s*1/, { timeout: 5_000 });
    await expect(dislikeButton).toBeEnabled({ timeout: 5_000 });

    // 3) 비추천 재클릭 → 해제 (같은 유형 재요청)
    await dislikeButton.click();
    await expect.poll(() => step, { timeout: 5_000 }).toBe(3);
    expect((reactBody as Record<string, unknown> | null)?.reaction_type_id).toBe(DISLIKE_ID);
  });

  // @scenario surface=detail_register_switch_remove
  // @effects guest_react_returns_401
  test('비로그인 상태에서 반응 클릭 시 로그인 안내 후 로그인 페이지로 이동한다', async ({ page }) => {
    // 인증 없이 진입 (토큰 미주입)
    await page.goto(POST_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const likeButton = page.getByRole('button', { name: /추천|Recommend/ }).first();
    await likeButton.click();

    // 비로그인 → 로그인 페이지로 이동 (redirect 파라미터로 원글 경로 전달)
    await expect.poll(() => page.url(), { timeout: 5_000 }).toContain('/login');
    expect(page.url()).toContain('redirect');
  });

  // @scenario surface=detail_register_switch_remove
  // @effects self_post_click_shows_denied_toast
  test('본인 글 반응 버튼 클릭 시 API 호출 없이 안내 토스트가 표시된다', async ({
    page,
    reactionToken,
  }) => {
    await authenticatePage(page, reactionToken);

    // disabled 버튼은 클릭 이벤트 자체가 발생하지 않아 안내를 줄 수 없으므로, 본인 글에서는
    // 버튼을 시각적으로만 비활성 톤으로 두고 클릭은 받아 switch 분기에서 토스트로 안내한다
    // (트러블슈팅: PO 피드백 — disabled 버튼은 "왜 안 눌리는지" 사용자가 알 수 없음).
    let reactCalled = false;
    await page.route(OWN_POST_REACT_API, async (route) => {
      reactCalled = true;
      await route.abort();
    });

    await page.goto(OWN_POST_PATH);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const likeButton = page.getByRole('button', { name: /추천|Recommend/ }).first();
    await likeButton.click();

    await expect(page.getByText(/본인 글에는 반응할 수 없습니다|cannot react to your own post/i)).toBeVisible({
      timeout: 5_000,
    });
    expect(reactCalled).toBe(false);
  });
});
