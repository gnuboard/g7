/**
 * E2E: 같은 레이아웃 경로 전환·쿼리만 바뀌는 탭 전환·언어 전환에서 화면 상태 (engine-v1.79.0)
 *
 * 1. 같은 레이아웃의 다른 경로 — 역할 「수정」(/admin/roles/{id}/edit) 에서 이름을 고친 뒤 「등록」
 *    (/admin/roles/create) 으로 SPA 이동하면, 상태를 소유한 보존 루트의 내부 저장소(A)에 남은 이전 폼
 *    (id·영어 이름)이 새 화면에 섞였다. 경로가 바뀌면 그 저장소를 렌더 단계에서 비운다.
 * 2. 쿼리만 바뀌는 탭 전환 — 환경설정에서 값을 고친 뒤 다른 탭으로 가면 설정 본체 재조회의 initLocal 이
 *    고친 값을 저장된 값으로 되돌린다. 이 복귀는 의도된 동작이다(2026-10-08 결정 — 저장은 지금 탭의 구획만
 *    보낸다). 말없이 사라지지 않게 환경설정 레이아웃이 고친 것이 있으면 탭을 옮기기 전에 확인 창을 띄운다.
 * 3. 언어 전환 — 작성 화면에서 입력한 뒤 언어를 바꾸면 재진입이 init·initLocal 을 다시 돌려 입력이 사라졌다.
 *    언어 전환 재진입은 전환 직전 `_local` 로 그린다.
 *
 * 단위 테스트(DynamicRenderer.sameLayoutRouteReset·TemplateApp.updateQueryParams.dirtyForm·TemplateApp.localeSwitch,
 * 레이아웃 admin-settings-unsaved-tab-confirm)는
 * 엔진 한 경로를 잠그고, 이 spec 은 실제 레이아웃·컴포넌트·자동 바인딩·라우터를 거쳐 화면과 전역 상태까지 본다.
 * 메뉴 클릭과 같은 경로인 라우터 navigate 로 이동한다(`page.goto` 는 전역 상태를 통째로 비워 결함이 숨는다).
 *
 */
import { test, expect, issueToken, authenticatePage, type Page } from '../../fixtures/auth';

const PERMISSIONS = ['core.permissions.read', 'core.permissions.create', 'core.permissions.update', 'core.permissions.delete', 'core.settings.read', 'core.settings.update'];

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
 * 전역 `_local` 의 한 경로 값을 읽습니다.
 *
 * @param page Playwright 페이지
 * @param path 점 경로 (예: form.name_raw)
 * @return 값
 */
async function readLocal(page: Page, path: string): Promise<unknown> {
  return page.evaluate((p) => p.split('.').reduce((acc: any, key) => acc?.[key], (window as any).G7Core?.state?.get?.()?._local ?? {}), path);
}

test.describe('같은 레이아웃 경로 전환·탭 전환·언어 전환의 화면 상태', () => {
  // 개발 서버에서 설정·역할 데이터 소스가 blocking 이라 첫 진입이 느리다 — 화면 단위 대기는 각 단언이 맡는다
  test.setTimeout(150_000);
  let pageErrors: string[];
  let token: string;

  test.beforeEach(async ({ page }) => {
    token = issueToken(...PERMISSIONS);
    await authenticatePage(page, token);
    await page.addInitScript(() => {
      try { localStorage.setItem('g7_locale', 'ko'); } catch { /* storage 접근 불가 환경은 무시 */ }
    });
    pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(error.message));
  });

  /**
   * @scenario case=role_form_browser
   * @effects previous_record_leaf_absent, typing_does_not_resurrect_previous_id, no_page_errors
   */
  test('역할 수정에서 이름을 고친 뒤 등록으로 가면 이전 역할의 영어 이름·id 가 섞이지 않는다', async ({ page }) => {
    // 시스템 역할은 수정 권한이 막혀 있어 이 테스트가 쓸 역할을 만들고 끝에 지운다
    const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };
    const created = await page.request.post('/api/admin/roles', { headers, data: { identifier: `e2e_route_${Date.now()}`, name: { ko: 'E2E 경로 전환', en: 'E2E Route Switch' }, description: { ko: '' }, is_active: true, permissions: [] } });
    expect(created.ok()).toBe(true);
    const roleId = (await created.json()).data.id as number;
    try {
    await page.goto(`/admin/roles/${roleId}/edit`);
    const koName = page.locator('input[name="name_raw[ko]"]').first();
    await expect(koName).toHaveValue(/.+/, { timeout: 90_000 });
    await koName.click();
    await page.keyboard.press('End');
    await page.keyboard.type('2y');
    await expect.poll(() => readLocal(page, 'hasChanges'), { timeout: 10_000 }).toBe(true);

    await spaNavigate(page, '/admin/roles/create');
    await expect(page.locator('input[name="identifier"]')).toBeEnabled({ timeout: 30_000 });
    await expect(page.locator('input[name="name_raw[en]"]')).toHaveValue('', { timeout: 10_000 });

    await page.locator('input[name="name_raw[ko]"]').first().fill('E2E 새 역할');
    await expect.poll(() => readLocal(page, 'form.name_raw'), { timeout: 10_000 }).toEqual({ ko: 'E2E 새 역할' });
    expect(await readLocal(page, 'form.id')).toBeUndefined();
    expect(pageErrors).toEqual([]);
    } finally {
      await page.request.delete(`/api/admin/roles/${roleId}`, { headers });
    }
  });

  /**
   * @scenario case=browser_stay_then_leave
   * @effects unsaved_tab_confirm_opens, unsaved_tab_confirm_stay_keeps_edits, unsaved_tab_confirm_leave_switches_tab
   */
  test('환경설정에서 값을 고친 채 다른 탭을 누르면 확인 창이 뜨고, 머무르면 고친 값이 남고 옮기면 저장된 값으로 돌아간다', async ({ page }) => {
    await page.goto('/admin/settings?tab=general');
    const siteName = page.locator('input[name="general.site_name"]');
    await expect(siteName).toBeVisible({ timeout: 30_000 });
    // 설정 본체가 도착해 칸이 채워진 뒤에 고친다(도착 전 입력은 이 spec 의 대상이 아니다)
    await expect(siteName).toHaveValue(/.+/, { timeout: 60_000 });
    await page.waitForLoadState('networkidle');
    const saved = await siteName.inputValue();
    const edited = `${saved} E2E`;
    await siteName.fill(edited);
    await expect.poll(() => readLocal(page, 'hasChanges'), { timeout: 10_000 }).toBe(true);

    const securityTab = page.locator('[role="tab"], button').filter({ hasText: /^\s*보안\s*$/ }).first();
    const stay = page.getByTestId('settings-unsaved-tab-stay');
    const leave = page.getByTestId('settings-unsaved-tab-leave');

    // 고친 채 다른 탭 → 옮기지 않고 확인 창
    await securityTab.click();
    await expect(stay).toBeVisible({ timeout: 10_000 });
    expect(page.url()).toContain('tab=general');

    // 머무르기 → 창만 닫히고 고친 값과 「변경 있음」은 그대로
    await stay.click();
    await expect(stay).toBeHidden({ timeout: 10_000 });
    expect(page.url()).toContain('tab=general');
    await expect(siteName).toHaveValue(edited);
    expect(await readLocal(page, 'hasChanges')).toBe(true);

    // 저장하지 않고 옮기기 → 탭이 바뀌고, 설정을 다시 받아 돌아오면 저장된 값이다(의도된 서버 값 복귀)
    await securityTab.click();
    await expect(leave).toBeVisible({ timeout: 10_000 });
    await leave.click();
    await page.waitForURL('**tab=security**', { timeout: 30_000 });
    await page.waitForLoadState('networkidle');
    await page.locator('[role="tab"], button').filter({ hasText: /^\s*일반\s*$/ }).first().click();
    await page.waitForURL('**tab=general**', { timeout: 30_000 });
    await page.waitForLoadState('networkidle');
    await expect(siteName).toHaveValue(saved, { timeout: 20_000 });
    await expect(stay).toBeHidden();
    expect(pageErrors).toEqual([]);
  });

  /**
   * @scenario case=locale_switch_role_form_browser
   * @effects unsaved_local_kept, no_page_errors
   */
  test('역할 등록에서 입력한 뒤 언어를 바꿔도 입력이 남는다', async ({ page }) => {
    await page.goto('/admin/roles/create');
    await expect(page.locator('input[name="identifier"]')).toBeEnabled({ timeout: 30_000 });
    await page.locator('input[name="identifier"]').fill('e2e_locale_keep');
    await page.locator('input[name="name_raw[ko]"]').first().fill('E2E 언어 유지');

    await page.evaluate(() => (window as any).G7Core.locale.change('en'));
    await expect.poll(() => page.evaluate(() => document.querySelector('h1')?.textContent ?? ''), { timeout: 30_000 }).not.toMatch(/[가-힣]/);

    await expect(page.locator('input[name="identifier"]')).toHaveValue('e2e_locale_keep', { timeout: 20_000 });
    expect(await readLocal(page, 'form.name_raw')).toMatchObject({ ko: 'E2E 언어 유지' });
    await page.evaluate(() => (window as any).G7Core.locale.change('ko'));
    expect(pageErrors).toEqual([]);
  });
});
