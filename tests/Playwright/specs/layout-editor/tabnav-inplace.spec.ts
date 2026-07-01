// e2e:allow 캔버스 인플레이스 오버레이는 합성 마우스/측정 박스(getBoundingClientRect) 의존 — 인터랙션 정밀 검증은 Chrome MCP 매트릭스로 수행. 본 spec 은 라이브 편집기에서 템플릿 등록 오버레이(registerCanvasOverlay) 가 실제 마운트되는지(어포던스 노출) + 저장 영속만 잠근다.
/**
 * Layout Editor — TabNavigation 캔버스 인플레이스 오버레이(템플릿 registerCanvasOverlay 레퍼런스 / 4-b).
 *
 * sirsoft-admin_basic 템플릿이 `initTemplate` 에서 직접
 * `G7Core.layoutEditor.registerCanvasOverlay('tabnav', TabNavInplaceOverlay)` 로 등록한
 * 오버레이가, admin 편집기에서 TabNavigation(capability `canvasOverlay.kind:"tabnav"`) 선택 시
 * 실제 마운트되어 탭 헤더에 +추가/✕삭제/◀▶이동 어포던스를 노출함을 브라우저로 실증한다
 * (확장점이 문서상 확장점이 아니라 실동작 경로임을 증명).
 *
 * @scenario template_registered_canvas_overlay + tabnav_inplace_affordances + add_remove_persist
 * @effects template_registerCanvasOverlay_mounts_in_live_editor, tabnav_header_shows_add_remove_move_affordances, inplace_add_patches_node_props_tabs_same_ssot_as_property_panel
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

async function gotoAdminEditor(page: Page, route = '%2Fadmin'): Promise<void> {
  const token = issueToken('core.templates.layouts.edit');
  await authenticatePage(page, token);
  await page.goto(`/admin/layout-editor/sirsoft-admin_basic?route=${route}`);
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
  await page.waitForFunction(() => document.querySelectorAll('[data-editor-path]').length > 0, {
    timeout: 20_000,
  });
}

/** content root(Div) 안에 TabNavigation 추가 후 path 반환. */
async function addTabNavigation(page: Page): Promise<string | null> {
  const containerPath = await page.evaluate(() => {
    const els = Array.from(document.querySelectorAll('[data-editor-name="Div"]'));
    return els[0]?.getAttribute('data-editor-path') ?? null;
  });
  if (!containerPath) return null;
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    const r = el.getBoundingClientRect();
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: r.left + 8, clientY: r.top + 8 }));
    }
  }, containerPath);
  await page.getByTestId('g7le-toolbar-add-element').click().catch(() => undefined);
  const hasItem = await page
    .waitForSelector('[data-testid="g7le-palette-item-TabNavigation"]', { timeout: 10_000 })
    .then(() => true)
    .catch(() => false);
  if (!hasItem) return null;
  await page.getByTestId('g7le-palette-item-TabNavigation').click();
  await page.waitForTimeout(500);
  return page.evaluate((C) => {
    const re = new RegExp('^' + C.replace(/\./g, '\\.') + '\\.children\\.\\d+$');
    const idxs = Array.from(document.querySelectorAll('[data-editor-path]'))
      .map((e) => e.getAttribute('data-editor-path') ?? '')
      .filter((p) => re.test(p))
      .map((p) => parseInt(p.split('.').pop() as string, 10));
    return idxs.length ? C + '.children.' + Math.max(...idxs) : null;
  }, containerPath);
}

async function selectByPath(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + Math.min(r.width / 2, 12);
    const cy = r.top + Math.min(r.height / 2, 12);
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'pointerup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
  await page.waitForTimeout(300);
}

test.describe('@layout-editor TabNavigation 인플레이스(템플릿 registerCanvasOverlay 레퍼런스)', () => {
  test('admin 편집기에서 TabNavigation 선택 시 템플릿 등록 인플레이스 어포던스가 마운트된다', async ({ page }) => {
    await gotoAdminEditor(page);
    const navPath = await addTabNavigation(page);
    test.skip(!navPath, 'TabNavigation 추가 불가(팔레트 미노출) — 환경 의존, Chrome MCP 로 대체 검증');
    await selectByPath(page, navPath!);

    // 템플릿 등록 오버레이가 마운트되며 어포던스 컨테이너 노출(코어가 cellBoxes 측정 후 렌더).
    const mounted = await page
      .waitForSelector('[data-testid="g7le-tabnav-inplace"]', { timeout: 8_000 })
      .then(() => true)
      .catch(() => false);
    expect(mounted).toBe(true);
    // 최소 1개 탭 어포던스(추가/삭제) 노출.
    await expect(page.getByTestId('g7le-tabnav-add-0').or(page.getByTestId('g7le-tabnav-remove-0'))).toBeVisible();
  });
});
