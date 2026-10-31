/**
 * 확장 관리 4화면의 갤러리/목록 보기 전환 브라우저 실측.
 *
 * 정적 테스트(`__tests__/layouts/admin-extension-view-mode.test.tsx`)가 잠그는 것은
 * "레이아웃이 그렇게 선언돼 있다" 까지다. 여기서는 브라우저가 실제로 그렇게 **그리는지**
 * 를 잰다 — 둘은 다른 명제다. CardGrid 가 만든 클래스가 빌드 CSS 에 없으면 선언은
 * 맞는데 화면은 1열로 남는다.
 *
 * @scenario screen=plugins, view=gallery, device=desktop_1440
 *
 * @effects default_is_gallery, toggle_persists_to_local_storage, preference_shared_across_screens, grid_columns_follow_view_mode, hidden_toggle_next_to_search_on_desktop, view_toggle_box_fits_buttons_on_mobile
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';

const VIEW_MODE_KEY = 'g7_view_mode_extensions';

/** 목록 화면이 데이터까지 그린 뒤로 넘어간다. */
async function gotoList(
  page: import('@playwright/test').Page,
  token: string,
  path: string,
  ref: string,
): Promise<void> {
  await authenticatePage(page, token);
  await page.goto(path);
  // networkidle 은 쓰지 않는다 — 관리자 앱이 알림 폴링을 계속 열어 두어 영영 idle 이 되지 않는다.
  // 카드 그리드가 나타나는 것이 곧 데이터가 도착했다는 신호다.
  await page.waitForSelector(`#${ref}`, { timeout: 60_000 });
  // 그리드 컨테이너가 먼저 서고 카드는 그 뒤에 들어온다 — 첫 카드를 기다려야 측정이 선다.
  await page.waitForSelector(`#${ref} .grid > *`, { timeout: 60_000 });
}

/** 카드 그리드의 실제 열 수를 계산한다. */
async function columnCount(page: import('@playwright/test').Page, ref: string): Promise<number> {
  return page.evaluate((dataRef) => {
    const grid = document.querySelector(`#${dataRef} .grid`);
    if (!grid) return 0;
    return getComputedStyle(grid).gridTemplateColumns.split(' ').filter(Boolean).length;
  }, ref);
}

test.describe('확장 목록 — 보기 전환', () => {
  // 확장 목록은 두 데이터소스(목록 + 자동 비활성화 배너)를 함께 기다리는 전환 오버레이를
  // 지나야 카드가 그려진다. 기본 30초로는 이 환경에서 모자란다.
  test.setTimeout(90_000);

  // @scenario screen=plugins, view=gallery, device=desktop_1440
  // @effects default_is_gallery, grid_columns_follow_view_mode
  test('P1 — 첫 진입은 갤러리이고 저장값을 만들지 않는다', async ({ page, extensionReadToken }) => {
    await authenticatePage(page, extensionReadToken);
    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');

    // 1440px 은 그리드 폭이 1072px 라 4열이다(열 수는 중단점이 아니라 컨테이너 폭 ÷ (카드 250 + 간격 16)).
    expect(await columnCount(page, 'plugin_card_grid')).toBe(4);
    // 아직 고른 적이 없으므로 저장소는 비어 있어야 한다(기본값을 미리 쓰지 않는다).
    expect(await page.evaluate((k) => window.localStorage.getItem(k), VIEW_MODE_KEY)).toBeNull();
    await expect(page.locator('#view_mode_gallery')).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('#view_mode_list')).toHaveAttribute('aria-pressed', 'false');
  });

  // @scenario screen=plugins, view=list, device=desktop_1440
  // @effects toggle_persists_to_local_storage, persisted_value_read_back_after_write, persisted_value_restored_on_reload
  test('P2 — 목록으로 바꾸면 저장되고 새로고침 뒤에도 남는다', async ({ page, extensionReadToken }) => {
    await authenticatePage(page, extensionReadToken);
    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');

    await page.locator('#view_mode_list').click();

    await expect.poll(() => columnCount(page, 'plugin_card_grid')).toBe(1);
    expect(await page.evaluate((k) => window.localStorage.getItem(k), VIEW_MODE_KEY)).toBe('list');

    await page.reload();
    await page.waitForSelector('#plugin_card_grid .grid > *', { timeout: 60_000 });

    await expect.poll(() => columnCount(page, 'plugin_card_grid')).toBe(1);
    await expect(page.locator('#view_mode_list')).toHaveAttribute('aria-pressed', 'true');
  });

  // @scenario screen=modules, view=list
  // @effects preference_shared_across_screens
  test('P3 — 고른 보기를 다른 화면이 이어받는다', async ({ page, extensionReadToken }) => {
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');
    await page.locator('#view_mode_list').click();
    await expect.poll(() => columnCount(page, 'plugin_card_grid')).toBe(1);

    // 브라우저 저장값이 살아 있는 동안 다른 화면이 같은 보기를 이어받는지 본다.
    // (이 하네스는 인증을 initScript 로 심어 네비게이션마다 컨텍스트 저장소가 초기화되므로
    //  연속 이동 전체를 한 번에 재지 않는다 — 그 축은 Chrome MCP 실측 T7 이 맡는다.)
    await page.goto('/admin/modules');
    await page.waitForSelector('#module_card_grid .grid > *', { timeout: 60_000 });

    await expect
      .poll(() => columnCount(page, 'module_card_grid'), { timeout: 20_000 })
      .toBe(1);
  });

  // @scenario screen=plugins, view=gallery, device=desktop_1440
  // @effects grid_columns_follow_view_mode
  test('P4 — 갤러리 열 수가 화면 폭을 따른다', async ({ page, extensionReadToken }) => {
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');

    // 열 수는 컨테이너 폭으로 정한다(카드 250 + 간격 16) — 사이드바가 열린 1100px 은 그리드 폭이 732px 라
    // 3열(782px)에 못 미쳐 2열이 맞다. 3열 표본은 컴포넌트 실측표(1280 → 3)를 따른다(#703 재측정).
    for (const [width, expected] of [[1440, 4], [1280, 3], [834, 2], [390, 1]] as const) {
      await page.setViewportSize({ width, height: 900 });
      await expect
        .poll(() => columnCount(page, 'plugin_card_grid'), { message: `${width}px 열 수` })
        .toBe(expected);
    }
  });

  // @scenario screen=plugins, icon_state=present, view=gallery
  // @effects icon_rendered_from_icon_url
  test('P5 — 동봉 확장의 아이콘이 실제로 그려진다', async ({ page, extensionReadToken }) => {
    await authenticatePage(page, extensionReadToken);
    const iconResponses: number[] = [];
    page.on('response', (r) => {
      if (/\/api\/extensions\/[^/]+\/[^/]+\/icon/.test(r.url())) iconResponses.push(r.status());
    });

    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');

    // lazy 이미지가 뷰포트 안에서 디코딩될 시간을 준다.
    await page.waitForFunction(() => {
      const imgs = [...document.querySelectorAll('#plugin_card_grid img')] as HTMLImageElement[];
      const visible = imgs.filter((i) => i.getBoundingClientRect().top < window.innerHeight);
      return visible.length > 0 && visible.every((i) => i.complete);
    }, { timeout: 30_000 });

    const loaded = await page.evaluate(() => {
      // 카드 안 요소는 행마다 반복돼 id 가 중복되므로 그리드 하위에서 구조로 집는다.
      const imgs = [...document.querySelectorAll('#plugin_card_grid img')] as HTMLImageElement[];
      // loading="lazy" 라 첫 화면 밖 카드는 아직 안 받았을 수 있다 — 보이는 것만 센다.
      const visible = imgs.filter((i) => i.getBoundingClientRect().top < window.innerHeight);
      return {
        count: imgs.length,
        visibleCount: visible.length,
        visibleDecoded: visible.every((i) => i.naturalWidth > 0),
      };
    });

    expect(loaded.count, '아이콘 <img> 가 하나도 없습니다').toBeGreaterThan(0);
    expect(loaded.count, '카드 수만큼 아이콘이 있어야 합니다').toBeGreaterThanOrEqual(10);
    expect(loaded.visibleCount, '첫 화면에 보이는 아이콘이 없습니다').toBeGreaterThan(0);
    expect(loaded.visibleDecoded, '브라우저가 그리지 못한 아이콘이 있습니다').toBe(true);
    expect(iconResponses.every((s) => s === 200 || s === 304)).toBe(true);
  });

  // @scenario screen=plugins, view=gallery, install_state=active
  // @effects gallery_actions_parity_with_list
  test('P6 — 카드 본문을 누르면 상세 창이 열린다', async ({ page, extensionReadToken }) => {
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');

    await page.locator('#plugin_card_grid [role="button"][tabindex="0"]').first().click();

    // 상세 창은 포털로 렌더돼 그리드 밖에 붙는다 — 역할로 집는다.
    await expect(page.getByRole('dialog')).toBeVisible({ timeout: 30_000 });
  });

  // @scenario screen=settings_language_packs_tab, icon_state=lp_target_composite
  // @effects language_pack_composite_badge
  test('P7 — 언어팩 카드에 로케일 배지가 붙는다', async ({ page, extensionReadToken }) => {
    await gotoList(page, extensionReadToken, '/admin/settings?tab=language_packs', 'lp_card_grid');

    // 대상 확장(또는 코어)에서 빌려 온 그림일 때만 배지가 붙는다.
    const badges = page.locator('#lp_card_grid span[aria-label]');
    expect(await badges.count()).toBeGreaterThan(0);
  });
});

test.describe('확장 목록 — 스위치 재동기와 숨김 항목 (#703)', () => {
  test.setTimeout(90_000);

  // 서버에는 아무 것도 바꾸지 않는다 — 끄기 요청을 가로채 409(의존 충돌)를 돌려준다.
  // @scenario screen=modules, view=gallery, install_state=active
  // @effects toggle_resyncs_to_server_value_on_refetch
  test('P8 — 의존 충돌 창을 취소하면 스위치가 서버 값(켜짐)으로 돌아온다', async ({ page, extensionReadToken }) => {
    await page.route('**/api/admin/modules/deactivate', (route) =>
      route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          message: 'conflict',
          errors: { dependent_templates: [{ identifier: 'e2e-dependent', name: 'E2E Dependent', type: 'user' }] },
        }),
      }),
    );
    await gotoList(page, extensionReadToken, '/admin/modules', 'module_card_grid');

    // 켜져 있고 누를 수 있는 첫 스위치
    const target = page.locator('#module_card_grid [role="switch"][aria-checked="true"]:not([aria-disabled="true"])').first();
    const label = await target.getAttribute('aria-label');
    const sw = page.locator(`#module_card_grid [role="switch"][aria-label="${label}"]`);

    await sw.click();
    // 강제 창이 열리면 스위치는 서버 값으로 돌아와 있어야 한다.
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 30_000 });
    await expect(sw).toHaveAttribute('aria-checked', 'true');

    await dialog.getByRole('button', { name: '취소' }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(sw).toHaveAttribute('aria-checked', 'true');
  });

  // 최고관리자 여부는 서버 응답(current_user.data.is_super)에서 온다 — 여기서는 응답에 값을 얹어
  // 화면 배선만 잰다(서버가 값을 주는지는 PHPUnit AuthUserSuperFlagTest 가 잠근다).
  // @scenario screen=modules, view=gallery
  // @effects hidden_toggle_refetch_uses_new_value
  test('P9 — 숨김 항목 포함을 켜면 바로 그 값(include_hidden=1)으로 목록을 다시 받고, 새로고침해도 유지한다', async ({ page, extensionReadToken }) => {
    await page.route('**/api/admin/auth/user', async (route) => {
      const response = await route.fetch();
      const json = await response.json();
      json.data = { ...(json.data ?? {}), is_super: true };
      await route.fulfill({ response, json });
    });
    await gotoList(page, extensionReadToken, '/admin/modules', 'module_card_grid');

    const checkbox = page.getByRole('checkbox', { name: /숨김/ });
    await expect(checkbox).toBeVisible({ timeout: 30_000 });

    const request = page.waitForRequest((r) => /\/api\/admin\/modules\?/.test(r.url()));
    await checkbox.click();
    expect(new URL((await request).url()).searchParams.get('include_hidden')).toBe('1');

    // 체크 값은 주소에 실린다 — 화면 상태에 두면 페이지를 넘길 때 풀려 2페이지를 숨김 없이 받았다(#703 실측).
    await expect(page).toHaveURL(/include_hidden=1/);
    const reload = page.waitForRequest((r) => /\/api\/admin\/modules\?/.test(r.url()));
    await page.reload();
    expect(new URL((await reload).url()).searchParams.get('include_hidden')).toBe('1');
    await expect(page.getByRole('checkbox', { name: /숨김/ })).toBeChecked({ timeout: 30_000 });

    const back = page.waitForRequest((r) => /\/api\/admin\/modules\?/.test(r.url()));
    await page.getByRole('checkbox', { name: /숨김/ }).click();
    expect(new URL((await back).url()).searchParams.get('include_hidden')).toBe('0');
    // 마지막 클릭이 부른 재조회와 인증 확인이 테스트 종료와 겹치면 가로채기 콜백이 닫힌 페이지에서 돈다.
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  // 7.1.0 출시 전 점검 — 툴바가 justify-between 에 자식 셋이라 체크박스가 남는 폭 정중앙(1440px x=698)에 떴고,
  // 모바일은 class 를 row-stack 으로 통째로 바꿔 보기 전환 상자 안에 8px 이 들어가 49px(버튼 40px)이었다.
  // @scenario screen=plugins, device=desktop_1440
  // @effects hidden_toggle_next_to_search_on_desktop, view_toggle_box_fits_buttons_on_mobile
  test('P11 — 체크박스는 PC 에서 검색칸 바로 왼쪽, 모바일에서 보기 전환 바로 오른쪽에 붙는다', async ({ page, extensionReadToken }) => {
    await page.route('**/api/admin/auth/user', async (route) => {
      const response = await route.fetch();
      const json = await response.json();
      json.data = { ...(json.data ?? {}), is_super: true };
      await route.fulfill({ response, json });
    });
    const measure = () => page.evaluate(() => {
      const box = (id: string) => document.getElementById(id)!.getBoundingClientRect();
      const g = box('view_mode_toggle');
      const l = box('include_hidden_toggle');
      const s = box('search_bar');
      const t = box('search_bar_wrapper');
      const btn = box('view_mode_gallery');
      return { bH: btn.height, gapLS: s.x - (l.x + l.width), gH: g.height, dyLG: Math.abs((l.y + l.height / 2) - (g.y + g.height / 2)), gapGL: l.x - (g.x + g.width), sBelow: s.y >= g.y + g.height, sRightGap: (t.x + t.width) - (s.x + s.width) };
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await gotoList(page, extensionReadToken, '/admin/plugins', 'plugin_card_grid');
    await expect(page.locator('#include_hidden_toggle')).toBeVisible({ timeout: 30_000 });
    const pc = await measure();
    expect(Math.round(pc.gapLS)).toBe(16);
    expect(Math.abs(pc.sRightGap)).toBeLessThan(1);

    // portable class 는 폭만 바꾸면 갱신되지 않으므로 새로고침해서 잰다.
    await page.setViewportSize({ width: 390, height: 900 });
    await page.reload();
    await page.waitForSelector('#plugin_card_grid .grid > *', { timeout: 60_000 });
    await expect(page.locator('#include_hidden_toggle')).toBeVisible({ timeout: 30_000 });
    const mobile = await measure();
    // 상자 = 버튼 높이 + 위아래 테두리(배율에 따라 1px 미만~1px). 수정 전에는 row-stack 패딩 8px 이 더해졌다.
    expect(mobile.gH - mobile.bH).toBeLessThanOrEqual(2.5);
    expect(mobile.dyLG).toBeLessThanOrEqual(2);
    expect(Math.round(mobile.gapGL)).toBe(16);
    expect(mobile.sBelow).toBe(true);
    expect(Math.abs(mobile.sRightGap)).toBeLessThan(1);
    await page.unrouteAll({ behavior: 'ignoreErrors' });
  });

  // 언어팩도 같은 격자·같은 재동기 신호를 쓴다. 꺼진 팩을 켜는 요청을 가로채 409(같은 로케일 자리 충돌)를
  // 돌려주고 고르는 창을 닫는다 — 환경설정 언어팩 탭은 이 창을 싣지 않아 아무 것도 뜨지 않았다(#703 실측).
  // @scenario screen=settings_language_packs_tab, view=gallery, install_state=inactive
  // @effects toggle_resyncs_to_server_value_on_refetch
  test('P10 — 언어팩 자리 충돌 창을 취소하면 스위치가 서버 값(꺼짐)으로 돌아온다', async ({ page, extensionReadToken }) => {
    await page.route('**/api/admin/language-packs/*/activate', (route) =>
      route.fulfill({
        status: 409,
        contentType: 'application/json',
        body: JSON.stringify({
          success: false,
          message: 'conflict',
          errors: { current: { identifier: 'e2e-current', locale: 'ja' }, target: { identifier: 'e2e-target', locale: 'ja' } },
        }),
      }),
    );
    await gotoList(page, extensionReadToken, '/admin/settings/language-packs', 'lp_card_grid');

    const target = page.locator('#lp_card_grid [role="switch"][aria-checked="false"]:not([aria-disabled="true"])').first();
    const label = await target.getAttribute('aria-label');
    const sw = page.locator(`#lp_card_grid [role="switch"][aria-label="${label}"]`).first();

    await sw.click();
    const dialog = page.getByRole('dialog');
    await expect(dialog).toBeVisible({ timeout: 30_000 });
    await expect(dialog).toContainText('e2e-current');
    await expect(sw).toHaveAttribute('aria-checked', 'false');

    await dialog.getByRole('button', { name: '취소' }).click();
    await expect(dialog).toBeHidden({ timeout: 10_000 });
    await expect(sw).toHaveAttribute('aria-checked', 'false');
  });
});
