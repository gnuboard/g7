/**
 * sirsoft-admin_basic — 모바일 뷰포트 가로 오버플로 회귀
 *
 * 배경: 모바일 헤더 우측 그룹은 `flex-shrink-0` 이라 좁아져도 폭을 유지한 채 화면 밖으로
 * 밀려난다. theme(36) + noti(36) + lang(79) + 통화(112) + gap 합계가 **420px** 를 요구해
 * 390px 에서 30px, 320px 에서 100px 이 잘렸다. 언어/통화/배송국가를 모바일 드로어의
 * 가로 칩으로 옮겨 해소했다.
 *
 * 이후 드로어가 세로로 길어져 관리자 메뉴가 스크롤 밖으로 밀리는 문제가 생겨,
 * 언어 / 통화·배송국가를 각각 독립 아코디언(기본 접힘 + 트리거에 현재값 요약)으로 바꿨다.
 *
 * 측정 규율 (이 spec 을 고칠 사람에게):
 *  - `document.scrollWidth - clientWidth`(docOver)는 **항상 0** 이다. `.admin_layout_root` 가
 *    `overflow-hidden` 이라 초과분이 문서 스크롤폭에 기여하지 않기 때문. 이걸 단언하면
 *    회귀를 영원히 못 잡는다.
 *  - 절대 폭 하한(`toBeGreaterThanOrEqual(80)`)도 회귀를 못 잡는다. **클립 0 을 직접 단언**한다.
 *  - 대신 두 지표를 쓴다: ① 요소 우측 끝이 뷰포트를 넘는가(clip)
 *                        ② `#right_content_area` 의 `scrollWidth - clientWidth`(rcaExcess)
 *
 * @scenario admin_viewport=390|320|1023|1024|1280 x surface=header|drawer|content
 * @effects mobile_header_no_clip, drawer_prefs_chips_on_screen, content_area_no_overflow,
 *          portable_desktop_boundary_flips_at_1024, desktop_header_currency_dropdown_preserved,
 *          drawer_prefs_collapsed_by_default, drawer_prefs_accordions_independent
 */
import { test, expect, authenticatePage } from '../../fixtures/admin-template-auth';
import type { Page } from '@playwright/test';

/** 세로 스크롤바(≈15px) 때문에 clientWidth 를 원하는 값으로 맞추려면 창을 그만큼 넓게 잡는다. */
const SCROLLBAR = 15;
const phone = { width: 390 + SCROLLBAR, height: 844 };
const smallPhone = { width: 320 + SCROLLBAR, height: 568 };
/**
 * `portable` 경계 양쪽. 엔진 `ResponsiveManager` 는 `portable = 0~1023px` 을
 * `window.innerWidth` 로 판정하므로 스크롤바 보정 없이 창 폭을 그대로 쓴다.
 * 1023 = portable 마지막 픽셀(드로어 렌더), 1024 = desktop 첫 픽셀(헤더 렌더).
 */
const portableEdge = { width: 1023, height: 800 };
const desktopEdge = { width: 1024, height: 800 };
const desktop = { width: 1280, height: 900 };

/** 요소의 우측 끝이 뷰포트를 넘는 픽셀 수 (넘지 않으면 0). */
async function clipOf(page: Page, selector: string): Promise<number> {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return -1;
    const vw = document.documentElement.clientWidth;
    return Math.max(0, Math.round(el.getBoundingClientRect().right - vw));
  }, selector);
}

/** #right_content_area 의 가로 초과분. */
async function rcaExcess(page: Page): Promise<number> {
  return page.evaluate(() => {
    const rca = document.getElementById('right_content_area');
    return rca ? rca.scrollWidth - rca.clientWidth : -1;
  });
}

/** settle 곡선 확인 — 지연 로딩(활동로그 페이지네이션 등) 때문에 첫 측정은 위음성이 난다. */
async function settledRcaExcess(page: Page, samples = 3): Promise<number> {
  let last = -1;
  for (let i = 0; i < samples; i += 1) {
    await page.waitForTimeout(1200);
    last = await rcaExcess(page);
  }
  return last;
}

async function openDrawer(page: Page): Promise<void> {
  await page.locator('#mobile_menu_toggle').click();
  // 드로어는 `transform: translateX(-256px) → 0` 을 0.3s 에 걸쳐 이동한다. 이 트랜지션이 끝나기
  // 전에 좌표를 읽으면 칩이 화면 밖(음수 x)에 있어 위양성이 난다.
  //
  // `getAnimations()` 로 기다리면 안 된다 — 클릭 직후에는 트랜지션이 아직 시작되지 않아
  // 빈 배열을 반환하고, waitForFunction 이 즉시 통과해 애니메이션 도중에 측정하게 된다.
  // 최종 기하(left === 0)가 안정될 때까지 기다린다.
  await page.waitForFunction(() => {
    const d = document.getElementById('left_sidebar_area');
    if (!d) return false;
    return Math.round(d.getBoundingClientRect().left) === 0;
  }, undefined, { timeout: 5_000 });
}

test.describe('@sirsoft-admin_basic 모바일 뷰포트 가로 오버플로', () => {
  test('390px: 모바일 헤더가 잘리지 않고 통화·언어는 헤더에 없다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(phone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#mobile_header_right').waitFor({ timeout: 20_000 });

    // 회귀 시 정확히 30 이 되어 실패한다.
    expect(await clipOf(page, '#mobile_header_right')).toBe(0);

    // 헤더에는 테마·알림만 남는다.
    const headerIds = await page.evaluate(() =>
      [...(document.getElementById('mobile_header_right')?.children ?? [])].map((c) => c.id),
    );
    expect(headerIds).toContain('theme_toggle_mobile');
    expect(headerIds).toContain('notification_center_mobile');
    expect(headerIds).not.toContain('language_selector_mobile');
    expect(headerIds).not.toContain('header_currency_slot_mobile');
  });

  test('320px: 헤더 클립 0 + 햄버거 탭 가능 (최소 폰)', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(smallPhone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#mobile_header_right').waitFor({ timeout: 20_000 });

    // 회귀 시 100 이 된다.
    expect(await clipOf(page, '#mobile_header_right')).toBe(0);

    // 햄버거가 화면 안이고 실제 히트 타깃이어야 한다(다른 요소가 덮으면 실패).
    const burgerTappable = await page.evaluate(() => {
      const b = document.getElementById('mobile_menu_toggle');
      if (!b) return false;
      const r = b.getBoundingClientRect();
      if (r.left < 0 || r.right > document.documentElement.clientWidth) return false;
      const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
      return !!hit && (b.contains(hit) || hit.contains(b));
    });
    expect(burgerTappable).toBe(true);
  });

  /**
   * 드로어 선호설정은 언어 / 통화·배송국가 두 개의 독립 아코디언이며 기본은 접힘이다.
   * 접힘 상태에서는 트리거에 현재 선택값만 요약 표기되고, 펼쳐야 칩이 렌더된다.
   * (전부 펼쳐 두면 드로어가 세로로 길어져 정작 관리자 메뉴가 스크롤 밖으로 밀린다.)
   */
  test('390px: 드로어 선호설정은 기본 접힘이고 트리거에 현재값을 요약 표기한다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(phone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await openDrawer(page);

    const prefs = page.locator('#admin_drawer_prefs');
    await expect(prefs).toBeVisible();

    // 언어: 접힘 → aria-expanded=false.
    const langToggle = page.locator('#admin_drawer_language_toggle');
    await expect(langToggle).toHaveAttribute('aria-expanded', 'false');

    // 접힘 상태 요약 = 현재 언어명. E2E 세션 로케일이 ko/en 중 무엇이든 성립해야 하므로
    // 특정 문자열('한국어')을 박지 않고, aria-selected 인 칩의 이름과 일치하는지로 본다.
    const summaryMatchesSelectedChip = await page.evaluate(() => {
      const toggle = document.querySelector('#admin_drawer_language_toggle');
      const selected = document.querySelector('#admin_drawer_language [role="option"][aria-selected="true"]');
      if (!toggle || !selected) return null;
      const chipName = (selected.textContent ?? '').trim();
      // 트리거 텍스트 = "<라벨><현재 언어명>" (칩 목록은 별도 래퍼라 포함되지 않는다)
      return { toggleText: (toggle.textContent ?? '').trim(), chipName };
    });
    expect(summaryMatchesSelectedChip).not.toBeNull();
    expect(summaryMatchesSelectedChip!.chipName.length).toBeGreaterThan(0);
    expect(summaryMatchesSelectedChip!.toggleText).toContain(summaryMatchesSelectedChip!.chipName);

    // 칩은 `max-h-0 overflow-hidden` 래퍼에 잘려 화면에 안 보인다.
    // 칩 자신은 레이아웃 박스를 갖고 있어(=Playwright 기준 visible) 칩에 not.toBeVisible()
    // 을 걸면 안 된다. 잘림을 만드는 **래퍼의 실제 높이 0** 을 단언한다.
    const langBodyHeight = await page.evaluate(() => {
      const body = document
        .querySelector('#admin_drawer_language_toggle')
        ?.parentElement?.querySelector('.overflow-hidden');
      return body ? Math.round(body.getBoundingClientRect().height) : -1;
    });
    expect(langBodyHeight).toBe(0);

    // 통화/배송국가(이커머스 주입): 접힘 → 패널 자체가 언마운트.
    // 모듈 비활성 환경에서는 슬롯이 비므로 존재할 때만 검증한다.
    const curTrigger = prefs.locator('button[aria-haspopup="listbox"]');
    if (await curTrigger.count()) {
      await expect(curTrigger).toHaveAttribute('aria-expanded', 'false');
      await expect(prefs.locator('[role="listbox"]')).toHaveCount(0);
    }
  });

  test('390px: 아코디언을 펼치면 언어·통화·배송국가 칩이 모두 화면 안에 있다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(phone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await openDrawer(page);

    // 언어 펼치기 (엔진 빌트인 setLocale 을 action.target 으로 호출하는 칩 3개)
    await page.locator('#admin_drawer_language_toggle').click();
    await expect(page.locator('#admin_drawer_language_toggle')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#admin_drawer_language [role="option"]')).toHaveCount(3);
    // 칩 자신은 접힘 상태에서도 레이아웃 박스를 가지므로, 잘림 래퍼가 실제로 열렸는지를 본다.
    await expect
      .poll(async () =>
        page.evaluate(() => {
          const body = document
            .querySelector('#admin_drawer_language_toggle')
            ?.parentElement?.querySelector('.overflow-hidden');
          return body ? Math.round(body.getBoundingClientRect().height) : -1;
        }),
      )
      .toBeGreaterThan(0);

    // 통화/배송국가 펼치기 (모듈 비활성 환경에서는 트리거 자체가 없다)
    const prefs = page.locator('#admin_drawer_prefs');
    const curTrigger = prefs.locator('button[aria-haspopup="listbox"]');
    if (await curTrigger.count()) {
      await curTrigger.click();
      await expect(prefs.locator('[role="listbox"]')).toHaveCount(1);
    }

    const chips = prefs.locator('[role="option"]');
    expect(await chips.count()).toBeGreaterThanOrEqual(3);

    // 칩이 한 개라도 뷰포트를 넘으면 실패.
    const anyChipClipped = await page.evaluate(() => {
      const vw = document.documentElement.clientWidth;
      return [...document.querySelectorAll('#admin_drawer_prefs [role="option"]')].some((el) => {
        const r = el.getBoundingClientRect();
        return r.width > 0 && (r.right > vw + 1 || r.left < -1);
      });
    });
    expect(anyChipClipped).toBe(false);
  });

  /**
   * 두 아코디언은 서로 다른 상태를 쓴다(언어=_global.mobileLanguageOpen,
   * 통화=_local.showCurrencyDropdown). 하나를 닫아도 다른 하나는 열린 채여야 한다.
   */
  test('390px: 언어와 통화 아코디언은 독립적으로 개폐된다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(phone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await openDrawer(page);

    const prefs = page.locator('#admin_drawer_prefs');
    const langToggle = page.locator('#admin_drawer_language_toggle');
    const curTrigger = prefs.locator('button[aria-haspopup="listbox"]');
    test.skip(!(await curTrigger.count()), '이커머스 모듈 비활성 — 통화 슬롯 없음');

    await langToggle.click();
    await curTrigger.click();
    await expect(langToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(curTrigger).toHaveAttribute('aria-expanded', 'true');

    // 언어만 닫는다 → 통화는 열린 채 유지 (독립성)
    await langToggle.click();
    await expect(langToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(curTrigger).toHaveAttribute('aria-expanded', 'true');
    await expect(prefs.locator('[role="listbox"]')).toHaveCount(1);

    // 드로어 안 인라인 아코디언이므로 배경 오버레이(fixed inset-0)는 렌더되지 않는다.
    // 렌더되면 드로어 전체 클릭을 가로챈다.
    const backdrops = await page.evaluate(
      () => document.querySelectorAll('#admin_drawer_prefs .fixed.inset-0').length,
    );
    expect(backdrops).toBe(0);
  });

  test('390px: 이커머스 환경설정 콘텐츠 영역이 넘치지 않는다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(phone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/ecommerce/settings');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#right_content_area').waitFor({ timeout: 20_000 });

    // 회귀 시 71 이 된다(이메일 행 nowrap + sticky-tab-nav 과다 bleed).
    expect(await settledRcaExcess(page)).toBe(0);
  });

  test('320px: 이커머스 환경설정 고정폭 입력이 넘치지 않는다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(smallPhone);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/ecommerce/settings');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#right_content_area').waitFor({ timeout: 20_000 });

    // 회귀 시 32 가 된다(w-80 고정폭 입력 2개).
    expect(await settledRcaExcess(page)).toBe(0);
  });

  test('1023px: portable 마지막 픽셀 — 드로어 선호설정이 렌더된다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(portableEdge);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#right_content_area').waitFor({ timeout: 20_000 });

    // 경계가 1px 밀리면(예: `<` → `<=` 오타) 여기서 잡힌다.
    await expect(page.locator('#admin_drawer_prefs')).toHaveCount(1);
    expect(await settledRcaExcess(page)).toBe(0);
  });

  test('1024px: desktop 첫 픽셀 — 드로어 선호설정이 사라지고 헤더 통화가 렌더된다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(desktopEdge);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#right_content_area').waitFor({ timeout: 20_000 });

    // 데스크톱에서 드로어 통화가 중복 노출되면 회귀다.
    await expect(page.locator('#admin_drawer_prefs')).toHaveCount(0);
    await expect(page.locator('#header_currency_slot_desktop')).toHaveCount(1);
    expect(await settledRcaExcess(page)).toBe(0);
  });

  test('1280px: 데스크톱 헤더/통화 드롭다운이 회귀하지 않는다', async ({ page, adminDashboardToken }) => {
    await page.setViewportSize(desktop);
    await authenticatePage(page, adminDashboardToken);
    await page.goto('/admin/dashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.locator('#right_content_area').waitFor({ timeout: 20_000 });

    // 드로어 선호설정 블록은 데스크톱에서 렌더되지 않아야 한다(중복 노출 = 회귀).
    await expect(page.locator('#admin_drawer_prefs')).toHaveCount(0);

    // 통화는 데스크톱 헤더 슬롯에 남는다.
    const desktopSlot = page.locator('#header_currency_slot_desktop');
    await expect(desktopSlot).toHaveCount(1);

    expect(await settledRcaExcess(page)).toBe(0);
  });
});
