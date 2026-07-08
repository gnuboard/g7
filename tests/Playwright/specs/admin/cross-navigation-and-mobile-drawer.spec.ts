/**
 * E2E: 관리자 ↔ 유저 화면 상호 이동 링크 + 모바일 사이드바 드로어 상단 잘림 수정 (#450)
 *
 * @scenario admin_sidebar_view_site, admin_board_view_user_page, mobile_drawer_not_clipped
 * @effects new_tab_navigation, drawer_starts_below_header
 *
 * 검증:
 *  1. 관리자 사이드바 하단 "사이트 보기" 링크가 A[href="/"][target="_blank"] 로 마운트
 *  2. 관리자 게시물 조회 화면에 "유저 화면 보기" 링크가 새 탭으로 마운트
 *  3. 모바일 뷰포트에서 드로어를 열면 드로어 상단이 헤더(약 64px) 아래에서 시작 —
 *     첫 메뉴 항목이 헤더에 가려 잘리지 않는다 (버그 회귀 가드)
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

/** admin 진입 공통 대기 */
async function gotoAdmin(page: import('@playwright/test').Page, path: string): Promise<void> {
  await page.goto(path);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForFunction(
    () => !window.location.pathname.includes('/admin/login') || document.readyState === 'complete',
    { timeout: 15_000 },
  );
}

// @scenario link=admin_sidebar_to_user_site, permitted=na
// @effects new_tab_navigation
test('@smoke #450 - 관리자 사이드바 "사이트 보기" 링크가 새 탭 A 로 마운트된다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  await gotoAdmin(page, '/admin/dashboard');
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const link = page.locator('#sidebar_view_site_link');
  await expect(link).toBeAttached({ timeout: 10_000 });
  await expect(link).toHaveAttribute('href', '/');
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

  // 사이드바 footer 는 스크롤 메뉴 밖의 형제이므로, 메뉴 영역과 별도로 마운트된다.
  await expect(page.locator('#sidebar_footer')).toBeAttached();
});

// @scenario link=admin_board_to_user_board, permitted=na
// @effects new_tab_navigation, board_admin_routes_by_slug
test('@smoke #450 - 관리자 게시물 조회 화면에 "유저 화면 보기" 새 탭 링크가 마운트된다', async ({ page }) => {
  // 게시판 관리 권한으로 관리자 게시물 목록 진입
  const token = issueToken('sirsoft-board.notice.admin.posts.read', 'sirsoft-board.notice.admin.manage');
  await authenticatePage(page, token);

  await gotoAdmin(page, '/admin/board/notice');
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const link = page.locator('#view_user_page_link').first();
  // 게시판이 없거나 접근 불가 환경에서는 스킵 (권한/데이터 의존)
  if ((await link.count()) === 0) {
    test.skip(true, '게시판(notice) 미존재 또는 접근 불가 — 데이터 의존 스킵');
  }
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('href', /\/board\/notice/);
});

// @scenario link=admin_product_to_user_detail, permitted=na
// @effects new_tab_navigation, product_detail_routes_by_code
test('@smoke #450 - 관리자 상품 수정 화면에 "유저 화면 보기" 새 탭 링크가 마운트된다', async ({ page }) => {
  const token = issueToken('sirsoft-ecommerce.product.read', 'sirsoft-ecommerce.product.update');
  await authenticatePage(page, token);

  // 임의 상품 편집 경로 진입 (상품 미존재 시 링크 미노출 → 데이터 의존 스킵)
  await gotoAdmin(page, '/admin/ecommerce/products/1/edit');
  expect(page.url()).not.toMatch(/\/admin\/login/);

  const link = page.locator('#view_user_page_link').first();
  if ((await link.count()) === 0) {
    test.skip(true, '상품(id=1) 미존재 또는 접근 불가 — 데이터 의존 스킵');
  }
  await expect(link).toHaveAttribute('target', '_blank');
  await expect(link).toHaveAttribute('href', /\/products\/1$/);
});

// @scenario link=admin_sidebar_to_user_site, permitted=na
// @effects drawer_starts_below_header
test('@smoke #450 - 모바일 드로어가 헤더 아래에서 시작해 첫 메뉴가 잘리지 않는다', async ({ page }) => {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);

  // 모바일 뷰포트 (portable breakpoint 진입)
  await page.setViewportSize({ width: 390, height: 780 });
  await gotoAdmin(page, '/admin/dashboard');
  expect(page.url()).not.toMatch(/\/admin\/login/);

  // 햄버거로 드로어 열기 (모바일 헤더의 토글). 토글 id 는 menu_toggle_btn 계열.
  const toggle = page.locator('#menu_toggle_btn, [data-testid="menu_toggle_btn"], .menu_toggle_btn').first();
  if ((await toggle.count()) > 0) {
    await toggle.click();
  }

  const drawer = page.locator('#left_sidebar_area');
  await expect(drawer).toBeAttached({ timeout: 10_000 });

  // 드로어의 상단 y 좌표가 헤더 높이(약 64px = h-16) 이상이어야 한다.
  // inset-y-0(top:0) 회귀 시 이 값이 0 이 되어 첫 메뉴가 헤더에 가려진다.
  const box = await drawer.boundingBox();
  if (box) {
    expect(box.y, '드로어가 top:0 으로 헤더를 덮음 (#450 회귀)').toBeGreaterThanOrEqual(48);
  }

  // 첫 메뉴 항목의 top 이 헤더 하단(약 56~64px)보다 아래에 있어야 한다.
  const firstItem = page.locator('#admin_sidebar_menu .admin-sidebar-item').first();
  if ((await firstItem.count()) > 0) {
    const itemBox = await firstItem.boundingBox();
    if (itemBox) {
      expect(itemBox.y, '첫 메뉴 항목이 헤더에 가려 잘림 (#450 회귀)').toBeGreaterThanOrEqual(48);
    }
  }
});
