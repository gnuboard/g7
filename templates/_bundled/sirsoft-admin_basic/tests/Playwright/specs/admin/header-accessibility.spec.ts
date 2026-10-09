/**
 * E2E: 관리자 머리글 접근성 회귀 — 7.1.0 종합 단계에서 고친 프론트 동작을 브라우저로 고정한다.
 *
 * - 언어 메뉴(`#language_selector_desktop`)는 이름 있는 listbox 이고 키보드로 다룬다(종합 3 ⑬, O-PF-1).
 *   단추에서 ↓ 로 열면 현재 언어 항목에 초점이 가고, ↓ 로 옮겨 Enter 로 고르면 회원 언어 저장
 *   `PATCH /api/admin/users/me/language` 가 **한 번만** 나간다(종합 3 O-C3-3, R1 ⑫). Esc 는 닫고 단추로 초점을 돌린다.
 * - 테마 단추 이름이 화면 언어를 따른다 — ko 「화면 테마」, en 「Theme」(종합 3b O-C3-1, 종전 영문 고정 「Toggle theme」).
 * - 대표 화면(대시보드, 회원 목록, 환경설정)에 이름 없는 단추와 링크가 0 이다(종합 3c O-C3b-3, 아이콘 꾸밈화 뒤 아이콘만
 *   있는 단추가 이름을 잃지 않는다).
 * - FileUploader 를 `initialFiles` 없이 놓아도 렌더가 멈춘다(종합 3 ⑪, O-PD-2). 번들 레이아웃은 모두 값을 넘기므로
 *   대시보드 레이아웃 응답에 업로더 하나를 덧붙여 재현한다. 수정 전 번들(HEAD 의 dist)을 끼운 같은 조작에서는
 *   업로더 아래 DOM 변이가 5초에 수십만 건이었다.
 *
 * 회원 언어를 바꾸는 describe 는 serial 이고, 끝나면 시작 언어로 되돌린다(실패해도 afterAll 이 API 로 복원).
 * 계정: `G7_E2E_ADMIN_EMAIL` + `G7_E2E_ADMIN_PASSWORD_FILE` 이 있으면 그 계정, 없으면 발급 토큰(fixtures/admin-login.ts).
 *
 * @scenario-manifest tests/scenarios/admin-header-accessibility-e2e.yaml
 */
// @scenario case=language_listbox_keyboard
// @scenario case=language_save_once
// @scenario case=theme_name_locale
// @scenario case=icon_only_controls_named
// @scenario case=uploader_without_initial_files
// @effects listbox_opens_on_current_option, arrow_enter_selects, escape_closes_and_returns_focus, language_patch_once, theme_name_follows_locale, no_unnamed_controls, uploader_render_settles
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';
import { adminToken, setAdminLanguage } from '../../fixtures/admin-login';
import { authenticatePage } from '../../fixtures/admin-template-auth';

const LANG_ENDPOINT = '/api/admin/users/me/language';

/** 머리글이 그려질 때까지 기다린다 — 언어 메뉴 단추가 보이면 부팅과 첫 렌더가 끝난 것이다. */
async function gotoAdmin(page: Page, path: string): Promise<void> {
  await page.goto(path);
  await expect(page.locator('#language_selector_desktop button[aria-haspopup="listbox"]')).toBeVisible({ timeout: 60_000 });
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

test.describe('관리자 머리글 언어 메뉴와 테마 단추', () => {
  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  let token = '';
  let startLanguage = 'ko';

  test.beforeAll(async ({ request }) => {
    token = await adminToken(request, 'core.dashboard.read');
    const me = await request.get('/api/auth/user', { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    if (me.ok()) startLanguage = (await me.json())?.data?.language ?? 'ko';
    if (startLanguage !== 'ko') await setAdminLanguage(request, token, 'ko');
  });

  test.afterAll(async ({ request }) => {
    if (token) await setAdminLanguage(request, token, startLanguage);
  });

  // @scenario case=language_listbox_keyboard
  // @effects listbox_opens_on_current_option, escape_closes_and_returns_focus
  test('언어 메뉴 — ↓ 로 열면 현재 언어에 초점, Esc 로 닫고 단추로 돌아온다', async ({ page }) => {
    await authenticatePage(page, token);
    await gotoAdmin(page, '/admin/dashboard');
    const trigger = page.locator('#language_selector_desktop button[aria-haspopup="listbox"]');

    await trigger.focus();
    await page.keyboard.press('ArrowDown');
    const listbox = page.getByRole('listbox', { name: '언어' });
    await expect(listbox).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-controls', (await listbox.getAttribute('id')) ?? '');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(listbox.getByRole('option')).toHaveCount(3);
    await expect(listbox.getByRole('option', { name: /한국어/ })).toBeFocused();
    await expect(listbox.getByRole('option', { name: /한국어/ })).toHaveAttribute('aria-selected', 'true');

    await page.keyboard.press('Escape');
    await expect(listbox).toBeHidden();
    await expect(trigger).toBeFocused();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  // @scenario case=language_save_once
  // @effects arrow_enter_selects, language_patch_once, theme_name_follows_locale
  test('언어 메뉴 — ↓·Enter 로 고르면 회원 언어 저장 요청이 한 번 나가고 테마 단추 이름이 그 언어를 따른다', async ({ page }) => {
    await authenticatePage(page, token);
    const patches: string[] = [];
    page.on('request', (req) => {
      if (req.method() === 'PATCH' && req.url().includes(LANG_ENDPOINT)) patches.push(req.postData() ?? '');
    });
    await gotoAdmin(page, '/admin/dashboard');

    await expect(page.getByRole('button', { name: '화면 테마', exact: true }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Toggle theme' })).toHaveCount(0);

    const trigger = page.locator('#language_selector_desktop button[aria-haspopup="listbox"]');
    await trigger.focus();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('option', { name: /한국어/ })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('option', { name: /English/ })).toBeFocused();
    const saved = page.waitForResponse((res) => res.request().method() === 'PATCH' && res.url().includes(LANG_ENDPOINT));
    await page.keyboard.press('Enter');
    expect((await saved).status()).toBe(200);

    await expect(page.getByRole('button', { name: 'Theme', exact: true }).first()).toBeVisible({ timeout: 30_000 });
    // 저장이 두 번(컴포넌트 + 엔진) 나가던 회귀(O-C3-3)를 잡는다 — 화면이 다시 그려질 시간을 둔 뒤 센다.
    await page.waitForTimeout(3_000);
    expect(patches).toHaveLength(1);
    expect(JSON.parse(patches[0])).toEqual({ language: 'en' });
    await expect(page.getByRole('button', { name: '화면 테마' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Theme', exact: true }).first()).toHaveAttribute('aria-expanded', 'false');

    // 한국어로 되돌린다 — 같은 경로로 한 번 더(저장 요청 누적 2)
    await trigger.focus();
    await page.keyboard.press('ArrowDown');
    const listbox = page.getByRole('listbox', { name: 'Language' });
    await expect(listbox).toBeVisible();
    await expect(listbox.getByRole('option', { name: /English/ })).toBeFocused();
    await page.keyboard.press('ArrowUp');
    await expect(listbox.getByRole('option', { name: /한국어/ })).toBeFocused();
    const savedBack = page.waitForResponse((res) => res.request().method() === 'PATCH' && res.url().includes(LANG_ENDPOINT));
    await page.keyboard.press('Enter');
    expect((await savedBack).status()).toBe(200);
    await expect(page.getByRole('button', { name: '화면 테마', exact: true }).first()).toBeVisible({ timeout: 30_000 });
    await page.waitForTimeout(3_000);
    expect(patches).toHaveLength(2);
  });
});

test.describe('관리자 대표 화면의 이름 없는 단추·링크', () => {
  test.describe.configure({ timeout: 120_000 });

  for (const path of ['/admin/dashboard', '/admin/users', '/admin/settings']) {
    // @scenario case=icon_only_controls_named
    // @effects no_unnamed_controls
    test(`${path} — 이름 없는 단추·링크 0`, async ({ page, request }) => {
      await authenticatePage(page, await adminToken(request, 'core.dashboard.read', 'core.users.read', 'core.settings.read'));
      await gotoAdmin(page, path);
      await page.waitForTimeout(3_000);
      const { tree, dom } = await unnamedControls(page);
      expect(tree).toEqual([]);
      expect(dom).toEqual([]);
    });
  }
});

test.describe('FileUploader 를 initialFiles 없이 놓은 화면', () => {
  test.describe.configure({ timeout: 120_000 });

  // @scenario case=uploader_without_initial_files
  // @effects uploader_render_settles
  test('업로더가 그려진 뒤 5초 동안 업로더 아래 DOM 변이가 없고 갱신 깊이 오류가 없다', async ({ page, request }) => {
    await authenticatePage(page, await adminToken(request, 'core.dashboard.read'));
    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error' && /Maximum update depth/.test(m.text())) errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    await page.route(/\/api\/layouts\/sirsoft-admin_basic\/admin_dashboard(\?|$)/, async (route) => {
      const res = await route.fetch();
      const json = await res.json();
      json.data.components.push({
        id: 'e2e_uploader_probe',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'e2e-uploader-probe', className: 'p-4' },
        children: [{ id: 'e2e_uploader', type: 'composite', name: 'FileUploader', props: { maxFiles: 3 } }],
      });
      await route.fulfill({ response: res, json });
    });
    await gotoAdmin(page, '/admin/dashboard');
    const probe = page.getByTestId('e2e-uploader-probe');
    await expect(probe.locator('input[type="file"]')).toHaveCount(1, { timeout: 30_000 });
    await page.waitForTimeout(2_000);
    const mutations = await probe.evaluate(async (el) => {
      let n = 0;
      const mo = new MutationObserver((list) => { n += list.length; });
      mo.observe(el, { subtree: true, childList: true, attributes: true, characterData: true });
      await new Promise((r) => setTimeout(r, 5_000));
      mo.disconnect();
      return n;
    });
    expect(mutations).toBeLessThanOrEqual(5);
    expect(errors).toEqual([]);
  });
});
