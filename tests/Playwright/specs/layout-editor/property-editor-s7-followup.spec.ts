/**
 * Layout Editor — S7 후속 4건 회귀.
 *
 *  A. 정렬 박스(flex) — 노드 파생 판정 + 해제 토글: 비-flex Div 선택 → "정렬 박스로 만들기"
 *     → 컨테이너 컨트롤 + 해제 버튼(만들기 버튼 사라짐) + className 에 flex → 해제 → 원복.
 *  B. 여백 측별 독립(spacing 위젯): 개별 모드에서 상/좌를 서로 다른 값으로 → pt-/pl- 토큰 공존.
 *  C. (A 로 해소) 수평 컨테이너(정렬 박스)에서 좌/우 삽입 어포던스 활성 — block 은 상/하만.
 *  D. 표(Table) 추가 → 머리글/본문 행이 실제로 렌더(빈 table 회귀 차단).
 *
 * @scenario flex_toggle_node_derived + spacing_per_side_independent + insertion_dir_by_flow + table_renders_rows
 * @effects flex_container_controls_and_disable + pt_pl_coexist + left_right_enabled_in_flex + table_tr_td_rendered
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

const CARD = '2.children.5.children.0.children.0.children.1'; // 로그인 카드(Div, 비-flex)

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
}

/** 지정 path 노드를 선택하고 ⓘ → 속성 설정으로 속성 편집 모달을 연다. */
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

test.describe('@layout-editor S7 후속 회귀', () => {
  test('A. 정렬 박스 만들기 → 컨테이너 컨트롤 + 해제 토글로 원복 (노드 파생 판정)', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // 초기: "정렬 박스로 만들기" 버튼, 컨테이너 섹션 없음.
    // 속성 모달은 패치마다 content 를 재마운트하므로(stateful tab → 노드 파생, S7 규율) 버튼이
    // 잠깐 detach/reattach 되어 toBeVisible 의 stable 체크가 흔들릴 수 있다. 존재(attached)만
    // 확인하고, 가시·클릭 가능성은 이어지는 click 의 actionability 자동 대기에 맡긴다.
    await expect(page.getByTestId('g7le-flex-enable')).toBeAttached();
    await expect(page.getByTestId('g7le-flex-container-section')).toHaveCount(0);

    // 만들기 → 컨테이너 컨트롤 + 해제 버튼 (만들기 사라짐), className 에 flex
    await page.getByTestId('g7le-flex-enable').click();
    await expect(page.getByTestId('g7le-flex-container-section')).toBeAttached();
    await expect(page.getByTestId('g7le-flex-disable')).toBeAttached();
    await expect(page.getByTestId('g7le-flex-enable')).toHaveCount(0);
    await expect.poll(() =>
      page.evaluate((p) => document.querySelector(`[data-editor-path="${p}"]`)?.className.split(/\s+/).includes('flex') ?? false, CARD),
    ).toBe(true);

    // 해제 → flex 제거 (되돌리기) → 만들기 버튼 복귀.
    // className 에서 flex 가 제거되는 것을 먼저 기다린 뒤(노드 파생 판정의 입력), 그 파생으로
    // "만들기" 버튼이 다시 렌더되는 것을 확인한다(모달 재마운트 순서 반영).
    await page.getByTestId('g7le-flex-disable').click();
    await expect.poll(() =>
      page.evaluate((p) => document.querySelector(`[data-editor-path="${p}"]`)?.className.split(/\s+/).includes('flex') ?? false, CARD),
    ).toBe(false);
    await expect(page.getByTestId('g7le-flex-enable')).toBeAttached();
  });

  test('B. 여백 개별 모드 — 상/좌를 다른 값으로 → pt-/pl- 토큰 공존', async ({ page }) => {
    await openEditorLogin(page);
    await openPropsFor(page, CARD);

    // padding spacing 위젯(첫 번째)을 개별 모드로 전환
    const widget = page.getByTestId('g7le-widget-spacing').first();
    await expect(widget).toBeVisible();
    await widget.getByTestId('g7le-spacing-mode-sides').click();

    // 상(t) 사용 + range idx4, 좌(l) 사용 + range idx2 (scale 0,1,2,3,4,6,8)
    await widget.getByTestId('g7le-spacing-side-t-enabled').check();
    await widget.getByTestId('g7le-spacing-side-t-range').fill('4');
    await widget.getByTestId('g7le-spacing-side-l-enabled').check();
    await widget.getByTestId('g7le-spacing-side-l-range').fill('2');

    // 카드 className 에 pt-* 와 pl-* 가 공존(서로 다른 측 독립값)
    await expect.poll(() =>
      page.evaluate((p) => {
        const toks = (document.querySelector(`[data-editor-path="${p}"]`)?.className ?? '').split(/\s+/);
        return toks.some((t) => t.startsWith('pt-')) && toks.some((t) => t.startsWith('pl-'));
      }, CARD),
    ).toBe(true);
  });

  test('C. 흐름별 삽입 방향 — flex-row 자식은 좌/우 활성, block 자식은 상/하만', async ({ page }) => {
    await openEditorLogin(page);

    // 카드는 flex-row wrapper 의 자식 → 좌/우 활성
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }, CARD);
    await page.waitForSelector('[data-testid="g7le-insertion-right"]', { timeout: 10_000 });
    expect(await page.getByTestId('g7le-insertion-right').getAttribute('data-disabled')).toBe('false');

    // 카드 본문의 block-flow 자식(폼) → 좌/우 비활성, 상/하 활성
    const formPath = `${CARD}.children.1`;
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
    }, formPath);
    await page.waitForSelector('[data-testid="g7le-insertion-below"]', { timeout: 10_000 });
    expect(await page.getByTestId('g7le-insertion-left').getAttribute('data-disabled')).toBe('true');
    expect(await page.getByTestId('g7le-insertion-below').getAttribute('data-disabled')).toBe('false');
  });

  test('D. 표 추가 → 머리글/본문 행이 실제 렌더(빈 table 회귀 차단)', async ({ page }) => {
    await openEditorLogin(page);

    // 카드 안 h2 선택 → 아래에 삽입 → 팔레트 Table
    const h2 = `${CARD}.children.0`;
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 4, clientY: r.top + 4, view: window }));
    }, h2);
    await page.waitForSelector('[data-testid="g7le-insertion-below"]', { timeout: 10_000 });
    await page.getByTestId('g7le-insertion-below').click();

    await page.waitForSelector('[data-testid="g7le-palette-item-Table"]', { timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-Table').click();

    // 캔버스에 table 이 렌더되고 tr/td 가 비어있지 않아야 한다(빈 table 회귀 차단).
    await expect
      .poll(
        () =>
          page.evaluate(() => {
            const t = document.querySelector('table[data-editor-path]');
            return t ? t.querySelectorAll('tr').length : 0;
          }),
        { timeout: 10_000 },
      )
      .toBe(3);

    const dims = await page.evaluate(() => {
      const t = document.querySelector('table[data-editor-path]')!;
      return { cells: t.querySelectorAll('td,th').length, h: Math.round(t.getBoundingClientRect().height) };
    });
    expect(dims.cells).toBeGreaterThanOrEqual(6);
    expect(dims.h).toBeGreaterThan(0);
  });

  test('E. 표 선택 → 8방향 리사이즈 핸들 노출(크기 조정 가능)', async ({ page }) => {
    await openEditorLogin(page);
    // 표 삽입
    const h2 = `${CARD}.children.0`;
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 4, clientY: r.top + 4, view: window }));
    }, h2);
    await page.waitForSelector('[data-testid="g7le-insertion-below"]', { timeout: 10_000 });
    await page.getByTestId('g7le-insertion-below').click();
    await page.waitForSelector('[data-testid="g7le-palette-item-Table"]', { timeout: 10_000 });
    await page.getByTestId('g7le-palette-item-Table').click();
    await page.waitForFunction(() => !!document.querySelector('table[data-editor-path]'), { timeout: 10_000 });

    // 표 선택
    await page.evaluate(() => {
      const el = document.querySelector('table[data-editor-path]')!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 30, clientY: r.top + 8, view: window }));
    });
    // 8방향 핸들 모두 노출 (width/height 컨트롤 선언 → 리사이즈 가능)
    for (const h of ['nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w']) {
      await expect(page.getByTestId(`g7le-resize-handle-${h}`)).toBeVisible();
    }
  });

  test('F. 선택 시 컴포넌트 타입 식별자 오버레이 — 큰 박스 inside / 작은 박스 outside', async ({ page }) => {
    await openEditorLogin(page);

    // 카드 Div(큰 박스) 선택 → "Div" 라벨, inside 배치
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 10, clientY: r.top + 10, view: window }));
    }, CARD);
    const divLabel = page.getByTestId('g7le-overlay-type-label');
    await expect(divLabel).toHaveText('Div');
    expect(await divLabel.getAttribute('data-placement')).toBe('inside');

    // H2 헤딩(얇은 박스) 선택 → "H2" 라벨, outside 배치(콘텐츠 비가림)
    await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`)!;
      const r = el.getBoundingClientRect();
      for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
        el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 6, clientY: r.top + 6, view: window }));
    }, `${CARD}.children.0`);
    const h2Label = page.getByTestId('g7le-overlay-type-label');
    await expect(h2Label).toHaveText('H2');
    expect(await h2Label.getAttribute('data-placement')).toBe('outside');
  });
});
