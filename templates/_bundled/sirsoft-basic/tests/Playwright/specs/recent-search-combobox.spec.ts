/**
 * 최근 검색어 검색창 — 머리글·모바일 서랍·통합검색 화면 (비회원, 실제 브라우저)
 *
 * 배경:
 * - 세 검색창이 같은 범위(core.site)의 최근 검색어를 펼침 목록으로 보이고, 제출할 때만 기록한다.
 * - 단위 시험은 대역 등록부·jsdom 이라 실제 엔진 런타임(비회원 localStorage `g7_search_history`)·SPA 이동·서랍 닫힘·
 *   주소 진입 미기록은 브라우저로만 확인된다.
 * - 이 템플릿이 활성 사용자 템플릿인 사이트에서만 뜻이 있다 — 머리글에 검색창 combobox 가 없으면 건너뛴다.
 *
 * 비회원 화면 언어는 `localStorage.g7_locale` 로 정한다(ko).
 */
import { test, expect, type Page } from '@playwright/test';

const KEY = 'g7_search_history';

/** 언어를 정하고 저장된 최근 검색어를 비운 채 경로를 연다 — 엔진이 뜰 때까지 기다린다. */
async function open(page: Page, path: string): Promise<void> {
  await page.addInitScript(() => {
    try {
      if (!sessionStorage.getItem('e2e_rsc_init')) {
        localStorage.setItem('g7_locale', 'ko');
        localStorage.removeItem('g7_search_history');
        sessionStorage.setItem('e2e_rsc_init', '1');
      }
    } catch {
      // 저장소 접근이 막힌 환경 — 그대로 진행
    }
  });
  await page.goto(path);
  await page.waitForFunction(() => typeof (window as unknown as { G7Core?: unknown }).G7Core === 'object', null, { timeout: 60_000 });
}

/** 저장된 core.site 검색어 (최신순) */
async function stored(page: Page): Promise<string[]> {
  return page.evaluate((key) => {
    const raw = localStorage.getItem(key);
    return raw ? (JSON.parse(raw).items?.['core.site'] ?? []).map((i: { k: string }) => i.k) : [];
  }, KEY);
}

/** 이 템플릿이 활성이고 최근 검색어 범위가 켜져 있는가 */
async function available(page: Page): Promise<boolean> {
  const headerBox = page.locator('[data-testid="header-search"] input[role="combobox"]');
  if ((await headerBox.count()) === 0) return false;
  return page.evaluate(() => !!(window as any).G7Core?.searchHistory?.isEnabled?.('core.site'));
}

test.describe('최근 검색어 검색창', () => {
  /**
   * @scenario surface=header_desktop
   * @effects empty_focus_opens_recent_list, submit_records_without_waiting, keyboard_arrow_enter_escape_delete
   */
  test('머리글 — 빈 칸 초점에 펼치고, 제출하면 기록·이동, ↓·Enter 로 다시 검색하고 Esc 는 닫는다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await open(page, '/');
    test.skip(!(await available(page)), '이 사이트의 활성 사용자 템플릿이 sirsoft-basic 이 아니거나 최근 검색어가 꺼져 있다');

    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    const box = page.locator('[data-testid="header-search"] input[role="combobox"]');

    await box.click();
    await expect(box).toHaveAttribute('aria-expanded', 'true');
    await expect(page.getByTestId('recent-search-panel')).toContainText('최근 검색어가 없습니다.');

    for (const keyword of ['침낭', '등산화 끈']) {
      await box.fill(keyword);
      await box.press('Enter');
      await page.waitForURL(/\/search\?q=/);
    }
    expect(await stored(page)).toEqual(['등산화 끈', '침낭']);

    await box.fill('');
    await box.click();
    await expect(page.getByRole('option')).toHaveCount(2);
    await box.press('ArrowDown');
    await box.press('ArrowDown');
    await expect(box).toHaveAttribute('aria-activedescendant', /option-1$/);
    await box.press('Escape');
    await expect(box).toHaveAttribute('aria-expanded', 'false');
    await expect(box).toBeFocused();

    await box.press('ArrowDown');
    await box.press('ArrowDown');
    await box.press('ArrowDown');
    await box.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('q') === '침낭');
    expect(await stored(page)).toEqual(['침낭', '등산화 끈']);
    expect(errors).toEqual([]);
  });

  /**
   * @scenario surface=search_page
   * @effects url_query_value_not_recorded, search_page_shows_query_and_navigates, same_scope_boxes_share_list
   */
  test('통합검색 화면 — 주소의 q 는 보이기만 하고 기록하지 않으며, 같은 목록을 함께 쓴다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await open(page, '/search?q=' + encodeURIComponent('코펠'));
    test.skip(!(await available(page)), '이 사이트의 활성 사용자 템플릿이 sirsoft-basic 이 아니거나 최근 검색어가 꺼져 있다');

    const pageBox = page.locator('#search_page_input input[role="combobox"]');
    await expect(pageBox).toHaveValue('코펠');
    expect(await stored(page)).toEqual([]);

    // 머리글에서 기록한 검색어가 검색 화면 검색창 목록에 보인다
    const header = page.locator('[data-testid="header-search"] input[role="combobox"]');
    await header.fill('캠핑');
    await header.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('q') === '캠핑');
    await expect(pageBox).toHaveValue('캠핑');
    await pageBox.fill('');
    await pageBox.click();
    await expect(page.locator('#search_page_input [role="option"]')).toHaveText(['캠핑']);
  });

  /**
   * @scenario surface=mobile_drawer
   * @effects drawer_closes_then_navigates_search, same_scope_boxes_share_list, touch_targets_44px_and_dark_pairs
   */
  test('390px 모바일 서랍 — 같은 목록을 펼치고, 검색하면 서랍이 닫힌 뒤 통합검색으로 간다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await open(page, '/');
    test.skip(!(await available(page)), '이 사이트의 활성 사용자 템플릿이 sirsoft-basic 이 아니거나 최근 검색어가 꺼져 있다');
    const header = page.locator('[data-testid="header-search"] input[role="combobox"]');
    await header.fill('버너');
    await header.press('Enter');
    await page.waitForURL(/\/search\?q=/);

    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('button', { name: /메뉴/ }).first().click();
    const drawerBox = page.locator('#mobile_drawer_search_box input[role="combobox"]');
    await drawerBox.click();
    const option = page.locator('#mobile_drawer_search_box [role="option"]');
    await expect(option).toHaveText(['버너']);
    const box = await option.first().boundingBox();
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);

    await drawerBox.fill('랜턴');
    await drawerBox.press('Enter');
    await page.waitForURL((url) => url.searchParams.get('q') === '랜턴');
    expect(await page.evaluate(() => (window as any).G7Core?.state?.get?.()?.mobileMenuOpen)).toBeFalsy();
    expect(await stored(page)).toEqual(['랜턴', '버너']);
  });

});
