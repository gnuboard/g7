/**
 * 게시판 확장점·홈 환영 배지 — 실제 브라우저에서 자리 수와 화면 언어를 잰다 (#149)
 *
 * 배경:
 * - 확장 모듈이 글마다 배지를 붙일 자리(`board_post_row_badges`)가 없었다. overlay(target-id)는 첫 일치 노드
 *   하나에만 들어가 게시판 유형 세 갈래 중 basic 에만 닿으므로, 템플릿이 이름 붙은 확장점을 연다.
 * - 확장점 호스트는 빈 `<div class="contents" data-extension-point="…">` 로 렌더된다. 단위 테스트는 굽기 산출
 *   JSON 만 보므로, 호스트가 실제 DOM 에 행마다(basic 은 PC·모바일 두 갈래) 생기는지와 그 때문에 콘솔 오류가
 *   생기지 않는지는 브라우저로만 확인된다.
 * - 홈 환영 카드 배지는 「Welcome」 하드코딩이라 ko·en·ja 모두 영어였다 — 새 키 `home.welcome_badge`.
 *
 * 비회원 화면 언어는 `localStorage.g7_locale` 로 정한다(config 의 locale 은 ko-KR 고정).
 */
import { test, expect, type Page } from '@playwright/test';

/** 화면 언어를 정하고 경로를 연다 — SPA 엔진이 뜰 때까지 기다린다. */
async function gotoWithLocale(page: Page, locale: 'ko' | 'en' | 'ja', path: string): Promise<void> {
  await page.addInitScript((l) => {
    try {
      if (!sessionStorage.getItem('e2e_locale_set')) {
        localStorage.setItem('g7_locale', l);
        sessionStorage.setItem('e2e_locale_set', '1');
      }
    } catch {
      // 저장소 접근이 막힌 환경 — 기본 언어로 진행
    }
  }, locale);
  await page.goto(path);
  await page.waitForFunction(() => typeof (window as unknown as { G7Core?: unknown }).G7Core === 'object', null, { timeout: 60_000 });
}

/**
 * 글 목록이 있는 basic 유형 공개 게시판 slug 하나를 공개 API 로 찾는다.
 * 시드 데이터에 특정 게시판이 있다고 가정하지 않는다.
 *
 * @param page Playwright 페이지
 * @return 게시판 slug 또는 없으면 null
 */
async function findBasicBoardWithPosts(page: Page): Promise<string | null> {
  const res = await page.request.get('/api/modules/sirsoft-board/boards/board-menu', { headers: { Accept: 'application/json' } });
  if (!res.ok()) return null;
  const body = await res.json();
  const boards: Array<{ slug?: string; type?: string }> = body?.data?.data ?? body?.data ?? [];
  for (const board of boards) {
    if (!board.slug || (board.type && board.type !== 'basic')) continue;
    const posts = await page.request.get(`/api/modules/sirsoft-board/boards/${board.slug}/posts`, { headers: { Accept: 'application/json' } });
    if (!posts.ok()) continue;
    const json = await posts.json();
    if ((json?.data?.data ?? []).length > 0 && (json?.data?.board?.type ?? 'basic') === 'basic') return board.slug;
  }
  return null;
}

test.describe('게시판 확장점 — 글 행 자리', () => {
  /**
   * @scenario surface=board_list_basic, locale=ko, site_name=empty
   * @effects basic_list_and_scrap_have_pc_and_mobile_slots, slot_hosts_have_no_node_id_and_use_contents_marker
   */
  test('basic 목록의 글 행마다 PC·모바일 두 자리가 생기고 콘솔 오류가 없다', async ({ page }) => {
    const errors: string[] = [];
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    const slug = await findBasicBoardWithPosts(page);
    test.skip(!slug, '글이 있는 basic 게시판이 없는 환경');

    await page.setViewportSize({ width: 1280, height: 900 });
    await gotoWithLocale(page, 'ko', `/board/${slug}`);

    // 행 = 상세로 가는 전체 덮개 링크(행마다 1개)
    const rowLinks = page.locator(`a[href^="/board/${slug}/"][aria-label]`);
    await expect(rowLinks.first()).toBeAttached({ timeout: 30_000 });
    const rows = await rowLinks.count();

    const slots = page.locator('[data-extension-point="board_post_row_badges"]');
    expect(await slots.count()).toBe(rows * 2);

    // 빈 호스트는 display:contents 라 상자를 만들지 않는다(제목 줄 흐름을 깨지 않음)
    const display = await slots.first().evaluate((el) => getComputedStyle(el).display);
    expect(display).toBe('contents');

    expect(errors).toEqual([]);
  });
});

test.describe('홈 환영 배지 — 화면 언어', () => {
  const CASES = [
    ['ko', '환영합니다'],
    ['en', 'Welcome'],
    ['ja', 'ようこそ'],
  ] as const;

  /**
   * @scenario surface=home_welcome, locale=en, site_name=set
   * @effects welcome_badge_follows_screen_locale
   */
  /**
   * @scenario surface=home_welcome, locale=ja, site_name=set
   * @effects welcome_badge_follows_screen_locale
   */
  for (const [locale, badge] of CASES) {
    /**
     * @scenario surface=home_welcome, locale=ko, site_name=set
     * @effects welcome_badge_follows_screen_locale
     */
    test(`${locale} 화면의 환영 배지는 「${badge}」`, async ({ page }) => {
      await gotoWithLocale(page, locale, '/');
      await expect(page.locator('html')).toHaveAttribute('lang', locale, { timeout: 30_000 });
      const card = page.locator('h2').first().locator('xpath=..');
      await expect(card.locator('span', { hasText: badge }).first()).toBeVisible({ timeout: 30_000 });
      if (locale !== 'en') {
        expect(await card.locator('span', { hasText: /^Welcome$/ }).count()).toBe(0);
      }
    });
  }
});
