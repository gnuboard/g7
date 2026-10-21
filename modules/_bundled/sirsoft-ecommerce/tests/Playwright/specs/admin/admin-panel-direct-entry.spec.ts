/**
 * 관리자 목록-편집 패널 화면 — 항목 주소 직접 진입·새 탭에서 그 항목 패널이 열린다 (#757)
 *
 * 브랜드·카테고리·상품 공통정보·상품정보고시는 왼쪽 목록 항목 이름이 링크다(`?id=<id>&mode=view`). 가운데 클릭으로 연
 * 새 탭과 주소 직접 진입은 화면이 마운트 때 쿼리로 항목을 골라야 오른쪽 패널이 열린다. 공통정보·고시는 진입 처리기가
 * 목록을 데이터소스 응답 껍데기에서 찾아 포기해 빈 패널(「공통정보를 선택하세요」)이 섰다(M1 실측 2026-10-15).
 *
 * 브라우저로만 드러나는 축: 마운트 때 진입 처리기가 실제 데이터소스(응답 본문)를 읽고 전역 선택을 세워 보기 패널이
 * 그려지는가. 처리기 단위 시험은 데이터소스 모양을 흉내 낼 뿐이다.
 *
 * 목록 응답은 가로챈다 — 계정의 실제 데이터와 무관하게 고를 항목(id 3)이 있는 목록을 세운다.
 *
 * @scenario surface=admin_layout
 * @effects panel_item_links_open_item_on_direct_entry
 */
import type { Page } from '@playwright/test';
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const SCREENS = [
  {
    name: '상품 공통정보',
    url: '/admin/ecommerce/common-info',
    api: '**/api/modules/sirsoft-ecommerce/admin/product-common-infos?**',
    item: { id: 3, name: { ko: 'E2E 직접진입 공통정보' }, is_default: false, is_active: true, content: { ko: '<p>본문</p>' } },
  },
  {
    name: '상품정보고시',
    url: '/admin/ecommerce/product-notices',
    api: '**/api/modules/sirsoft-ecommerce/admin/product-notice-templates?**',
    item: { id: 3, name: { ko: 'E2E 직접진입 상품군' }, is_active: true, fields: [], fields_count: 0 },
  },
] as const;

/**
 * 목록 응답을 고를 항목이 든 목록으로 세웁니다(목록 API 의 GET 만 — 다른 요청은 그대로 보낸다).
 *
 * @param page Playwright 페이지
 * @param api 목록 API 패턴
 * @param item 고를 항목
 */
async function mockList(page: Page, api: string, item: Record<string, unknown>): Promise<void> {
  await page.route(api, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.fallback();

      return;
    }
    const abilities = { can_create: true, can_update: true, can_delete: true };
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        success: true,
        message: null,
        data: {
          data: [{ id: 1, name: { ko: '다른 항목' }, is_active: true, abilities }, { ...item, abilities }],
          meta: { current_page: 1, last_page: 1, per_page: 20, total: 2 },
          abilities,
        },
      }),
    });
  });
}

for (const screen of SCREENS) {
  test.describe(`${screen.name} — 항목 주소 직접 진입`, () => {
    /**
     * @scenario surface=admin_layout
     * @effects panel_item_links_open_item_on_direct_entry
     */
    test(`${screen.name} ?id=3&mode=view 로 바로 열면 그 항목의 보기 패널이 열린다`, async ({ page, productManageToken }) => {
      await authenticatePage(page, productManageToken);
      await mockList(page, screen.api, screen.item);
      await page.goto(`${screen.url}?id=3&mode=view`);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      // 진입 처리기는 목록 도착을 최대 3초 기다린다 — 그 안에 보기 패널이 서고 빈 안내는 없어야 한다
      await expect(page.locator('#view_panel')).toBeVisible({ timeout: 15_000 });
      await expect(page.locator('#view_panel h2').first()).toHaveText(screen.item.name.ko);
      await expect(page.locator('#empty_state_panel')).toHaveCount(0);
    });

    /**
     * @scenario surface=admin_layout
     * @effects panel_item_links_open_item_on_direct_entry
     */
    test(`${screen.name} 목록의 항목 이름 링크는 그 주소를 가리킨다(새 탭이 같은 곳)`, async ({ page, productManageToken }) => {
      await authenticatePage(page, productManageToken);
      await mockList(page, screen.api, screen.item);
      await page.goto(screen.url);
      await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

      const link = page.getByRole('link', { name: screen.item.name.ko, exact: true });
      await expect(link).toBeVisible({ timeout: 15_000 });
      await expect(link).toHaveAttribute('href', `${screen.url}?id=3&mode=view`);
    });
  });
}
