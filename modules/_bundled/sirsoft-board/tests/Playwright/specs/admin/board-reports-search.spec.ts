/**
 * 게시판 신고 관리 목록 — 검색 기능 동작 검증 (이슈 #413 item 72).
 *
 * 결함: 검색어 입력 setState 가 target:"global" 로 _global 에 저장되는데,
 * 검색 실행 액션(searchBoardReports)과 input value 바인딩은 _local 을 읽어
 * 검색어가 요청에 전달되지 않아 "검색해도 작동하지 않음".
 *
 * 수정: 검색 Select/Input change setState 의 target:"global" 제거 → _local 저장.
 * reset 버튼은 검색어(searchField/searchQuery)만 _local 초기화로 분리.
 *
 * 단위(Vitest namedActionsBoardReports) 는 JSON 구조(쓰기/읽기 스코프 일치)만 검증하므로,
 * 브라우저 수준(입력 → 검색 실행 시 URL query 반영, 결과 필터링, 새로고침 복원)은 이 spec 이 담당.
 *
 * @scenario board-reports-search
 * @axes field=all field=post_title field=board_name field=author_name field=reporter_name
 * @effects search_keyword_propagated_to_url_query,
 *          search_field_propagated_to_url_query,
 *          search_input_value_retained_after_navigation,
 *          reset_clears_search_keyword,
 *          mobile_search_propagates_to_url_query
 *
 * 활성화 절차: PlaywrightIssueToken 발급이 가능한 환경에서 test.describe.skip → test.describe.
 */
import { test, expect, authenticatePage } from '../../fixtures/board-auth';

const REPORTS_URL = '/admin/boards/reports';

test.describe.skip('게시판 신고현황 — 검색 기능 동작 (#413-72)', () => {
  // @scenario field=all
  // @effects search_keyword_propagated_to_url_query, search_field_propagated_to_url_query
  test('검색어 입력 후 검색 실행 시 URL query 에 filters[0][value] 가 실린다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(REPORTS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 검색어 입력 (전체 필드)
    const searchInput = page.locator('#search_input input, #search_input').first();
    await searchInput.fill('테스트키워드');

    // 검색 버튼 클릭
    await page.locator('#search_button').click();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // URL query 에 검색어가 filters[0][value] 로 전달되어야 함 (회귀 핵심)
    const url = new URL(page.url());
    expect(url.searchParams.get('filters[0][value]')).toBe('테스트키워드');
    expect(url.searchParams.get('filters[0][field]')).toBe('all');
  });

  // @scenario field=board_name
  // @effects search_field_propagated_to_url_query, search_keyword_propagated_to_url_query
  test('검색 필드(게시판명) 선택 + 검색어 입력이 URL query 에 함께 전달된다', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(REPORTS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 검색 필드 select → board_name
    await page.locator('#search_field_select select, #search_field_select').first()
      .selectOption('board_name');
    await page.locator('#search_input input, #search_input').first().fill('자유게시판');
    await page.locator('#search_button').click();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const url = new URL(page.url());
    expect(url.searchParams.get('filters[0][field]')).toBe('board_name');
    expect(url.searchParams.get('filters[0][value]')).toBe('자유게시판');
  });

  // @scenario field=author_name
  // @effects search_input_value_retained_after_navigation
  test('검색 후 입력창에 검색어가 유지된다 (URL query → _local 복원)', async ({
    page,
    settingsToken,
  }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(`${REPORTS_URL}?filters[0][field]=author_name&filters[0][value]=홍길동`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // T6 영속성: URL query 의 검색어가 입력창에 복원되어야 함 (_local 동기화)
    const searchInput = page.locator('#search_input input, #search_input').first();
    await expect(searchInput).toHaveValue('홍길동', { timeout: 5_000 });
  });

  // @scenario field=all
  // @effects reset_clears_search_keyword
  test('초기화 버튼 클릭 시 검색어가 비워진다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await page.goto(`${REPORTS_URL}?filters[0][field]=all&filters[0][value]=지울키워드`);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('#reset_button').click();
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    // 초기화 후 입력창이 비고 URL 에 검색어가 없어야 함
    const searchInput = page.locator('#search_input input, #search_input').first();
    await expect(searchInput).toHaveValue('');
    const url = new URL(page.url());
    expect(url.searchParams.get('filters[0][value]')).toBeFalsy();
  });

  // @scenario field=post_title
  // @effects mobile_search_propagates_to_url_query
  test('모바일 뷰포트에서 검색어가 URL query 에 전달된다', async ({ page, settingsToken }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await authenticatePage(page, settingsToken);
    await page.goto(REPORTS_URL);
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    await page.locator('#mobile_search_field_select select, #mobile_search_field_select').first()
      .selectOption('post_title');
    await page.locator('#mobile_search_input input, #mobile_search_input').first().fill('공지사항');
    // 모바일은 Enter 키로 검색 실행
    await page.locator('#mobile_search_input input, #mobile_search_input').first().press('Enter');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const url = new URL(page.url());
    expect(url.searchParams.get('filters[0][field]')).toBe('post_title');
    expect(url.searchParams.get('filters[0][value]')).toBe('공지사항');
  });
});
