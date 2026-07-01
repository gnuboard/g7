/**
 * Layout Editor — 캔버스가 번들 editor-spec sampleData 로 데이터 의존 화면을 채우는지 회귀 가드.
 *
 * 배경:
 *  - PreviewCanvas 가 `useSampleData({ editorSpec: undefined })` 로 하드코딩되어,
 *  Phase 4 에서 도입한 번들 editor-spec.sampleData 가 캔버스 샘플 해소에 전혀 반영되지 않았다.
 *    그 결과 상품 상세 등 데이터 의존 화면이 캔버스에서 빈 상태로 표시됐다(이미지 없음/가격 공백/"출시예정").
 *  - 수정: Chrome 이 주입하는 병합 spec 을 `useSampleData` 에 연결(`editorSpec: spec ?? undefined`).
 *  - 본 spec 은 sirsoft-basic 편집기에서 상품 상세 라우트를 선택하면 캔버스가 product 샘플로
 *    채워져 가격(`원`) 텍스트가 나타나는지(= sampleData 가 실제 캔버스 바인딩을 채움) 검증한다.
 *    Vitest 는 JSON+격리 렌더, 본 spec 은 실제 편집기 chrome + 캔버스 통합 렌더.
 *
 * @scenario route_select:data_dependent + sample_provider_source:bundled_editor_spec
 * @effects canvas_data_dependent_screen_filled_with_sample_data
 * @effects preview_canvas_uses_merged_editor_spec_sampledata
 */
import { test, expect, issueToken, authenticatePage } from '../../fixtures/auth';

test.describe('@layout-editor 캔버스 샘플 데이터 렌더', () => {
  test('sirsoft-basic — 상품 상세 라우트 선택 시 캔버스가 product 샘플로 채워진다', async ({ page }) => {
    const tokenAuth = issueToken('core.templates.layouts.edit');
    await authenticatePage(page, tokenAuth);

    await page.goto('/admin/layout-editor/sirsoft-basic?route=%2Fshop%2Fproducts%2F%3Aid');
    await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    const canvas = page.getByTestId('g7le-preview-canvas');
    await canvas.waitFor({ state: 'visible', timeout: 30_000 });

    // product 샘플의 sales_status='on_sale' + selling_price_formatted 가 해소되면 가격(원)이 캔버스에 렌더된다.
    // 회귀(editorSpec: undefined)였다면 product 가 빈 객체로 떨어져 "출시예정" 분기만 보이고 가격은 없다.
    await expect
      .poll(async () => (await canvas.innerText()).includes('원'), {
        message: '상품 상세 캔버스에 가격(원) 이 렌더되어야 함 — product 샘플이 채워졌다는 신호',
        timeout: 20_000,
      })
      .toBe(true);

    // 회귀 신호인 "출시예정 상품" 만 단독으로 보이는 빈 상태가 아니어야 한다(가격이 함께 있으면 on_sale 분기 렌더).
    const text = await canvas.innerText();
    expect(text).toContain('원');
  });
});
