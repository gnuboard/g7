/**
 * 관리자 이커머스 — 클릭만 받던 영역이 키보드로 닿고 동작한다 (7.1.1).
 * 모듈 sirsoft-ecommerce (관리자 `/admin/ecommerce/products/create` 상품 폼 구획 머리 · 관리자 머리글 통화 선택, 관리자 계정).
 *
 * 결함: 상품 폼 구획 머리(「기본정보」·「상세설명」 등 15개)가 click 만 받는 `div` 라 Tab 으로 닿지 않고 Enter·Space 로
 *   접고 펼 수 없었다. 고침: `<button type="button" aria-expanded>`(액션 무변경). 머리글 통화 선택은 Esc 로 닫히지 않았다 →
 *   목록 항목·트리거에서 Esc 로 닫고 초점을 트리거로 돌린다(모듈 핸들러 `sirsoft-ecommerce.focusElement`).
 *
 * 데이터: 서버에 상품을 만들지 않는다 — GET·HEAD 가 아닌 `/api/` 요청은 모두 가짜 200 으로 답한다. 저장 단추는 누르지 않는다.
 *   계정은 실행 환경변수 `G7_E2E_TOKEN`(관리자 토큰)으로만 받고, 없으면 사유와 함께 건너뛴다(새 계정·역할을 만들지 않는다).
 *
 * @scenario-manifest tests/scenarios/e2e-admin-click-only-keyboard.yaml
 *
 * 실행(관리자 토큰 필요):
 *   $env:PLAYWRIGHT_BASE_URL='https://example.com'; $env:G7_E2E_TOKEN='<일회용 관리자 토큰>'
 *   npx playwright test --config=tests/Playwright/playwright.config.ts tests/Playwright/specs/admin/admin-click-only-keyboard.spec.ts --workers=1 --trace=off
 */
import { test, expect, type Locator, type Page } from '@playwright/test';

test.describe.configure({ timeout: 180_000 });

/** 관리자 토큰 — 실행 환경변수로만 받는다(출력하지 않는다) */
const TOKEN = process.env.G7_E2E_TOKEN ?? '';

/**
 * 토큰을 심고 쓰기 요청을 가로챈다.
 *
 * @param page Playwright 페이지
 * @returns 가로챈 쓰기 요청(`METHOD 경로`)
 */
async function prepare(page: Page): Promise<string[]> {
  await page.addInitScript((t) => {
    localStorage.setItem('auth_token', t);
  }, TOKEN);
  const blocked: string[] = [];
  await page.route(
    (url) => url.pathname.startsWith('/api/'),
    (route) => {
      const req = route.request();
      if (req.method() === 'GET' || req.method() === 'HEAD') return route.fallback();
      blocked.push(`${req.method()} ${new URL(req.url()).pathname}`);

      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, message: 'e2e-intercepted', data: {} }) });
    },
  );
  await page.setViewportSize({ width: 1440, height: 900 });

  return blocked;
}

/**
 * Tab 만 눌러 대상에 초점이 오게 한다.
 *
 * @param page Playwright 페이지
 * @param target 닿아야 할 요소
 * @param max Tab 최대 횟수
 * @returns 누른 Tab 횟수(못 닿으면 -1)
 */
async function tabTo(page: Page, target: Locator, max = 80): Promise<number> {
  for (let i = 1; i <= max; i += 1) {
    await page.keyboard.press('Tab');
    if (await target.evaluate((el) => el === document.activeElement)) return i;
  }

  return -1;
}

test.describe('관리자 이커머스 키보드 조작', () => {
  test.skip(!TOKEN, '관리자 토큰 환경변수 없음 — G7_E2E_TOKEN 을 주고 실행한다');

  test.afterEach(async ({ page }) => {
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  /**
   * @scenario case=product_form_section_headers
   * @effects reachable_by_tab, enter_space_toggle_expanded
   */
  test('상품 등록 「기본정보」·「상세설명」 머리 — Tab 으로 닿고 Enter 로 접고 Space 로 편다', async ({ page }) => {
    const blocked = await prepare(page);
    await page.goto('/admin/ecommerce/products/create');
    await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 60_000 });

    for (const name of ['기본정보', '상세설명']) {
      const header = page.getByRole('button', { name, exact: true });
      await expect(header, `${name} 머리`).toBeVisible({ timeout: 60_000 });
      await expect(header).toHaveAttribute('aria-expanded', 'true');
      // 순차 초점 순서에 들어 있는지 — 머리에 초점을 둔 뒤 Shift+Tab 으로 앞 요소로 갔다가 Tab 한 번으로 머리에 돌아와야 한다
      // (tabIndex=-1 이거나 div 면 앞 요소에서 Tab 이 머리를 건너뛴다). 앞 구획의 칸(편집기 등)을 다 지나지 않게 한다
      await header.focus();
      await page.keyboard.press('Shift+Tab');
      expect(await header.evaluate((el) => el === document.activeElement), 'Shift+Tab 으로 앞 요소로 갔다').toBe(false);
      expect(await tabTo(page, header, 3), `${name} 머리에 Tab 으로 닿는다`).toBeGreaterThan(0);
      await page.keyboard.press('Enter');
      await expect(header).toHaveAttribute('aria-expanded', 'false');
      await page.keyboard.press('Space');
      await expect(header).toHaveAttribute('aria-expanded', 'true');
    }
    expect(blocked.filter((b) => b.includes('/products')), '상품 저장 요청 없음').toEqual([]);
  });

  /**
   * @scenario case=admin_header_currency_escape
   * @effects escape_closes_currency_list, focus_returns_to_trigger
   */
  test('관리자 머리글 통화 선택 — 항목에서 Esc 로 닫고 초점이 통화 단추로 돌아오며, 트리거 Esc 도 닫는다', async ({ page }) => {
    await prepare(page);
    await page.goto('/admin');
    await page.waitForFunction(() => typeof (window as any).G7Core?.dispatch === 'function', null, { timeout: 60_000 });

    // 같은 조각이 머리글·서랍에 둘 그려질 수 있고 상자 id 에는 슬롯 접미가 붙는다 — 보이는 트리거 하나를 고르고 그 부모 상자에서 목록을 찾는다
    const trigger = page.locator('[id^="ext_admin_header_currency_selector"] > button[aria-haspopup="listbox"]:visible').first();
    try {
      await trigger.waitFor({ state: 'visible', timeout: 30_000 });
    } catch {
      test.skip(true, '관리자 머리글 통화 선택이 보이지 않는다(사용 통화 0개) — 잴 수 없다');
    }
    const listbox = trigger.locator('xpath=..').getByRole('listbox');

    await trigger.focus();
    await expect(trigger, '통화 단추에 초점').toBeFocused();
    await page.keyboard.press('Enter');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(listbox).toHaveCount(1);
    // 키보드 계약(사용자 조각과 같다): 항목은 `<button role="option">` 이라 순차 초점 대상이고, 트리거 바로 뒤 DOM 에 그려진다 —
    // 열고 난 뒤 초점이 트리거에 남아 있어야 Tab 한 번에 첫 항목에 닿는다. 어긋나면 어디로 갔는지 남긴다.
    const where = () =>
      page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el) return 'null';

        return `${el.tagName}${el.id ? `#${el.id}` : ''}[role=${el.getAttribute('role') ?? ''}][label=${el.getAttribute('aria-label') ?? ''}]`;
      });
    await expect.poll(() => trigger.evaluate((el) => el === document.activeElement), { message: '목록을 연 뒤에도 초점이 통화 단추에 남는다' }).toBe(true);
    const firstOption = listbox.getByRole('option').first();
    const trail: string[] = [];
    let reached = -1;
    for (let i = 1; i <= 10 && reached < 0; i += 1) {
      await page.keyboard.press('Tab');
      trail.push(await where());
      if (await firstOption.evaluate((el) => el === document.activeElement)) reached = i;
    }
    expect(reached, `통화 항목에 Tab 으로 닿는다 — Tab 경로: ${trail.join(' → ')}`).toBeGreaterThan(0);
    await page.keyboard.press('Escape');
    await expect(listbox).toHaveCount(0);
    await expect.poll(() => trigger.evaluate((el) => el === document.activeElement), { message: '초점이 통화 단추로 돌아온다' }).toBe(true);

    await page.keyboard.press('Enter');
    await expect(listbox).toHaveCount(1);
    await page.keyboard.press('Escape');
    await expect(listbox).toHaveCount(0);
    await expect(trigger).toBeFocused();
  });
});
