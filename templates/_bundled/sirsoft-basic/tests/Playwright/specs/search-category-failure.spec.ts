/**
 * 통합검색 카테고리 실패 표면화 — 브라우저 렌더 검증 (공개 이슈 #103)
 *
 * 배경:
 * - 카테고리 검색이 서버에서 예외로 실패하면 종전에는 HTTP 200 + "검색 결과가 없습니다"
 *   로 위장됐다. 수정 후에는 응답의 `categories_failed`/`search_failed` 를 근거로
 *   화면이 "검색 중 오류" 안내를 그려야 한다.
 * - 라이브 DB 에 임의 예외를 만들지 않는다 — `page.route` 로 실패 응답을 주입한다.
 *   (인덱스 부재 축은 LIKE 폴백으로 흡수되므로 Chrome MCP 라이브 시나리오가 담당)
 *
 * 축 마킹은 아래 태그에 `키=값, 키=값` 한 줄로 적는다 — 매니페스트 대조기가 읽는
 * 형식이 그것뿐이라, 다른 이름의 태그에 적으면 조합이 조용히 0건으로 집계된다.
 * 설명문에도 그 태그 이름을 리터럴로 쓰지 않는다(docblock 의 첫 매치를 가로챈다).
 * 아래 파일 단위 마킹이 기본축이고, 개별 test 의 라인 마킹이 나머지 조합을 채운다.
 *
 * @scenario category=posts, scope=all_tab, failure=single
 * @effects failed_flag_in_response,
 *          error_notice_instead_of_empty,
 *          unaffected_category_renders_normally
 */
import { test, expect, type Page } from '@playwright/test';

/** 실패 안내 문구 (ko/en — 사이트 로케일에 무관하게 매칭) */
const FAILED_TITLE = /검색 중 오류가 발생했습니다|An error occurred while searching/;

/** "결과 없음" 문구 (ko/en) */
const EMPTY_ALL = /^검색 결과가 없습니다\.$|^No results found\.$/;

/**
 * 검색 응답 데이터 골격을 만듭니다 (buildResponse 가 조립하는 실제 키 집합의 부분집합).
 *
 * @param overrides 덮어쓸 필드
 * @returns 응답 data 값
 */
function searchData(overrides: Record<string, unknown> = {}): Record<string, unknown> {
  return {
    q: '문의',
    total: 0,
    all_count: 0,
    all_count_is_exact: true,
    total_relation: 'exact',
    total_is_exact: true,
    result_cap: 10000,
    posts_count: 0,
    products_count: 0,
    pages_count: 0,
    posts: { items: [], available_boards: [] },
    products: [],
    pages: { items: [] },
    counts_are_exact: { posts: true, products: true, pages: true },
    categories_failed: { posts: false, products: false, pages: false },
    search_failed: false,
    ...overrides,
  };
}

/**
 * `/api/search` 응답을 fixture 로 대체합니다.
 *
 * @param page Playwright 페이지
 * @param data 응답 data 값
 */
async function mockSearchResponse(page: Page, data: Record<string, unknown>): Promise<void> {
  await page.route('**/api/search**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ success: true, message: 'ok', data }),
    });
  });
}

test.describe('통합검색 카테고리 실패 표면화 (#103)', () => {
  test('posts 만 실패한 전체 탭 — posts 섹션은 오류 안내, 다른 카테고리는 정상 렌더', async ({ page }) => {
    await mockSearchResponse(page, searchData({
      total: 1,
      all_count: 1,
      pages_count: 1,
      pages: {
        items: [{
          id: 1,
          title: '이용약관',
          title_highlighted: '이용약관',
          content_preview: '본 약관은 문의 안내를 포함합니다',
          content_preview_highlighted: '본 약관은 문의 안내를 포함합니다',
          published_at: '2026-02-01',
          url: '/page/terms',
        }],
      },
      counts_are_exact: { posts: false, products: true, pages: true },
      categories_failed: { posts: true, products: false, pages: false },
      search_failed: true,
    }));

    await page.goto('/search?q=문의');

    // posts 섹션: 오류 안내가 렌더된다 ("결과 없음" 이 아니라)
    await expect(page.getByText(FAILED_TITLE).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/게시글 검색 결과가 없습니다|No posts found/)).toHaveCount(0);

    // 실패하지 않은 pages 카테고리는 결과를 정상 렌더한다
    // ('이용약관' 은 사이트 네비의 페이지 링크와도 매칭되므로 고유한 미리보기 문구로 단언)
    await expect(page.getByText('본 약관은 문의 안내를 포함합니다').first()).toBeVisible();
  });

  // @scenario category=posts, scope=category_tab, failure=single
  // @effects failed_flag_in_response, error_notice_instead_of_empty
  test('posts 탭 실패 — 탭 화면이 "결과 없음" 대신 오류 안내를 그린다', async ({ page }) => {
    await mockSearchResponse(page, searchData({
      counts_are_exact: { posts: false, products: true, pages: true },
      categories_failed: { posts: true, products: false, pages: false },
      search_failed: true,
    }));

    await page.goto('/search?q=문의&type=posts');

    await expect(page.getByText(FAILED_TITLE).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(/게시글 검색 결과가 없습니다|No posts found/)).toHaveCount(0);
  });

  // 이 한 케이스가 세 카테고리 동시 실패를 덮으므로 카테고리 축을 각각 마킹한다.
  // @scenario category=posts, scope=all_tab, failure=all
  // @effects failed_flag_in_response, error_notice_instead_of_empty
  // @scenario category=products, scope=all_tab, failure=all
  // @effects failed_flag_in_response, error_notice_instead_of_empty
  // @scenario category=pages, scope=all_tab, failure=all
  // @effects failed_flag_in_response, error_notice_instead_of_empty
  test('전 카테고리 실패한 전체 탭 — 전역 오류 안내를 그린다', async ({ page }) => {
    await mockSearchResponse(page, searchData({
      counts_are_exact: { posts: false, products: false, pages: false },
      categories_failed: { posts: true, products: true, pages: true },
      search_failed: true,
    }));

    await page.goto('/search?q=문의');

    await expect(page.getByText(FAILED_TITLE).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(EMPTY_ALL)).toHaveCount(0);
  });

  test('실패 없는 0건 응답 — 종전 "결과 없음" 렌더가 유지된다 (회귀 가드)', async ({ page }) => {
    await mockSearchResponse(page, searchData());

    await page.goto('/search?q=문의');

    await expect(page.getByText(EMPTY_ALL).first()).toBeVisible({ timeout: 15_000 });
    await expect(page.getByText(FAILED_TITLE)).toHaveCount(0);
  });
});
