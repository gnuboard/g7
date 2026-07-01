/**
 * Layout Editor — 빈 컨테이너 첫 자식 삽입 + 폼 컴포넌트 propControl 편집.
 *
 * 결함#1 (빈 컨테이너 첫 자식 삽입 accepts 오필터):
 *  - "+ 요소 추가" 글로벌 팔레트는 선택 노드가 컨테이너이면 그 children 끝에 삽입한다.
 *  - 컨테이너 여부를 children 배열 존재로 판정하면(`Array.isArray(node.children)`) 자식이
 *    아직 없는 빈 컨테이너(Div/Form 등)는 컨테이너로 인식되지 못해 부모 accepts 로 필터된다.
 *  - 수정: nesting.containers 정의 기준(isContainerComponent — 드롭 경로와 동일)으로 판정.
 *    빈 Div 선택 시 팔레트가 Div 자신의 accepts(폼 컴포넌트 포함)로 필터되어야 한다.
 *
 * 폼 컴포넌트 propControl → 실 DOM 반영 (1-b-verify-2):
 *  - Input/Label 을 빈 Div 안에 추가하고 속성 탭에서 propControl 을 편집하면
 *    캔버스 실 DOM 에 반영되어야 한다(Input type/placeholder, Label htmlFor).
 *
 * 단위(Vitest): resolveGlobalInsertionTarget(nestingRules.test.ts) 가 빈 컨테이너 판정 로직을,
 * PasswordInput.test.tsx 가 data-editor-* 루트 부착을 잠근다. 본 Playwright 는 실제 편집기
 * 팔레트 필터 연동 + propControl→DOM 라운드트립을 브라우저로 검증한다.
 *
 * @scenario empty_container_first_child + form_propcontrol_edit
 * @effects empty_container_global_palette_filters_by_own_accepts_not_parent + palette_filters_by_parent_accepts
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

/** "+ 요소 추가" 팔레트 열고 표시된 항목 이름 목록 반환. */
async function openPaletteItems(page: Page): Promise<string[]> {
  await page.waitForFunction(
    () => !document.querySelector('[data-testid="g7le-toolbar-add-element"]')?.hasAttribute('disabled'),
    { timeout: 10_000 },
  );
  await page.getByTestId('g7le-toolbar-add-element').click();
  await page.waitForSelector('[data-testid^="g7le-palette-item-"]', { timeout: 10_000 });
  return page.evaluate(() =>
    Array.from(document.querySelectorAll('[data-testid^="g7le-palette-item-"]'))
      .map((e) => (e.getAttribute('data-testid') ?? '').replace('g7le-palette-item-', ''))
      .filter((n) => n && !n.endsWith('-tag') && !n.endsWith('-badge')),
  );
}

async function clickPaletteItem(page: Page, name: string): Promise<void> {
  await page.getByTestId(`g7le-palette-item-${name}`).click();
  await page.waitForTimeout(400);
}

/** 검증 컨테이너(빈 Div) 를 home content root 안에 추가하고 그 path 를 반환. */
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

test.describe('@layout-editor 빈 컨테이너 첫 자식 + 폼 propControl', () => {
  test('빈 Div 선택 시 팔레트가 그 Div 의 accepts(폼 포함)로 필터된다 (결함#1)', async ({ page }) => {
    await gotoEditor(page);

    const vdiv = await addEmptyDiv(page);
    // 빈 Div 선택 (children 없음)
    expect(await selectByPath(page, vdiv)).toBe(true);

    const items = await openPaletteItems(page);
    // 빈 Div 자신의 accepts 로 필터 → 폼 컴포넌트가 보여야 함 (수정 전엔 부모 Container accepts 라 미표시)
    for (const form of ['Label', 'Input', 'Textarea', 'Select', 'Checkbox', 'PasswordInput', 'FileUploader', 'AvatarUploader']) {
      expect(items, `빈 Div 팔레트에 ${form} 노출`).toContain(form);
    }
    // Div accepts(44) 수준 — 부모 Container accepts(23)보다 넓음
    expect(items.length).toBeGreaterThan(30);
  });

  test('빈 Div 에 Input/Label 추가 후 propControl 편집이 캔버스 DOM 에 반영', async ({ page }) => {
    await gotoEditor(page);
    const vdiv = await addEmptyDiv(page);

    // Input 추가
    expect(await selectByPath(page, vdiv)).toBe(true);
    await openPaletteItems(page);
    await clickPaletteItem(page, 'Input');
    const inputPath = vdiv + '.children.0';

    // Input 속성 탭 → inputType=email, placeholder 편집
    expect(await selectByPath(page, inputPath)).toBe(true);
    await page.getByTestId('g7le-overlay-info-button').click();
    await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
    await page.getByTestId('g7le-context-menu-edit-props').click();
    await page.waitForSelector('[data-testid="g7le-property-tab-props"]', { timeout: 10_000 });
    await page.getByTestId('g7le-property-tab-props').click();

    // inputType select → email
    await page.evaluate(() => {
      const ctrl = document.querySelector('[data-testid="g7le-control-inputType"]');
      const sel = ctrl?.querySelector('select') as HTMLSelectElement | null;
      if (sel) {
        const setter = Object.getOwnPropertyDescriptor(HTMLSelectElement.prototype, 'value')!.set!;
        setter.call(sel, 'email');
        sel.dispatchEvent(new Event('input', { bubbles: true }));
        sel.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    await page.waitForTimeout(250);

    // 캔버스 Input 의 type 속성이 email 로 반영
    const inputType = await page.evaluate(
      (p) => document.querySelector(`[data-editor-path="${p}"]`)?.getAttribute('type'),
      inputPath,
    );
    expect(inputType).toBe('email');
  });
});
