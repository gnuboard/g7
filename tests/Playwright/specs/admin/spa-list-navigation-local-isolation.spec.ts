/**
 * E2E: SPA 목록 → 목록 이동 시 `_local` 격리 (트러블슈팅 state-closure 사례 4, engine-v1.72.1)
 *
 * 관리자 base 의 `admin_layout_root` 는 `_fromBase` 로 페이지 전환에도 보존된다. 그 인스턴스의
 * React 내부 상태(저장소 A)에는 이전 페이지의 `_local` 전체가 남는데, 초기화가 새 페이지의
 * 첫 렌더 뒤(useEffect)에 일어나면 첫 렌더가 stale A 를 새 `_local` 위에 덮은 컨텍스트를
 * 자식에게 주고, ColumnSelector·FilterVisibilitySelector 의 마운트 동기화 콜백이 그 값을
 * `setState` 로 되써 전역 `_local` 에 영구 고정된다 — 쿠폰 목록의 `visibleColumns` 가 주문
 * 컬럼으로 남아 표에 체크박스·작업 열만 남는다.
 *
 * 단위 테스트(DynamicRenderer.fromBaseLayoutKeyReset.test.tsx)는 렌더러 한 인스턴스의 초기화
 * 시점을 잠그고, 이 spec 은 실제 base 레이아웃·실제 컴포넌트·실제 ActionDispatcher 경로를 거쳐
 * 화면과 전역 상태까지 격리가 도달하는지를 본다. 브라우저에 목록 컬럼 저장값(`g7_columns_*`)이
 * 있으면 증상이 가려지므로, 저장값이 없는 상태를 매 테스트 전에 강제한다.
 *
 * `page.goto` 로 두 화면을 따로 열면 전역 상태가 통째로 비워져 결함이 나타나지 않는다. 결함이
 * 관측된 경로는 앱이 떠 있는 채로 레이아웃만 바뀌는 SPA 이동이므로, 메뉴 클릭과 같은 경로인
 * 라우터 navigate 를 쓴다.
 *
 * // @scenario transition=layout_switch, origin=order_list
 * // @effects previous_page_keys_absent_in_global_local, destination_visible_columns_kept, datagrid_header_not_collapsed, no_page_errors
 */
import { test, expect, issueToken, authenticatePage, type Page } from '../../fixtures/auth';

const PERMISSIONS = [
  'sirsoft-ecommerce.orders.read',
  'sirsoft-ecommerce.promotion-coupon.read',
  'sirsoft-ecommerce.products.read',
];

/** 주문 목록 레이아웃(state)에만 있는 `_local` 키 — 다른 목록에 남아 있으면 유출이다. */
const ORDER_LIST_ONLY_KEYS = ['ordererSearchResults', 'bulkCourier', 'bulkTrackingNumber', 'excelDownloadType'];

/** 쿠폰 목록 레이아웃(state)에만 있는 `_local` 키. */
const COUPON_LIST_ONLY_KEYS = ['creatorSearchResults'];

/**
 * 전역 `_local` 의 키 목록과 표시 컬럼을 읽습니다.
 *
 * @param page Playwright 페이지
 * @return 키 목록과 visibleColumns
 */
async function readLocal(page: Page): Promise<{ keys: string[]; visibleColumns: string[] }> {
  return page.evaluate(() => {
    const local = (window as any).G7Core?.state?.get?.()?._local ?? {};
    return { keys: Object.keys(local), visibleColumns: Array.isArray(local.visibleColumns) ? local.visibleColumns : [] };
  });
}

/**
 * 메뉴 클릭과 같은 경로(라우터 navigate)로 SPA 이동합니다.
 *
 * @param page Playwright 페이지
 * @param path 이동할 경로
 * @return void
 */
async function spaNavigate(page: Page, path: string): Promise<void> {
  await page.evaluate((target) => {
    (window as any).__templateApp.router.navigate(target);
  }, path);
  await page.waitForURL(`**${path}**`, { timeout: 30_000 });
}

/**
 * 목록 표의 헤더 셀 수를 읽습니다 — 체크박스·작업 열만 남으면 2 다.
 *
 * @param page Playwright 페이지
 * @return 헤더 셀 수
 */
async function headerCellCount(page: Page): Promise<number> {
  return page.locator('table thead th').count();
}

test.describe('SPA 목록 → 목록 이동 시 _local 격리', () => {
  let pageErrors: string[];

  test.beforeEach(async ({ page }) => {
    const token = issueToken(...PERMISSIONS);
    await authenticatePage(page, token);
    // 저장값이 없는 브라우저 조건 — 있으면 ColumnSelector 가 저장값을 써서 증상이 가려진다
    await page.addInitScript(() => {
      try {
        Object.keys(localStorage)
          .filter((key) => /^g7_(columns|filters)_/.test(key))
          .forEach((key) => localStorage.removeItem(key));
      } catch {
        // storage 접근 불가 환경은 무시
      }
    });
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
  });

  /**
   * @scenario transition=layout_switch, origin=order_list
   * @effects previous_page_keys_absent_in_global_local, destination_visible_columns_kept, datagrid_header_not_collapsed, no_page_errors
   */
  test('주문 목록 → 쿠폰 목록: 주문 목록 키가 남지 않고 쿠폰 컬럼이 그려진다', async ({ page }) => {
    await page.goto('/admin/ecommerce/orders');
    await expect(page.locator('#admin_order_list_columns')).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await readLocal(page)).visibleColumns.includes('order_number'), { timeout: 20_000 }).toBe(true);

    await spaNavigate(page, '/admin/ecommerce/promotion-coupons');
    await expect(page.locator('#admin_coupon_list_columns')).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await readLocal(page)).visibleColumns.includes('name'), { timeout: 20_000 }).toBe(true);

    const local = await readLocal(page);
    for (const key of ORDER_LIST_ONLY_KEYS) {
      expect(local.keys, `주문 목록 키 ${key} 가 쿠폰 목록 _local 에 남았다`).not.toContain(key);
    }
    expect(local.visibleColumns).not.toContain('order_number');
    expect(await headerCellCount(page)).toBeGreaterThan(2);
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario transition=layout_switch, origin=coupon_list
   * @effects previous_page_keys_absent_in_global_local, destination_visible_columns_kept, datagrid_header_not_collapsed, no_page_errors
   */
  test('쿠폰 목록 → 주문 목록 (역방향): 쿠폰 목록 키가 남지 않고 주문 컬럼이 그려진다', async ({ page }) => {
    await page.goto('/admin/ecommerce/promotion-coupons');
    await expect(page.locator('#admin_coupon_list_columns')).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await readLocal(page)).visibleColumns.includes('name'), { timeout: 20_000 }).toBe(true);

    await spaNavigate(page, '/admin/ecommerce/orders');
    await expect(page.locator('#admin_order_list_columns')).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await readLocal(page)).visibleColumns.includes('order_number'), { timeout: 20_000 }).toBe(true);

    const local = await readLocal(page);
    for (const key of COUPON_LIST_ONLY_KEYS) {
      expect(local.keys, `쿠폰 목록 키 ${key} 가 주문 목록 _local 에 남았다`).not.toContain(key);
    }
    expect(local.visibleColumns).not.toContain('target_type_label');
    expect(await headerCellCount(page)).toBeGreaterThan(2);
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario transition=layout_switch, origin=product_list
   * @effects previous_page_keys_absent_in_global_local, destination_visible_columns_kept, datagrid_header_not_collapsed, no_page_errors
   */
  test('상품 목록 → 쿠폰 목록: 세 번째 목록으로 이어 가도 격리된다', async ({ page }) => {
    await page.goto('/admin/ecommerce/orders');
    await expect(page.locator('#admin_order_list_columns')).toBeVisible({ timeout: 30_000 });

    await spaNavigate(page, '/admin/ecommerce/products');
    await expect(page.locator('table thead th').first()).toBeVisible({ timeout: 30_000 });

    await spaNavigate(page, '/admin/ecommerce/promotion-coupons');
    await expect(page.locator('#admin_coupon_list_columns')).toBeVisible({ timeout: 30_000 });
    await expect.poll(async () => (await readLocal(page)).visibleColumns.includes('name'), { timeout: 20_000 }).toBe(true);

    const local = await readLocal(page);
    for (const key of ORDER_LIST_ONLY_KEYS) {
      expect(local.keys).not.toContain(key);
    }
    expect(local.visibleColumns).not.toContain('order_number');
    expect(local.visibleColumns).not.toContain('thumbnail');
    expect(await headerCellCount(page)).toBeGreaterThan(2);
    expect(pageErrors).toEqual([]);
  });
});
