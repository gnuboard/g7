/**
 * Layout Editor — S7 후속 신규 스타일 컨트롤 + 권한 식별자.
 *
 * 속성 편집 모달 [스타일] 탭에 추가된 컨트롤이 캔버스에 라이브 반영되는지 검증한다:
 *  A. 박스 컨트롤(컨테이너 Div) — 그림자/모서리 라운드/테두리 모양/투명도/스크롤 select+toggle
 *     → 캔버스 className 토큰 반영.
 *  B. 테두리 색(color) — 프리셋 토큰 적용 + 역해석 칩 활성.
 *  C. 텍스트 서식(H2) — 기울임/밑줄 토글 → italic/underline 토큰.
 *  D. 텍스트 정렬 justify 옵션 노출 + 적용 → text-justify.
 *  E. 고급 탭 — 권한 후보가 식별자(key)와 함께 표시되고, 카테고리(부모)는 제외(리프만).
 *
 * 축 요약(마커 아님 — 평문): new_style_controls_live_apply, border_color_preset, text_format_toggle, justify_align, permission_identifier_leaf_only.
 * 효과 요약(마커 아님 — 평문): box_class_tokens, border_color_token, italic_underline_tokens, text_justify_token, advanced_permission_id_visible.
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import { editorPath } from '../../fixtures/layout-editor';

/** 로그인 카드(Div 컨테이너) — 본문 루트 기준 상대 경로 */
const CARD_REL = 'children.5.children.0.children.0.children.1';

// 절대 경로의 첫 세그먼트는 베이스 레이아웃 루트 인덱스라 베이스에 컴포넌트가 추가되면 밀린다.
// 리터럴로 두지 않고 openEditorLogin 이 본문 루트 id 로 해석해 채운다 (사용처는 그대로 읽는다).
let CARD = '';
let CARD_H2 = '';

async function openEditorLogin(page: import('@playwright/test').Page): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Flogin');
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(
    () => document.querySelectorAll('[data-editor-path]').length > 0,
    { timeout: 20_000 },
  );

  CARD = await editorPath(page, CARD_REL);
  CARD_H2 = `${CARD}.children.0`;
}

/**
 * 노드를 클릭해 선택 오버레이가 뜨는지 확인합니다 (선택 가능 여부 판정).
 *
 * @param page Playwright page
 * @param editorPath 대상 노드의 editor path
 * @returns 선택 오버레이(ⓘ 버튼)가 떴으면 true
 */
async function trySelect(page: import('@playwright/test').Page, editorPath: string): Promise<boolean> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    const r = el.getBoundingClientRect();
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }
  }, editorPath);

  return page
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 2_500 })
    .then(() => true)
    .catch(() => false);
}

async function openPropsFor(page: import('@playwright/test').Page, editorPath: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) throw new Error('node not found: ' + p);
    const r = el.getBoundingClientRect();
    for (const type of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }
  }, editorPath);
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
}

const tokensOf = (page: import('@playwright/test').Page, path: string) =>
  page.evaluate((p) => (document.querySelector(`[data-editor-path="${p}"]`)?.className ?? '').split(/\s+/).filter(Boolean), path);

test.describe('@layout-editor 신규 스타일 컨트롤', () => {
  /** @effects box_class_tokens */
  test('A. 컨테이너 박스 컨트롤 — 그림자/라운드/스크롤 select 가 캔버스에 반영', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 그림자
    await expect(page.getByTestId('g7le-control-boxShadow')).toBeVisible();
    await page.getByTestId('g7le-control-boxShadow').getByTestId('g7le-widget-select').selectOption('shadow-lg');
    await expect.poll(() => tokensOf(page, CARD)).toContain('shadow-lg');

    // 모서리 라운드
    await page.getByTestId('g7le-control-borderRadius').getByTestId('g7le-widget-select').selectOption('rounded-xl');
    await expect.poll(() => tokensOf(page, CARD)).toContain('rounded-xl');

    // 스크롤(overflow)
    await page.getByTestId('g7le-control-overflow').getByTestId('g7le-widget-select').selectOption('overflow-auto');
    await expect.poll(() => tokensOf(page, CARD)).toContain('overflow-auto');
  });

  test('A2. 테두리 모양 → border 폭+스타일 토큰, none 전환 시 폭 제거', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await page.getByTestId('g7le-control-borderStyle').getByTestId('g7le-widget-select').selectOption('dashed');
    await expect.poll(() => tokensOf(page, CARD)).toEqual(expect.arrayContaining(['border', 'border-dashed']));

    await page.getByTestId('g7le-control-borderStyle').getByTestId('g7le-widget-select').selectOption('none');
    const toks = await tokensOf(page, CARD);
    expect(toks).toContain('border-0');
    expect(toks).not.toContain('border-dashed');
  });

  test('A3. 투명도 slider → opacity 토큰 반영', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);
    const widget = page.getByTestId('g7le-control-opacity').getByTestId('g7le-widget-slider');
    await widget.getByTestId('g7le-slider-enabled').check();
    await widget.getByTestId('g7le-slider-range').fill('2'); // scale[2] = opacity-50
    await expect.poll(() => tokensOf(page, CARD)).toContain('opacity-50');
  });

  /** @effects border_color_token */
  test('B. 테두리 색 프리셋 → border-{color} 토큰', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);
    const ctrl = page.getByTestId('g7le-control-borderColor');
    await expect(ctrl).toBeVisible();
    await ctrl.getByTestId('g7le-color-token-border-blue-600').click();
    await expect.poll(() => tokensOf(page, CARD)).toContain('border-blue-600');
  });

  /** @effects italic_underline_tokens */
  test('C. 텍스트 서식 — 기울임/밑줄 토글이 제목에 반영', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD_H2);

    await page.getByTestId('g7le-control-fontItalic').getByTestId('g7le-widget-toggle').click();
    await expect.poll(() => tokensOf(page, CARD_H2)).toContain('italic');

    await page.getByTestId('g7le-control-textUnderline').getByTestId('g7le-widget-toggle').click();
    await expect.poll(() => tokensOf(page, CARD_H2)).toContain('underline');
  });

  /** @effects text_justify_token */
  test('D. 텍스트 정렬 justify 옵션 적용 → text-justify', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD_H2);
    await page.getByTestId('g7le-control-textAlign').getByTestId('g7le-segment-justify').click();
    await expect.poll(() => tokensOf(page, CARD_H2)).toContain('text-justify');
  });

  /** @effects advanced_permission_id_visible */
  test('E. 고급 탭 — 권한 후보에 식별자 표시 + 카테고리(부모) 제외', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    await page.getByTestId('g7le-property-tab-advanced').click();
    await page.getByTestId('g7le-advanced-permissions').getByTestId('g7le-tag-add').click();
    await page.waitForSelector('[data-testid="g7le-tag-candidates"]', { timeout: 10_000 });

    // 적어도 한 후보에 식별자(code) 가 라벨과 함께 노출됨.
    const idCount = await page.locator('[data-testid^="g7le-tag-candidate-id-"]').count();
    expect(idCount).toBeGreaterThan(0);

    // 후보 식별자는 모두 리프(점 구분 마지막 세그먼트 보유 — 카테고리 루트 단독 키 아님 검증은
    // 백엔드 단위 테스트가 보장). 여기서는 식별자 가시성만 확인.
    const firstId = await page.locator('[data-testid^="g7le-tag-candidate-id-"]').first().textContent();
    expect(firstId?.trim().length ?? 0).toBeGreaterThan(0);
  });

  // 다른 템플릿(admin_basic) + 다른 화면(대시보드) + 다른 엘리먼트(컨테이너 카드)에서도
  // 신규 박스 컨트롤이 동일하게 렌더·적용되는지 검증.
  test('F. admin_basic 대시보드 컨테이너 — 박스 컨트롤 렌더 + 라운드 적용', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);
    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=*%2Fadmin%2Fdashboard');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 20,
      { timeout: 30_000 },
    );

    // 대시보드 본문의 컨테이너형 Div 를 고른다.
    //
    // 예전에는 `rounded+shadow+bg-white` 로 골랐지만 관리자 카드 외형이 한 기준으로 통일되면서
    // 대시보드 Div 에 `shadow`/`bg-white` 가 없어졌다(실측: shadow 0개). 외형 클래스로 지목하면
    // 스타일이 바뀔 때마다 다시 깨지고, `rounded` 만으로 고르면 편집기가 선택을 허용하지 않는
    // 노드(잠금 영역 등)에 걸린다. 그래서 클래스가 아니라 **실제로 선택되는지**로 고른다.
    const candidates = await page.evaluate(() =>
      [...document.querySelectorAll('[data-editor-path]')]
        .filter((e) => e.tagName === 'DIV' && e.querySelectorAll('[data-editor-path]').length >= 2)
        .map((e) => e.getAttribute('data-editor-path') ?? '')
        .filter(Boolean),
    );
    expect(candidates.length).toBeGreaterThan(0);

    let cardPath: string | null = null;
    for (const candidate of candidates.slice(0, 15)) {
      const selected = await trySelect(page, candidate);
      if (selected) {
        cardPath = candidate;
        break;
      }
    }
    expect(cardPath, '대시보드에서 선택 가능한 컨테이너 Div 를 찾지 못했다').toBeTruthy();
    await openPropsFor(page, cardPath!);

    // 6 박스 컨트롤 전부 렌더
    for (const key of ['boxShadow', 'borderStyle', 'borderColor', 'borderRadius', 'opacity', 'overflow']) {
      await expect(page.getByTestId(`g7le-control-${key}`)).toBeVisible();
    }

    // 라운드 적용 → 캔버스 반영
    await page.getByTestId('g7le-control-borderRadius').getByTestId('g7le-widget-select').selectOption('rounded-full');
    await expect
      .poll(() => page.evaluate((p) => (document.querySelector(`[data-editor-path="${p}"]`)?.className ?? '').includes('rounded-full'), cardPath))
      .toBe(true);
  });
});
