/**
 * E2E: 템플릿 환경설정 화면·파비콘 head 링크 (#434)
 *
 * 배경: 설정 화면은 코어가 켜진 관리자 템플릿에 교차 등록한 레이아웃이라, 레이아웃 JSON 단위 시험은 「등록·라우트·렌더」가
 * 실제로 이어지는지 보지 못한다. 파비콘 링크는 서버 `<head>` 산출물이라 브라우저 문서에서만 확인된다.
 *
 * 검증:
 *  1. 템플릿 목록 → 「설정」 메뉴 → 설정 화면 진입, 제목·파비콘 칸이 번역문으로 그려지고 콘솔 오류가 없다
 *  2. 보기 권한만 있으면 읽기 전용 배너가 보이고 저장 바가 없다
 *  2-1. 섹션마다 탭 하나 — 탭을 누르면 주소 `?tab=` 이 바뀌고 그 섹션 카드만 보인다, 고친 값이 있으면 이동 전 확인 창
 *  3. 사용자·관리자 문서 head 에 파비콘 링크가 있고 그 주소가 200 이미지로 응답한다
 *  4. 화면에서 올린 파비콘이 우선순위대로 head 에 나간다 — 템플릿 > 일반 탭 > 번들(올린 첨부는 시험이 화면에서 지운다)
 */
import { test, expect, issueToken, issueScopedToken, authenticatePage } from '../../fixtures/auth';

// @scenario case=settings_layout
// @effects settings_layout_registered_into_active_admin, settings_route_generated, list_exposes_settings_route
test('#434 - 템플릿 목록 「설정」 으로 설정 화면에 들어간다', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await authenticatePage(page, issueToken('core.templates.read', 'core.templates.settings'));

  await page.goto('/admin/templates/user');
  await page.getByRole('button', { name: '더보기' }).first().click();
  const settingsItem = page.getByRole('menuitem', { name: '설정' });
  await expect(settingsItem).toBeVisible({ timeout: 20_000 });
  await settingsItem.click();

  await expect(page).toHaveURL(/\/admin\/templates\/[^/]+\/settings$/);
  await expect(page.locator('#template_settings_section_branding')).toBeVisible({ timeout: 20_000 });
  const text = await page.locator('#template_settings_content').innerText();
  expect(text).not.toContain('$t:');
  expect(text).not.toContain('template_settings.');
  await expect(page.getByTestId('template-settings-footer')).toBeVisible();

  expect(consoleErrors).toEqual([]);
});

// @scenario case=settings_layout
// @effects read_only_without_settings_permission
test('#434 - 보기 권한만 있으면 읽기 전용이다', async ({ page }) => {
  // admin 역할은 전체 권한을 가져 읽기 전용 분기가 열리지 않는다 — 권한 경계는 역할 없이 발급한다
  await authenticatePage(page, issueScopedToken('core.templates.read'));

  await page.goto('/admin/templates/sirsoft-basic/settings');
  await expect(page.getByTestId('template-settings-read-only')).toBeVisible({ timeout: 20_000 });
  await expect(page.getByTestId('template-settings-footer')).toHaveCount(0);
});

// @scenario case=settings_layout
// @effects settings_tabs_per_section
test('#434 - 섹션마다 탭 하나이고, 고친 값이 있으면 탭 이동 전에 묻는다', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (message) => {
    if (message.type() === 'error') consoleErrors.push(message.text());
  });

  await authenticatePage(page, issueToken('core.templates.read', 'core.templates.settings'));

  await page.goto('/admin/templates/sirsoft-basic/settings');
  await expect(page.getByRole('tab')).toHaveCount(4, { timeout: 20_000 });
  await expect(page.locator('#template_settings_section_branding')).toBeVisible();
  await expect(page.locator('#template_settings_section_footer')).toHaveCount(0);

  await page.getByRole('tab', { name: '푸터' }).click();
  await expect(page).toHaveURL(/\/settings\?tab=footer$/);
  await expect(page.locator('#template_settings_section_footer')).toBeVisible();
  await expect(page.locator('#template_settings_section_branding')).toHaveCount(0);

  // 고친 값이 있으면 확인 창 — [머무르기] 는 탭·고친 값을 그대로 둔다(저장하지 않으므로 사이트 값은 바뀌지 않는다)
  await page.locator('#template_settings_section_footer [role=switch]').first().click();
  await page.getByRole('tab', { name: '홈' }).click();
  await expect(page.getByText('저장하지 않은 변경')).toBeVisible();
  await page.getByRole('button', { name: '머무르기' }).click();
  await expect(page).toHaveURL(/\/settings\?tab=footer$/);
  await expect(page.getByRole('button', { name: '저장', exact: true })).toBeEnabled();

  // 섹션이 하나뿐인 템플릿도 탭 줄이 있다
  await page.goto('/admin/templates/sirsoft-admin_basic/settings');
  await expect(page.getByRole('tab')).toHaveCount(1, { timeout: 20_000 });

  expect(consoleErrors).toEqual([]);
});

const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

// @scenario surface=spa
// @effects highest_valid_tier_wins
test('#434 - 템플릿 → 일반 탭 → 번들 순으로 파비콘이 내려간다', async ({ page, request }) => {
  test.setTimeout(300_000);
  await authenticatePage(page, issueToken('core.templates.read', 'core.templates.settings', 'core.settings.read', 'core.settings.update'));

  const userIcon = async () => {
    const html = await (await request.get('/')).text();

    return html.match(/<link rel="icon"[^>]*href="([^"]+)"/)?.[1] ?? null;
  };
  const pick = (uploader: string, name: string) =>
    page.locator(`${uploader} input[type=file]`).setInputFiles({ name, mimeType: 'image/png', buffer: Buffer.from(PNG_1PX, 'base64') });
  // 삭제는 확인 창 뒤 바로 DELETE 가 나간다 — 응답을 기다려야 1칸이 비어 다음 파일을 받는다
  const clear = async (uploader: string) => {
    const remove = page.locator(`${uploader} [aria-label="삭제"]`);
    if ((await remove.count()) === 0) return;
    const deleted = page.waitForResponse((r) => /\/api\/admin\/attachments\/\d+$/.test(r.url()) && r.request().method() === 'DELETE');
    await remove.first().click();
    await page.getByRole('dialog').getByRole('button', { name: /삭제|확인/ }).first().click();
    await deleted;
  };
  const save = async (path: RegExp, method: string) => {
    const saved = page.waitForResponse((r) => path.test(new URL(r.url()).pathname) && r.request().method() === method, { timeout: 60_000 });
    await page.locator('#save_button').click();
    expect((await saved).status()).toBe(200);
  };
  const openTemplateSettings = async () => {
    await page.goto('/admin/templates/sirsoft-basic/settings');
    await page.locator('#template_settings_field_branding_favicon input[type=file]').waitFor({ state: 'attached', timeout: 30_000 });
    await expect(page.locator('#template_settings_section_branding')).toBeVisible({ timeout: 20_000 });
  };
  const openGeneralTab = async () => {
    await page.goto('/admin/settings');
    await page.locator('#site_favicon_uploader input[type=file]').waitFor({ state: 'attached', timeout: 30_000 });
    await expect(page.locator('input[name="general.site_name"]')).not.toHaveValue('', { timeout: 20_000 });
  };
  const templatePath = /^\/api\/admin\/templates\/sirsoft-basic\/settings$/;
  const settingsPath = /^\/api\/admin\/settings$/;

  await openTemplateSettings();
  await clear('#template_settings_field_branding_favicon');
  await pick('#template_settings_field_branding_favicon', 'e2e-template-favicon.png');
  await save(templatePath, 'PUT');
  const templateIcon = await userIcon();
  expect(templateIcon).toMatch(/\/api\/favicon\//);

  await openGeneralTab();
  await clear('#site_favicon_uploader');
  await pick('#site_favicon_uploader', 'e2e-core-favicon.png');
  await save(settingsPath, 'POST');
  expect(await userIcon(), '템플릿 계층이 일반 탭보다 우선한다').toBe(templateIcon);

  await openTemplateSettings();
  await clear('#template_settings_field_branding_favicon');
  await save(templatePath, 'PUT');
  const coreIcon = await userIcon();
  expect(coreIcon).toMatch(/\/api\/favicon\//);
  expect(coreIcon, '템플릿을 비우면 일반 탭 파비콘으로 내려간다').not.toBe(templateIcon);

  await openGeneralTab();
  await clear('#site_favicon_uploader');
  await save(settingsPath, 'POST');
  expect(await userIcon(), '둘 다 비우면 번들 아이콘').toMatch(/\/assets\/core\/favicon\/favicon\.(ico|svg)/);
});

// @scenario surface=spa
// @effects highest_valid_tier_wins
test('#434 - 문서 head 의 파비콘 링크가 이미지로 응답한다', async ({ page, request }) => {
  for (const path of ['/', '/admin/login']) {
    await page.goto(path);
    const hrefs = await page.locator('link[rel~="icon"], link[rel="apple-touch-icon"]').evaluateAll((links) =>
      links.map((link) => (link as HTMLLinkElement).href),
    );

    expect(hrefs.length).toBeGreaterThan(0);
    for (const href of hrefs) {
      const response = await request.get(href);
      expect(response.status()).toBe(200);
      expect(response.headers()['content-type']).toMatch(/^image\//);
    }
  }
});
