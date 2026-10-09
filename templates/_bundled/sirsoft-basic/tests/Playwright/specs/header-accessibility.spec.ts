/**
 * E2E: 사용자 머리글 접근성 회귀 — 7.1.0 종합 단계에서 고친 프론트 동작을 브라우저로 고정한다(비회원 화면).
 *
 * - 390 폭 머리글의 아이콘 단추 4개(장바구니, 사용자, 메뉴, 메뉴 닫기)가 화면 언어의 이름을 갖고, 메뉴 단추는
 *   aria-expanded 로 드로어 상태를 알린다. 비회원의 사용자 단추는 「로그인」이다(빈 회원 객체를 회원으로 보던 결함).
 *   드로어 언어 칩은 이름 있는 listbox 안의 option 이다(종합 3 ⑬, 고아 option 이던 결함).
 * - 데스크톱 언어 메뉴는 listbox 키보드 계약을 따른다 — ↓ 로 열면 현재 언어 항목에 초점, ↓ 로 옮겨 Enter 로 고르고,
 *   Esc 는 닫고 단추로 초점을 돌린다(종합 3 ⑬).
 * - 테마 단추 이름이 화면 언어를 따른다 — ko 「화면 테마」, en 「Theme」(종합 3b, 종전 「Toggle theme」 고정).
 * - 홈과 게시판 목록에 이름 없는 단추와 링크가 0 이다(종합 3c, 아이콘 꾸밈화 뒤 아이콘만 있는 단추의 이름).
 *
 * 비회원 화면 언어는 `localStorage.g7_locale` 로 정한다(config 의 locale 은 ko-KR 고정).
 *
 * @scenario-manifest tests/scenarios/header-accessibility-e2e.yaml
 */
// @scenario case=mobile_header_names_ko
// @scenario case=mobile_header_names_en
// @scenario case=language_listbox_keyboard
// @scenario case=theme_name_ko
// @scenario case=theme_name_en
// @scenario case=icon_only_controls_named
// @effects mobile_buttons_named, menu_expanded_state, drawer_language_listbox, listbox_opens_on_current_option, arrow_enter_selects, escape_closes_and_returns_focus, theme_name_follows_locale, no_unnamed_controls
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

const NAMES = {
  ko: { cart: '장바구니 (담긴 상품 0개)', user: '로그인', menu: '메뉴', close: '메뉴 닫기', language: '언어', theme: '화면 테마' },
  en: { cart: 'Cart (0 items)', user: 'Login', menu: 'Menu', close: 'Close menu', language: 'Language', theme: 'Theme' },
} as const;

/** 비회원 화면 언어를 정하고 홈을 연다 — SPA 가 떠서 머리글이 그려질 때까지 기다린다. */
async function gotoHome(page: Page, locale: 'ko' | 'en', path = '/'): Promise<void> {
  await page.addInitScript((l) => {
    try {
      if (!sessionStorage.getItem('e2e_locale_set')) {
        localStorage.setItem('g7_locale', l);
        sessionStorage.setItem('e2e_locale_set', '1');
      }
    } catch {
      // 저장소 접근이 막힌 환경 — 기본 언어로 진행
    }
  }, locale);
  await page.goto(path);
  await page.waitForFunction(() => typeof (window as unknown as { G7Core?: unknown }).G7Core === 'object', null, { timeout: 60_000 });
  await expect(page.getByRole('button', { name: NAMES[locale].theme, exact: true }).first()).toBeVisible({ timeout: 60_000 });
}

/**
 * 이름 없는 단추·링크를 접근성 트리와 DOM 두 경로로 센다.
 *
 * @param page 대상 페이지
 * @returns 접근성 트리의 이름 없는 줄, DOM 의 보이는 이름 없는 요소 요약
 */
async function unnamedControls(page: Page): Promise<{ tree: string[]; dom: string[] }> {
  const snapshot = await page.locator('body').ariaSnapshot();
  const tree = snapshot.split('\n').filter((l) => /^\s*- (button|link)(\s*\[[^\]]*\])*\s*:?\s*$/.test(l)).map((l) => l.trim());
  const dom = await page.evaluate(() => {
    const out: string[] = [];
    const visible = (el: Element) => {
      const r = (el as HTMLElement).getBoundingClientRect();
      const s = getComputedStyle(el as HTMLElement);
      return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none' && !el.closest('[aria-hidden="true"]');
    };
    for (const el of Array.from(document.querySelectorAll('button, a[href], [role="button"], [role="link"]'))) {
      if (!visible(el)) continue;
      const labelledby = el.getAttribute('aria-labelledby');
      const named = (el.getAttribute('aria-label') ?? '').trim()
        || (labelledby ? labelledby.split(/\s+/).map((id) => document.getElementById(id)?.textContent ?? '').join('').trim() : '')
        || (el.getAttribute('title') ?? '').trim()
        || (el.textContent ?? '').trim()
        || Array.from(el.querySelectorAll('img[alt]')).map((i) => i.getAttribute('alt')).join('').trim()
        || (el.querySelector('[aria-label]:not([aria-hidden="true"])')?.getAttribute('aria-label') ?? '').trim();
      if (!named) out.push(`${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}.${(el.getAttribute('class') ?? '').split(/\s+/).slice(0, 3).join('.')}`);
    }
    return out;
  });
  return { tree, dom };
}

test.describe('390 폭 머리글 아이콘 단추 이름과 드로어 언어 목록', () => {
  test.describe.configure({ timeout: 120_000 });

  for (const locale of ['ko', 'en'] as const) {
    // @scenario case=mobile_header_names_ko
    // @scenario case=mobile_header_names_en
    // @effects mobile_buttons_named, menu_expanded_state, drawer_language_listbox
    test(`${locale} — 단추 4개 이름, 메뉴 펼침 상태, 드로어 언어 listbox`, async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await gotoHome(page, locale);
      const n = NAMES[locale];

      await expect(page.locator('#mobile_cart_btn')).toHaveAccessibleName(n.cart);
      await expect(page.locator('#mobile_user_btn')).toHaveAccessibleName(n.user);
      const menu = page.locator('#mobile_menu_toggle');
      await expect(menu).toHaveAccessibleName(n.menu);
      await expect(menu).toHaveAttribute('aria-expanded', 'false');

      await menu.click();
      await expect(menu).toHaveAttribute('aria-expanded', 'true');
      const close = page.getByRole('button', { name: n.close, exact: true });
      await expect(close).toBeVisible();

      let listbox = page.getByRole('listbox', { name: n.language });
      if (!(await listbox.isVisible())) {
        await page.locator('#mobile_drawer_language_toggle').click();
        listbox = page.getByRole('listbox', { name: n.language });
      }
      await expect(listbox).toBeVisible();
      await expect(listbox.getByRole('option').first()).toBeVisible();
      await expect(listbox.getByRole('option', { selected: true })).toHaveCount(1);
      // 감싼 listbox 밖에 떨어진 option(고아) 이 없다
      expect(await page.locator('[role="option"]:visible').evaluateAll((els) => els.filter((e) => !e.closest('[role="listbox"]')).length)).toBe(0);

      await close.click();
      await expect(menu).toHaveAttribute('aria-expanded', 'false');

      const { tree, dom } = await unnamedControls(page);
      expect(tree).toEqual([]);
      expect(dom).toEqual([]);
    });
  }
});

test.describe('데스크톱 머리글 언어 메뉴와 테마 단추', () => {
  test.describe.configure({ timeout: 120_000 });

  // @scenario case=language_listbox_keyboard
  // @effects listbox_opens_on_current_option, arrow_enter_selects, escape_closes_and_returns_focus
  test('언어 메뉴 — ↓ 로 열면 현재 언어에 초점, Esc 로 닫힘, ↓·Enter 로 고르면 화면 언어가 바뀐다', async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoHome(page, 'ko');
    const trigger = page.getByRole('button', { name: NAMES.ko.language, exact: true }).first();

    await trigger.focus();
    await page.keyboard.press('ArrowDown');
    const listbox = page.getByRole('listbox', { name: NAMES.ko.language });
    await expect(listbox).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-controls', (await listbox.getAttribute('id')) ?? '');
    await expect(listbox.getByRole('option', { name: /한국어/ })).toBeFocused();
    await expect(listbox.getByRole('option', { name: /한국어/ })).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Escape');
    await expect(listbox).toBeHidden();
    await expect(trigger).toBeFocused();

    await page.keyboard.press('ArrowDown');
    await expect(listbox.getByRole('option', { name: /한국어/ })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(listbox.getByRole('option', { name: /English/ })).toBeFocused();
    await page.keyboard.press('Enter');

    await expect(page.getByRole('button', { name: NAMES.en.theme, exact: true }).first()).toBeVisible({ timeout: 30_000 });
    expect(await page.evaluate(() => localStorage.getItem('g7_locale'))).toBe('en');
    await expect(page.getByRole('button', { name: NAMES.en.language, exact: true }).first()).toBeVisible();
  });

  for (const locale of ['ko', 'en'] as const) {
    // @scenario case=theme_name_ko
    // @scenario case=theme_name_en
    // @effects theme_name_follows_locale
    test(`${locale} — 테마 단추 이름 「${NAMES[locale].theme}」, 영문 고정 이름 없음`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await gotoHome(page, locale);
      const theme = page.getByRole('button', { name: NAMES[locale].theme, exact: true }).first();
      await expect(theme).toHaveAttribute('aria-expanded', 'false');
      await theme.click();
      await expect(theme).toHaveAttribute('aria-expanded', 'true');
      await page.keyboard.press('Escape');
      await expect(page.getByRole('button', { name: 'Toggle theme' })).toHaveCount(0);
    });
  }
});

test.describe('사용자 대표 화면의 이름 없는 단추·링크', () => {
  test.describe.configure({ timeout: 120_000 });

  for (const path of ['/', '/board/free']) {
    // @scenario case=icon_only_controls_named
    // @effects no_unnamed_controls
    test(`${path} — 이름 없는 단추·링크 0`, async ({ page }) => {
      await page.setViewportSize({ width: 1440, height: 900 });
      await gotoHome(page, 'ko', path);
      await page.waitForTimeout(3_000);
      const { tree, dom } = await unnamedControls(page);
      expect(tree).toEqual([]);
      expect(dom).toEqual([]);
    });
  }
});
