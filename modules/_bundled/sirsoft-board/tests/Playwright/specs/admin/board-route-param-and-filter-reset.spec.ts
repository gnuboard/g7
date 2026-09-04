/**
 * 게시판 관리 화면 — 라우트 파라미터 판정 · 필터 page 초기화 · 답글 첨부 (76차).
 *
 * 넷 다 브라우저에서만 드러난 결함이다. 단위(Vitest)는 레이아웃 JSON 의 조건식·query 키를
 * 보고, Feature(PHPUnit)는 검증 규칙과 직렬화를 보지만, 「편집 화면인데 신규 화면과 똑같이
 * 그려진다」·「좁히면 빈 페이지에 갇힌다」는 실제 라우트 위에서 렌더해 봐야 보인다.
 *
 * 귀속 매니페스트는 `tests/scenarios/board-form-route-param.yaml` 과
 * `board-posts-filter-page-reset.yaml` 이다 — 둘 다 `test_files` 로 이 파일을 지목하므로
 * 여기에 시나리오 id 를 되적지 않는다(파서가 읽는 것은 아래 `k=v` 마킹뿐이고, 이름만 적은
 * bare 토큰은 조합에 기여하지 않은 채 규약 불일치 래칫만 올린다).
 *
 * @axes screen=create screen=edit
 *       filter=status filter=enter_search filter=category
 *       page_origin=first page_origin=later
 * @effects edit_screen_shows_role_permission_sections,
 *          create_screen_hides_role_permission_sections,
 *          create_mode_notice_only_on_create,
 *          slug_input_locked_on_edit,
 *          slug_input_open_on_create,
 *          narrowing_filter_resets_page_when_merging_query,
 *          pager_navigation_keeps_its_own_page
 *
 * 이 spec 은 skip 하지 않는다 — 76차에 실제 브라우저에서 전건 통과를 확인했고, 선택자도
 * 그때 잡힌 것만 쓴다. `free` 게시판과 그 게시글은 시드가 항상 만든다.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const CREATE_URL = '/admin/boards/create';

/** 실측 대상 게시판 — 시드가 항상 만드는 자유게시판 */
const BOARD_SLUG = 'free';
const EDIT_URL = `/admin/boards/${BOARD_SLUG}/edit`;
const POSTS_URL = `/admin/board/${BOARD_SLUG}`;

/**
 * 선택자는 76차 브라우저 실측에서 실제로 잡힌 것만 쓴다.
 *
 * - 소스의 `data-ref` 는 DOM 에 **`id`** 로 내려온다 (`[data-ref=...]` 속성 선택자는 안 잡힌다)
 * - 페이지네이션은 `<nav>` 이 아니라 `.pagination` 컨테이너 안의 버튼이다 (aria-label 은 번역된다)
 * - **텍스트 선택자를 쓰지 않는다** — E2E 브라저는 영어 로케일로 뜼서
 *   한국어 라벨(`권한 설정` 등)로 잡으면 영영 매칭되지 않는다
 * - 탭은 스크롤-스파이라 모든 섹션이 처음부터 DOM 에 있다 — 탭을 누를 필요가 없다
 */

/**
 * 게시판 폼이 그려질 때까지 기다린다.
 *
 * 이 화면은 `transition_overlay.wait_for` 로 역할 목록을 기다리는데 그 응답이 느릴 수 있어
 * `domcontentloaded` 직후에는 입력칸이 아직 없다.
 */
async function waitForBoardForm(page: any): Promise<void> {
  await page.locator('[name="slug"]').waitFor({ state: 'visible', timeout: 60_000 });
}

/** 게시글 목록의 검색줄이 그려질 때까지 기다린다. */
async function waitForPostsGrid(page: any): Promise<void> {
  await page.locator('#advanced_search_toggle').waitFor({ state: 'visible', timeout: 60_000 });
}

/** 「상세 검색」 패널을 펜다 — 상태 필터가 그 안에 있다. */
async function openAdvancedSearch(page: any): Promise<void> {
  await page.locator('#advanced_search_toggle').click();
  await page.locator('#advanced_filters').waitFor({ state: 'visible', timeout: 15_000 });
}

test.describe.configure({ timeout: 120_000 });

test.describe('게시판 관리 화면 — 라우트 파라미터 · 필터 초기화 (76차)', () => {
  // @scenario screen=edit
  // @effects edit_screen_shows_role_permission_sections, create_mode_notice_only_on_create
  test('편집 화면에서 역할별 접근 제어 아코디언이 관리자·사용자 모두 나타난다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(EDIT_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForBoardForm(page);

    // 존재를 먼저 확정한 뒤 부재를 단언한다 — 반대로 하면 렌더 전에 통과해 버린다
    await expect(page.locator('#permissions_section_admin')).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.locator('#permissions_section_user')).toBeVisible();

    // 편집 화면인데 「생성 후 수정 화면에서 설정하세요」가 남아 있으면 자기를 가리키는 막다른 길
    await expect(page.locator('#permissions_create_mode_notice')).toHaveCount(0);
  });

  // @scenario screen=create
  // @effects create_screen_hides_role_permission_sections, create_mode_notice_only_on_create
  test('신규 작성 화면에서는 권한 아코디언 대신 안내만 나온다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForBoardForm(page);

    await expect(page.locator('#permissions_create_mode_notice')).toBeVisible({
      timeout: 15_000,
    });
    await expect(page.locator('#permissions_section_admin')).toHaveCount(0);
    await expect(page.locator('#permissions_section_user')).toHaveCount(0);
  });

  // @scenario screen=edit
  // @effects slug_input_locked_on_edit
  test('편집 화면의 slug 입력칸은 잠겨 있다 (서버가 slug 변경을 받지 않는다)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(EDIT_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForBoardForm(page);

    const slugInput = page.locator('[name="slug"]');
    await expect(slugInput).toHaveValue(BOARD_SLUG, { timeout: 15_000 });
    await expect(slugInput).toBeDisabled();
  });

  // @scenario screen=create
  // @effects slug_input_open_on_create
  test('신규 작성 화면의 slug 입력칸은 열려 있다', async ({ page, boardManageToken }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(CREATE_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForBoardForm(page);

    const slugInput = page.locator('[name="slug"]');
    await expect(slugInput).toBeVisible({ timeout: 15_000 });
    await expect(slugInput).toBeEnabled();
  });

  // @scenario filter=status, page_origin=later
  // @effects narrowing_filter_resets_page_when_merging_query
  test('뒷페이지에서 상태 필터를 좁히면 1페이지로 돌아온다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${POSTS_URL}?page=2&per_page=15`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForPostsGrid(page);

    // 사전조건 — 실제로 2페이지에 있다
    expect(new URL(page.url()).searchParams.get('page')).toBe('2');

    await openAdvancedSearch(page);
    await page.locator('input[name="status_filter"][value="blinded"]').click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(new URL(page.url()).searchParams.get('page')).toBe('1');
    expect(new URL(page.url()).searchParams.get('status')).toBe('blinded');
  });

  // @scenario filter=enter_search, page_origin=later
  // @effects narrowing_filter_resets_page_when_merging_query
  test('뒷페이지에서 엔터로 검색해도 1페이지로 돌아온다', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${POSTS_URL}?page=2&per_page=15`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForPostsGrid(page);

    const search = page.locator('#search_input');
    await search.fill('테스트');
    await search.press('Enter');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    // 페이지 되돌림은 `page` 를 빈 값으로 덮어 키 자체를 지우는 형태로도 표현된다
    // (주소에 키가 없으면 1페이지). 표기가 아니라 "첫 페이지로 돌아왔는가" 를 본다 —
    // 되돌리지 못한 회귀는 `2` 로 남으므로 어느 표기든 그대로 걸린다.
    expect(new URL(page.url()).searchParams.get('page') ?? '1').toBe('1');
  });

  // @scenario filter=category, page_origin=later
  // @effects pager_navigation_keeps_its_own_page
  test('페이지 이동 자신은 고른 페이지로 간다 (초기화 규칙의 예외)', async ({
    page,
    boardManageToken,
  }) => {
    await authenticatePage(page, boardManageToken);
    await page.goto(`${POSTS_URL}?per_page=15`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await waitForPostsGrid(page);

    // aria-label 은 번역되므로(`common.page_n`) 쓰지 않는다 — 클래스 + 숫자 텍스트로 잡는다
    const secondPage = page.locator('.pagination button').filter({ hasText: /^2$/ }).first();
    await expect(secondPage).toBeVisible({ timeout: 15_000 });
    await secondPage.click();
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    expect(new URL(page.url()).searchParams.get('page')).toBe('2');
  });
});
