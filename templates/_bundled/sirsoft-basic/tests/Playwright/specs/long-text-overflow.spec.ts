/**
 * E2E: 긴 텍스트 넘침 방지 (#143) — 사이트 이름·게시판 이름이 길어도 화면이 넘치지 않는다.
 *
 * 배경 (2026-10-12 실측, 로그인 기준): 공백 없는 23자 사이트 이름 `SirSoftBasicMenuReverse` 에서 모바일 상단 우측
 * 단추가 297/320px 4개, 375px 2개, 414px 에서도 메뉴 단추가 화면 밖이었고 문서에 가로 스크롤(scrollWidth 419)이 생겼다.
 * 홈 환영 카드 제목은 297px 51px, 320px 28px 넘쳤다. 공백·한글 긴 이름은 4줄로 접혀 고정 높이(h-14) 헤더를 위아래로 넘쳤다.
 *
 * 재현 방법 — 서버 데이터를 바꾸지 않는다:
 *  - 사이트 이름: `addInitScript` 로 `window.G7Config` 대입을 가로채 `settings.general.site_name` 만 바꾼다(첫 렌더부터 적용).
 *  - 게시판 이름: `page.route` 로 게시판 모듈 API JSON 의 이름 필드를 공백 없는 긴 URL 형 문자열로 바꿔 돌려준다.
 *  - 측정은 화면 글자가 새 값으로 바뀐 것을 확인한 뒤에 한다(고정 대기는 직전 값을 잰 전례가 있다).
 *
 * 지표: `docOver = scrollWidth − clientWidth`(기대 0), 상단 우측 단추 right ≤ clientWidth, 메뉴 단추 중심의
 * `elementFromPoint` 가 메뉴 단추 자신, 이름 Span 의 text-overflow 가 ellipsis 이고 높이가 헤더 안, 환영 제목 넘침 ≤ 0.
 *
 * @scenario-manifest tests/scenarios/long-text-overflow.yaml
 */
import { test, expect } from '@playwright/test';
import type { Page } from '@playwright/test';

// 첫 진입은 SPA·확장 번들 로드로 수십 초가 걸릴 수 있다 — 대기 상한(60s)이 기본 테스트 상한(30s)에 먼저 끊기지 않게 한다.
test.describe.configure({ timeout: 90_000 });

/** 제보값 — 공백 없는 영문 23자. */
const NAME_NO_SPACE = 'SirSoftBasicMenuReverse';
/** PC 헤더용 — 공백 없는 영문 52자. */
const NAME_52 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ';
/** 한글 15자 — 수정 전에는 4줄로 접혀 헤더를 세로로 넘쳤다. */
const NAME_KO = '우리동네중고거래커뮤니티사이트';
/** 데이터 텍스트용 — 공백 없는 URL 형 문자열. */
const LONG_TEXT = `https://example.com/${'a'.repeat(80)}`;

/**
 * 첫 렌더부터 사이트 이름을 바꾼다 — `window.G7Config` 대입을 setter 로 가로챈다.
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

/**
 * 게시판 목록류 API(서랍 메뉴·홈 요약·인기 게시판·최근 글·게시판 모아보기) 응답의 이름·제목을 긴 문자열로 바꾼다.
 *
 * 게시판이 하나도 없는 환경에서도 재현되도록, 목록이 비어 있으면 게시판 1개(최근 글 1개 포함)를 합성해 넣는다.
 * 응답 봉투는 `{ success, data: [...] }` 이다.
 *
 * @param page 대상 페이지
 */
async function longBoardTexts(page: Page): Promise<void> {
  const keys = new Set(['name', 'board_name', 'title']);
  const rewrite = (node: unknown): unknown => {
    if (Array.isArray(node)) return node.map(rewrite);
    if (node && typeof node === 'object') {
      const out: Record<string, unknown> = {};
      for (const [k, v] of Object.entries(node as Record<string, unknown>)) {
        out[k] = keys.has(k) && typeof v === 'string' && v !== '' ? LONG_TEXT : rewrite(v);
      }
      return out;
    }
    return node;
  };
  const post = { id: 990001, board_slug: 'e2e-long-board', board_name: LONG_TEXT, title: LONG_TEXT, is_secret: false, is_new: true, comment_count: 1 };
  const board = { id: 990001, slug: 'e2e-long-board', name: LONG_TEXT, description: LONG_TEXT, posts_count: 1, post_count: 1, recent_posts: [post] };
  await page.route(/\/api\/modules\/sirsoft-board\/boards(\/board-menu|\/popular-boards|\/posts\/recent)?(\?.*)?$/, async (route) => {
    try {
      const response = await route.fetch();
      const body = (await response.json()) as { data?: unknown };
      if (Array.isArray(body.data) && body.data.length === 0) {
        body.data = route.request().url().includes('/posts/recent') ? [post] : [board];
      }
      await route.fulfill({ response, json: rewrite(body) });
    } catch {
      // 테스트가 끝나 페이지가 닫힌 뒤 도착한 늦은 요청 — 응답할 곳이 없다
    }
  });
}

/** 홈을 열고 상단 이름이 기대값으로 그려질 때까지 기다린다. */
async function gotoHomeWithName(page: Page, name: string, path = '/'): Promise<void> {
  await page.goto(path);
  await page.waitForFunction(
    (n) => document.querySelector('#mobile_header_left')?.textContent?.includes(n)
      || document.querySelector('header')?.textContent?.includes(n),
    name,
    { timeout: 60_000 },
  );
}

/** 모바일 상단·문서·환영 카드 수치를 잰다. */
async function measureMobile(page: Page) {
  return page.evaluate(() => {
    const doc = document.documentElement;
    const vw = doc.clientWidth;
    const header = document.getElementById('mobile_header')!;
    const right = document.getElementById('mobile_header_right')!;
    const left = document.getElementById('mobile_header_left')!;
    const nameSpan = left.querySelector('span');
    const buttons = [...right.querySelectorAll('button')].filter((b) => b.getBoundingClientRect().width > 0);
    const menu = document.getElementById('mobile_menu_toggle') ?? buttons[buttons.length - 1];
    const mr = menu.getBoundingClientRect();
    const hit = document.elementFromPoint(mr.left + mr.width / 2, mr.top + mr.height / 2);
    const hb = header.getBoundingClientRect();
    const h2 = [...document.querySelectorAll('h2')].find((h) => h.closest('.col-span-2'));
    const card = h2?.closest('.col-span-2') as HTMLElement | undefined;
    return {
      vw,
      docOver: doc.scrollWidth - vw,
      buttonCount: buttons.length,
      maxButtonRight: Math.max(...buttons.map((b) => Math.round(b.getBoundingClientRect().right))),
      menuHit: !!hit && (menu === hit || menu.contains(hit)),
      nameEllipsis: nameSpan ? getComputedStyle(nameSpan).textOverflow : 'no-span',
      nameTop: nameSpan ? Math.round(nameSpan.getBoundingClientRect().top - hb.top) : 0,
      nameBottomOver: nameSpan ? Math.round(nameSpan.getBoundingClientRect().bottom - hb.bottom) : 0,
      h2Over: h2 && card
        ? Math.round(h2.getBoundingClientRect().left + h2.scrollWidth - (card.getBoundingClientRect().right - parseFloat(getComputedStyle(card).paddingRight)))
        : null,
    };
  });
}

test.describe('긴 사이트 이름 — 모바일 상단·홈 환영 카드', () => {
  for (const width of [297, 320, 375, 414]) {
    /**
     * @scenario case=mobile_header_long_site_name
     * @scenario case=home_welcome_card_long_site_name
     * @effects document_no_horizontal_overflow, header_right_buttons_in_viewport, menu_button_hit_target, site_name_single_line_ellipsis, block_text_wraps_within_box
     */
    test(`${width}px 공백 없는 23자 이름: 문서 넘침 0, 우측 단추 화면 안, 이름 한 줄 …`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      await overrideSiteName(page, NAME_NO_SPACE);
      await gotoHomeWithName(page, NAME_NO_SPACE);
      await expect(page.locator('#mobile_header_right')).toBeVisible();

      const m = await measureMobile(page);
      expect(m.docOver).toBe(0);
      expect(m.buttonCount).toBeGreaterThanOrEqual(4);
      expect(m.maxButtonRight).toBeLessThanOrEqual(m.vw);
      expect(m.menuHit).toBe(true);
      expect(m.nameEllipsis).toBe('ellipsis');
      expect(m.nameTop).toBeGreaterThanOrEqual(0);
      expect(m.nameBottomOver).toBeLessThanOrEqual(0);
      expect(m.h2Over).not.toBeNull();
      expect(m.h2Over!).toBeLessThanOrEqual(0);
    });
  }

  /**
   * @scenario case=mobile_header_long_site_name
   * @effects site_name_single_line_ellipsis, header_right_buttons_in_viewport
   */
  test('297px 한글 15자 이름: 고정 높이 헤더를 세로로 넘치지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 297, height: 800 });
    await overrideSiteName(page, NAME_KO);
    await gotoHomeWithName(page, NAME_KO);

    const m = await measureMobile(page);
    expect(m.docOver).toBe(0);
    expect(m.nameTop).toBeGreaterThanOrEqual(0);
    expect(m.nameBottomOver).toBeLessThanOrEqual(0);
    expect(m.maxButtonRight).toBeLessThanOrEqual(m.vw);
  });
});

test.describe('긴 사이트 이름 — PC 헤더', () => {
  /**
   * @scenario case=desktop_header_long_site_name
   * @effects document_no_horizontal_overflow, site_name_single_line_ellipsis, header_right_buttons_in_viewport
   */
  test('1024px 52자 이름: 문서 넘침 0, 우측 액션 화면 안, 이름 …', async ({ page }) => {
    await page.setViewportSize({ width: 1024, height: 800 });
    await overrideSiteName(page, NAME_52);
    await gotoHomeWithName(page, NAME_52);

    const m = await page.evaluate((name) => {
      const doc = document.documentElement;
      const header = document.querySelector('header')!;
      const span = [...header.querySelectorAll('span')].find((s) => s.textContent === name)!;
      const bar = span.closest('button')!.parentElement!;
      const right = bar.lastElementChild as HTMLElement;
      return {
        vw: doc.clientWidth,
        docOver: doc.scrollWidth - doc.clientWidth,
        rightEnd: Math.round(right.getBoundingClientRect().right),
        ellipsis: getComputedStyle(span).textOverflow,
        truncated: span.scrollWidth > span.clientWidth,
      };
    }, NAME_52);
    expect(m.docOver).toBe(0);
    expect(m.rightEnd).toBeLessThanOrEqual(m.vw);
    expect(m.ellipsis).toBe('ellipsis');
    expect(m.truncated).toBe(true);
  });
});

test.describe('긴 게시판 이름 — 홈·게시판 목록·서랍 메뉴', () => {
  for (const width of [297, 1280]) {
    /**
     * @scenario case=home_board_summary_long_name
     * @scenario case=home_popular_boards_long_name
     * @scenario case=home_recent_posts_board_badge
     * @effects document_no_horizontal_overflow, flex_text_shrinks_and_wraps, narrow_label_ellipsis_with_cap
     */
    test(`${width}px 홈: 게시판 이름·배지가 길어도 문서 넘침 0`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await longBoardTexts(page);
      await page.goto('/');
      await page.waitForFunction((t) => document.body.textContent?.includes(t), LONG_TEXT, { timeout: 60_000 });

      const m = await page.evaluate((t) => {
        const doc = document.documentElement;
        const hosts = [...document.querySelectorAll('body *')].filter((el) => el.childElementCount === 0 && el.textContent?.includes(t));
        return {
          docOver: doc.scrollWidth - doc.clientWidth,
          count: hosts.length,
          overflowing: hosts
            .filter((el) => el.getBoundingClientRect().width > 0)
            .filter((el) => el.getBoundingClientRect().right > doc.clientWidth + 1).length,
        };
      }, LONG_TEXT);
      expect(m.count).toBeGreaterThan(0);
      expect(m.docOver).toBe(0);
      expect(m.overflowing).toBe(0);
    });
  }

  /**
   * @scenario case=board_card_long_name
   * @effects document_no_horizontal_overflow, flex_text_shrinks_and_wraps
   */
  test('297px 게시판 목록 카드: 이름이 카드 안에서 줄바꿈된다', async ({ page }) => {
    await page.setViewportSize({ width: 297, height: 900 });
    await longBoardTexts(page);
    await page.goto('/boards');
    await page.waitForFunction((t) => [...document.querySelectorAll('h3')].some((h) => h.textContent === t), LONG_TEXT, { timeout: 60_000 });

    const m = await page.evaluate((t) => {
      const doc = document.documentElement;
      const h3 = [...document.querySelectorAll('h3')].find((h) => h.textContent === t)!;
      const card = h3.closest('button')!;
      return {
        docOver: doc.scrollWidth - doc.clientWidth,
        h3Over: Math.round(h3.getBoundingClientRect().right - card.getBoundingClientRect().right),
        wrapped: h3.getBoundingClientRect().height > parseFloat(getComputedStyle(h3).lineHeight) * 1.5,
      };
    }, LONG_TEXT);
    expect(m.docOver).toBe(0);
    expect(m.h3Over).toBeLessThanOrEqual(0);
    expect(m.wrapped).toBe(true);
  });

  /**
   * @scenario case=drawer_board_menu_long_name
   * @effects narrow_label_ellipsis_with_cap, document_no_horizontal_overflow
   */
  test('297px 서랍 메뉴: 게시판 이름이 한 줄 … 로 서랍 안에 머문다', async ({ page }) => {
    await page.setViewportSize({ width: 297, height: 900 });
    await longBoardTexts(page);
    await page.goto('/');
    const toggle = page.locator('#mobile_menu_toggle');
    await expect(toggle).toBeVisible({ timeout: 60_000 });
    // 엔진이 핸들러를 붙이기 전의 클릭은 무시되므로 열릴 때까지 다시 누른다
    await expect(async () => {
      await toggle.click();
      await expect(toggle).toHaveAttribute('aria-expanded', 'true', { timeout: 1_000 });
    }).toPass({ timeout: 20_000 });
    await page.waitForFunction((t) => [...document.querySelectorAll('span')].some((s) => s.textContent === t && s.getBoundingClientRect().width > 0), LONG_TEXT, { timeout: 20_000 });

    const m = await page.evaluate((t) => {
      const span = [...document.querySelectorAll('span')].find((s) => s.textContent === t && s.getBoundingClientRect().width > 0)!;
      const button = span.closest('button')!;
      return {
        ellipsis: getComputedStyle(span).textOverflow,
        spanOver: Math.round(span.getBoundingClientRect().right - button.getBoundingClientRect().right),
        docOver: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      };
    }, LONG_TEXT);
    expect(m.ellipsis).toBe('ellipsis');
    expect(m.spanOver).toBeLessThanOrEqual(0);
    expect(m.docOver).toBe(0);
  });
});
