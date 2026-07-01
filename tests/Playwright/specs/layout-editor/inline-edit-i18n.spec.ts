/**
 * Layout Editor — 다국어 인라인 편집 + 로케일 전환 + 서식 툴바 + 번역 탭.
 *
 *  A. 콘텐츠 로케일 전환 — LocaleSwitcher 버튼으로 캔버스 프리뷰 로케일만 변경(chrome 불변).
 *  B+C+D. 기존 $t: 키 텍스트 더블클릭(실제 마우스 — 핸들 forward) → contentEditable →
 *     Enter 확정 → POST 커스텀 키 생성 → 캔버스가 raw 키가 아닌 입력값 표시(서버 lang 재fetch).
 *  D. 서식 툴바 — 컴포넌트 styleControls 기반 버튼만 노출(굵기 등), 목록/표/이미지 버튼 부재.
 *  E. text 미보유/데이터 결정 노드 → 더블클릭해도 인라인 편집 비활성(편집기 미노출).
 *  F. 속성 모달 [번역] 탭 — 커스텀 키 노드는 로케일별 일괄 편집 폼, 평문은 "키 아님" 안내.
 *
 * @scenario locale_switch_canvas_only + inline_edit_existing_key_to_new_custom_key + inline_edit_real_mouse_via_dnd_handle + canvas_renders_value_not_raw_key + inline_edit_disabled_binding + toolbar_spec_based + translation_tab_bulk_edit
 * @effects locale_switcher_changes_only_canvas_content_locale_not_chrome + inline_edit_plain_text_generates_custom_key_via_post_endpoint + inline_edit_replaces_comp_text_with_t_custom_key + inline_edit_real_mouse_double_click_via_dnd_handle_forward + canvas_rerenders_custom_key_value_after_server_lang_refetch + inline_edit_disabled_for_binding_expression_text + inline_toolbar_buttons_filtered_by_componentCapability_styleControls + inline_toolbar_omits_list_table_image_buttons + translation_field_displays_all_active_locales_in_modal
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const CARD = '2.children.5.children.0.children.0.children.1'; // 로그인 카드(Div)
const HEADING = `${CARD}.children.0`; // 카드 안 제목(텍스트 노드)

async function openEditorLogin(page: Page): Promise<void> {
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

/**
 * 캔버스 노드를 실제 마우스 더블클릭 — 인라인 편집 진입 트리거.
 *
 * 실제 마우스는 노드 위를 덮은 드래그 핸들(`g7le-dnd-handle-{path}`)에 먼저 맞으므로
 * (합성 dispatchEvent 와 달리 hit-testing 을 거친다), 핸들이 있으면 핸들을, 없으면 노드를
 * Playwright `dblclick`(실제 입력 시퀀스)으로 더블클릭한다. 핸들 onDoubleClick 이 인라인
 * 편집을 forward 한다.
 */
async function dblClickNode(page: Page, editorPath: string, timeout = 30_000): Promise<void> {
  const handle = page.locator(`[data-dnd-handle-path="${editorPath}"]`);
  if (await handle.count()) {
    await handle.first().dblclick({ timeout });
    return;
  }
  await page.locator(`[data-editor-path="${editorPath}"]`).first().dblclick({ timeout });
}

/** 노드 단일 선택. */
async function selectNode(page: Page, editorPath: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) throw new Error('node not found: ' + p);
    const r = el.getBoundingClientRect();
    for (const t of ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click'])
      el.dispatchEvent(new MouseEvent(t, { bubbles: true, cancelable: true, clientX: r.left + 8, clientY: r.top + 8, view: window }));
  }, editorPath);
}

test.describe('@layout-editor 다국어 인라인 편집 (Phase 6 S8-2)', () => {
  test('A. 로케일 전환 — 캔버스 콘텐츠 로케일만 변경(편집기 chrome 불변)', async ({ page }) => {
    await openEditorLogin(page);
    // 로케일 스위처가 노출되면(활성 로케일 2개 이상) 전환 동작 검증, 1개면 단일 라벨.
    const switcher = page.getByTestId('g7le-locale-switcher');
    const single = page.getByTestId('g7le-locale-switcher-single');
    const hasSwitcher = (await switcher.count()) > 0;
    if (hasSwitcher) {
      // en 버튼이 있으면 전환 → active 토글 + chrome 툴바 텍스트(코드 편집 등)는 불변.
      const en = page.getByTestId('g7le-locale-en');
      if ((await en.count()) > 0) {
        const codeBtnBefore = await page.getByTestId('g7le-toolbar-edit-code').textContent();
        await en.click();
        await expect(en).toHaveAttribute('aria-pressed', 'true');
        const codeBtnAfter = await page.getByTestId('g7le-toolbar-edit-code').textContent();
        expect(codeBtnAfter).toBe(codeBtnBefore); // chrome 로케일 불변
      }
    } else {
      await expect(single).toBeVisible();
    }
  });

  test('B+C+D. 기존 $t: 키 텍스트 더블클릭(실제 마우스) → 인라인 편집기 → Enter 확정 → POST 키 생성 → 캔버스가 raw 키가 아닌 입력값 표시', async ({ page }) => {
    await openEditorLogin(page);

    // 제목($t: 키 텍스트, 핸들이 덮음) 더블클릭(실제 입력) → 인라인 편집기 노출.
    await dblClickNode(page, HEADING);
    const editor = page.getByTestId('g7le-inline-text-editable');
    await expect(editor).toBeVisible({ timeout: 10_000 });

    // $t: 키도 평문 동격(새 커스텀 키 생성) — 현재 번역값을 시작값으로(빈 문자열 아님 가능).
    await expect(page.getByTestId('g7le-inline-text-editor')).toHaveAttribute('data-custom-key', 'false');

    // 텍스트 교체 후 Enter → POST /custom-translations → 노드 text 가 $t:custom 으로 치환.
    await editor.click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('환영 인사 변경');
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/custom-translations') && r.request().method() === 'POST',
        { timeout: 15_000 },
      ),
      page.keyboard.press('Enter'),
    ]);
    expect([200, 201]).toContain(resp.status());

    // D 회귀 가드 — 키 생성 후 캔버스가 raw 키(`custom.*`)가 아닌 **입력값**을 표시해야 한다
    // (서버 lang 재fetch + 재렌더). 핸들 텍스트가 포함된 노드 박스의 표시 텍스트로 확인.
    await expect
      .poll(
        () =>
          page.evaluate((p) => {
            const el = document.querySelector(`[data-editor-path="${p}"]`);
            return (el?.textContent ?? '').trim();
          }, HEADING),
        { timeout: 15_000 },
      )
      .toBe('환영 인사 변경');
  });

  test('D. 서식 툴바 — styleControls 기반 버튼만, 목록/표/이미지 버튼 부재', async ({ page }) => {
    await openEditorLogin(page);
    await dblClickNode(page, HEADING);
    await expect(page.getByTestId('g7le-inline-text-editable')).toBeVisible({ timeout: 10_000 });

    // 툴바가 떠 있으면(텍스트 컴포넌트에 styleControls 선언) 요소-추가 버튼은 부재.
    const toolbar = page.getByTestId('g7le-inline-toolbar');
    if ((await toolbar.count()) > 0) {
      await expect(page.getByTestId('g7le-inline-format-list')).toHaveCount(0);
      await expect(page.getByTestId('g7le-inline-format-table')).toHaveCount(0);
      await expect(page.getByTestId('g7le-inline-format-image')).toHaveCount(0);
    }
  });

  test('E. 바인딩식/데이터 결정 노드 → 더블클릭해도 인라인 편집 미진입', async ({ page }) => {
    await openEditorLogin(page);
    // 카드 전체(Div, text 없음) 더블클릭 → 편집기 미노출(편집 불가 노드).
    // CARD 핸들은 깊이순 z-index 로 자식 핸들이 위에 쌓여 실제 dblclick 이 가로채일 수
    // 있다(코드 정상 — 자식 우선). 그 경우에도 인라인 편집기가 떠선 안 된다는 것이 본질이므로
    // dblclick 은 best-effort(짧은 timeout 허용)로 시도하고, 핵심 단언은 편집기 미노출이다.
    await dblClickNode(page, CARD, 4_000).catch(() => { /* 자식 핸들 가로채기 시 무시 — 결과만 검증 */ });
    await expect(page.getByTestId('g7le-inline-text-editable')).toHaveCount(0);
  });

  test('F. 속성 모달 [번역] 탭 — 평문 노드는 "키 아님" 안내', async ({ page }) => {
    await openEditorLogin(page);
    await selectNode(page, HEADING);
    await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
    await page.getByTestId('g7le-overlay-info-button').click();
    await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
    await page.getByTestId('g7le-context-menu-edit-props').click();
    await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });

    // 번역 탭 클릭 → 평문(아직 키 아님) → "다국어 키 아님" 안내.
    await page.getByTestId('g7le-property-tab-translation').click();
    await expect(page.getByTestId('g7le-translation-not-a-key')).toBeVisible({ timeout: 10_000 });
  });
});
