/**
 * 미발행 페이지 관리자 미리보기.
 *
 * @scenario viewer=guest, viewer=member, viewer=other_admin, viewer=page_reader, published=published, published=unpublished
 * @effects page_reader_admin_previews_unpublished_page_with_200,
 *          preview_banner_renders_when_is_preview_true,
 *          preview_banner_hidden_for_published_or_guest,
 *          guest_gets_404_for_unpublished_page,
 *          member_gets_404_for_unpublished_page
 *
 * 정책: 페이지 조회 권한(sirsoft-page.pages.read) 보유 관리자만 사용자 화면(/page/{slug})에서
 *   미발행 페이지를 미리볼 수 있고, 이때 "미발행 페이지 미리보기 중" 배너가 노출된다.
 *   비로그인·일반 회원·페이지 권한 없는 관리자는 미발행 페이지에서 404(페이지를 찾을 수 없습니다) 를 본다.
 *   발행 페이지는 누구에게나 배너 없이 노출된다.
 *
 * DOM 식별: 레이아웃 노드 id 는 HTML id 로 렌더되지 않으므로(콘텐츠 노드는 id 속성 미출력)
 *   사용자에게 보이는 텍스트로 식별한다(getByText 는 콘텐츠 비동기 렌더를 auto-wait).
 *   - 미리보기 배너: "미발행 페이지 미리보기 중입니다"
 *   - not-found:    "페이지를 찾을 수 없습니다"
 *   - 시드 페이지 제목: 미발행="미발행 미리보기 대상" / 발행="발행 대조군"
 *
 * 시드: playwright:seed-page 가 고정 슬러그를 발급한다.
 *   - 미발행: test-e2e-unpublished-preview (제목 "미발행 미리보기 대상")
 *   - 발행:   test-e2e-published-preview   (제목 "발행 대조군")
 */
import { mergeTests } from '@playwright/test';
import { test as authTest, expect, authenticatePage } from '../../fixtures/page-auth';
import { test as seedTest } from '../../fixtures/page-seed';

const test = mergeTests(authTest, seedTest);

const UNPUBLISHED_URL = '/page/test-e2e-unpublished-preview';
const PUBLISHED_URL = '/page/test-e2e-published-preview';

// 앱 locale(ko/en)에 무관하게 매칭 — 실행 환경 기본 locale 이 EN 일 수 있다.
// 제목은 seed 가 넣은 고정 문자열이라 locale 무관.
const PREVIEW_BANNER_TEXT = /미발행 페이지 미리보기 중입니다|Previewing an unpublished page/;
const NOT_FOUND_TEXT = /페이지를 찾을 수 없습니다|Page Not Found/;
const UNPUBLISHED_TITLE = '미발행 미리보기 대상';
const PUBLISHED_TITLE = '발행 대조군';

test.describe('미발행 페이지 관리자 미리보기', () => {
  test('페이지 조회 권한 관리자는 미발행 페이지를 미리보고 배너가 노출된다', async ({
    page,
    pageReadToken,
    seededPage,
  }) => {
    expect(seededPage.unpublished_slug).toBe('test-e2e-unpublished-preview');

    await authenticatePage(page, pageReadToken);
    await page.goto(UNPUBLISHED_URL);

    // 미발행이지만 권한자라 본문 노출 + 미리보기 배너 노출
    await expect(page.getByRole('heading', { name: UNPUBLISHED_TITLE })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(PREVIEW_BANNER_TEXT)).toBeVisible();
    await expect(page.getByText(NOT_FOUND_TEXT)).toHaveCount(0);
  });

  test('비로그인 사용자는 미발행 페이지에서 not-found 를 본다', async ({ page, seededPage }) => {
    expect(seededPage.unpublished_slug).toBe('test-e2e-unpublished-preview');

    // 인증 없음(게스트)
    await page.goto(UNPUBLISHED_URL);

    await expect(page.getByRole('heading', { name: NOT_FOUND_TEXT })).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(PREVIEW_BANNER_TEXT)).toHaveCount(0);
  });

  /*
   * "권한 없는 로그인 사용자 / pages.read 없는 다른 모듈 관리자 → 404" 케이스는 E2E 로
   * 재현 불가하여 PHPUnit 이 담당한다. 근거: PlaywrightIssueToken.makeAdminUser 는 발급 유저를
   * 무조건 코어 `admin` 롤에 부여하는데, 활성 DB 의 admin 롤은 시드로 sirsoft-page.pages.read 를
   * 포함한 전체 권한(131개)을 이미 보유한다. 따라서 issueToken() 으로는 "페이지 권한이 없는 유저"
   * 를 만들 수 없다(항상 pages.read 를 상속). 해당 게이트 분기는 아래 PHPUnit 이 격리 검증한다:
   *   - PublicPageControllerTest::test_non_admin_user_cannot_preview_unpublished_page
   *   - PublicPageControllerTest::test_admin_without_pages_read_cannot_preview_unpublished_page
   * (createAdminUser 는 admin 롤에 명시 권한만 syncWithoutDetaching 하여 격리 가능)
   */

  test('발행된 페이지는 배너 없이 노출된다 (관리자 포함)', async ({
    page,
    pageReadToken,
    seededPage,
  }) => {
    expect(seededPage.published_slug).toBe('test-e2e-published-preview');

    await authenticatePage(page, pageReadToken);
    await page.goto(PUBLISHED_URL);

    await expect(page.getByRole('heading', { name: PUBLISHED_TITLE })).toBeVisible({ timeout: 30_000 });
    // 발행 페이지는 is_preview=false → 배너 미노출
    await expect(page.getByText(PREVIEW_BANNER_TEXT)).toHaveCount(0);
  });
});