/**
 * Layout Editor — 배경 이미지 캔버스 반영 회귀.
 *
 * 결함: 속성 모달의 `image` 위젯이 만든 `{ url, size, repeat, position }` 객체를
 * 레시피 엔진이 4개 background CSS 속성으로 분해하지 못해, 캔버스(및 저장 후 사용자
 * 페이지)에 배경이 반영되지 않았다(모달 미리보기 썸네일만 정상). 단위(Vitest)에서는
 * applyStyleProp/reverseResolve 가 객체를 통째로 써 React 가 무시하는 것을 직접
 * 잡지 못해(브라우저 렌더만이 포착), Playwright 로 실제 캔버스 DOM 의 inline
 * `background-image: url(...)` 적용을 검증한다.
 *
 * @scenario background_image_apply + background_image_mode_switch
 * @effects canvas_background_image_url + canvas_background_size_mode
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

/**
 * 캔버스의 배경이 보일 만한 박스형 Div 영역을 골라 path 를 돌려준다. 좌측 라우트 트리
 * 패널과 겹치지 않도록 캔버스 안쪽(left > 360)으로 한정한다.
 */
async function pickBoxAreaPath(page: Page): Promise<string | null> {
  return page.evaluate(() => {
    const cands = Array.from(
      document.querySelectorAll('[data-editor-path][data-editor-name="Div"]'),
    ).filter((el) => {
      const r = el.getBoundingClientRect();
      return r.width > 200 && r.width < 800 && r.height > 80 && r.height < 320 && r.left > 360;
    });
    return cands[0]?.getAttribute('data-editor-path') ?? null;
  });
}

/**
 * 캔버스 위임 click 핸들러로 노드를 선택한다. 패널/오버레이가 좌표를 가로채는 것을
 * 피하려 실제 편집기가 받는 pointer/click 시퀀스를 노드 중앙에 직접 발사한다.
 */
async function selectNode(page: Page, path: string): Promise<void> {
  await page.evaluate((p) => {
    const el = document.querySelector(`[data-editor-path="${p}"]`);
    if (!el) return;
    el.scrollIntoView({ block: 'center' });
    const r = el.getBoundingClientRect();
    const cx = r.left + r.width / 2;
    const cy = r.top + r.height / 2;
    for (const type of ['pointerover', 'pointermove', 'pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click']) {
      el.dispatchEvent(new MouseEvent(type, { bubbles: true, clientX: cx, clientY: cy }));
    }
  }, path);
}

async function openStyleTab(page: Page): Promise<void> {
  await page.waitForSelector('[data-testid="g7le-overlay-info-button"]', { timeout: 10_000 });
  await page.getByTestId('g7le-overlay-info-button').click();
  await page.waitForSelector('[data-testid="g7le-context-menu-edit-props"]', { timeout: 5_000 });
  await page.getByTestId('g7le-context-menu-edit-props').click();
  await page.waitForSelector('[data-testid="g7le-property-modal"]', { timeout: 10_000 });
  await page.getByTestId('g7le-property-tab-style').click();
}

test.describe('@layout-editor 배경 이미지 캔버스 반영', () => {
  test('속성 모달 image 위젯 → URL 입력 → 캔버스 노드 inline background-image url(...) 반영', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 0,
      { timeout: 20_000 },
    );

    // 박스형 Div 영역 선택 → ⓘ → 속성 설정 → 모달 → 스타일 탭
    const targetPath = await pickBoxAreaPath(page);
    expect(targetPath).not.toBeNull();
    await selectNode(page, targetPath as string);
    await openStyleTab(page);

    // 배경 이미지 위젯의 URL 입력에 외부 URL 을 넣고 blur → applyRecipe 가 캔버스에 반영.
    const url = 'https://example.com/api/templates/sirsoft-basic/layout-attachments/5/file';
    const urlInput = page.getByTestId('g7le-image-url');
    await urlInput.fill(url);
    await urlInput.blur();

    // 캔버스 노드의 inline style 에 background-image: url(...) 가 적용되어야 한다(핵심 검증).
    await expect
      .poll(
        async () =>
          page.evaluate((p) => {
            const el = document.querySelector(`[data-editor-path="${p}"]`);
            return el ? getComputedStyle(el).backgroundImage : '';
          }, targetPath),
        { timeout: 8_000 },
      )
      .toContain('layout-attachments/5/file');

    // size/repeat 도 기본 모드(채움 = cover/no-repeat)로 함께 적용되어야 한다.
    const styleSnap = await page.evaluate((p) => {
      const el = document.querySelector(`[data-editor-path="${p}"]`) as HTMLElement | null;
      const cs = el ? getComputedStyle(el) : null;
      return { size: cs?.backgroundSize, repeat: cs?.backgroundRepeat };
    }, targetPath);
    expect(styleSnap.size).toBe('cover');
    expect(styleSnap.repeat).toBe('no-repeat');
  });

  test('표시 모드 전환(맞춤/타일) → 캔버스 backgroundSize/Repeat 정합', async ({ page }) => {
    const token = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, token);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2F');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
    await page.waitForSelector('[data-testid="g7le-preview-frame"]', { timeout: 30_000 });
    await page.waitForFunction(
      () => document.querySelectorAll('[data-editor-path]').length > 0,
      { timeout: 20_000 },
    );

    const targetPath = await pickBoxAreaPath(page);
    expect(targetPath).not.toBeNull();
    await selectNode(page, targetPath as string);
    await openStyleTab(page);

    const url = 'https://example.com/api/templates/sirsoft-basic/layout-attachments/5/file';
    const urlInput = page.getByTestId('g7le-image-url');
    await urlInput.fill(url);
    await urlInput.blur();
    await expect.poll(async () =>
      page.evaluate((p) => {
        const el = document.querySelector(`[data-editor-path="${p}"]`);
        return el ? getComputedStyle(el).backgroundImage : '';
      }, targetPath),
    ).toContain('layout-attachments/5/file');

    // 맞춤(fit) = contain / no-repeat
    await page.getByTestId('g7le-image-mode-fit').click();
    await expect.poll(async () =>
      page.evaluate((p) => {
        const el = document.querySelector(`[data-editor-path="${p}"]`);
        return el ? getComputedStyle(el).backgroundSize : '';
      }, targetPath),
    ).toBe('contain');

    // 타일(tile) = auto / repeat
    await page.getByTestId('g7le-image-mode-tile').click();
    await expect.poll(async () =>
      page.evaluate((p) => {
        const el = document.querySelector(`[data-editor-path="${p}"]`);
        return el ? getComputedStyle(el).backgroundRepeat : '';
      }, targetPath),
    ).toBe('repeat');
  });
});
