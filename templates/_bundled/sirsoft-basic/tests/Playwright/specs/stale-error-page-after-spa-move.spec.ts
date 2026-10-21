/**
 * 늦게 온 오류 화면(404)이 이미 SPA 로 옮겨 간 다음 화면을 덮지 않는다 (7.1.1).
 * 템플릿 sirsoft-basic (유저 화면 — 게시판 목록·클레임 신청서·클레임 상세).
 *
 * 결함: 잘못된 주소로 들어가 404 가 뜨기 전(약 0.5초) 다른 화면으로 SPA 이동하면, 주소창은 도착 화면인데 본문은 늦게 온
 *   `showErrorPage` 의 「404 페이지를 찾을 수 없습니다」로 남았다(경로별 2~4/5). 엔진이 오류 화면을 세우기 전에 그 요청을 낸 라우트가
 *   지금 라우트인지 확인한다(`TemplateApp.getRouteChangeId`·`DataSourceManager` 경로 세대).
 *
 * 재현 창 고정: 경합을 정상 속도에서 기다리지 않는다 — 잘못된 주소의 데이터 요청을 `page.route` 로 2.5초 붙잡아 두고, 그 사이에
 *   다른 화면으로 옮긴 뒤 응답(404)을 풀어 준다(browser-verification.md `mem:race-defect-needs-forced-window-and-instrumentation`).
 *   같은 붙잡기에서 옮기지 않으면 404 가 뜨는 대조군을 함께 돌려, 붙잡은 경로가 실제로 오류 화면을 내는 경로임을 확인한다.
 *
 * 계정: 게시판 축은 비회원. 마이페이지 축은 실행 환경변수 계정(`tests/Playwright/fixtures/env-account.ts`)으로만 — 없으면 그 축만 사유와 함께 건너뛴다.
 * 쓰기 요청은 모두 가로챈다(서버 불변). 주문·클레임은 모의 404 라 실제 데이터를 쓰지 않는다.
 *
 * @scenario-manifest tests/scenarios/e2e-stale-error-page-after-spa-move.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npm run test:e2e -- stale-error-page-after-spa-move --workers=1
 */
import { test, expect, type Page } from '@playwright/test';
import {
  ACCOUNT_SKIP_REASON,
  authenticateWith,
  dismissCookieBanner,
  envAccountToken,
  errorPage404,
  guardWrites,
  hasEnvAccount,
  spaNavigate,
  waitForSpa,
} from '../fixtures/env-account';

test.describe.configure({ timeout: 90_000 });

/** 데이터 응답을 붙잡아 두는 시간 — 그 사이에 다른 화면으로 옮긴다 */
const HOLD_MS = 2_500;

interface HeldRoute {
  /** 붙잡은 요청이 들어왔는가 */
  seen: () => boolean;
  /** 붙잡은 응답을 풀어 줬는가 */
  released: () => boolean;
}

/**
 * 경로가 맞는 GET 을 붙잡았다가 HOLD_MS 뒤 404(또는 서버 응답)로 풀어 준다.
 *
 * @param page Playwright 페이지
 * @param match 붙잡을 API 경로 판정
 * @param mode 'fulfill404' 이면 모의 404, 'server' 면 서버에 그대로 보낸다
 * @returns 붙잡기 상태
 */
async function holdApi(page: Page, match: (pathname: string) => boolean, mode: 'fulfill404' | 'server'): Promise<HeldRoute> {
  let seen = false;
  let released = false;
  await page.route(
    (url) => match(url.pathname),
    async (route) => {
      if (route.request().method() !== 'GET') return route.fallback();
      seen = true;
      await new Promise((r) => setTimeout(r, HOLD_MS));
      released = true;
      try {
        if (mode === 'server') {
          await route.fallback();
        } else {
          await route.fulfill({
            status: 404,
            contentType: 'application/json',
            body: JSON.stringify({ success: false, message: 'not found' }),
          });
        }
      } catch {
        // 페이지가 그 요청을 이미 버렸으면(취소) 풀 응답이 없다 — 판정은 화면으로 한다
      }
    },
  );

  return { seen: () => seen, released: () => released };
}

/**
 * 잘못된 주소로 SPA 이동 → (옮기면) 붙잡은 사이 다른 화면으로 SPA 이동 → 응답을 푼 뒤 3초 관찰.
 * 도착 화면은 실제 라우트여야 한다 — 라우트에 없는 주소는 문서 이동으로 404 를 받아 이 판정을 흐린다(문서 요청 0 으로 확인).
 *
 * @param page Playwright 페이지
 * @param held 붙잡기 상태
 * @param badPath 잘못된 주소
 * @param destination 옮겨 갈 화면(null 이면 옮기지 않는 대조군)
 */
async function runMove(page: Page, held: HeldRoute, badPath: string, destination: string | null): Promise<void> {
  let documents = 0;
  page.on('request', (r) => {
    if (r.resourceType() === 'document') documents += 1;
  });
  await spaNavigate(page, badPath);
  await expect.poll(held.seen, { message: '잘못된 주소의 데이터 요청을 붙잡았다', timeout: 20_000 }).toBe(true);
  if (destination) {
    await page.waitForTimeout(500);
    await spaNavigate(page, destination);
    await expect.poll(() => new URL(page.url()).pathname, { timeout: 15_000 }).toBe(destination);
  }
  await expect.poll(held.released, { timeout: HOLD_MS + 10_000 }).toBe(true);
  await page.waitForTimeout(3_000);
  expect(documents, '두 이동 모두 SPA — 문서를 다시 읽지 않는다').toBe(0);
}

test.describe('늦은 오류 화면 — 비회원 게시판', () => {
  const slug = `__e2e_f45_${Date.now().toString(36)}`;
  const isBoardApi = (p: string): boolean => p.startsWith(`/api/modules/sirsoft-board/boards/${slug}`);

  /**
   * @scenario case=guest_board_move_away
   * @effects stale_404_does_not_cover_destination, destination_route_kept
   */
  test('없는 게시판의 404 가 오기 전 인기글로 옮기면 인기글 화면이 남는다', async ({ page }) => {
    await guardWrites(page);
    const held = await holdApi(page, isBoardApi, 'server');
    await page.goto('/');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    await runMove(page, held, `/board/${slug}`, '/boards/popular');

    expect(new URL(page.url()).pathname).toBe('/boards/popular');
    await expect(errorPage404(page)).toHaveCount(0);
  });

  /**
   * @scenario case=guest_board_control
   * @effects held_path_shows_404_when_staying
   */
  test('대조군 — 옮기지 않으면 같은 붙잡기에서 404 화면이 뜬다', async ({ page }) => {
    await guardWrites(page);
    const held = await holdApi(page, isBoardApi, 'server');
    await page.goto('/');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    await runMove(page, held, `/board/${slug}`, null);

    await expect(errorPage404(page)).toBeVisible({ timeout: 15_000 });
  });
});

test.describe('늦은 오류 화면 — 회원 마이페이지 클레임', () => {
  test.skip(!hasEnvAccount(), ACCOUNT_SKIP_REASON);

  const order = 'E2EF45NOPE';
  const claim = 'E2EF45CLAIM';
  const isOrderApi = (p: string): boolean => p === `/api/modules/sirsoft-ecommerce/user/orders/${order}`;
  const isClaimApi = (p: string): boolean => p.startsWith(`/api/modules/sirsoft-ecommerce/user/claims/${claim}`);

  const cases: Array<{ name: string; bad: string; match: (p: string) => boolean }> = [
    { name: '없는 주문의 반품 신청서', bad: `/mypage/orders/${order}/claims/apply/return`, match: isOrderApi },
    { name: '없는 주문의 잘못된 신청 유형', bad: `/mypage/orders/${order}/claims/apply/bogus`, match: isOrderApi },
    { name: '없는 클레임 상세', bad: `/mypage/claims/${claim}`, match: isClaimApi },
  ];

  for (const c of cases) {
    /**
     * @scenario case=member_claim_move_away
     * @effects stale_404_does_not_cover_destination, destination_route_kept
     */
    test(`${c.name} — 404 가 오기 전 다른 마이페이지로 옮기면 그 화면이 남는다`, async ({ page, request }) => {
      const token = await envAccountToken(request);
      await authenticateWith(page, token!);
      await guardWrites(page);
      const held = await holdApi(page, c.match, 'fulfill404');
      await page.goto('/mypage/orders');
      await waitForSpa(page);
      await dismissCookieBanner(page);

      await runMove(page, held, c.bad, '/mypage/board');

      expect(new URL(page.url()).pathname).toBe('/mypage/board');
      await expect(errorPage404(page)).toHaveCount(0);
    });
  }

  /**
   * @scenario case=member_claim_control
   * @effects held_path_shows_404_when_staying
   */
  test('대조군 — 옮기지 않으면 없는 주문의 반품 신청서에 404 화면이 뜬다', async ({ page, request }) => {
    const token = await envAccountToken(request);
    await authenticateWith(page, token!);
    await guardWrites(page);
    const held = await holdApi(page, isOrderApi, 'fulfill404');
    await page.goto('/mypage/orders');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    await runMove(page, held, `/mypage/orders/${order}/claims/apply/return`, null);

    await expect(errorPage404(page)).toBeVisible({ timeout: 15_000 });
  });
});
