/**
 * sirsoft-admin_basic — 긴 사이트 이름 넘침 방지 (#143)
 *
 * 배경: 모바일 관리자 상단 좌측 칸(`.mobile_header_left` — 메뉴 단추 + 이름)이 `flex items-center gap-3` 뿐이라
 * flex 자식 기본값 `min-width:auto` 로 긴 이름 폭 아래로 줄지 않았다. 이름 Span 은 이미 truncate 이고 우측 묶음은
 * flex-shrink-0 이지만 바깥 칸이 줄지 않으니 우측 테마·알림 단추가 화면 밖으로 밀렸다. 로그인 화면 제목(text-4xl)은
 * 공백 없는 이름이면 카드를 넘쳤다.
 *
 * 재현: `addInitScript` 로 `window.G7Config` 대입을 가로채 `settings.general.site_name` 만 바꾼다(서버 데이터 무변경).
 *
 * 측정 규율: `.admin_layout_root` 가 `overflow-hidden` 이라 문서 `scrollWidth − clientWidth` 는 늘 0 이다 — 그 값을
 * 단언하면 회귀를 못 잡는다. 대신 우측 묶음의 right 가 뷰포트를 넘는 픽셀(clip)을 직접 잰다.
 *
 * @scenario-manifest tests/scenarios/admin-long-site-name.yaml
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 제보값 — 공백 없는 영문 23자. */
const NAME_NO_SPACE = 'SirSoftBasicMenuReverse';
/** 공백 없는 영문 52자. */
const NAME_52 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ';

/**
 * 첫 렌더부터 사이트 이름을 바꾼다.
 *
 * @param page 대상 페이지
 * @param name 넣을 사이트 이름
 */
async function overrideSiteName(page: Page, name: string): Promise<void> {
  await page.addInitScript((siteName) => {
    let stored: any;
    Object.defineProperty(window, 'G7Config', {
      configurable: true,
      get: () => stored,
      set: (value) => {
        if (value?.settings?.general) value.settings.general.site_name = siteName;
        stored = value;
      },
    });
  }, name);
}

test.describe('@sirsoft-admin_basic 긴 사이트 이름', () => {
  for (const width of [297, 320, 375]) {
    /**
     * @scenario surface=mobile_header
     * @effects mobile_header_right_in_viewport, mobile_site_name_single_line_ellipsis
     */
    test(`${width}px 모바일 관리자 상단: 우측 단추가 화면 안이고 이름은 한 줄 …`, async ({ page, adminDashboardToken }) => {
      await page.setViewportSize({ width, height: 800 });
      await overrideSiteName(page, NAME_52);
      await authenticatePage(page, adminDashboardToken);
      await page.goto('/admin/dashboard');
      await page.waitForFunction(
        (n) => document.getElementById('mobile_logo')?.textContent?.includes(n),
        NAME_52,
        { timeout: 60_000 },
      );

      const m = await page.evaluate(() => {
        const vw = document.documentElement.clientWidth;
        const right = document.getElementById('mobile_header_right')!;
        const span = document.querySelector('#mobile_logo span') as HTMLElement;
        const toggle = document.getElementById('mobile_menu_toggle')!;
        const tr = toggle.getBoundingClientRect();
        const hit = document.elementFromPoint(tr.left + tr.width / 2, tr.top + tr.height / 2);
        return {
          clip: Math.max(0, Math.round(right.getBoundingClientRect().right - vw)),
          ellipsis: getComputedStyle(span).textOverflow,
          truncated: span.scrollWidth > span.clientWidth,
          toggleHit: !!hit && (toggle === hit || toggle.contains(hit)),
        };
      });
      expect(m.clip).toBe(0);
      expect(m.ellipsis).toBe('ellipsis');
      expect(m.truncated).toBe(true);
      expect(m.toggleHit).toBe(true);
    });
  }

  /**
   * @scenario surface=login_title
   * @effects login_title_wraps_within_card
   */
  test('297px 로그인 화면 제목: 카드 안에서 줄바꿈된다', async ({ page }) => {
    await page.setViewportSize({ width: 297, height: 800 });
    await overrideSiteName(page, NAME_NO_SPACE);
    await page.goto('/admin/login');
    await page.waitForFunction(
      (n) => document.getElementById('logo_text')?.textContent === n,
      NAME_NO_SPACE,
      { timeout: 60_000 },
    );

    const m = await page.evaluate(() => {
      const h1 = document.getElementById('logo')!;
      const card = document.getElementById('login_card_container')!;
      return {
        over: Math.round(h1.getBoundingClientRect().left + h1.scrollWidth - card.getBoundingClientRect().right),
        docOver: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    });
    expect(m.over).toBeLessThanOrEqual(0);
    expect(m.docOver).toBe(0);
  });
});
