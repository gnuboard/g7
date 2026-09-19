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
 * @effects default_is_gallery, toggle_persists_to_local_storage, preference_shared_across_screens, grid_columns_follow_view_mode
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

    // 1440px 은 xl 구간이라 4열이다(lg 3 → xl 4).
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

    for (const [width, expected] of [[1440, 4], [1100, 3], [834, 2], [390, 1]] as const) {
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
