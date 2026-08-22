/**
 * sirsoft-basic 모바일 뷰포트 가로 오버플로 회귀 E2E.
 *
 * 배경 (390x844 실측):
 * - 헤더 우측 그룹에 언어(71px)+통화(91px)가 있어 26개 라우트 중 24개에서
 *   document.scrollWidth = 401px (뷰포트 390px 대비 +11px).
 *   320px(iPhone SE)에서는 햄버거 버튼이 화면 밖으로 밀려 내비게이션 자체가 불가능했다.
 * - 비회원 댓글폼: 아바타 76px 들여쓰기 + flex-1 입력 2개 → 문서 +81px 초과
 * - 상품상세 쿠폰 칩: 라벨에 whitespace-nowrap 이 없어 39px 폭에서 2줄로 줄바꿈
 *
 * 조치:
 * - 언어/통화를 모바일 드로어(mobile_drawer_prefs)로 이동, 가로 칩으로 나열
 * - 통화/배송국가 주입 조각은 responsive.portable 로 모바일에서만 static 인라인 목록
 * - 데스크톱(≥1024px)은 기존 헤더 드롭다운 유지
 *
 * 후속 (드로어 세로 길이):
 * - 언어 / 통화·배송국가를 각각 독립 아코디언으로 접는다(기본 접힘). 접힘 상태에서도
 *   트리거에 현재값(언어명 / 통화·배송국가)을 요약 표기해 펼치지 않고 알 수 있게 한다.
 * - 언어=템플릿 소유(_global.mobileLanguageOpen), 통화=주입 조각 소유(_local.showCurrencyDropdown).
 *
 * 단위 테스트(Vitest)는 레이아웃 JSON 구조만 본다. 실제 브라우저 폭·줄 수·가시성은
 * 여기서만 검증된다 (위지윅 발행 회귀 #238 교훈).
 */
import { test, expect, type Page } from '@playwright/test';

/** 문서가 뷰포트보다 가로로 넘치는 px (0 이어야 정상) */
async function docOverflow(page: Page): Promise<number> {
  return page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth
  );
}

/**
 * 뷰포트를 벗어난 가시 요소 목록.
 *
 * 제외 대상 (자기 자신부터 조상까지 거슬러 올라가며 판정):
 *  - 숨김 요소(display/visibility/opacity)
 *  - 화면 밖으로 밀어둔 off-canvas 서브트리 — 닫힌 드로어(`translate-x-full`)
 *  - `position: fixed` 서브트리 — 문서 스크롤 폭에 기여하지 않는다.
 *    (예: PageTransitionIndicator 의 로딩 바는 translateX 애니메이션으로 한때
 *     `right > VW` 가 되지만 fixed 라 문서를 넓히지 않는다)
 *  - 조상이 `overflow-x: hidden|clip` 으로 잘라내는 요소
 *
 * 문서 전체 오버플로는 `docOverflow()` 가 별도로 본다. 이 함수는 "어떤 요소가 범인인가"를
 * 진단하기 위한 것이다.
 */
async function overflowingElements(page: Page): Promise<Array<{ id: string; cls: string }>> {
  return page.evaluate(() => {
    const VW = document.documentElement.clientWidth;
    const isExcluded = (el: Element): boolean => {
      let n: Element | null = el;
      while (n && n !== document.documentElement) {
        const cs = getComputedStyle(n);
        if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) {
          return true;
        }
        if (cs.position === 'fixed') return true;
        if (n !== el && /hidden|clip/.test(cs.overflowX)) return true;
        const r = n.getBoundingClientRect();
        if (r.width > 0 && (r.left >= VW - 1 || r.right <= 1)) return true;
        n = n.parentElement;
      }
      return false;
    };
    return [...document.body.querySelectorAll('*')]
      .filter((el) => {
        const r = el.getBoundingClientRect();
        if (!r.width || !r.height || isExcluded(el)) return false;
        return r.right > VW + 1 || r.left < -1;
      })
      .map((el) => ({ id: el.id, cls: String(el.className).slice(0, 60) }));
  });
}

test.describe('모바일 헤더/드로어 (390px)', () => {
  test('@smoke 홈에서 문서가 가로로 넘치지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await expect(page.locator('#mobile_menu_toggle')).toBeVisible({ timeout: 15_000 });

    expect(await docOverflow(page)).toBe(0);
    expect(await overflowingElements(page)).toEqual([]);
  });

  test('헤더 우측 그룹에 언어/통화 셀렉터가 없다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await expect(page.locator('#mobile_header_right')).toBeVisible({ timeout: 15_000 });

    await expect(page.locator('#mobile_header_right #mobile_lang_selector_wrap')).toHaveCount(0);
    await expect(page.locator('#mobile_header_right #mobile_currency_selector_wrap')).toHaveCount(0);
  });

  /**
   * 드로어 선호설정은 언어 / 통화·배송국가 두 개의 독립 아코디언이며 기본은 접힘이다.
   * 전부 펼쳐 두면 드로어가 세로로 길어져 정작 메뉴가 스크롤 밖으로 밀린다.
   */
  test('드로어 선호설정은 기본 접힘이고 트리거에 현재값을 요약 표기한다 (비회원 포함)', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.locator('#mobile_menu_toggle').click();

    const prefs = page.locator('#mobile_drawer_prefs');
    await expect(prefs).toBeVisible();

    // 언어: 접힘 (트리거 aria-expanded=false, 잘림 래퍼 높이 0)
    const langToggle = page.locator('#mobile_drawer_language_toggle');
    await expect(langToggle).toHaveAttribute('aria-expanded', 'false');
    const langBodyHeight = await page.evaluate(() => {
      const body = document
        .querySelector('#mobile_drawer_language_toggle')
        ?.parentElement?.querySelector('.overflow-hidden');
      return body ? Math.round(body.getBoundingClientRect().height) : -1;
    });
    expect(langBodyHeight).toBe(0);

    // 접힘 상태 요약 = 현재 선택된 언어 칩의 이름 (세션 로케일 무관)
    const summary = await page.evaluate(() => {
      const toggle = document.querySelector('#mobile_drawer_language_toggle');
      const selected = document.querySelector('#mobile_drawer_language [role="option"][aria-selected="true"]');
      if (!toggle || !selected) return null;
      return { toggleText: (toggle.textContent ?? '').trim(), chipName: (selected.textContent ?? '').trim() };
    });
    expect(summary).not.toBeNull();
    expect(summary!.toggleText).toContain(summary!.chipName);

    // 통화 슬롯: 이커머스 모듈 주입 + id 스코프. 접힘 → 트리거는 보이고 패널은 언마운트.
    const currencyRoot = page.locator('#mobile_drawer_currency_wrap [id^="ext_header_currency_selector"]');
    await expect(currencyRoot).toBeVisible();
    const trigger = currencyRoot.locator('button[aria-haspopup="listbox"]');
    await expect(trigger).toBeVisible();
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
    await expect(currencyRoot.locator('[role="listbox"]')).toHaveCount(0);

    expect(await docOverflow(page)).toBe(0);
  });

  test('아코디언을 펼치면 언어/통화 칩이 가로로 나열되고 뷰포트를 넘지 않는다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.locator('#mobile_menu_toggle').click();

    const prefs = page.locator('#mobile_drawer_prefs');
    await expect(prefs).toBeVisible();

    // 언어 펼치기 — iteration 이 Button 에 걸려 있어야 칩이 가로로 나열된다
    await page.locator('#mobile_drawer_language_toggle').click();
    const langChips = prefs.locator('button[role="option"]:not(#mobile_drawer_currency_wrap button)');
    expect(await langChips.count()).toBeGreaterThan(0);

    // 통화 펼치기 — portable 에서는 absolute 팝오버가 아니라 static 인라인 목록
    const currencyRoot = page.locator('#mobile_drawer_currency_wrap [id^="ext_header_currency_selector"]');
    await currencyRoot.locator('button[aria-haspopup="listbox"]').click();
    const panel = currencyRoot.locator('[role="listbox"]');
    await expect(panel).toBeVisible();
    await expect(panel).toHaveClass(/\bstatic\b/);

    // 모든 옵션이 칩(rounded-full) 이고 뷰포트를 넘지 않는다
    const chips = currencyRoot.locator('button[role="option"]');
    const n = await chips.count();
    expect(n).toBeGreaterThan(0);
    for (let i = 0; i < n; i += 1) {
      await expect(chips.nth(i)).toHaveClass(/rounded-full/);
    }

    // 드로어 안 인라인 아코디언이라 배경 오버레이(fixed inset-0)는 렌더되지 않는다.
    // 렌더되면 드로어 전체 클릭을 가로챈다.
    const backdrops = await page.evaluate(
      () => document.querySelectorAll('#mobile_drawer_prefs .fixed.inset-0').length,
    );
    expect(backdrops).toBe(0);

    expect(await docOverflow(page)).toBe(0);
  });

  test('언어와 통화 아코디언은 독립적으로 개폐된다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    await page.locator('#mobile_menu_toggle').click();

    const langToggle = page.locator('#mobile_drawer_language_toggle');
    const currencyRoot = page.locator('#mobile_drawer_currency_wrap [id^="ext_header_currency_selector"]');
    const trigger = currencyRoot.locator('button[aria-haspopup="listbox"]');

    await langToggle.click();
    await trigger.click();
    await expect(langToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');

    // 언어만 닫는다 → 통화는 열린 채 유지
    await langToggle.click();
    await expect(langToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(currencyRoot.locator('[role="listbox"]')).toHaveCount(1);
  });

  test('320px 에서도 햄버거 버튼을 누를 수 있다 (내비게이션 접근성)', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 844 });
    await page.goto('/');

    const toggle = page.locator('#mobile_menu_toggle');
    // toBeInViewport 는 요소 존재를 기다리지 않는다(기본 5s 안에 하이드레이션이 끝나지 않으면
    // element(s) not found). 병렬 워커 부하에서 플레이키했으므로 먼저 가시성을 기다린다.
    await expect(toggle).toBeVisible({ timeout: 15_000 });
    await expect(toggle).toBeInViewport();
    expect(await docOverflow(page)).toBe(0);

    await toggle.click();
    await expect(page.locator('#mobile_drawer_prefs')).toBeVisible();
    expect(await docOverflow(page)).toBe(0);
  });
});

test.describe('데스크톱 회귀 방지 (1280px)', () => {
  test('헤더 통화 셀렉터는 여전히 absolute 드롭다운이다', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');

    const root = page.locator('#ext_header_currency_selector__header_currency_slot_desktop');
    const trigger = root.locator('button[aria-haspopup="listbox"]');
    await expect(trigger).toBeVisible({ timeout: 15_000 });

    await trigger.click();
    const panel = root.locator('[role="listbox"]');
    await expect(panel).toBeVisible();
    await expect(panel).toHaveClass(/\babsolute\b/);
    await expect(panel).toHaveClass(/\bz-50\b/);

    // 세로 목록 — 칩이 아니다
    const options = panel.locator('button[role="option"]');
    expect(await options.count()).toBeGreaterThan(0);
    await expect(options.first()).not.toHaveClass(/rounded-full/);

    expect(await docOverflow(page)).toBe(0);
  });
});

test.describe('비회원 폼/칩 (390px)', () => {
  test('게시판 글쓰기폼의 이름/비밀번호가 세로로 쌓인다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/board/free/write');

    const pw = page.locator('input[type="password"]').first();
    await expect(pw).toBeVisible({ timeout: 15_000 });

    // 압착(147px) 이 아니라 전체폭에 가까워야 한다
    const box = await pw.boundingBox();
    expect(box!.width).toBeGreaterThan(250);
    expect(await docOverflow(page)).toBe(0);
  });

  test('상품 상세의 쿠폰 칩 라벨이 한 줄을 유지한다', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/shop/products/105');

    // 쿠폰 칩 라벨은 whitespace-nowrap Span. 상품에 다운로드 가능 쿠폰이 없으면 렌더되지 않는다.
    const labels = page.locator('button span.whitespace-nowrap');
    await page.waitForLoadState('networkidle');
    const count = await labels.count();
    test.skip(count === 0, '이 상품에 다운로드 가능한 쿠폰이 없다');

    for (let i = 0; i < count; i += 1) {
      const lines = await labels.nth(i).evaluate((el) => {
        const lh = parseFloat(getComputedStyle(el).lineHeight) || 16;
        return Math.max(1, Math.round(el.getBoundingClientRect().height / lh));
      });
      expect(lines).toBe(1);
    }

    // 칩 컨테이너는 가로 스크롤이 아니라 줄바꿈으로 흘린다.
    // (컨테이너 > [ticket Icon, iteration Div ×N, 더보기 Button] > Button > Span 구조라
    //  DOM 깊이가 가변적이므로 조상 체인을 올라가며 flex 컨테이너를 찾는다)
    const containerInfo = await labels.first().evaluate((el) => {
      // 칩 Button 자신도 inline-flex 이므로, wrap 을 켠 첫 조상(=칩 컨테이너)까지 올라간다
      let n: HTMLElement | null = el.parentElement;
      while (n && n !== document.body) {
        const cs = getComputedStyle(n);
        if (cs.flexWrap === 'wrap') {
          return { flexWrap: cs.flexWrap, overflowX: cs.overflowX };
        }
        n = n.parentElement;
      }
      return null;
    });
    expect(containerInfo).not.toBeNull();
    expect(containerInfo!.overflowX).not.toBe('auto');

    expect(await docOverflow(page)).toBe(0);
  });
});
