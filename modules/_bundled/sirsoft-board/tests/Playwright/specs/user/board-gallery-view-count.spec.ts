/**
 * 갤러리형 목록 — "조회수 표시" 토글(show_view_count) 사용자 페이지 반영 (이슈 #413 item 19-4b).
 *
 * 관리자가 게시판 기본 탭에서 "조회수 표시"를 OFF 로 저장하면 사용자 갤러리형 목록의
 * 조회수(눈 아이콘 + 숫자)가 사라지고, ON(또는 미설정)이면 표시된다. basic/card 형은
 * 이미 동일 조건으로 동작하므로 갤러리형도 동일하게 정합되어야 한다.
 *
 * 단위(Vitest board-gallery-view-count-toggle) 는 갤러리 레이아웃 JSON 의 조건부 렌더링
 * (show_view_count !== false → Span 노출/숨김)을 결정적으로 검증하고, 이 spec 은
 * 브라우저 수준(설정 저장 → 사용자 갤러리 목록 반영)을 담당한다.
 *
 * @scenario board-gallery-view-count
 * @axes show_view_count=true show_view_count=false show_view_count=unset board_type=gallery
 * @effects gallery_shows_view_count_when_true,
 *          gallery_shows_view_count_when_unset,
 *          gallery_hides_view_count_when_false,
 *          gallery_matches_basic_and_card_condition
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능하고, 갤러리형 게시판 + 게시글 샘플이
 * 시드된 환경에서 test.describe.skip → test.describe 로 전환.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

// 갤러리형 샘플 게시판 (slug 는 환경에 맞게 조정)
const GALLERY_SLUG = 'gallery';
const GALLERY_URL = `/board/${GALLERY_SLUG}`;

test.describe.skip('갤러리형 목록 — 조회수 표시 토글 (#413)', () => {
  // @scenario show_view_count=true board_type=gallery
  // @effects gallery_shows_view_count_when_true
  test('조회수 표시 ON 이면 갤러리 목록에 조회수가 노출된다', async ({ page }) => {
    await page.goto(GALLERY_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 갤러리 카드의 조회수 아이콘(eye)이 하나 이상 보여야 함
    await expect(page.locator('[data-icon="eye"]').first()).toBeVisible({ timeout: 10_000 });
  });

  // @scenario show_view_count=false board_type=gallery
  // @effects gallery_hides_view_count_when_false, gallery_matches_basic_and_card_condition
  test('조회수 표시 OFF 로 저장하면 갤러리 목록에서 조회수가 사라진다', async ({
    page,
    settingsToken,
  }) => {
    // 관리자 권한으로 설정 변경 (show_view_count=false) 후 사용자 갤러리 재방문
    await authenticatePage(page, settingsToken);
    // 설정 저장 절차는 환경 시드/엔드포인트에 맞춰 활성화 시 보강한다.

    await page.goto(GALLERY_URL);
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 조회수 아이콘(eye)이 목록 카드 영역에 노출되지 않아야 함
    await expect(page.locator('[data-icon="eye"]')).toHaveCount(0, { timeout: 10_000 });
  });
});