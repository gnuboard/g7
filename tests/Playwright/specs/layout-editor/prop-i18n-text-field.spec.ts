/**
 * Layout Editor — 텍스트 propControl·children 항목 텍스트 동적 다국어 위젯.
 *
 * 7-a 가 깐 공통 위젯 `I18nTextField`(A. 현재 로케일 미리보기 + B. 🌐 ko/en/ja 펼침 폼 +
 * C. `{{...}}` 바인딩 읽기전용)를 7-b 에서 (1) 속성 패널의 텍스트 propControl(placeholder/
 * label/helper/alt/제목 등 `widget:"i18n-text"` + `apply:propValue`) 과 (2) children 노드
 * 에디터의 **항목 텍스트** 양쪽에 적용했다. 본 E2E 는 라이브 편집기에서 §공통 검증 표준의
 * 핵심 경로를 브라우저로 확인한다(empty-container-form-propcontrols.spec.ts 의 검증된 add→select
 * 패턴 재사용 — 갓 추가한 노드를 안정 선택):
 *
 *  1. 빈 Div 에 Input 추가 → 속성 탭의 placeholder propControl 이 raw `$t:` 키가 아닌
 *     **해석값 미리보기 input + 🌐 토글** 로 렌더(평문 input 아님).
 *  2. 평문 입력 → blur → POST `/custom-translations`(키 자동 생성) → 위젯이 키 모델로 전환.
 *  3. 🌐 펼침 → ko/en/ja 일괄 편집 폼(미번역 로케일 마크) 노출.
 *  4. 빈 Div 에 Ul 추가 → children 항목 텍스트도 동일 공통 위젯으로 편집(평문→키 생성).
 *  5. 저장 → 레이아웃 PUT 200(토큰이 content 에 영속).
 *
 * 언어별 캔버스/실사용자화면 렌더 round-trip 은 단위(prop-i18n-text-field.test.tsx /
 * ChildrenListControl.test.tsx)가 분류·키 round-trip 을, inline-edit-i18n.spec.ts 가 캔버스
 * 재렌더(raw 키 아님)를 잠근다. 본 spec 은 propControl·항목 텍스트 위젯이 실제 편집기에서
 * raw 키 미노출·키 생성·펼침 폼·저장 영속을 보장하는지 확인한다.
 *
 * (2026-06-11) — 펼침(🌐)이 속성 창 [번역] 탭과 **같은 컴포넌트**(TranslationField)를
 * 공유하도록 통합돼, 펼침부 testid 가 `g7le-i18n-text-field-expand-*` → `g7le-translation-*`(고정)로
 * 바뀌었다. 더해 데이터가 든 키는 칸자리에 **항상 칩**(PlaceholderChipInput)으로 렌더하고 `+데이터`로
 * 글자 위치에 데이터를 끼워 키화한다. 칸자리 칩/`+데이터`는 contentEditable·합성 PointerEvent 의존이라
 * Playwright 부적합(정책 — 본 파일 외 칩 위젯과 동일) → Chrome MCP 매트릭스(27케이스) +
 * 단위(prop-i18n-text-field.test 칸자리 칩 4건)로 검증한다. 본 spec 은 종전대로 평문 미리보기 input·키
 * 생성·펼침 폼 노출·저장 영속(비-칩 경로)을 라이브로 잠근다.
 *
 * @scenario prop_i18n_text_field_inline_preview + prop_i18n_create_key_via_post + prop_i18n_expand_ko_en_ja + children_item_text_i18n_widget + token_persists_on_save
 * @effects text_propcontrol_renders_i18n_widget_not_raw_key_input, prop_i18n_plain_input_creates_custom_key_via_post, prop_i18n_expand_shows_all_active_locales_bulk_form, children_item_text_uses_shared_i18n_widget_ssot, custom_key_token_recorded_in_prop_value_and_node_text_persists_on_put
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoEditor(page: Page, route = '%2F'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

/** 합성 pointer/click 으로 노드 선택 → overlay info 버튼 등장 대기. */
async function selectByPath(page: Page, path: string): Promise<boolean> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + Math.min(r.width / 2, 10);
    const cy = r.top + Math.min(r.height / 2, 10);
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
  return page
    .waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 5_000 })
    .then(() => true)
    .catch(() => false);
}

async function openPaletteItems(page: Page): Promise<void> {
  await page.waitForFunction(
    () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
    { timeout: 10_000 },
  );
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid^="g7le-palette-item-"]', { timeout: 10_000 });
}

async function clickPaletteItem(page: Page, name: string): Promise<void> {
  await page.getByTestId(`g7le-palette-item-${name}`).click();
  await page.waitForTimeout(400);
}

/** home content root 안에 빈 Div 추가 후 그 path 반환(검증된 패턴). */
async function addEmptyDiv(page: Page): Promise<string> {
  const contentRoot = '2.children.5.children.0.children.0';
  expect(await selectByPath(page, contentRoot)).toBe(true);
  await openPaletteItems(page);
  await clickPaletteItem(page, 'Div');
  return page.evaluate((C) => {
    const re = new RegExp('^' + C.replace(/\./g, '\\.') + '\\.children\\.\\d+$');
    const idxs = Array.from(document.querySelectorAll('[data-editor-path]'))
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter((p) => re.test(p))
      .map((p) => parseInt(p.split('.').pop() as string, 10));
    return C + '.children.' + Math.max(...idxs);
  }, contentRoot);
}

/** 선택된 노드의 속성 탭 열기. */
async function openPropsTab(page: Page): Promise<void> {
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-tab-props"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-props').click();
  await page.waitForTimeout(200);
}

test.describe('@layout-editor 텍스트 propControl 동적 다국어 (부록7 7-b)', () => {
  test('Input placeholder propControl 이 raw 키 아닌 i18n 위젯(미리보기+🌐)으로 렌더되고 평문 입력→키 생성→펼침', async ({ page }) => {
    await gotoEditor(page);
    const vdiv = await addEmptyDiv(page);

    // 빈 Div 안에 Input 추가.
    expect(await selectByPath(page, vdiv)).toBe(true);
    await openPaletteItems(page);
    await clickPaletteItem(page, 'Input');
    const inputPath = `${vdiv}.children.0`;

    // Input 속성 탭 → placeholder(i18n-text+propValue) = I18nTextField.
    expect(await selectByPath(page, inputPath)).toBe(true);
    await openPropsTab(page);

    const preview = page.getByTestId('g7le-prop-i18n-inputPlaceholder-preview');
    const toggle = page.getByTestId('g7le-prop-i18n-inputPlaceholder-toggle');
    await expect(preview).toBeVisible({ timeout: 10_000 });
    await expect(toggle).toBeVisible();
    // raw `$t:` 키가 미리보기 값으로 노출되지 않는다.
    expect(await preview.inputValue()).not.toMatch(/^\$t:/);

    // 평문 입력 → blur → POST 키 생성.
    await preview.click();
    await preview.fill('이메일을 입력하세요');
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/custom-translations') && r.request().method() === 'POST',
        { timeout: 15_000 },
      ),
      preview.blur(),
    ]);
    expect([200, 201]).toContain(resp.status());

    // 키 생성 후 🌐 펼침 → ko/en/ja 일괄 편집 폼.
    await page.getByTestId('g7le-prop-i18n-inputPlaceholder-toggle').click();
    await expect(page.getByTestId('g7le-prop-i18n-inputPlaceholder-expand')).toBeVisible({ timeout: 10_000 });
    await expect(page.getByTestId('g7le-prop-i18n-inputPlaceholder-expand-input-ko')).toHaveValue('이메일을 입력하세요');
  });

  test('children 항목 텍스트도 동일 i18n 위젯으로 편집(평문→키 생성) + 저장 PUT 200 영속', async ({ page }) => {
    await gotoEditor(page);
    const vdiv = await addEmptyDiv(page);

    // 빈 Div 안에 Ul 추가.
    expect(await selectByPath(page, vdiv)).toBe(true);
    await openPaletteItems(page);
    await clickPaletteItem(page, 'Ul');
    const ulPath = `${vdiv}.children.0`;

    // Ul 선택 → children 에디터.
    expect(await selectByPath(page, ulPath)).toBe(true);
    await openPropsTab(page);
    await expect(page.getByTestId('g7le-children-editor')).toBeVisible();

    // 항목 추가 → 행 0 의 텍스트 위젯(I18nTextField 미리보기) 노출(평문 input 아님).
    await page.getByTestId('g7le-children-add').click();
    await page.waitForTimeout(300);
    const itemPreview = page.getByTestId('g7le-children-i18n-0-preview');
    await expect(itemPreview).toBeVisible({ timeout: 10_000 });

    // 평문 항목 텍스트 입력 → blur → POST 키 생성(항목 text 토큰 치환).
    await itemPreview.click();
    await itemPreview.fill('첫째 항목');
    const [resp] = await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/custom-translations') && r.request().method() === 'POST',
        { timeout: 15_000 },
      ),
      itemPreview.blur(),
    ]);
    expect([200, 201]).toContain(resp.status());

    // 저장 → 레이아웃 PUT 200(토큰이 content 에 영속).
    await page.getByTestId('g7le-property-modal-done').click().catch(() => undefined);
    const savePromise = page.waitForResponse(
      (r) => /\/api\/admin\/templates\/sirsoft-basic\/layouts\//.test(r.url()) && r.request().method() === 'PUT',
      { timeout: 15_000 },
    );
    await page.getByRole('button', { name: /save|저장/i }).first().click();
    const saveRes = await savePromise;
    expect(saveRes.status()).toBe(200);
  });

  test('Input placeholder propControl 위젯 마운트(미리보기 또는 바인딩 배지 중 하나는 항상 존재)', async ({ page }) => {
    await gotoEditor(page);
    const vdiv = await addEmptyDiv(page);
    expect(await selectByPath(page, vdiv)).toBe(true);
    await openPaletteItems(page);
    await clickPaletteItem(page, 'Input');
    const inputPath = `${vdiv}.children.0`;
    expect(await selectByPath(page, inputPath)).toBe(true);
    await openPropsTab(page);
    const preview = page.getByTestId('g7le-prop-i18n-inputPlaceholder-preview');
    const binding = page.getByTestId('g7le-prop-i18n-inputPlaceholder-binding');
    await expect(preview.or(binding).first()).toBeVisible({ timeout: 10_000 });
  });

  // 가로 스크롤 회귀 — 속성 모달 본문은 긴 텍스트 입력·🌐 펼침 폼에서도 가로로
  // 넘치지 않아야 한다. 컨트롤
  // 행/위젯 컨테이너 + 펼침 폼의 minWidth:0 가드를 라이브 브라우저로 잠근다.
  // @scenario property_modal_no_horizontal_scroll_on_long_input + property_modal_no_horizontal_scroll_on_expand
  // @effects control_row_and_widget_minwidth0_prevent_body_horizontal_scroll, expand_locale_form_minwidth0_prevents_body_horizontal_scroll
  test('속성 모달 본문은 긴 입력·🌐 펼침에서도 가로 스크롤이 없다', async ({ page }) => {
    await gotoEditor(page);
    const vdiv = await addEmptyDiv(page);
    expect(await selectByPath(page, vdiv)).toBe(true);
    await openPaletteItems(page);
    await clickPaletteItem(page, 'Input');
    const inputPath = `${vdiv}.children.0`;
    expect(await selectByPath(page, inputPath)).toBe(true);
    await openPropsTab(page);

    const noBodyHScroll = () =>
      page.evaluate(() => {
        const b = document.querySelector('[data-testid="g7le-property-modal-body"]') as HTMLElement | null;
        return b ? b.scrollWidth <= b.clientWidth + 1 : true;
      });

    const preview = page.getByTestId('g7le-prop-i18n-inputPlaceholder-preview');
    await expect(preview).toBeVisible({ timeout: 10_000 });
    // 기본 상태 — 넘침 없음.
    expect(await noBodyHScroll()).toBe(true);

    // 매우 긴 텍스트 입력 → blur → 키 생성. 입력칸이 줄어들어 본문을 넘기지 않아야 한다.
    await preview.click();
    await preview.fill('아주 긴 안내 문구 텍스트 가나다라마바사아자차카타파하 ABCDEFGHIJKLMNOP');
    await Promise.all([
      page.waitForResponse(
        (r) => r.url().includes('/custom-translations') && r.request().method() === 'POST',
        { timeout: 15_000 },
      ),
      preview.blur(),
    ]);
    expect(await noBodyHScroll()).toBe(true);

    // 🌐 펼침(ko/en/ja 폼) — 펼침 폼도 본문을 넘기지 않아야 한다.
    await page.getByTestId('g7le-prop-i18n-inputPlaceholder-toggle').click();
    await expect(page.getByTestId('g7le-prop-i18n-inputPlaceholder-expand')).toBeVisible({ timeout: 10_000 });
    expect(await noBodyHScroll()).toBe(true);

    // 펼침 폼 ko 입력칸에 긴 텍스트 — 펼침 상태에서도 넘침 없음.
    await page
      .getByTestId('g7le-prop-i18n-inputPlaceholder-expand-input-ko')
      .fill('아주 긴 다국어 라벨 텍스트 가나다라마바사아자차카타파하그그그그그그');
    expect(await noBodyHScroll()).toBe(true);
  });
});
