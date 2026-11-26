/**
 * 관리자 환경설정 탭 막대 — 1280 폭에서 단추 글자는 한 줄, 넘치는 탭은 다음 줄로 감긴다 (설계 확정 2026-11-08)
 *
 * 탭이 14개로 늘자 1280 폭에서 단추가 줄어들며 글자가 꺾였다(「일/반」, 단추 높이 58px). 결정은 「두 줄은 두되 글자만 안
 * 꺾임」이다 — 단추는 줄바꿈 없이 제 폭을 지키고, 막대가 넘치면 단추째 다음 줄로 감긴다.
 * 조합과 효과 표식은 시험 앞에 있다.
 */
import { test, expect } from '@playwright/test';
import { issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@sirsoft-admin_basic 환경설정 탭 막대 줄바꿈', () => {
  // @scenario viewport=1280, tabs=14
  // @effects settings_tab_labels_stay_on_one_line, settings_tab_bar_wraps_instead_of_squeezing
  test('1280 폭: 탭 단추 글자는 한 줄이고, 막대는 찌그러뜨리지 않고 줄을 늘린다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await authenticatePage(page, issueToken());
    await page.goto('/admin/settings?tab=general');
    await page.waitForLoadState('networkidle', { timeout: 30_000 });

    const tablist = page.locator('#tab_navigation[role="tablist"], #tab_navigation [role="tablist"]').first();
    await expect(tablist).toBeVisible({ timeout: 15_000 });

    const measured = await tablist.evaluate((list) => {
      const tabs = Array.from(list.querySelectorAll<HTMLElement>('[role="tab"]'));
      const lineHeight = (el: HTMLElement) => parseFloat(getComputedStyle(el).lineHeight) || 20;
      return {
        count: tabs.length,
        tabs: tabs.map((t) => {
          const label = t.textContent?.trim() ?? '';
          const style = getComputedStyle(t);
          const contentHeight = t.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom);
          return { label, whiteSpace: style.whiteSpace, lines: Math.round(contentHeight / lineHeight(t)), width: t.getBoundingClientRect().width, scrollWidth: t.scrollWidth, clientWidth: t.clientWidth };
        }),
        rows: new Set(tabs.map((t) => Math.round(t.getBoundingClientRect().top))).size,
        overflowX: list.scrollWidth > list.clientWidth + 1,
      };
    });

    expect(measured.count).toBeGreaterThanOrEqual(12);
    for (const tab of measured.tabs) {
      expect(tab.whiteSpace, `「${tab.label}」 단추가 줄바꿈을 허용합니다`).toBe('nowrap');
      expect(tab.lines, `「${tab.label}」 글자가 ${tab.lines}줄로 꺾였습니다`).toBe(1);
      expect(tab.scrollWidth, `「${tab.label}」 글자가 단추 밖으로 넘칩니다`).toBeLessThanOrEqual(tab.clientWidth + 1);
    }
    // 막대는 가로로 넘치지 않고(찌그러뜨리지도 않고) 줄을 늘린다
    expect(measured.overflowX, '탭 막대가 가로로 넘칩니다').toBe(false);
    expect(measured.rows).toBeGreaterThanOrEqual(1);
    expect(measured.rows).toBeLessThanOrEqual(3);
  });
});
