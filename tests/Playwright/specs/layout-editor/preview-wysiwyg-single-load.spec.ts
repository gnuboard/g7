/**
 * E2E: 레이아웃 편집기 미리보기의 본문 편집기(CKEditor) — 본체를 한 번만 실행해 편집기가 선다
 *
 * 결함: 미리보기는 스크립트 목록이 정해지기 전에 컴포넌트를 한 번 마운트한다. 그때 편집기 플러그인이 자체 로더로
 * 본체(UMD)를 싣고, 목록이 정해지면 미리보기가 레이아웃 스크립트로 같은 본체를 또 실었다. UMD 는 실행 첫머리에
 * `window.CKEDITOR = {}` 를 대입하고 이미 실행된 적이 있으면 멈추므로, 두 번째 실행이 전역을 빈 객체로 덮어
 * 편집기가 「편집기를 불러오지 못했습니다」 안내와 함께 임시 입력창으로 떨어졌다. 확장자 붙은 주소를 웹서버가
 * 가로채는 사이트에서는 미리보기의 레이아웃 스크립트 요청이 404 이기도 했다.
 *
 * 자산 주소 방식 두 가지를 모두 강제해 서버 설정과 무관하게 잰다 — 확장자 방식은 이 서버가 확장자 주소를 가로채도
 * 같은 응답을 200 으로 돌려주도록 가로채기로 맞춘다.
 *
 * @scenario asset_class=vendored, outcome=loaded
 * @effects vendored_editor_umd_executes_once_across_loaders, preview_layout_script_url_converted_to_current_mode
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';
import type { Page } from '@playwright/test';

const ROUTE = '*/admin/pages/:id/edit';

/**
 * 자산 주소 방식을 고정한다. 확장자 방식이면 확장자 주소 요청을 같은 응답의 200 으로 바꿔 준다.
 *
 * @param page 페이지
 * @param mode 자산 주소 방식
 */
async function forceAssetUrlMode(page: Page, mode: 'extension' | 'extensionless'): Promise<void> {
  await page.addInitScript((m) => {
    try {
      Object.keys(localStorage).filter((k) => k.startsWith('g7_asset_url_mode')).forEach((k) => localStorage.removeItem(k));
    } catch {
      // 저장소에 접근할 수 없는 환경은 그대로 둔다
    }
    (window as any).__g7AssetUrlMode = m;
  }, mode);
  if (mode === 'extension') {
    await page.route(/\/api\/[^?]+\.(js|css|json)(\?.*)?$/, async (route) => {
      const response = await route.fetch();
      await route.fulfill({ response, status: 200 });
    });
  }
}

for (const mode of ['extensionless', 'extension'] as const) {
  /**
   * @scenario asset_class=vendored, outcome=loaded
   * @effects vendored_editor_umd_executes_once_across_loaders, preview_layout_script_url_converted_to_current_mode
   */
  test(`미리보기의 본문 편집기가 본체를 한 번만 실행해 선다 (${mode})`, async ({ page }) => {
    await forceAssetUrlMode(page, mode);
    await authenticatePage(page, issueToken('core.templates.layouts.edit'));

    const umdStatuses: number[] = [];
    page.on('response', (res) => {
      if (res.url().includes('ckeditor5.umd.js')) umdStatuses.push(res.status());
    });

    await page.goto('/admin/layout-editor/sirsoft-admin_basic?route=' + encodeURIComponent(ROUTE));
    const frame = page.locator('[data-testid="g7le-preview-frame"]');
    await expect(frame).toBeVisible({ timeout: 60_000 });
    await expect(frame.locator('.ck-editor').first()).toBeVisible({ timeout: 30_000 });

    const state = await page.evaluate(() => {
      const w = window as any;
      return {
        mode: w.G7Config?.assetUrlMode ?? null,
        classic: typeof w.CKEDITOR?.ClassicEditor === 'function',
        umdTags: Array.from(document.querySelectorAll('script[src]')).filter((s) => (s.getAttribute('src') ?? '').includes('ckeditor5.umd.js')).length,
      };
    });

    expect(state.mode).toBe(mode);
    expect(state.classic, '본체 전역이 빈 객체로 덮이지 않았다').toBe(true);
    expect(state.umdTags, '본체 태그는 하나다').toBe(1);
    expect(umdStatuses.every((status) => status === 200), `본체 응답 ${umdStatuses.join(',')}`).toBe(true);
    await expect(page.getByText('편집기를 불러오지 못했습니다')).toHaveCount(0);
  });
}
