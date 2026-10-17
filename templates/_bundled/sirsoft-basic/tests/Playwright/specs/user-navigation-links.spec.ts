/**
 * E2E: 사용자 화면의 이동 자리는 주소를 가진 링크다 — 새 탭으로 열 수 있다 (#757)
 *
 * 결함: 머리글 메뉴·상품 카드·게시글 행·페이지 번호가 `<button>` + 클릭 이동이라 렌더 시점에 주소가 없었다.
 *   Ctrl+클릭·가운데 클릭·우클릭 「새 탭에서 열기」가 되지 않았다.
 *   이동 자리를 `<a href>` 로 그리고, 보통 클릭만 지금처럼 SPA 로 옮긴다. 수정키 클릭은 브라우저에 맡겨 새 탭이 열린다.
 *
 * 단위/정적:
 *   - src/components/composite/__tests__/NavigationLinks.test.tsx — 합성 컴포넌트 링크 계약
 *   - __tests__/layouts/navigation-links.test.ts — 레이아웃 전수의 클릭 이동이 href 를 가진 A
 *   이 spec 은 실제 브라우저에서 Ctrl+클릭이 새 탭을 열고 원래 탭은 그대로인지, 보통 클릭은 문서를 다시 읽지 않는지 본다.
 *
 * 게시판 시험은 전제를 스스로 세운다 — `playwright:seed-board-posts --tag=<워커>` 로 쪽당 2건·글 3건(2쪽)인 임시 게시판을
 * 만들고(기본 권한: 비로그인 읽기 · 회원 쓰기) afterAll 에서 `--teardown` 으로 지운다. 상품은 공개 API 로 찾는다.
 *
 * @scenario-manifest tests/scenarios/navigation-links.yaml
 */
import { test, expect, type Page, type BrowserContext } from '@playwright/test';

import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { authenticatePage } from '../../../../../../tests/Playwright/fixtures/auth';

const __dirname = dirname(fileURLToPath(import.meta.url));

/** 시드 게시판 — slug · 글 번호(작성 순) · 쪽당 글 수 · 마지막 쪽 · 기본 `user` 역할 회원의 토큰 */
type BoardSeed = { board_slug: string; post_ids: number[]; per_page: number; last_page: number; member_token: string };

/** 이 워커가 만든 시드와 그 태그 */
let boardSeed: BoardSeed | null = null;
let boardSeedTag = '';

/**
 * 게시판 시드 커맨드를 실행합니다(코어 루트에서, 3중 가드 옵트인).
 *
 * @param args 커맨드 인자
 * @returns 표준 출력
 */
function runBoardSeed(args: string[]): string {
  return execSync(`php artisan playwright:seed-board-posts ${args.join(' ')}`, {
    cwd: process.env.G7_ROOT || resolve(__dirname, '../../../../../../'),
    encoding: 'utf-8',
    env: { ...process.env, G7_PLAYWRIGHT_BYPASS: '1' },
  });
}

/**
 * 두 쪽인 임시 게시판을 만들거나(워커당 한 번) 이미 만든 것을 돌려줍니다.
 *
 * @returns 시드 게시판
 */
function seededBoard(): BoardSeed {
  if (!boardSeed) {
    boardSeedTag = `w${test.info().workerIndex}-${process.pid}`;
    const out = runBoardSeed([`--tag=${boardSeedTag}`, '--posts=3', '--per-page=2', '--json']);
    boardSeed = JSON.parse(out.trim().split(/\r?\n/).pop() ?? '{}') as BoardSeed;
    expect(boardSeed.last_page, '시드 게시판은 두 쪽이다').toBeGreaterThanOrEqual(2);
  }

  return boardSeed;
}

test.afterAll(() => {
  // 개발 DB 에 시험용 게시판이 남으면 게시판 메뉴·목록 건수가 조용히 달라진다
  if (boardSeed) runBoardSeed([`--tag=${boardSeedTag}`, '--teardown']);
});

/**
 * 화면을 열고 첫 렌더가 끝날 때까지 기다립니다.
 *
 * @param page 대상 페이지
 * @param path 열 경로
 */
async function open(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('networkidle');
}

/**
 * 링크를 Ctrl(맥은 Cmd)+클릭해 새 탭이 열리는지 보고, 원래 탭은 이동하지 않았는지 확인합니다.
 *
 * @param context 브라우저 컨텍스트
 * @param page 원래 탭
 * @param link 누를 링크
 * @param expectedPath 새 탭이 열어야 할 경로(정규식)
 */
async function expectModifiedClickOpensNewTab(
  context: BrowserContext,
  page: Page,
  link: ReturnType<Page['locator']>,
  expectedPath: RegExp,
): Promise<void> {
  const before = page.url();
  const [popup] = await Promise.all([
    context.waitForEvent('page'),
    link.click({ modifiers: ['ControlOrMeta'] }),
  ]);
  // 수정키로 연 탭은 about:blank 로 먼저 생기고 그 상태의 load 는 바로 끝난다 — 실제 주소로 바뀐 뒤 읽는다
  await popup.waitForURL((u) => u.protocol !== 'about:');
  await popup.waitForLoadState('domcontentloaded');

  expect(new URL(popup.url()).pathname + new URL(popup.url()).search).toMatch(expectedPath);
  expect(page.url(), '원래 탭은 이동하지 않는다').toBe(before);
  await popup.close();
}

/**
 * 게시판 글 목록 응답의 글쓰기 권한을 읽습니다(그 요청 주체 기준).
 *
 * @param page 요청에 쓸 페이지
 * @param slug 게시판 slug
 * @param token 회원 토큰(없으면 비로그인)
 * @returns 글쓰기 권한
 */
async function canWrite(page: Page, slug: string, token?: string): Promise<boolean> {
  const res = await page.request.get(`/api/modules/sirsoft-board/boards/${slug}/posts`, {
    headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
  });
  const json = res.ok() ? await res.json() : null;

  return json?.data?.abilities?.can_write === true;
}

test.describe('사용자 화면 — 이동 자리는 링크', () => {
  // @scenario component=header
  // @effects modified_click_opens_browser_default
  test('머리글 인기글 메뉴를 Ctrl+클릭하면 새 탭이 열리고 원래 탭은 그대로다', async ({ context, page }) => {
    await open(page, '/');
    const popular = page.getByTestId('nav-popular');

    await expect(popular).toHaveAttribute('href', '/boards/popular');
    await expectModifiedClickOpensNewTab(context, page, popular, /^\/boards\/popular/);
  });

  // @scenario component=header
  // @effects plain_click_spa_navigate
  test('머리글 인기글 메뉴를 보통 클릭하면 문서를 다시 읽지 않고 옮겨 간다', async ({ page }) => {
    await open(page, '/');
    // 문서를 다시 읽으면 이 표식이 사라진다
    await page.evaluate(() => {
      (window as unknown as Record<string, unknown>).__spaMarker = true;
    });

    await page.getByTestId('nav-popular').click();
    await expect(page).toHaveURL(/\/boards\/popular/);
    expect(await page.evaluate(() => (window as unknown as Record<string, unknown>).__spaMarker)).toBe(true);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  test('상품 목록의 상품을 Ctrl+클릭하면 상품 상세가 새 탭으로 열린다', async ({ context, page }) => {
    await open(page, '/');
    const shopHref = await page.getByTestId('nav-shop').getAttribute('href');
    test.skip(!shopHref, '쇼핑 메뉴가 없어 검증할 수 없습니다');

    const res = await page.request.get('/api/modules/sirsoft-ecommerce/products?per_page=1', { headers: { Accept: 'application/json' } });
    const body = res.ok() ? await res.json() : null;
    const item = (body?.data?.data ?? body?.data ?? [])[0];
    const code = item?.product_code ?? item?.id;
    test.skip(code === undefined, '공개 상품이 없어 검증할 수 없습니다');

    await open(page, shopHref!);
    const product = page.locator(`a[href*="/products/${code}"]`).first();
    await expect(product).toBeVisible();

    await expectModifiedClickOpensNewTab(context, page, product, /\/products\/[^/?]+/);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  test('게시판 목록의 게시글 행을 Ctrl+클릭하면 글 상세가 새 탭으로 열린다', async ({ context, page }) => {
    const board = seededBoard();
    // 최신순 1쪽 맨 위 = 마지막에 만든 글
    const newest = board.post_ids[board.post_ids.length - 1];

    await open(page, `/board/${board.board_slug}`);
    const row = page.locator(`a[href^="/board/${board.board_slug}/${newest}"]`).first();
    await expect(row).toBeVisible();

    await expectModifiedClickOpensNewTab(context, page, row, new RegExp(`^/board/${board.board_slug}/\\d+`));
  });

  // @scenario component=pagination
  // @effects modified_click_opens_browser_default
  test('게시판 목록의 2쪽 번호를 Ctrl+클릭하면 2쪽이 새 탭으로 열린다', async ({ context, page }) => {
    const board = seededBoard();

    await open(page, `/board/${board.board_slug}`);
    const pageTwo = page.locator('a[href*="page=2"]').filter({ hasText: /^\s*2\s*$/ }).first();
    await expect(pageTwo).toBeVisible();

    await expectModifiedClickOpensNewTab(context, page, pageTwo, /[?&]page=2(&|$)/);
  });

  // @scenario component=layout
  // @effects layout_moves_are_links
  test('게시판 글쓰기 — 쓸 수 있는 회원은 글쓰기 화면 링크(Ctrl+클릭 새 탭), 비로그인은 단추다', async ({ browser }) => {
    const slug = seededBoard().board_slug;

    // 시드가 만든 기본 `user` 역할 회원 — 시드 게시판 권한은 비로그인 읽기 · 회원 쓰기다.
    // 역할 없는 토큰 계정(issueScopedToken)은 「회원」 역할이 없어 쓰기 권한을 받지 못한다.
    const memberToken = seededBoard().member_token;
    expect(memberToken, '시드가 회원 토큰을 낸다').toBeTruthy();
    const seen: Record<string, boolean> = {};
    for (const token of [undefined, memberToken]) {
      const who = token ? '회원' : '비로그인';
      const context = await browser.newContext();
      const page = await context.newPage();
      if (token) await authenticatePage(page, token);
      const allowed = await canWrite(page, slug, token);
      seen[who] = allowed;

      await open(page, `/board/${slug}`);
      const writeLink = page.locator(`a[href^="/board/${slug}/write"]`).first();
      if (allowed) {
        await expect(writeLink, `${who} — 글쓰기 링크`).toBeVisible();
        await expectModifiedClickOpensNewTab(context, page, writeLink, new RegExp(`^/board/${slug}/write`));
      } else {
        await expect(writeLink, `${who} — 권한이 없으면 링크가 아니다`).toHaveCount(0);
      }
      await context.close();
    }

    // 두 갈래를 모두 지났다 — 시드 게시판 권한이 사이트 기본(회원 쓰기 · 비로그인 못 씀)과 다르면 여기서 드러난다
    expect(seen, '회원은 링크 갈래, 비로그인은 단추 갈래').toEqual({ 비로그인: false, 회원: true });
  });
});
