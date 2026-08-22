/**
 * E2E: 총 건수 상한 표기 계약 + 상품 그리드 페이저 (#519)
 *
 * 총 건수가 상한을 넘으면 서버가 `last_page: null` 을 보낸다. 화면이 그 값을 숫자로
 * 잘못 해석하면 페이저가 통째로 사라지거나 "1 / 1" 로 굳는다 — 응답은 정상이고 콘솔
 * 에러도 없어 API 테스트로는 드러나지 않는다. 브라우저에서만 드러나는 성질이라
 * 여기서 잡는다.
 *
 * @scenario case=bounded_total_and_product_grid_pager
 *
 * @effects search_pager_survives_null_last_page,
 *          search_count_marks_inexact_total,
 *          product_grid_pager_uses_has_more_pages
 */
import type { Page } from '@playwright/test';
import { test, expect } from '../../fixtures/auth';

/**
 * 화면 진입 공통 대기.
 *
 * `networkidle` 은 폴링이 있는 화면에서 idle 이 되지 않으므로 쓰지 않는다.
 *
 * @param page 대상 페이지
 * @param path 이동할 경로
 * @returns void
 */
async function gotoAndSettle(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded');
  await acceptCookieConsent(page);
  await page.waitForTimeout(1200);
}

/**
 * 쿠키 동의 배너를 처리한다.
 *
 * GDPR 사전 차단(preblocker)이 동의 전까지 데이터 요청을 막으므로, 배너를 남겨 둔 채로는
 * "화면이 어떤 API 를 부르는가" 를 관찰할 수 없다. 배너가 없는 사이트에서는 아무 일도 하지 않는다.
 *
 * @param page 대상 페이지
 * @returns void
 */
async function acceptCookieConsent(page: Page): Promise<void> {
  const accept = page.getByRole('button', { name: /모두 동의|Accept all/i }).first();

  if (await accept.isVisible({ timeout: 3_000 }).catch(() => false)) {
    await accept.click();
    await page.waitForTimeout(500);
  }
}

// 쇼핑 목록 라우트는 '/shop' 이 아니라 '/shop/products' 다 (템플릿 routes.json 기준).
const SHOP_LIST_PATH = '/shop/products';

test.describe('총 건수 상한과 페이지 이동', () => {
  // @scenario case=search_response_carries_accuracy_meta
  // @effects search_count_marks_inexact_total
  test('검색 응답이 총 건수 정확도를 함께 싣는다', async ({ page }) => {
    const responsePromise = page.waitForResponse(
      (r) => r.url().includes('/api/search?') && r.status() === 200,
      { timeout: 20_000 },
    );

    await gotoAndSettle(page, '/search?q=' + encodeURIComponent('테스트'));

    const body = await (await responsePromise).json();
    const data = body?.data;

    expect(data, '검색 응답에 data 가 없다').toBeTruthy();
    expect(data).toHaveProperty('total_is_exact');
    expect(data).toHaveProperty('total_relation');

    // 정확도가 true 면 종전 표기 그대로, false 면 "이상" 표기가 붙어야 한다.
    // 표기 문구는 로케일에 따라 다르므로 둘 중 하나가 화면에 있는지로 판정한다.
    // 본문 전체를 대상으로 하므로 앵커(`$`)를 쓰지 않는다 — 뒤에 다른 문구가 따라온다.
    const suffix = data.total_is_exact
      ? /\d+\s*건|\d+\s*results/i
      : /건 이상|\+ results|more than/i;

    await expect(page.locator('body')).toContainText(suffix, { timeout: 15_000 });
  });

  // @scenario case=search_pager_survives_null_last_page
  // @effects search_pager_survives_null_last_page
  test('마지막 페이지를 모르는 목록에서도 페이저가 사라지지 않는다', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));

    // 상한 초과 상태는 실데이터로 재현하기 어려우므로 응답을 가로채 주입한다.
    // last_page: null + has_more_pages: true 가 이 시나리오의 핵심 입력이다.
    await page.route('**/api/search?**', async (route) => {
      const response = await route.fetch();
      const body = await response.json();

      if (body?.data) {
        body.data.last_page = null;
        body.data.has_more_pages = true;
        body.data.total_relation = 'at_least';
        body.data.total_is_exact = false;
      }

      await route.fulfill({ response, json: body });
    });

    await gotoAndSettle(page, '/search?q=' + encodeURIComponent('테스트'));

    expect(errors, '검색 화면 렌더 중 자바스크립트 오류가 발생했다').toEqual([]);

    // "다음" 이동 수단이 실제로 화면에 남아 있어야 한다. 이것이 사라지면 상한 초과
    // 검색에서 2페이지 이후에 도달할 방법이 없어진다.
    const nextControl = page
      .locator('button:has(i.fa-chevron-right), a:has(i.fa-chevron-right)')
      .first();

    await expect(nextControl, '마지막 페이지를 모른다는 이유로 "다음" 이동이 사라졌다')
      .toBeVisible({ timeout: 15_000 });
  });
});

test.describe('상품 그리드 페이저', () => {
  // @scenario case=product_grid_pager_uses_has_more_pages
  // @effects product_grid_pager_uses_has_more_pages
  test('상품 그리드 페이저가 마지막 페이지를 몰라도 접히지 않는다', async ({ page }) => {
    await page.route('**/api/modules/sirsoft-ecommerce/products?**', async (route) => {
      const response = await route.fetch();
      const body = await response.json().catch(() => null);

      if (!body?.data?.pagination) {
        return route.fulfill({ response });
      }

      // 총 건수를 상한까지만 센 응답을 흉내 낸다 — last_page 는 계산할 수 없다
      body.data.pagination.last_page = null;
      body.data.pagination.has_more_pages = true;
      body.data.pagination.total_relation = 'at_least';
      body.data.pagination.total_is_exact = false;

      return route.fulfill({ response, body: JSON.stringify(body) });
    });

    await gotoAndSettle(page, SHOP_LIST_PATH);

    // last_page 가 null 이라고 페이저가 통째로 사라지면 1페이지 밖 상품에 도달할 방법이 없다
    const nextButton = page.locator('button:has(i.fa-chevron-right)').last();
    await expect(nextButton).toBeVisible({ timeout: 15_000 });
    await expect(nextButton).toBeEnabled();
  });
});
