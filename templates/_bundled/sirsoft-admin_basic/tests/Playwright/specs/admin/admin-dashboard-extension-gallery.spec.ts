/**
 * 대시보드 확장 상태 갤러리 브라우저 실측.
 *
 * 정적 테스트(`__tests__/layouts/admin_dashboard.extension_gallery.test.tsx`)가 잠그는 것은 반복식·문구 조건이다.
 * 여기서는 실제 화면에서 ① 플러그인이 많아도 칸에 6장만 깔리고 「외 N개 더 있음」이 붙는지,
 * ② 작은 카드가 칸 밖으로 넘치지 않는지(PC 3칸 배치·모바일 1칸 배치), ③ 깨진 아이콘 주소가
 * 기본 글리프로 물러나는지를 잰다.
 *
 * 사이트에 설치된 플러그인 수에 기대지 않도록 설치 목록 응답의 첫 행을 복제해 15건으로 채우고,
 * 첫 행의 아이콘 주소를 없는 경로로 바꿔 넣는다.
 *
 * @scenario-manifest templates/_bundled/sirsoft-admin_basic/tests/scenarios/admin-dashboard-extension-gallery.yaml
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

const PLUGIN_COUNT = 15;
const BROKEN_ICON = '/e2e-dashboard-gallery/missing-icon.svg';

/**
 * 플러그인 설치 목록 응답을 15건으로 늘리고 첫 행 아이콘을 깨진 주소로 바꾼다.
 *
 * @param page 대상 페이지
 */
async function padPluginList(page: Page): Promise<void> {
  await page.route(/\/api\/admin\/plugins\/installed/, async (route) => {
    if (route.request().method() !== 'GET') {
      await route.continue();
      return;
    }
    const response = await route.fetch();
    const body = await response.json();
    const rows: any[] = body?.data?.data ?? [];
    const base = rows[0] ?? { identifier: 'e2e-plugin', name: 'E2E', version: '1.0.0', status: 'active' };
    body.data.data = Array.from({ length: PLUGIN_COUNT }, (_, i) => ({
      ...base,
      identifier: `e2e-gallery-plugin-${i + 1}`,
      name: `시험 플러그인 ${i + 1}`,
      icon_url: i === 0 ? BROKEN_ICON : null,
    }));
    await route.fulfill({ response, json: body });
  });
}

test.describe('@sirsoft-admin_basic 대시보드 확장 상태 갤러리', () => {
  test.setTimeout(90_000);

  // 1110px: 사이드바가 있는 화면에서 바깥이 3칸이라 칸 하나가 245px 남짓 — 화면 폭 기준 2열이던 판본은 카드가 100px 로
  // 줄어 배지가 카드 밖으로 넘쳤다. 열 수는 칸 폭(@container)으로 정한다.
  for (const width of [1600, 1110, 375]) {
    // @scenario kind=plugin, overflow=over_six
    // @effects gallery_shows_at_most_six_in_server_order, overflow_note_only_when_over_six, gallery_cards_stay_inside_column, broken_icon_falls_back_to_glyph
    test(`${width}px: 플러그인 15개 중 6장만 깔고 외 9개를 적으며 카드가 칸 안에 머문다`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await authenticatePage(page, issueToken('core.dashboard.read', 'core.modules.read', 'core.plugins.read', 'core.templates.read'));
      await padPluginList(page);

      await page.goto('/admin/dashboard');
      // networkidle 은 쓰지 않는다 — 관리자 앱이 알림 폴링을 계속 열어 두어 영영 idle 이 되지 않는다.
      await page.waitForSelector('#plugin_item_0', { timeout: 60_000 });

      await expect(page.locator('#plugin_list > *')).toHaveCount(6);
      await expect(page.locator('#plugin_name_0')).toHaveText('시험 플러그인 1');
      await expect(page.locator('#plugin_name_5')).toHaveText('시험 플러그인 6');
      await expect(page.locator('#plugin_more_count')).toHaveText('외 9개 더 있음');

      // 카드가 칸 밖으로, 배지가 카드 밖으로 넘치지 않고, 배지 글자가 세로로 꺾이지 않는다(한 줄 높이)
      const layout = await page.evaluate(() => {
        const card = document.getElementById('plugin_status_card')!.getBoundingClientRect();
        return Array.from(document.querySelectorAll('#plugin_list > *')).map((el, i) => {
          const r = el.getBoundingClientRect();
          const badge = document.getElementById(`plugin_badge_${i}`)!.getBoundingClientRect();
          return {
            overflow: Math.max(0, Math.round(r.right - card.right), Math.round(card.left - r.left), Math.round(badge.right - r.right)),
            badgeOneLine: badge.height <= 26,
          };
        });
      });
      expect(layout.map((m) => m.overflow)).toEqual([0, 0, 0, 0, 0, 0]);
      expect(layout.every((m) => m.badgeOneLine)).toBe(true);

      // 깨진 아이콘은 이미지가 걷히고 그 아래 깔린 글리프가 남는다
      await expect(page.locator('#plugin_icon_img_0')).toHaveCount(0, { timeout: 15_000 });
      await expect(page.locator('#plugin_icon_0 i, #plugin_icon_0 svg').first()).toBeVisible();
    });
  }
});
