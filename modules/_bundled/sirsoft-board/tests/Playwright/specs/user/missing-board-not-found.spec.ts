/**
 * 없는 게시판 주소는 401/403 이 아니라 404 화면이다 (7.1.1).
 * 모듈 sirsoft-board (사용자 화면 `/board/{slug}` — 템플릿 sirsoft-basic 의 게시판 목록 404 갈래와 함께 본다).
 *
 * 결함: 없는 게시판 slug 의 글 목록 API 가 게시판 존재 확인보다 권한 검사를 먼저 해, 비회원은 401(로그인 이동)·회원은 403
 *   을 받았다. 게시판 존재 확인 미들웨어(`EnsureBoardExists`)를 권한보다 앞에 두고, 템플릿 목록 화면의 404 갈래가 오류 화면을 세운다.
 *
 * 데이터: 만들지 않는다 — 실행마다 존재할 수 없는 slug 를 쓴다. 비회원 화면이라 계정이 필요 없고 쓰기 요청은 가로챈다.
 *
 * @scenario-manifest tests/scenarios/e2e-missing-board-not-found.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- missing-board-not-found --workers=1
 */
import { test, expect, type Page } from '@playwright/test';

/** 존재할 수 없는 게시판 slug (실행마다 다르게) */
const missingSlug = (): string => `__e2e_f22_${Date.now().toString(36)}`;

/**
 * GET·HEAD 가 아닌 `/api/` 요청을 서버에 보내지 않는다(분석 수집 등).
 *
 * @param page Playwright 페이지
 */
async function guardWrites(page: Page): Promise<void> {
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    (route) => {
      const m = route.request().method();
      if (m === 'GET' || m === 'HEAD') return route.fallback();

      return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true,"data":{}}' });
    },
  );
}

test.describe('없는 게시판 주소 — 404', () => {
  /**
   * @scenario case=guest_screen
   * @effects missing_board_screen_is_404, missing_board_not_login_redirect
   */
  test('비회원이 없는 게시판 주소를 열면 404 화면이 뜨고 로그인으로 보내지 않는다', async ({ page }) => {
    await guardWrites(page);
    const slug = missingSlug();
    const statuses: number[] = [];
    page.on('response', (r) => {
      if (new URL(r.url()).pathname === `/api/modules/sirsoft-board/boards/${slug}/posts`) statuses.push(r.status());
    });

    await page.goto(`/board/${slug}`);

    await expect(page.locator('div.text-8xl', { hasText: '404' })).toBeVisible({ timeout: 30_000 });
    expect(new URL(page.url()).pathname, '로그인 화면으로 보내지 않는다').toBe(`/board/${slug}`);
    expect(statuses, '글 목록 API 응답').toContain(404);
    expect(statuses.filter((s) => s === 401 || s === 403), '401·403 없음').toEqual([]);
  });

  /**
   * @scenario case=guest_api
   * @effects missing_board_api_is_404
   */
  test('비회원 글 목록·글 보기 API 가 없는 게시판에 404 로 답한다', async ({ request }) => {
    const slug = missingSlug();
    const list = await request.get(`/api/modules/sirsoft-board/boards/${slug}/posts`, { headers: { Accept: 'application/json' } });
    expect(list.status(), '글 목록').toBe(404);
    const show = await request.get(`/api/modules/sirsoft-board/boards/${slug}/posts/1`, { headers: { Accept: 'application/json' } });
    expect(show.status(), '글 보기').toBe(404);
  });
});
