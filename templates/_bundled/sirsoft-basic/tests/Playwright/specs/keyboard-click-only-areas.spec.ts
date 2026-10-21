/**
 * 클릭만 받던 영역이 키보드로 닿고 동작한다 (7.1.1 — basic 화면 통화 선택 Esc).
 * 템플릿 sirsoft-basic (유저 화면, 비회원).
 *
 * 결함(낮음 110곳): 첨부 머리·첨부 카드·장바구니 금액 줄 같은 영역이 `div` 에 click 동작만 있어 Tab 으로 닿지 않고
 *   Enter·Space 로 쓸 수 없었다. 고침: 같은 동작의 `<button type="button">`(+`aria-expanded`)로 바꿨다(액션 무변경).
 *   머리글 통화 선택(이커머스 확장 조각)은 Esc 로 닫히지 않았다 → 목록 항목·트리거에서 Esc 로 닫고 초점을 트리거로 돌린다.
 *
 * 판정: 「Tab 으로 닿는다」 는 그 요소 앞의 글자를 눌러 순차 초점 시작점을 둔 뒤 Tab 만으로 그 요소에 초점이 오는지로 잰다.
 *   그 뒤 Enter·Space 로 상태(`aria-expanded`·펼친 내용·내려받기 요청)가 바뀌는지 본다.
 *
 * 데이터: 서버를 바꾸지 않는다 — 게시글 응답에는 첨부 1개를 이 브라우저에서만 덧붙이고 내려받기 주소를 가짜로 답한다.
 *   장바구니는 키 발급·조회를 모의한다(`tests/Playwright/specs/cart-checkbox-name-special-chars.spec.ts` 와 같은 방식). 쓰기 요청은 가로챈다. 계정 불필요.
 *
 * @scenario-manifest tests/scenarios/e2e-keyboard-click-only-areas.yaml
 *
 * 실행:
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/keyboard-click-only-areas.spec.ts --workers=1
 */
import { test, expect, type Locator, type Page } from '@playwright/test';
import { dismissCookieBanner, guardWrites, waitForSpa } from '../fixtures/env-account';

test.describe.configure({ timeout: 120_000 });

/**
 * Tab 만 눌러 대상에 초점이 오게 한다.
 *
 * @param page Playwright 페이지
 * @param target 닿아야 할 요소
 * @param max Tab 최대 횟수
 * @returns 누른 Tab 횟수(못 닿으면 -1)
 */
async function tabTo(page: Page, target: Locator, max = 60): Promise<number> {
  for (let i = 1; i <= max; i += 1) {
    await page.keyboard.press('Tab');
    if (await target.evaluate((el) => el === document.activeElement)) return i;
  }

  return -1;
}

/**
 * 순차 초점 시작점을 둔다 — 초점을 받지 않는 글자를 누르면 Chrome 은 그 자리부터 Tab 을 센다.
 *
 * @param anchor 시작점으로 쓸 글자 요소
 */
async function setFocusStart(anchor: Locator): Promise<void> {
  await anchor.click({ position: { x: 2, y: 2 } });
}

test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'ignoreErrors' });
});

test.describe('클릭만 받던 영역의 키보드 조작', () => {
  /**
   * @scenario case=board_post_attachments
   * @effects reachable_by_tab, enter_space_toggle_expanded, enter_activates_card
   */
  test('게시글 첨부 — 머리는 Tab 으로 닿고 Space·Enter 로 접고 펴며, 카드는 Enter 로 내려받는다', async ({ page, request }) => {
    // 공개 게시판의 글 하나를 고른다(비밀글 아님) — 응답에만 첨부를 덧붙인다
    const boards = ((await (await request.get('/api/modules/sirsoft-board/boards', { headers: { Accept: 'application/json' } })).json())?.data ?? []) as Array<{ slug: string }>;
    let target: { slug: string; id: number } | null = null;
    for (const b of boards) {
      const res = await request.get(`/api/modules/sirsoft-board/boards/${b.slug}/posts`, { headers: { Accept: 'application/json' } });
      if (!res.ok()) continue;
      const post = (((await res.json())?.data?.data ?? []) as Array<{ id: number; is_secret?: boolean }>).find((p) => !p.is_secret);
      if (post) {
        target = { slug: b.slug, id: post.id };
        break;
      }
    }
    test.skip(!target, '비회원이 읽을 수 있는 공개 게시글이 없어 잴 수 없다');

    await guardWrites(page);
    const FAKE_DOWNLOAD = '/api/modules/sirsoft-board/e2e-fake-attachment/9901';
    await page.route(
      (url) => url.pathname === `/api/modules/sirsoft-board/boards/${target!.slug}/posts/${target!.id}`,
      async (route) => {
        if (route.request().method() !== 'GET') return route.fallback();
        const res = await route.fetch();
        const body = await res.json();
        if (body?.data) {
          body.data.has_attachment = true;
          body.data.attachment_count = 1;
          body.data.attachments = [
            { id: 9901, original_filename: 'e2e-첨부.txt', size_formatted: '1 KB', is_image: false, download_url: FAKE_DOWNLOAD, preview_url: null },
          ];
          body.data.abilities = { ...(body.data.abilities ?? {}), can_download: true };
        }

        return route.fulfill({ response: res, json: body });
      },
    );
    let downloads = 0;
    await page.route(
      (url) => url.pathname === FAKE_DOWNLOAD,
      (route) => {
        downloads += 1;

        return route.fulfill({ status: 200, contentType: 'text/plain', body: 'e2e' });
      },
    );

    await page.goto(`/board/${target!.slug}/${target!.id}`);
    await waitForSpa(page);
    await dismissCookieBanner(page);

    const header = page.getByRole('heading', { name: /첨부파일/ }).getByRole('button');
    await expect(header).toBeVisible({ timeout: 30_000 });
    await expect(header).toHaveAttribute('aria-expanded', 'true');
    await setFocusStart(page.getByRole('heading', { level: 1 }).first());
    expect(await tabTo(page, header), '첨부 머리에 Tab 으로 닿는다').toBeGreaterThan(0);

    const card = page.getByRole('button', { name: /e2e-첨부\.txt/ });
    await expect(card).toBeVisible();
    await page.keyboard.press('Space');
    await expect(header).toHaveAttribute('aria-expanded', 'false');
    await expect(card).toHaveCount(0);
    await page.keyboard.press('Enter');
    await expect(header).toHaveAttribute('aria-expanded', 'true');
    await expect(card).toBeVisible();

    expect(await tabTo(page, card, 5), '첨부 카드에 Tab 으로 닿는다').toBeGreaterThan(0);
    const downloadEvent = page.waitForEvent('download', { timeout: 10_000 }).catch(() => null);
    await page.keyboard.press('Enter');
    await expect.poll(() => downloads, { message: 'Enter 로 내려받기 요청' }).toBe(1);
    await downloadEvent;
  });

  /**
   * @scenario case=cart_amount_line
   * @effects reachable_by_tab, enter_space_toggle_expanded
   */
  test('장바구니 「상품 금액」 줄 — Tab 으로 닿고 Enter 로 과세 내역을 펴며 Space 로 접는다', async ({ page }) => {
    const FAKE_CART_KEY = 'ck_e2eg22e2eg22e2eg22e2eg22e2eg22ab';
    await guardWrites(page);
    await page.addInitScript((key) => localStorage.setItem('g7_cart_key', key), FAKE_CART_KEY);
    await page.route('**/api/modules/sirsoft-ecommerce/cart/key', (route) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data: { cart_key: FAKE_CART_KEY } }) }),
    );
    const items = [0, 1].map((i) => ({
      id: 9500 + i,
      quantity: 1,
      product_option_id: 9600 + i,
      available: true,
      product: { id: 9700 + i, name: `E2E 상품 ${i + 1}`, sales_status: 'on_sale', thumbnail_url: null },
      product_option: { id: 9600 + i, option_name: '기본', price: 1000, stock_quantity: 10 },
    }));
    const summary = {
      subtotal: 2000,
      subtotal_formatted: '2,000원',
      taxable_amount: 2000,
      tax_free_amount: 0,
      total_discount: 0,
      total_shipping: 0,
      final_amount: 2000,
      final_amount_formatted: '2,000원',
      multi_currency: { KRW: { subtotal_formatted: '2,000원', taxable_amount_formatted: '2,000원', final_amount_formatted: '2,000원' } },
    };
    await page.route('**/api/modules/sirsoft-ecommerce/cart/query**', (route) =>
      route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, data: { items, item_ids: items.map((it) => it.id), item_count: items.length, calculation: { summary } } }),
      }),
    );

    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/shop/cart');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    const line = page.getByRole('button', { name: /^상품 금액/ });
    await expect(line).toBeVisible({ timeout: 30_000 });
    await expect(line).toHaveAttribute('aria-expanded', 'false');
    await setFocusStart(page.getByRole('heading', { level: 1 }).first());
    expect(await tabTo(page, line, 80), '「상품 금액」 줄에 Tab 으로 닿는다').toBeGreaterThan(0);

    await page.keyboard.press('Enter');
    await expect(line).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByText('과세 금액', { exact: true })).toBeVisible();
    await page.keyboard.press('Space');
    await expect(line).toHaveAttribute('aria-expanded', 'false');
    await expect(page.getByText('과세 금액', { exact: true })).toHaveCount(0);
  });

  /**
   * @scenario case=header_currency_escape
   * @effects escape_closes_currency_list, focus_returns_to_trigger, reachable_by_tab
   */
  test('머리글 통화 선택 — 항목에서 Esc 로 닫고 초점이 통화 단추로 돌아오며, 트리거 Esc 도 닫는다', async ({ page }) => {
    await guardWrites(page);
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await waitForSpa(page);
    await dismissCookieBanner(page);

    const trigger = page.locator('[data-testid="currency-switcher"]:visible').first();
    try {
      await trigger.waitFor({ state: 'visible', timeout: 20_000 });
    } catch {
      test.skip(true, '머리글 통화 선택이 보이지 않는다(사용 통화 0개 또는 이커머스 비활성) — 잴 수 없다');
    }
    // 목록은 트리거와 같은 상자(트리거의 부모) 안에 있다 — 같은 조각이 머리글·서랍에 둘 그려지고, 상자 id 에는 슬롯 접미가 붙는다
    const listbox = trigger.locator('xpath=..').getByRole('listbox');

    // 트리거는 Tab 으로 닿는다(머리글 앞쪽)
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    expect(await tabTo(page, trigger, 40), '통화 단추에 Tab 으로 닿는다').toBeGreaterThan(0);
    await page.keyboard.press('Enter');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(listbox).toHaveCount(1);

    // 항목으로 Tab → Esc: 목록이 닫히고 초점은 트리거
    const firstOption = listbox.getByRole('option').first();
    expect(await tabTo(page, firstOption, 5), '통화 항목에 Tab 으로 닿는다').toBeGreaterThan(0);
    await page.keyboard.press('Escape');
    await expect(listbox).toHaveCount(0);
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect.poll(() => trigger.evaluate((el) => el === document.activeElement), { message: '초점이 통화 단추로 돌아온다' }).toBe(true);

    // 트리거에서 다시 열고 곧바로 Esc
    await page.keyboard.press('Enter');
    await expect(listbox).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(listbox).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });
});
