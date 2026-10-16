/**
 * 쇼핑몰 설정 「본인인증 정책」 탭 — 이전·다음 쪽 링크 (#757 WP-L W7)
 *
 * 이전·다음 쪽은 단추가 아니라 실제 주소를 가진 링크다 — 가운데 클릭·새 탭으로 그 쪽을 연다.
 * 첫 쪽의 「이전」, 끝 쪽의 「다음」은 갈 곳이 없어 잠긴 링크(`disabled` → href 없음 · `aria-disabled="true"`)다.
 *
 * 브라우저로만 드러나는 축: **잠긴 링크가 잠겨 보이는가.** `<a>` 에는 `:disabled` 가 없어 단추 시절의
 * `disabled:opacity-50` 은 아무것도 하지 않는다 — 잠금 꾸밈은 `aria-disabled:` 변형이어야 한다. 한쪽만 바꿔
 * 둔 채 남으면(2026-10-15 validate-plan 실측: 「다음」만 남음) 마지막 쪽의 「다음」이 눌리는 것처럼 보인다.
 * 레이아웃 시험은 클래스 글자만 보고, 실제로 흐려지는지는 여기서만 보인다.
 *
 * 목록 응답은 가로챈다 — 정책 수와 무관하게 「1/2 쪽」·「2/2 쪽」을 세운다.
 *
 * @scenario page_position=first
 * @effects identity_policy_pager_links_carry_the_page_address, identity_policy_pager_locked_link_looks_locked
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const TAB_URL = '/admin/ecommerce/settings?tab=identity_policies';
const POLICIES_API = '**/api/modules/sirsoft-ecommerce/admin/identity-policies*';

/**
 * 정책 목록 응답을 요청한 쪽 번호로 세웁니다(전 2쪽).
 *
 * @param page Playwright 페이지
 */
async function mockTwoPages(page: Page): Promise<void> {
  await page.route(POLICIES_API, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fallback();

      return;
    }
    const current = Number(new URL(route.request().url()).searchParams.get('page') ?? 1);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: {
          data: [],
          meta: { current_page: current, last_page: 2, per_page: 20, total: 25 },
          abilities: { can_update: true },
        },
      }),
    });
  });
}

/**
 * 쪽 이동 링크 둘을 돌려줍니다. 잠긴 링크는 href 가 없어 링크 역할이 없다 — 태그와 글자로 찾는다.
 *
 * @param page Playwright 페이지
 * @returns 이전·다음 링크
 */
function pagerLinks(page: Page) {
  const pager = page.locator('#policy_pagination');

  return {
    prev: pager.locator('a', { hasText: /이전|Prev|前へ/ }),
    next: pager.locator('a', { hasText: /다음|Next|次へ/ }),
  };
}

/**
 * 탭과 쪽 이동 줄이 그려질 때까지 기다립니다.
 *
 * @param page Playwright 페이지
 * @param pageNo 기대하는 현재 쪽
 */
async function waitForPager(page: Page, pageNo: number): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#tab_content_identity_policies')).toBeVisible({ timeout: 30_000 });
  await expect(page.locator('#policy_pagination')).toContainText(`${pageNo}`, { timeout: 20_000 });
}

/**
 * 요소의 계산된 불투명도.
 *
 * @param locator 대상
 * @returns opacity 값
 */
async function opacityOf(locator: ReturnType<Page['locator']>): Promise<number> {
  return Number(await locator.evaluate((el) => getComputedStyle(el).opacity));
}

test.describe('본인인증 정책 탭 — 쪽 이동 링크', () => {
  /**
   * @scenario page_position=first
   * @effects identity_policy_pager_links_carry_the_page_address, identity_policy_pager_locked_link_looks_locked
   */
  test('첫 쪽: 「이전」은 잠긴 링크로 흐리게 서고 「다음」은 2쪽 주소를 든다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await mockTwoPages(page);
    await page.goto(TAB_URL);
    await waitForPager(page, 1);

    const { prev, next } = pagerLinks(page);
    await expect(prev).toHaveAttribute('aria-disabled', 'true', { timeout: 15_000 });
    await expect(prev, '잠긴 「이전」이 아직 주소를 든다').not.toHaveAttribute('href', /./);
    expect(await opacityOf(prev), '잠긴 「이전」이 흐려지지 않는다 — aria-disabled: 꾸밈이 빠졌다').toBeCloseTo(0.5, 1);

    await expect(next).toHaveAttribute('href', /[?&]tab=identity_policies(&|$)/);
    await expect(next).toHaveAttribute('href', /[?&]page=2(&|$)/);
    await expect(next).not.toHaveAttribute('aria-disabled', 'true');
    expect(await opacityOf(next), '갈 수 있는 「다음」이 흐리다').toBe(1);
  });

  /**
   * @scenario page_position=last
   * @effects identity_policy_pager_links_carry_the_page_address, identity_policy_pager_locked_link_looks_locked
   */
  test('끝 쪽: 「다음」은 잠긴 링크로 흐리게 서고 「이전」은 1쪽 주소를 든다', async ({ page, settingsToken }) => {
    await authenticatePage(page, settingsToken);
    await mockTwoPages(page);
    await page.goto(`${TAB_URL}&page=2`);
    await waitForPager(page, 2);

    const { prev, next } = pagerLinks(page);
    await expect(next).toHaveAttribute('aria-disabled', 'true', { timeout: 15_000 });
    await expect(next, '잠긴 「다음」이 아직 주소를 든다').not.toHaveAttribute('href', /./);
    // 회귀 축(2026-10-15): 「다음」에만 단추 시절 `disabled:` 꾸밈이 남아 끝 쪽에서도 눌리는 것처럼 보였다.
    expect(await opacityOf(next), '잠긴 「다음」이 흐려지지 않는다 — aria-disabled: 꾸밈이 빠졌다').toBeCloseTo(0.5, 1);

    await expect(prev).toHaveAttribute('href', /[?&]page=1(&|$)/);
    await expect(prev).not.toHaveAttribute('aria-disabled', 'true');
    expect(await opacityOf(prev), '갈 수 있는 「이전」이 흐리다').toBe(1);
  });
});
