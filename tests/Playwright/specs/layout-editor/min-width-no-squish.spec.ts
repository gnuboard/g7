/**
 * Layout Editor — 대화면 전용 최소 너비 (좁은 창에서 압착 대신 가로 스크롤).
 *
 * 레이아웃 편집기는 라우트 트리(280px 고정) + 디바이스 미리보기 캔버스 + 라벨 있는 툴바
 * 버튼 10여 개가 나란히 놓이는 대화면 전용 도구다. 종전에는 셸에 너비 하한이 없어 창을
 * 좁히면 이들이 창 너비에 맞춰 압착됐다 — 툴바가 flex row 라 각 항목 폭이 깎이고 라벨이
 * 글자 단위로 줄바꿈됐다("요 소 추 가"). 이제 셸이 최소 너비(EDITOR_MIN_WIDTH=1280)를
 * 유지하고, 모자란 폭은 브라우저 가로 스크롤이 흡수한다(반응형 재배치 아님).
 *
 * 본 E2E 가 필요한 이유: 단위 테스트(LayoutEditorChrome.test.tsx)는 jsdom 이라 레이아웃
 * 엔진이 없어 "style 선언이 존재한다" 까지만 잠근다. **실제로 압착이 멈췄는지**(셸이 뷰포트에
 * 눌리지 않는지, 툴바 항목이 자연 폭을 유지하고 줄바꿈되지 않는지)는 실제 렌더 박스를 측정하는
 * 브라우저에서만 증명된다 — 앞의 두 케이스가 그 회귀 가드다(수정 되돌림 시 red 확인).
 *
 * 세 번째 케이스(클리핑 차단)는 이 수정의 회귀 가드가 아니라 그 **전제**를 잠근다 — 상세는
 * 해당 테스트 주석 참조.
 *
 * @scenario editor_min_width + narrow_viewport_no_squish + horizontal_scroll
 * @effects shell_keeps_min_width_below_viewport, toolbar_items_keep_natural_width_no_wrap, narrow_window_produces_horizontal_scroll_not_compression
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

/** 셸 최소 너비 — LayoutEditorChrome.tsx 의 EDITOR_MIN_WIDTH 와 동일 SSoT. */
const EDITOR_MIN_WIDTH = 1280;

/** 압착을 유발하는 좁은 창 — 최소 너비보다 확실히 좁게 잡는다. */
const NARROW_WIDTH = 900;

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-toolbar"]', { timeout: 30_000 });
}

test.describe('레이아웃 편집기 — 좁은 창 압착 차단', () => {
  test('좁은 창에서도 셸이 최소 너비를 유지한다 (압착 없음)', async ({ page }) => {
    await page.setViewportSize({ width: NARROW_WIDTH, height: 900 });
    await gotoEditor(page);

    // 셸의 실제 렌더 폭이 뷰포트로 눌리지 않고 최소 너비 이상을 유지한다.
    const shellWidth = await page
      .getByTestId('g7le-chrome')
      .evaluate((el) => el.getBoundingClientRect().width);
    expect(shellWidth).toBeGreaterThanOrEqual(EDITOR_MIN_WIDTH);
  });

  test('좁은 창에서 툴바 항목이 깎이거나 줄바꿈되지 않는다', async ({ page }) => {
    // 넓은 창에서 각 툴바 항목의 자연 폭·높이를 먼저 측정한다.
    await page.setViewportSize({ width: 1600, height: 900 });
    await gotoEditor(page);
    const wide = await measureToolbarItems(page);
    expect(wide.length).toBeGreaterThan(1);

    // 창을 최소 너비 아래로 좁혀도 각 항목의 폭·높이가 그대로여야 한다.
    // 압착되면 폭이 줄고, 라벨이 글자 단위로 줄바꿈되며 높이가 늘어난다("요 소 추 가").
    await page.setViewportSize({ width: NARROW_WIDTH, height: 900 });
    await page.waitForTimeout(300); // 리사이즈 후 레이아웃 확정
    const narrow = await measureToolbarItems(page);

    // 절대 임계값(예: width > 40)은 거짓 통과를 만든다 — 같은 항목의 두 상태를 직접 비교한다.
    expect(narrow).toEqual(wide);
  });

  // 주의: 본 케이스는 최소 너비 수정의 **회귀 가드가 아니다** — 수정 전에도 캔버스 프레임
  // (데스크톱 1280px)이 좁은 창을 넘쳐 가로 스크롤 자체는 존재했다(수정 되돌림 시 green 유지 확인).
  // 이 케이스가 잠그는 것은 별개의 전제다: 넘친 폭이 `overflow-x: hidden` 으로 **잘리지 않고**
  // 스크롤로 접근 가능해야 최소 너비 설계가 성립한다. 호스트/템플릿 CSS 가 나중에 body 나
  // #app 에 overflow-x:hidden 을 걸면 편집기 우측이 영영 닿을 수 없게 되는데, 그것을 여기서 잡는다.
  test('넘친 폭이 잘리지 않고 가로 스크롤로 접근 가능하다 (클리핑 차단)', async ({ page }) => {
    await page.setViewportSize({ width: NARROW_WIDTH, height: 900 });
    await gotoEditor(page);

    // 문서가 뷰포트보다 넓어야(= 가로 스크롤 가능) 편집기 전체를 스크롤로 볼 수 있다.
    // overflow-x:hidden 으로 잘리면 scrollWidth == clientWidth 라 우측이 영영 접근 불가.
    const { scrollWidth, clientWidth } = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
    }));
    expect(scrollWidth).toBeGreaterThan(clientWidth);

    // 실제로 우측 끝까지 스크롤되는지 확인 — 스크롤이 가능하다는 선언만으로는 부족하다.
    await page.evaluate(() => window.scrollTo(document.documentElement.scrollWidth, 0));
    const scrolledX = await page.evaluate(() => window.scrollX);
    expect(scrolledX).toBeGreaterThan(0);
  });
});

/**
 * 툴바 직접 자식 중 **실제 렌더 박스를 가진 항목**의 폭·높이(정수 반올림).
 *
 * 압착되면 폭이 줄고(flex-shrink), 라벨이 글자 단위로 줄바꿈되면 높이가 는다 — 두 값이
 * 창 크기와 무관하게 동일해야 압착이 없는 것이다.
 *
 * 박스 없는 노드(`<style>` 태그, 조건부 미표시 요소 등)는 제외한다. 이들은 창 크기와
 * 무관하게 0×0 이라 비교에 의미가 없고, 포함하면 측정 노이즈만 만든다.
 */
async function measureToolbarItems(page: Page): Promise<Array<{ w: number; h: number }>> {
  return page.getByTestId('g7le-toolbar').evaluate((toolbar) =>
    Array.from(toolbar.children)
      .map((el) => {
        const r = el.getBoundingClientRect();
        return { w: Math.round(r.width), h: Math.round(r.height) };
      })
      .filter((box) => box.w > 0 && box.h > 0),
  );
}
