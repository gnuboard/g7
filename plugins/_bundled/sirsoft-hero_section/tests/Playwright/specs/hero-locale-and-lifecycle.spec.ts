/**
 * E2E: 언어 전환·플러그인 비활성화·조회 전용 관리자
 *
 * @scenario-manifest tests/scenarios/e2e-flows.yaml
 * @effects user_screen_reflects_admin_change, test_data_cleaned_up
 *
 * - 언어를 바꾸면 엔진이 화면을 새로 마운트하며 공개 API 를 새 언어로 다시 받아 배너 문구가 바뀐다(새로고침 없이).
 * - 플러그인을 끄면 주입 조각과 공개 API 가 함께 빠져 홈 상단 자리가 비고, 다시 켜면 돌아온다. 사양이 끝나면 반드시 다시 켠다.
 * - 조회 권한만 가진 관리자는 목록·미리보기는 보지만 등록·순서 변경·설정 저장·일괄 단추가 없고 등록 화면은 403 이다.
 */
import { execFileSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  test, expect, authenticatePage, createBanner, gotoUser, slider, slideTitles, uniqueTitle,
  ADMIN_LIST, API, VIEWPORTS,
} from '../fixtures/hero-auth';

/** 코어 루트 — 플러그인 켜기·끄기 artisan 을 부른다 */
const CORE_ROOT = process.env.G7_ROOT || resolve(dirname(fileURLToPath(import.meta.url)), '../../../../../../');

/**
 * 플러그인을 켜거나 끈다(출력은 자르지 않는다 — 파이프로 끊으면 상태 복원 전에 죽는다).
 *
 * @param action activate | deactivate
 */
function pluginCommand(action: 'activate' | 'deactivate'): void {
  execFileSync('php', ['artisan', `plugin:${action}`, 'sirsoft-hero_section', '--no-interaction'], { cwd: CORE_ROOT, stdio: 'pipe', timeout: 120_000 });
}

for (const viewport of ['desktop', 'mobile'] as const) {
  // @scenario flow=locale_switch_changes_text, viewport=desktop
  // @scenario flow=locale_switch_changes_text, viewport=mobile
  // @effects user_screen_reflects_admin_change, test_data_cleaned_up
  test(`언어를 바꾸면 홈 배너 문구가 그 언어로 바뀐다 (${viewport})`, async ({ page, request, managerToken, created }) => {
    const ko = uniqueTitle('한국어');
    const en = `E2E English ${Date.now()}`;
    created.push(await createBanner(request, managerToken, { title: { ko, en } }));

    await page.setViewportSize(VIEWPORTS[viewport]);
    await gotoUser(page, '/');
    await expect(slider(page)).toBeVisible({ timeout: 30_000 });
    await expect.poll(() => slideTitles(page)).toContain(ko);

    const refetched = page.waitForResponse((r) => r.url().includes(`${API}/hero?position=home_hero`), { timeout: 30_000 });
    await page.evaluate(() => (window as any).G7Core.locale.change('en'));
    await refetched;
    await expect.poll(() => slideTitles(page), { timeout: 20_000 }).toContain(en);
    expect(await slideTitles(page)).not.toContain(ko);
  });
}

// @scenario flow=plugin_deactivate_hides_section, viewport=desktop
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('플러그인을 끄면 홈 상단 배너 자리가 비고, 다시 켜면 돌아온다 (desktop)', async ({ page, request, managerToken, created }) => {
  const title = uniqueTitle('수명주기');
  created.push(await createBanner(request, managerToken, { title: { ko: title } }));
  await page.setViewportSize(VIEWPORTS.desktop);

  try {
    pluginCommand('deactivate');
    await gotoUser(page, '/');
    // 홈 본문은 그대로 그려진다 — 배너 자리만 빈다
    await expect(page.locator('body')).toContainText('최근 게시글', { timeout: 30_000 });
    await expect(slider(page)).toHaveCount(0);
    expect((await request.get(`${API}/hero?position=home_hero`, { headers: { Accept: 'application/json' } })).status()).toBe(404);
  } finally {
    pluginCommand('activate');
  }

  await gotoUser(page, '/');
  await expect.poll(() => slideTitles(page), { timeout: 30_000 }).toContain(title);
});

// @scenario flow=read_only_admin_sees_no_edit_buttons, viewport=desktop
// @effects user_screen_reflects_admin_change, test_data_cleaned_up
test('조회 권한만 있는 관리자는 목록은 보지만 고치는 단추가 없고 등록 화면은 막힌다 (desktop)', async ({ page, request, managerToken, readerToken, created }) => {
  const first = uniqueTitle('조회A');
  created.push(await createBanner(request, managerToken, { title: { ko: first } }));
  created.push(await createBanner(request, managerToken, { title: { ko: uniqueTitle('조회B') } }));

  await authenticatePage(page, readerToken);
  await page.setViewportSize(VIEWPORTS.desktop);
  await page.goto(`${ADMIN_LIST}?position=home_hero`);
  await expect(page.locator('#hero_banner_datagrid')).toContainText(first, { timeout: 30_000 });

  // 위치 배너가 2건 이상이어도 [순서 변경]·[배너 등록]·[설정 저장] 이 없다
  await expect(page.locator('#create_button')).toHaveCount(0);
  await expect(page.locator('#reorder_button')).toHaveCount(0);
  await expect(page.locator('#slider_save_button')).toHaveCount(0);

  // 선택은 되지만 일괄 단추는 하나도 없다
  const pick = page.getByRole('checkbox', { name: `${first} 선택` });
  await pick.click();
  await expect(pick).toBeChecked();
  await expect(page.locator('#bulk_action_bar')).toBeVisible();
  await expect(page.locator('#bulk_show_button')).toHaveCount(0);
  await expect(page.locator('#bulk_hide_button')).toHaveCount(0);
  await expect(page.locator('#bulk_delete_button')).toHaveCount(0);

  // 등록 화면은 등록·수정 권한이 필요하다 — 막혀서 입력 폼이 그려지지 않는다
  await page.goto(`${ADMIN_LIST}/create?position=home_hero`);
  await page.waitForLoadState('domcontentloaded');
  await expect(page.locator('#footer_save_button')).toHaveCount(0, { timeout: 20_000 });
  // 코어 권한 화면이 필요한 권한 이름을 알려 준다
  await expect(page.locator('body')).toContainText('sirsoft-hero_section.banners.update', { timeout: 20_000 });
});
